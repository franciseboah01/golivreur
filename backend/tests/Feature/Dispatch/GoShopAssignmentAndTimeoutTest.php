<?php

use App\Jobs\AlerterAdminPasDelivreur;
use App\Jobs\NotifierLivreurGoShop;
use App\Jobs\SendPushNotification;
use App\Models\City;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\Notification;
use App\Models\Order;
use App\Models\User;
use App\Modules\GoShop\Services\AssignmentService;
use Illuminate\Support\Facades\Queue;

it('P6 Point 1: NotifierLivreurGoShop exclut le livreur sans reponse apres 30s, passe au suivant puis passe en waiting apres 3 tentatives', function () {
    Queue::fake([SendPushNotification::class, AlerterAdminPasDelivreur::class, NotifierLivreurGoShop::class]);

    $city = City::factory()->create();
    $client = User::factory()->create(['role' => 'client', 'city_id' => $city->id, 'device_token' => 'tok-client']);
    $merchant = Merchant::factory()->create(['city_id' => $city->id, 'lat' => 6.8770, 'lng' => -6.4502]);

    $driver1 = User::factory()->create(['role' => 'driver', 'city_id' => $city->id, 'device_token' => 'tok-goshop-1']);
    $courier1 = Courier::factory()->create([
        'user_id' => $driver1->id,
        'city_id' => $city->id,
        'is_online' => true,
        'is_active' => true,
        'current_lat' => 6.8771,
        'current_lng' => -6.4502,
        'location_updated_at' => now(),
    ]);

    $driver2 = User::factory()->create(['role' => 'driver', 'city_id' => $city->id, 'device_token' => 'tok-goshop-2']);
    $courier2 = Courier::factory()->create([
        'user_id' => $driver2->id,
        'city_id' => $city->id,
        'is_online' => true,
        'is_active' => true,
        'current_lat' => 6.8850,
        'current_lng' => -6.4520,
        'location_updated_at' => now(),
    ]);

    $order = Order::factory()->create([
        'city_id' => $city->id,
        'client_id' => $client->id,
        'merchant_id' => $merchant->id,
        'courier_id' => null,
        'status' => Order::STATUS_ACCEPTED,
        'pickup_lat' => $merchant->lat,
        'pickup_lng' => $merchant->lng,
    ]);

    // 1. Attribution initiale (tentative 1) -> propose au plus proche (courier1)
    app(AssignmentService::class)->attribuerLivreurGoShop($order);

    expect($order->fresh()->assignment_attempts)->toBe(1)
        ->and(Notification::where('user_id', $driver1->id)->where('type', 'course_offer_goshop')->exists())->toBeTrue();

    Queue::assertPushed(NotifierLivreurGoShop::class, fn (NotifierLivreurGoShop $job): bool => $job->orderId === $order->id
        && $job->courierId === $courier1->id
        && $job->tentative === 1
        && in_array($courier1->id, $job->exclusions, true));

    // 2. Expiration 30s sans acceptation -> exclut courier1 et propose a courier2 (tentative 2)
    app()->call([new NotifierLivreurGoShop(
        $order->id,
        $courier1->id,
        1,
        [$courier1->id],
        now()->subSeconds(35)->toIso8601String(),
    ), 'handle']);

    expect($order->fresh()->assignment_attempts)->toBe(2)
        ->and(Notification::where('user_id', $driver2->id)->where('type', 'course_offer_goshop')->exists())->toBeTrue();

    Queue::assertPushed(NotifierLivreurGoShop::class, fn (NotifierLivreurGoShop $job): bool => $job->orderId === $order->id
        && $job->courierId === $courier2->id
        && $job->tentative === 2
        && in_array($courier1->id, $job->exclusions, true)
        && in_array($courier2->id, $job->exclusions, true));

    // 3. Expiration apres 3e tentative sans preneur -> statut waiting + AlerterAdminPasDelivreur + notification client
    app()->call([new NotifierLivreurGoShop(
        $order->id,
        $courier2->id,
        3,
        [$courier1->id, $courier2->id],
        now()->subSeconds(35)->toIso8601String(),
    ), 'handle']);

    $order->refresh();
    expect($order->status)->toBe(Order::STATUS_WAITING)
        ->and($order->assignment_attempts)->toBe(3)
        ->and(Order::TRANSITIONS_AUTORISEES)->toHaveKey(Order::STATUS_WAITING)
        ->and(Order::TRANSITIONS_AUTORISEES[Order::STATUS_ACCEPTED])->toContain(Order::STATUS_WAITING)
        ->and(Notification::where('user_id', $client->id)->where('type', 'order_waiting')->exists())->toBeTrue();

    Queue::assertPushed(AlerterAdminPasDelivreur::class, fn (AlerterAdminPasDelivreur $job): bool => $job->type === 'goshop');
});

it('P6 Point 1 idempotence: NotifierLivreurGoShop ne relance pas une commande deja acceptee par un livreur', function () {
    Queue::fake([SendPushNotification::class, AlerterAdminPasDelivreur::class, NotifierLivreurGoShop::class]);

    $city = City::factory()->create();
    $client = User::factory()->create(['role' => 'client', 'city_id' => $city->id]);
    $driver1 = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    $courier1 = Courier::factory()->create(['user_id' => $driver1->id, 'city_id' => $city->id, 'is_online' => true, 'is_active' => true]);

    $driver2 = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    Courier::factory()->create(['user_id' => $driver2->id, 'city_id' => $city->id, 'is_online' => true, 'is_active' => true]);

    $order = Order::factory()->create([
        'city_id' => $city->id,
        'client_id' => $client->id,
        'courier_id' => $courier1->id,
        'status' => Order::STATUS_ACCEPTED,
    ]);

    app()->call([new NotifierLivreurGoShop(
        $order->id,
        $courier1->id,
        1,
        [$courier1->id],
        now()->subSeconds(40)->toIso8601String(),
    ), 'handle']);

    $order->refresh();
    expect($order->status)->toBe(Order::STATUS_ACCEPTED)
        ->and($order->courier_id)->toBe($courier1->id)
        ->and(Notification::where('user_id', $driver2->id)->exists())->toBeFalse()
        ->and(Notification::where('user_id', $client->id)->where('type', 'order_waiting')->exists())->toBeFalse();

    Queue::assertNotPushed(AlerterAdminPasDelivreur::class);
    Queue::assertNotPushed(NotifierLivreurGoShop::class);
});
