<?php

use App\Models\City;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\User;
use App\Modules\GoShop\Services\OrderService;
use Illuminate\Validation\ValidationException;
use Tymon\JWTAuth\Facades\JWTAuth;

it('D3 nominal: le livreur assigné de la même ville confirme le ramassage depuis accepted ou ready -> picked_up', function (string $status) {
    $city = City::factory()->create();
    $driver = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    $courier = Courier::factory()->create(['user_id' => $driver->id, 'city_id' => $city->id]);
    $merchant = Merchant::factory()->create(['city_id' => $city->id]);

    $order = Order::factory()->create([
        'city_id' => $city->id,
        'merchant_id' => $merchant->id,
        'courier_id' => $courier->id,
        'status' => $status,
        'picked_up_at' => null,
    ]);

    $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($driver))
        ->postJson("/api/v1/driver/orders/{$order->id}/confirm-pickup")
        ->assertOk()
        ->assertJsonPath('succes', true)
        ->assertJsonPath('donnees.status', Order::STATUS_PICKED_UP);

    $order->refresh();
    expect($order->status)->toBe(Order::STATUS_PICKED_UP)
        ->and($order->picked_up_at)->not->toBeNull();
})->with([Order::STATUS_ACCEPTED, Order::STATUS_READY]);

it('D3 autre ville: refuse 404 sans fuite si la commande est dans une autre ville même avec incohérence', function () {
    $cityDaloa = City::factory()->create(['name' => 'Daloa']);
    $cityBouake = City::factory()->create(['name' => 'Bouaké']);

    $driverDaloa = User::factory()->create(['role' => 'driver', 'city_id' => $cityDaloa->id]);
    $courierDaloa = Courier::factory()->create(['user_id' => $driverDaloa->id, 'city_id' => $cityDaloa->id]);

    // Même si courier_id pointait par anomalie sur ce livreur, city_id différent => 404 sans fuite
    $orderBouake = Order::factory()->create([
        'city_id' => $cityBouake->id,
        'courier_id' => $courierDaloa->id,
        'status' => Order::STATUS_READY,
    ]);

    $response = $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($driverDaloa))
        ->postJson("/api/v1/driver/orders/{$orderBouake->id}/confirm-pickup")
        ->assertStatus(404)
        ->assertJsonPath('succes', false);

    expect($response->json('message'))->not->toContain('Bouaké')
        ->and($orderBouake->fresh()->status)->toBe(Order::STATUS_READY);
});

it('D3 mauvais propriétaire: refuse 403 si la commande est non assignée ou assignée à un autre livreur', function (bool $assignedToOther) {
    $city = City::factory()->create();

    $driverA = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    Courier::factory()->create(['user_id' => $driverA->id, 'city_id' => $city->id]);

    $otherCourierId = null;
    if ($assignedToOther) {
        $driverB = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
        $otherCourierId = Courier::factory()->create(['user_id' => $driverB->id, 'city_id' => $city->id])->id;
    }

    $order = Order::factory()->create([
        'city_id' => $city->id,
        'courier_id' => $otherCourierId,
        'status' => Order::STATUS_READY,
    ]);

    $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($driverA))
        ->postJson("/api/v1/driver/orders/{$order->id}/confirm-pickup")
        ->assertStatus(403)
        ->assertJsonPath('succes', false);

    expect($order->fresh()->status)->toBe(Order::STATUS_READY);
})->with([
    'non assignée' => [false],
    'autre livreur' => [true],
]);

it('D3 mauvais statut: refuse 422 pour tout statut hors accepted/ready', function (string $status) {
    $city = City::factory()->create();
    $driver = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    $courier = Courier::factory()->create(['user_id' => $driver->id, 'city_id' => $city->id]);

    $order = Order::factory()->create([
        'city_id' => $city->id,
        'courier_id' => $courier->id,
        'status' => $status,
    ]);

    $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($driver))
        ->postJson("/api/v1/driver/orders/{$order->id}/confirm-pickup")
        ->assertStatus(422)
        ->assertJsonPath('succes', false);

    expect($order->fresh()->status)->toBe($status);
})->with([
    Order::STATUS_PENDING,
    Order::STATUS_REFUSED,
    Order::STATUS_PICKED_UP,
    Order::STATUS_IN_TRANSIT,
    Order::STATUS_DELIVERED,
    Order::STATUS_CANCELLED,
]);

it('D3 rôle interdit: refuse 403 aux rôles non livreurs', function (string $role) {
    $city = City::factory()->create();
    $user = User::factory()->create(['role' => $role, 'city_id' => $city->id]);
    $order = Order::factory()->create(['city_id' => $city->id, 'status' => Order::STATUS_READY]);

    $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($user))
        ->postJson("/api/v1/driver/orders/{$order->id}/confirm-pickup")
        ->assertForbidden();

    expect($order->fresh()->status)->toBe(Order::STATUS_READY);
})->with(['client', 'merchant', 'city_support', 'super_admin']);

it('D3 graphe CDC: OrderService respecte strictement les transitions d\'états autorisées', function () {
    $city = City::factory()->create();
    $merchant = Merchant::factory()->create(['city_id' => $city->id]);
    $orderService = app(OrderService::class);

    expect(Order::TRANSITIONS_AUTORISEES)->toHaveKeys([
        Order::STATUS_PENDING,
        Order::STATUS_ACCEPTED,
        Order::STATUS_READY,
        Order::STATUS_PICKED_UP,
        Order::STATUS_IN_TRANSIT,
        Order::STATUS_REFUSED,
        Order::STATUS_DELIVERED,
        Order::STATUS_CANCELLED,
    ]);

    // accepterCommande: refusé si pas pending (ex: ready, delivered, cancelled, refused)
    foreach ([Order::STATUS_ACCEPTED, Order::STATUS_READY, Order::STATUS_PICKED_UP, Order::STATUS_DELIVERED, Order::STATUS_CANCELLED, Order::STATUS_REFUSED] as $badStatus) {
        $order = Order::factory()->create(['city_id' => $city->id, 'merchant_id' => $merchant->id, 'status' => $badStatus]);
        expect(fn () => $orderService->accepterCommande($order, $merchant))->toThrow(ValidationException::class);
    }

    // accepterCommande: refusé si autre commerce
    $otherMerchant = Merchant::factory()->create(['city_id' => $city->id]);
    $pendingOther = Order::factory()->create(['city_id' => $city->id, 'merchant_id' => $otherMerchant->id, 'status' => Order::STATUS_PENDING]);
    expect(fn () => $orderService->accepterCommande($pendingOther, $merchant))->toThrow(ValidationException::class);

    // refuserCommande: refusé si pas pending
    foreach ([Order::STATUS_ACCEPTED, Order::STATUS_READY, Order::STATUS_DELIVERED, Order::STATUS_CANCELLED] as $badStatus) {
        $order = Order::factory()->create(['city_id' => $city->id, 'merchant_id' => $merchant->id, 'status' => $badStatus]);
        expect(fn () => $orderService->refuserCommande($order, 'test'))->toThrow(ValidationException::class);
    }

    // signalerCommandePrete: refusé si pas accepted
    foreach ([Order::STATUS_PENDING, Order::STATUS_READY, Order::STATUS_PICKED_UP, Order::STATUS_DELIVERED, Order::STATUS_CANCELLED, Order::STATUS_REFUSED] as $badStatus) {
        $order = Order::factory()->create(['city_id' => $city->id, 'merchant_id' => $merchant->id, 'status' => $badStatus]);
        expect(fn () => $orderService->signalerCommandePrete($order))->toThrow(ValidationException::class);
    }
});
