<?php

use App\Jobs\AlerterAdminCodeBloque;
use App\Jobs\AnnulerCommandeTimeout;
use App\Jobs\NotifierLivreurGoShop;
use App\Jobs\SendPushNotification;
use App\Models\City;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Parcel;
use App\Models\ParcelPhoto;
use App\Models\Product;
use App\Models\User;
use App\Modules\GoColis\Services\ParcelService;
use App\Modules\GoShop\Services\AssignmentService;
use App\Modules\GoShop\Services\OrderService;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

it('P6 Point 7: AnnulerCommandeTimeout annule pending apres 45s, laisse accepted intact et lit acceptation_commerce_secondes', function () {
    Queue::fake([SendPushNotification::class, AnnulerCommandeTimeout::class]);

    $city = City::factory()->create();
    $client = User::factory()->create(['role' => 'client', 'city_id' => $city->id]);
    $merchant = Merchant::factory()->create(['city_id' => $city->id]);

    $pendingOrder = Order::factory()->create([
        'city_id' => $city->id,
        'client_id' => $client->id,
        'merchant_id' => $merchant->id,
        'status' => Order::STATUS_PENDING,
    ]);

    $acceptedOrder = Order::factory()->create([
        'city_id' => $city->id,
        'client_id' => $client->id,
        'merchant_id' => $merchant->id,
        'status' => Order::STATUS_ACCEPTED,
    ]);

    // 1. Avant 45s (20s) -> pas annulee
    $this->travel(20)->seconds();
    app()->call([new AnnulerCommandeTimeout($pendingOrder->id), 'handle']);
    expect($pendingOrder->fresh()->status)->toBe(Order::STATUS_PENDING);

    // 2. Apres 45s (+26s = 46s) -> pending annulee, accepted jamais touchee
    $this->travel(26)->seconds();
    app()->call([new AnnulerCommandeTimeout($pendingOrder->id), 'handle']);
    app()->call([new AnnulerCommandeTimeout($acceptedOrder->id), 'handle']);

    $pendingOrder->refresh();
    expect($pendingOrder->status)->toBe(Order::STATUS_CANCELLED)
        ->and($pendingOrder->cancel_reason)->toContain('45s')
        ->and($acceptedOrder->fresh()->status)->toBe(Order::STATUS_ACCEPTED)
        ->and(Notification::where('user_id', $client->id)->where('type', 'order_refused')->count())->toBe(1);

    // 3. Surcharge acceptation_commerce_secondes = 60 -> non annulee a 45s, annulee a 60s
    $this->travelBack();
    app(SettingsService::class)->set('delais_regles', ['acceptation_commerce_secondes' => 60], $city->id);

    $product = Product::factory()->create(['merchant_id' => $merchant->id, 'price' => 1500, 'is_available' => true]);
    $customOrder = app(OrderService::class)->creerCommande($client, [
        'merchant_id' => $merchant->id,
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
        'delivery_address' => 'Quartier Soleil',
        'delivery_lat' => 6.8800,
        'delivery_lng' => -6.4510,
        'payment_method' => 'cash',
    ]);

    Queue::assertPushed(AnnulerCommandeTimeout::class, function (AnnulerCommandeTimeout $job) use ($customOrder): bool {
        if ($job->orderId !== $customOrder->id || $job->delay === null) {
            return false;
        }

        $delaySec = (int) round(now()->diffInSeconds($job->delay, false));

        return $delaySec >= 58 && $delaySec <= 61;
    });

    expect(Notification::where('user_id', $merchant->user_id)->where('type', 'new_order')->latest('id')->first()?->body)
        ->toContain('60s');

    $this->travel(45)->seconds();
    app()->call([new AnnulerCommandeTimeout($customOrder->id), 'handle']);
    expect($customOrder->fresh()->status)->toBe(Order::STATUS_PENDING);

    $this->travel(16)->seconds();
    app()->call([new AnnulerCommandeTimeout($customOrder->id), 'handle']);
    expect($customOrder->fresh()->status)->toBe(Order::STATUS_CANCELLED)
        ->and($customOrder->fresh()->cancel_reason)->toContain('60s');
});

it('P6 Point 7: erreurs_code_avant_blocage et acceptation_livreur_secondes lus depuis SettingsService', function () {
    Queue::fake([SendPushNotification::class, AlerterAdminCodeBloque::class, NotifierLivreurGoShop::class]);

    $city = City::factory()->create();
    app(SettingsService::class)->set('delais_regles', [
        'acceptation_livreur_secondes' => 45,
        'erreurs_code_avant_blocage' => 2,
    ], $city->id);

    $driver = User::factory()->create(['role' => 'driver', 'city_id' => $city->id, 'device_token' => 'tok-d-set']);
    $courier = Courier::factory()->create([
        'user_id' => $driver->id,
        'city_id' => $city->id,
        'is_online' => true,
        'is_active' => true,
        'current_lat' => 6.8771,
        'current_lng' => -6.4502,
        'location_updated_at' => now(),
    ]);

    // 1. acceptation_livreur_secondes = 45 -> delay ~ 45s sur NotifierLivreurGoShop
    $orderForDispatch = Order::factory()->create([
        'city_id' => $city->id,
        'status' => Order::STATUS_ACCEPTED,
        'pickup_lat' => 6.8770,
        'pickup_lng' => -6.4502,
        'payment_method' => 'orange_money',
    ]);
    app(AssignmentService::class)->attribuerLivreurGoShop($orderForDispatch);

    Queue::assertPushed(NotifierLivreurGoShop::class, function (NotifierLivreurGoShop $job): bool {
        $delaySec = $job->delay ? (int) round(now()->diffInSeconds($job->delay, false)) : 0;

        return $delaySec >= 43 && $delaySec <= 46;
    });

    // 2. erreurs_code_avant_blocage = 2 pour GoShop
    $order = Order::factory()->create([
        'city_id' => $city->id,
        'courier_id' => $courier->id,
        'status' => Order::STATUS_PICKED_UP,
        'confirmation_code' => Hash::make('4321'),
        'confirmation_attempts' => 0,
        'confirmation_locked' => false,
    ]);

    $orderService = app(OrderService::class);
    expect(fn () => $orderService->validerCodeConfirmation($order, '0000', $driver))
        ->toThrow(ValidationException::class, '1 tentative(s)');
    expect($order->fresh()->confirmation_locked)->toBeFalse();

    expect(fn () => $orderService->validerCodeConfirmation($order->fresh(), '0000', $driver))
        ->toThrow(ValidationException::class, 'bloquée');
    expect($order->fresh()->confirmation_locked)->toBeTrue();

    // 3. erreurs_code_avant_blocage = 2 pour GoColis
    $parcel = Parcel::factory()->create([
        'city_id' => $city->id,
        'courier_id' => $courier->id,
        'status' => Parcel::STATUS_ACCEPTED,
        'sender_code' => Hash::make('9876'),
        'sender_code_attempts' => 0,
        'sender_code_locked' => false,
    ]);

    ParcelPhoto::create([
        'parcel_id' => $parcel->id,
        'type' => 'pickup',
        'photo_url' => 'https://cdn.golivreur.test/pickup.jpg',
    ]);

    $parcelService = app(ParcelService::class);
    expect(fn () => $parcelService->validerCodeExpediteur($parcel, '0000'))
        ->toThrow(ValidationException::class, '1 tentative(s)');

    expect(fn () => $parcelService->validerCodeExpediteur($parcel->fresh(), '0000'))
        ->toThrow(ValidationException::class, 'Expédition bloquée');

    expect($parcel->fresh()->sender_code_locked)->toBeTrue();
    Queue::assertPushed(AlerterAdminCodeBloque::class, 2);
});
