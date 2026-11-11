<?php

use App\Models\City;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tymon\JWTAuth\Facades\JWTAuth;

it('D2 nominal: un livreur actif et en ligne accepte une course proposée (accepted ou ready) de sa ville', function (string $status) {
    $city = City::factory()->create();
    $driver = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    $courier = Courier::factory()->create([
        'user_id' => $driver->id,
        'city_id' => $city->id,
        'is_online' => true,
        'is_active' => true,
        'consecutive_refusals' => 1,
    ]);
    $merchant = Merchant::factory()->create(['city_id' => $city->id]);

    $order = Order::factory()->create([
        'city_id' => $city->id,
        'merchant_id' => $merchant->id,
        'courier_id' => null,
        'status' => $status,
    ]);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $token = JWTAuth::fromUser($driver);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/driver/orders/{$order->id}/accept")
        ->assertOk()
        ->assertJsonPath('succes', true);

    $queries = collect(DB::getQueryLog())->pluck('query')->implode("\n");
    DB::disableQueryLog();

    expect(strtolower($queries))->toContain('for update')
        ->and(strtolower($queries))->toContain('"courier_id" is null');

    $order->refresh();
    expect($order->courier_id)->toBe($courier->id)
        ->and($order->status)->toBe($status);
})->with([Order::STATUS_ACCEPTED, Order::STATUS_READY]);

it('D2 autre ville: refuse 404 sans fuite si la commande est dans une autre ville', function () {
    $cityDaloa = City::factory()->create(['name' => 'Daloa']);
    $cityBouake = City::factory()->create(['name' => 'Bouaké']);

    $driverDaloa = User::factory()->create(['role' => 'driver', 'city_id' => $cityDaloa->id]);
    Courier::factory()->create(['user_id' => $driverDaloa->id, 'city_id' => $cityDaloa->id, 'is_online' => true, 'is_active' => true]);

    $orderBouake = Order::factory()->create([
        'city_id' => $cityBouake->id,
        'courier_id' => null,
        'status' => Order::STATUS_ACCEPTED,
    ]);

    $token = JWTAuth::fromUser($driverDaloa);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/driver/orders/{$orderBouake->id}/accept")
        ->assertStatus(404)
        ->assertJsonPath('succes', false);

    expect($response->json('message'))->not->toContain('Bouaké')
        ->and($orderBouake->fresh()->courier_id)->toBeNull();
});

it('D2 mauvais statut: refuse 422 si la commande n\'est pas au statut accepted ou ready', function (string $status) {
    $city = City::factory()->create();
    $driver = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    Courier::factory()->create(['user_id' => $driver->id, 'city_id' => $city->id, 'is_online' => true, 'is_active' => true]);

    $order = Order::factory()->create([
        'city_id' => $city->id,
        'courier_id' => null,
        'status' => $status,
    ]);

    $token = JWTAuth::fromUser($driver);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/driver/orders/{$order->id}/accept")
        ->assertStatus(422)
        ->assertJsonPath('succes', false);

    expect($order->fresh()->courier_id)->toBeNull()
        ->and($order->fresh()->status)->toBe($status);
})->with([
    Order::STATUS_PENDING,
    Order::STATUS_REFUSED,
    Order::STATUS_PICKED_UP,
    Order::STATUS_IN_TRANSIT,
    Order::STATUS_DELIVERED,
    Order::STATUS_CANCELLED,
]);

it('D2 disponibilité (hors ligne): refuse 422 si le livreur est hors ligne', function () {
    $city = City::factory()->create();
    $order = Order::factory()->create([
        'city_id' => $city->id,
        'courier_id' => null,
        'status' => Order::STATUS_ACCEPTED,
    ]);

    $offlineUser = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    Courier::factory()->create(['user_id' => $offlineUser->id, 'city_id' => $city->id, 'is_online' => false, 'is_active' => true]);

    $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($offlineUser))
        ->postJson("/api/v1/driver/orders/{$order->id}/accept")
        ->assertStatus(422)
        ->assertJsonPath('succes', false);

    expect($order->fresh()->courier_id)->toBeNull();
});

it('D2 disponibilité (suspendu): refuse 403 si le livreur ou son utilisateur est inactif', function (bool $courierActive, bool $userActive) {
    $city = City::factory()->create();
    $order = Order::factory()->create([
        'city_id' => $city->id,
        'courier_id' => null,
        'status' => Order::STATUS_ACCEPTED,
    ]);

    $suspendedUser = User::factory()->create(['role' => 'driver', 'city_id' => $city->id, 'is_active' => $userActive]);
    Courier::factory()->create(['user_id' => $suspendedUser->id, 'city_id' => $city->id, 'is_online' => true, 'is_active' => $courierActive]);

    $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($suspendedUser))
        ->postJson("/api/v1/driver/orders/{$order->id}/accept")
        ->assertStatus(403)
        ->assertJsonPath('succes', false);

    expect($order->fresh()->courier_id)->toBeNull();
})->with([
    'courier inactif' => [false, true],
    'user inactif' => [true, false],
]);

it('D2 disponibilité (pénalité active): refuse 403 si penalty_until est dans le futur', function () {
    $city = City::factory()->create();
    $order = Order::factory()->create([
        'city_id' => $city->id,
        'courier_id' => null,
        'status' => Order::STATUS_ACCEPTED,
    ]);

    $penalizedUser = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    $courier = Courier::factory()->create([
        'user_id' => $penalizedUser->id,
        'city_id' => $city->id,
        'is_online' => true,
        'is_active' => true,
        'penalty_until' => now()->addMinutes(10),
    ]);

    $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($penalizedUser))
        ->postJson("/api/v1/driver/orders/{$order->id}/accept")
        ->assertStatus(403)
        ->assertJsonPath('succes', false);

    expect($order->fresh()->courier_id)->toBeNull()
        ->and($courier->fresh()->is_online)->toBeFalse();
});

it('D2 disponibilité (3 refus): applique la pénalité 15 minutes et met hors ligne sur acceptOrder', function () {
    $city = City::factory()->create();
    $order = Order::factory()->create([
        'city_id' => $city->id,
        'courier_id' => null,
        'status' => Order::STATUS_ACCEPTED,
    ]);

    $threeRefusalsUser = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    $threeRefusalsCourier = Courier::factory()->create([
        'user_id' => $threeRefusalsUser->id,
        'city_id' => $city->id,
        'is_online' => true,
        'is_active' => true,
        'consecutive_refusals' => 3,
        'penalty_until' => null,
    ]);

    $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($threeRefusalsUser))
        ->postJson("/api/v1/driver/orders/{$order->id}/accept")
        ->assertStatus(403)
        ->assertJsonPath('succes', false);

    $threeRefusalsCourier->refresh();
    expect($order->fresh()->courier_id)->toBeNull()
        ->and($threeRefusalsCourier->is_online)->toBeFalse()
        ->and($threeRefusalsCourier->penalty_until)->not->toBeNull()
        ->and($threeRefusalsCourier->penalty_until->isFuture())->toBeTrue();
});

it('D2 disponibilité (pénalité expirée): efface une pénalité passée et autorise acceptOrder', function () {
    $city = City::factory()->create();
    $order = Order::factory()->create([
        'city_id' => $city->id,
        'courier_id' => null,
        'status' => Order::STATUS_ACCEPTED,
    ]);

    $user = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    $courier = Courier::factory()->create([
        'user_id' => $user->id,
        'city_id' => $city->id,
        'is_online' => true,
        'is_active' => true,
        'consecutive_refusals' => 3,
        'penalty_until' => now()->subMinute(),
    ]);

    $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($user))
        ->postJson("/api/v1/driver/orders/{$order->id}/accept")
        ->assertOk()
        ->assertJsonPath('succes', true);

    expect($order->fresh()->courier_id)->toBe($courier->id)
        ->and($courier->fresh()->consecutive_refusals)->toBe(0)
        ->and($courier->fresh()->penalty_until)->toBeNull();
});

it('D2 concurrence et propriétaire: deux livreurs acceptent la même commande -> un seul succès (200) et l\'autre 409', function () {
    $city = City::factory()->create();

    $driverA = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    $courierA = Courier::factory()->create(['user_id' => $driverA->id, 'city_id' => $city->id, 'is_online' => true, 'is_active' => true]);

    $driverB = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    Courier::factory()->create(['user_id' => $driverB->id, 'city_id' => $city->id, 'is_online' => true, 'is_active' => true]);

    $order = Order::factory()->create([
        'city_id' => $city->id,
        'courier_id' => null,
        'status' => Order::STATUS_ACCEPTED,
    ]);

    $resA = $this->actingAs($driverA, 'api')
        ->postJson("/api/v1/driver/orders/{$order->id}/accept");

    $resB = $this->actingAs($driverB, 'api')
        ->postJson("/api/v1/driver/orders/{$order->id}/accept");

    // Driver A déjà assigné qui réessaie reçoit 409 également
    $resARepeat = $this->actingAs($driverA, 'api')
        ->postJson("/api/v1/driver/orders/{$order->id}/accept");

    expect($resA->getStatusCode())->toBe(200)
        ->and($resB->getStatusCode())->toBe(409)
        ->and($resB->json('succes'))->toBeFalse()
        ->and($resB->json('message'))->not->toBeEmpty()
        ->and($resARepeat->getStatusCode())->toBe(409)
        ->and($order->fresh()->courier_id)->toBe($courierA->id);
});

it('D2 atomicité conditionnelle: si une mise à jour concurrente assigne courier_id avant l\'update conditionnel, retourne 409', function () {
    $city = City::factory()->create();

    $driverA = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    $courierA = Courier::factory()->create(['user_id' => $driverA->id, 'city_id' => $city->id, 'is_online' => true, 'is_active' => true]);

    $driverB = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    Courier::factory()->create(['user_id' => $driverB->id, 'city_id' => $city->id, 'is_online' => true, 'is_active' => true]);

    $order = Order::factory()->create([
        'city_id' => $city->id,
        'courier_id' => null,
        'status' => Order::STATUS_ACCEPTED,
    ]);

    $injected = false;
    DB::listen(function ($query) use (&$injected, $order, $courierA) {
        if (! $injected && str_contains(strtolower($query->sql), 'for update') && str_contains(strtolower($query->sql), '"orders"')) {
            $injected = true;
            DB::table('orders')->where('id', $order->id)->update(['courier_id' => $courierA->id]);
        }
    });

    $resB = $this->actingAs($driverB, 'api')
        ->postJson("/api/v1/driver/orders/{$order->id}/accept")
        ->assertStatus(409)
        ->assertJsonPath('succes', false);

    expect($order->fresh()->courier_id)->toBe($courierA->id);
});

it('D2 rôle interdit: refuse 403 aux rôles non livreurs', function (string $role) {
    $city = City::factory()->create();
    $user = User::factory()->create(['role' => $role, 'city_id' => $city->id]);
    $order = Order::factory()->create(['city_id' => $city->id, 'status' => Order::STATUS_ACCEPTED]);

    $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($user))
        ->postJson("/api/v1/driver/orders/{$order->id}/accept")
        ->assertForbidden();

    expect($order->fresh()->courier_id)->toBeNull();
})->with(['client', 'merchant', 'city_support', 'super_admin']);
