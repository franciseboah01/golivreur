<?php

use App\Jobs\NotifierLivreurGoColis;
use App\Jobs\NotifierLivreurGoShop;
use App\Models\City;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Parcel;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Modules\GoShop\Services\AssignmentService;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Tymon\JWTAuth\Facades\JWTAuth;

it('refuse acceptOrder, acceptParcel et dispatch auto cash avec 2 courses cash actives et solde juste au-dessus du minimum', function () {
    Queue::fake();

    $city = City::factory()->create();
    app(SettingsService::class)->set('courses.max_actives', 5, $city->id);
    app(SettingsService::class)->set('wallet.solde_minimum_cash', 10000, $city->id);

    $merchant = Merchant::factory()->create(['city_id' => $city->id]);
    $driver = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    $courier = Courier::factory()->create([
        'user_id' => $driver->id,
        'city_id' => $city->id,
        'is_online' => true,
        'is_active' => true,
        'current_lat' => 6.8770,
        'current_lng' => -6.4502,
        'location_updated_at' => now(),
    ]);

    // Solde juste au-dessus du minimum (10 000 + 500 = 10 500)
    $wallet = Wallet::create([
        'owner_type' => 'courier',
        'owner_id' => $courier->id,
        'balance' => 10500,
    ]);

    // 1re course cash active (GoShop) : à reverser = 1800 + 700 - 1000 = 1500
    Order::factory()->cash()->create([
        'city_id' => $city->id,
        'merchant_id' => $merchant->id,
        'courier_id' => $courier->id,
        'status' => Order::STATUS_ACCEPTED,
        'products_total' => 1800,
        'delivery_fee' => 700,
        'platform_commission' => 270,
        'merchant_amount' => 1530,
        'courier_amount' => 1000,
    ]);

    // 2e course cash active (GoColis) : à reverser = 1500 + 0 - 1000 = 500
    // Total engagements cash en cours = 1500 + 500 = 2000 -> seuil requis = 10 000 + 2 000 = 12 000
    Parcel::factory()->cash()->create([
        'city_id' => $city->id,
        'courier_id' => $courier->id,
        'status' => Parcel::STATUS_AT_SENDER,
        'shipping_fee' => 1500,
        'insurance_amount' => 0,
        'platform_commission' => 500,
        'courier_amount' => 1000,
    ]);

    // 3e course candidate GoShop (reversement = 500 <= 8500 disponible brut, mais 10500 < 12000 requis)
    $thirdOrder = Order::factory()->cash()->create([
        'city_id' => $city->id,
        'merchant_id' => $merchant->id,
        'courier_id' => null,
        'status' => Order::STATUS_READY,
        'products_total' => 1000,
        'delivery_fee' => 500,
        'platform_commission' => 150,
        'merchant_amount' => 850,
        'courier_amount' => 1000,
    ]);

    // 3e course candidate GoColis (reversement = 300)
    $thirdParcel = Parcel::factory()->cash()->create([
        'city_id' => $city->id,
        'courier_id' => null,
        'status' => Parcel::STATUS_PENDING,
        'shipping_fee' => 1200,
        'insurance_amount' => 0,
        'platform_commission' => 300,
        'courier_amount' => 900,
    ]);

    $assignment = app(AssignmentService::class);
    $assignment->attribuerLivreurGoShop($thirdOrder);
    $assignment->attribuerLivreurGoColis($thirdParcel);
    Queue::assertNotPushed(NotifierLivreurGoShop::class);
    Queue::assertNotPushed(NotifierLivreurGoColis::class);

    $token = JWTAuth::fromUser($driver);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/driver/orders/{$thirdOrder->id}/accept")
        ->assertStatus(422)
        ->assertJsonPath('succes', false);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/driver/parcels/{$thirdParcel->id}/accept")
        ->assertStatus(422)
        ->assertJsonPath('succes', false);

    expect($thirdOrder->fresh()->courier_id)->toBeNull()
        ->and($thirdParcel->fresh()->courier_id)->toBeNull();

    // Une course non cash (orange_money) reste acceptable tant que max_actives le permet
    $omOrder = Order::factory()->create([
        'city_id' => $city->id,
        'merchant_id' => $merchant->id,
        'courier_id' => null,
        'status' => Order::STATUS_READY,
        'payment_method' => 'orange_money',
        'payment_status' => 'paid',
    ]);
    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/driver/orders/{$omOrder->id}/accept")
        ->assertOk();

    // Une fois le wallet rechargé à minimum + engagements (10 000 + 2 000 = 12 000), l'acceptation cash passe
    $wallet->update(['balance' => 12000]);

    Auth::forgetGuards();
    app()->forgetScopedInstances();
    JWTAuth::unsetToken();
    $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($driver))
        ->postJson("/api/v1/driver/parcels/{$thirdParcel->id}/accept")
        ->assertOk()
        ->assertJsonPath('donnees.courier_id', $courier->id);
});

it('documente le rollback atomique 422 si le debit de reversement cash echoue a la livraison', function () {
    $city = City::factory()->create();
    $merchant = Merchant::factory()->create(['city_id' => $city->id]);
    $driver = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    $courier = Courier::factory()->create([
        'user_id' => $driver->id,
        'city_id' => $city->id,
        'is_online' => true,
        'is_active' => true,
    ]);

    Wallet::create([
        'owner_type' => 'courier',
        'owner_id' => $courier->id,
        'balance' => 500,
    ]);

    $order = Order::factory()->cash()->create([
        'city_id' => $city->id,
        'merchant_id' => $merchant->id,
        'courier_id' => $courier->id,
        'status' => Order::STATUS_PICKED_UP,
        'products_total' => 4000,
        'delivery_fee' => 800,
        'platform_commission' => 760,
        'merchant_amount' => 3400,
        'courier_amount' => 640,
        'confirmation_code' => Hash::make('4829'),
    ]);

    $this->actingAs($driver, 'api')
        ->postJson("/api/v1/driver/orders/{$order->id}/confirm-delivery", ['code' => '4829'])
        ->assertStatus(422)
        ->assertJsonPath('succes', false);

    expect($order->fresh()->status)->toBe(Order::STATUS_PICKED_UP)
        ->and($order->fresh()->payment_status)->toBe('pending_cash')
        ->and((int) Wallet::where('owner_type', 'courier')->where('owner_id', $courier->id)->value('balance'))->toBe(500)
        ->and(WalletTransaction::where('order_id', $order->id)->count())->toBe(0);
});
