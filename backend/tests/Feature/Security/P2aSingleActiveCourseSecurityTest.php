<?php

use App\Models\City;
use App\Models\Courier;
use App\Models\Order;
use App\Models\Parcel;
use App\Models\User;
use App\Services\SettingsService;
use Tymon\JWTAuth\Facades\JWTAuth;

it('P2a refuse 409 une 2e course GoShop si le livreur a déjà une course active (défaut courses.max_actives = 1), puis autorise après livraison', function () {
    $city = City::factory()->create();
    $driver = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    $courier = Courier::factory()->create(['user_id' => $driver->id, 'city_id' => $city->id, 'is_online' => true, 'is_active' => true]);

    $activeOrder = Order::factory()->create([
        'city_id' => $city->id,
        'courier_id' => $courier->id,
        'status' => Order::STATUS_ACCEPTED,
    ]);
    $secondOrder = Order::factory()->create([
        'city_id' => $city->id,
        'courier_id' => null,
        'status' => Order::STATUS_ACCEPTED,
    ]);

    $token = JWTAuth::fromUser($driver);

    // 2e acceptation refusée (409) tant que la 1ère commande est active
    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/driver/orders/{$secondOrder->id}/accept")
        ->assertStatus(409)
        ->assertJsonPath('succes', false);

    expect($secondOrder->fresh()->courier_id)->toBeNull();

    // Une fois la 1ère livrée -> nouvelle acceptation possible
    $activeOrder->update(['status' => Order::STATUS_DELIVERED]);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/driver/orders/{$secondOrder->id}/accept")
        ->assertOk()
        ->assertJsonPath('succes', true);

    expect($secondOrder->fresh()->courier_id)->toBe($courier->id);
});

it('P2a refuse 409 acceptOrder si le livreur a déjà un colis GoColis actif en cours', function () {
    $city = City::factory()->create();
    $driver = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    $courier = Courier::factory()->create(['user_id' => $driver->id, 'city_id' => $city->id, 'is_online' => true, 'is_active' => true]);

    Parcel::factory()->create([
        'city_id' => $city->id,
        'courier_id' => $courier->id,
        'status' => Parcel::STATUS_IN_TRANSIT,
    ]);
    $order = Order::factory()->create([
        'city_id' => $city->id,
        'courier_id' => null,
        'status' => Order::STATUS_ACCEPTED,
    ]);

    $this->actingAs($driver, 'api')
        ->postJson("/api/v1/driver/orders/{$order->id}/accept")
        ->assertStatus(409)
        ->assertJsonPath('succes', false);

    expect($order->fresh()->courier_id)->toBeNull();
});

it('P2a autorise 2 courses actives lorsque le paramètre courses.max_actives vaut 2 et bloque la 3e', function () {
    $city = City::factory()->create();
    app(SettingsService::class)->set('courses.max_actives', 2, $city->id);

    $driver = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    $courier = Courier::factory()->create(['user_id' => $driver->id, 'city_id' => $city->id, 'is_online' => true, 'is_active' => true]);

    Order::factory()->create([
        'city_id' => $city->id,
        'courier_id' => $courier->id,
        'status' => Order::STATUS_PICKED_UP,
    ]);
    $secondOrder = Order::factory()->create([
        'city_id' => $city->id,
        'courier_id' => null,
        'status' => Order::STATUS_ACCEPTED,
    ]);
    $thirdOrder = Order::factory()->create([
        'city_id' => $city->id,
        'courier_id' => null,
        'status' => Order::STATUS_READY,
    ]);

    $token = JWTAuth::fromUser($driver);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/driver/orders/{$secondOrder->id}/accept")
        ->assertOk()
        ->assertJsonPath('succes', true);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/driver/orders/{$thirdOrder->id}/accept")
        ->assertStatus(409)
        ->assertJsonPath('succes', false);

    expect($secondOrder->fresh()->courier_id)->toBe($courier->id)
        ->and($thirdOrder->fresh()->courier_id)->toBeNull();
});
