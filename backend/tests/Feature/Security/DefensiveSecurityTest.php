<?php

use App\Models\City;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Parcel;
use App\Models\User;
use App\Models\Wallet;
use App\Modules\GoColis\Services\ParcelService;
use App\Modules\GoShop\Services\OrderService;
use App\Services\WalletService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tymon\JWTAuth\Facades\JWTAuth;

it('empêche un client d\'accéder aux détails du colis d\'un autre client (anti-IDOR)', function () {
    $city = City::factory()->create();
    $clientA = User::factory()->create(['role' => 'client', 'city_id' => $city->id]);
    $clientB = User::factory()->create(['role' => 'client', 'city_id' => $city->id]);

    $parcel = Parcel::factory()->create([
        'city_id' => $city->id,
        'sender_id' => $clientA->id,
    ]);

    $tokenB = JWTAuth::fromUser($clientB);

    $response = $this->withHeader('Authorization', "Bearer {$tokenB}")
        ->getJson("/api/v1/client/parcels/{$parcel->id}");

    $response->assertStatus(403);
});

it('un city manager ne peut pas consulter une commande d\'une autre ville (anti-IDOR)', function () {
    $cityDaloa = City::factory()->create(['name' => 'Daloa']);
    $cityBouake = City::factory()->create(['name' => 'Bouaké']);

    $managerDaloa = User::factory()->create(['role' => 'city_manager', 'city_id' => $cityDaloa->id]);
    $orderBouake = Order::factory()->create(['city_id' => $cityBouake->id]);

    $token = JWTAuth::fromUser($managerDaloa);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/v1/client/orders/{$orderBouake->id}");

    $response->assertStatus(403);
});

it('empêche la double validation et le double crédit d\'une commande GoShop déjà livrée', function () {
    $city = City::factory()->create();
    $client = User::factory()->create(['role' => 'client', 'city_id' => $city->id]);
    $merchantUser = User::factory()->create(['role' => 'merchant', 'city_id' => $city->id]);
    $driverUser = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);

    $merchant = Merchant::factory()->create(['user_id' => $merchantUser->id, 'city_id' => $city->id]);
    $courier = Courier::factory()->create(['user_id' => $driverUser->id, 'city_id' => $city->id]);

    $order = Order::factory()->create([
        'city_id' => $city->id,
        'client_id' => $client->id,
        'merchant_id' => $merchant->id,
        'courier_id' => $courier->id,
        'status' => Order::STATUS_PICKED_UP,
        'confirmation_code' => Hash::make('1234'),
        'confirmation_locked' => false,
        'courier_amount' => 1500,
        'merchant_amount' => 4000,
    ]);

    $this->actingAs($driverUser, 'api');
    $orderService = app(OrderService::class);

    // 1ère validation -> OK
    $validated = $orderService->validerCodeConfirmation($order, '1234');
    expect($validated->status)->toBe(Order::STATUS_DELIVERED);
    expect((float) Wallet::where('owner_type', 'courier')->where('owner_id', $courier->id)->value('balance'))->toBe(1500.0)
        ->and((float) Wallet::where('owner_type', 'merchant')->where('owner_id', $merchant->id)->value('balance'))->toBe(4000.0);

    // 2ème validation (tentative de double validation ou race condition) -> doit lever ValidationException sans double crédit
    expect(fn () => $orderService->validerCodeConfirmation($order, '1234'))
        ->toThrow(ValidationException::class);
    expect((float) Wallet::where('owner_type', 'courier')->where('owner_id', $courier->id)->value('balance'))->toBe(1500.0)
        ->and((float) Wallet::where('owner_type', 'merchant')->where('owner_id', $merchant->id)->value('balance'))->toBe(4000.0);
});

it('empêche la double validation et le double crédit d\'un colis GoColis déjà livré', function () {
    $city = City::factory()->create();
    $sender = User::factory()->create(['role' => 'client', 'city_id' => $city->id]);
    $driverUser = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    $courier = Courier::factory()->create(['user_id' => $driverUser->id, 'city_id' => $city->id]);

    $parcel = Parcel::factory()->create([
        'city_id' => $city->id,
        'sender_id' => $sender->id,
        'courier_id' => $courier->id,
        'status' => Parcel::STATUS_PICKED_UP,
        'recipient_code' => Hash::make('5678'),
        'recipient_code_locked' => false,
        'courier_amount' => 2000,
    ]);

    // Ajouter photo de livraison factice
    $parcel->photos()->create([
        'type' => 'delivery',
        'photo_url' => 'https://storage.golivreur.ci/test.jpg',
    ]);

    $parcelService = app(ParcelService::class);

    // 1ère validation -> OK
    $delivered = $parcelService->validerCodeDestinataire($parcel, '5678');
    expect($delivered->status)->toBe(Parcel::STATUS_DELIVERED);

    // 2ème tentative -> doit lever ValidationException
    expect(fn () => $parcelService->validerCodeDestinataire($parcel, '5678'))
        ->toThrow(ValidationException::class);
});

it('empêche un solde négatif lors d\'un retrait sur wallet', function () {
    $walletService = app(WalletService::class);
    $city = City::factory()->create();
    $driverUser = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    $courier = Courier::factory()->create(['user_id' => $driverUser->id, 'city_id' => $city->id]);

    $wallet = Wallet::create([
        'owner_type' => 'courier',
        'owner_id' => $courier->id,
        'balance' => 1000,
        'total_earned' => 1000,
        'total_withdrawn' => 0,
    ]);

    // Tentative de retrait de 5000 alors que le solde est de 1000
    expect(function () use ($walletService, $wallet) {
        $reflection = new ReflectionClass($walletService);
        $method = $reflection->getMethod('effectuerTransaction');
        $method->setAccessible(true);
        $method->invokeArgs($walletService, [
            $wallet,
            'withdrawal',
            -5000, // Débit de 5000
            'Retrait Wave',
        ]);
    })->toThrow(InvalidArgumentException::class, 'Solde insuffisant');

    expect($wallet->fresh()->balance)->toEqual(1000);
});
