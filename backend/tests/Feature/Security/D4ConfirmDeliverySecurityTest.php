<?php

use App\Jobs\AlerterAdminCodeBloque;
use App\Models\City;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\User;
use App\Modules\GoShop\Services\OrderService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tymon\JWTAuth\Facades\JWTAuth;

it('D4 nominal: le livreur assigné de la même ville valide une course picked_up/in_transit avec le bon code sans fuite dans les logs', function (string $status) {
    $loggedMessages = [];
    Log::listen(function ($entry) use (&$loggedMessages) {
        $loggedMessages[] = $entry->message.' '.json_encode($entry->context);
    });

    $city = City::factory()->create();
    $driver = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    $courier = Courier::factory()->create(['user_id' => $driver->id, 'city_id' => $city->id]);
    $merchant = Merchant::factory()->create(['city_id' => $city->id]);

    $order = Order::factory()->create([
        'city_id' => $city->id,
        'merchant_id' => $merchant->id,
        'courier_id' => $courier->id,
        'status' => $status,
        'confirmation_code' => Hash::make('7391'),
        'confirmation_attempts' => 0,
        'confirmation_locked' => false,
        'courier_amount' => 900,
        'merchant_amount' => 4100,
    ]);

    $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($driver))
        ->postJson("/api/v1/driver/orders/{$order->id}/confirm-delivery", ['code' => '7391'])
        ->assertOk()
        ->assertJsonPath('succes', true)
        ->assertJsonPath('donnees.status', Order::STATUS_DELIVERED);

    expect($order->fresh()->status)->toBe(Order::STATUS_DELIVERED)
        ->and(implode("\n", $loggedMessages))->not->toContain('7391');
})->with([Order::STATUS_PICKED_UP, Order::STATUS_IN_TRANSIT]);

it('D4 autre ville: refuse 404 sans consommer aucune tentative sur confirmDelivery hors ville', function () {
    $cityDaloa = City::factory()->create(['name' => 'Daloa']);
    $cityBouake = City::factory()->create(['name' => 'Bouaké']);

    $driverDaloa = User::factory()->create(['role' => 'driver', 'city_id' => $cityDaloa->id]);
    $courierDaloa = Courier::factory()->create(['user_id' => $driverDaloa->id, 'city_id' => $cityDaloa->id]);

    $orderBouake = Order::factory()->create([
        'city_id' => $cityBouake->id,
        'courier_id' => $courierDaloa->id,
        'status' => Order::STATUS_PICKED_UP,
        'confirmation_code' => Hash::make('4829'),
        'confirmation_attempts' => 0,
    ]);

    $response = $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($driverDaloa))
        ->postJson("/api/v1/driver/orders/{$orderBouake->id}/confirm-delivery", ['code' => '0000'])
        ->assertStatus(404)
        ->assertJsonPath('succes', false);

    expect($response->json('message'))->not->toContain('Bouaké')
        ->and($orderBouake->fresh()->confirmation_attempts)->toBe(0);
});

it('D4 non livrable + mauvais code (API): confirmation_attempts reste INCHANGÉ pour pending, accepted, ready, delivered, cancelled, refused', function (string $status) {
    $city = City::factory()->create();
    $driver = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    $courier = Courier::factory()->create(['user_id' => $driver->id, 'city_id' => $city->id]);

    $order = Order::factory()->create([
        'city_id' => $city->id,
        'courier_id' => $courier->id,
        'status' => $status,
        'confirmation_code' => Hash::make('4829'),
        'confirmation_attempts' => 1,
        'confirmation_locked' => false,
    ]);

    $response = $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($driver))
        ->postJson("/api/v1/driver/orders/{$order->id}/confirm-delivery", ['code' => '0000'])
        ->assertStatus(422)
        ->assertJsonPath('succes', false);

    expect($order->fresh()->confirmation_attempts)->toBe(1)
        ->and($order->fresh()->confirmation_locked)->toBeFalse()
        ->and($response->getContent())->not->toContain('4829')
        ->and($response->getContent())->not->toContain('0000');
})->with([
    Order::STATUS_PENDING,
    Order::STATUS_ACCEPTED,
    Order::STATUS_READY,
    Order::STATUS_REFUSED,
    Order::STATUS_DELIVERED,
    Order::STATUS_CANCELLED,
]);

it('D4 service direct non livrable + mauvais code: confirmation_attempts reste INCHANGÉ dans OrderService::validerCodeConfirmation', function (string $status) {
    $city = City::factory()->create();
    $driver = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    $courier = Courier::factory()->create(['user_id' => $driver->id, 'city_id' => $city->id]);

    $order = Order::factory()->create([
        'city_id' => $city->id,
        'courier_id' => $courier->id,
        'status' => $status,
        'confirmation_code' => Hash::make('4829'),
        'confirmation_attempts' => 1,
        'confirmation_locked' => false,
    ]);

    $this->actingAs($driver, 'api');
    $orderService = app(OrderService::class);

    expect(fn () => $orderService->validerCodeConfirmation($order, '0000'))
        ->toThrow(ValidationException::class);

    expect($order->fresh()->confirmation_attempts)->toBe(1)
        ->and($order->fresh()->confirmation_locked)->toBeFalse();
})->with([
    Order::STATUS_PENDING,
    Order::STATUS_ACCEPTED,
    Order::STATUS_READY,
    Order::STATUS_REFUSED,
    Order::STATUS_DELIVERED,
    Order::STATUS_CANCELLED,
]);

it('D4 mauvais propriétaire: livreur non assigné refusé avant tout incrément dans OrderService et API', function () {
    $city = City::factory()->create();
    $driverA = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    Courier::factory()->create(['user_id' => $driverA->id, 'city_id' => $city->id]);

    $driverB = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    $courierB = Courier::factory()->create(['user_id' => $driverB->id, 'city_id' => $city->id]);

    $order = Order::factory()->create([
        'city_id' => $city->id,
        'courier_id' => $courierB->id,
        'status' => Order::STATUS_PICKED_UP,
        'confirmation_code' => Hash::make('4829'),
        'confirmation_attempts' => 0,
    ]);

    // Appel API par livreur A -> 403, attempts inchangé
    $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($driverA))
        ->postJson("/api/v1/driver/orders/{$order->id}/confirm-delivery", ['code' => '0000'])
        ->assertStatus(403);
    expect($order->fresh()->confirmation_attempts)->toBe(0);

    // Appel direct OrderService par livreur A connecté -> ValidationException, attempts inchangé
    $this->actingAs($driverA, 'api');
    expect(fn () => app(OrderService::class)->validerCodeConfirmation($order, '0000'))
        ->toThrow(ValidationException::class);
    expect($order->fresh()->confirmation_attempts)->toBe(0);

    // Commande sans livreur -> refusé avant incrément
    $unassignedOrder = Order::factory()->create([
        'city_id' => $city->id,
        'courier_id' => null,
        'status' => Order::STATUS_PICKED_UP,
        'confirmation_code' => Hash::make('4829'),
        'confirmation_attempts' => 0,
    ]);
    expect(fn () => app(OrderService::class)->validerCodeConfirmation($unassignedOrder, '0000'))
        ->toThrow(ValidationException::class);
    expect($unassignedOrder->fresh()->confirmation_attempts)->toBe(0);
});

it('D4 3 mauvais codes + verrouillage puis bon code refusé sans fuite dans logs ni réponses', function () {
    Queue::fake();

    $loggedMessages = [];
    Log::listen(function ($entry) use (&$loggedMessages) {
        $loggedMessages[] = $entry->message.' '.json_encode($entry->context);
    });

    $city = City::factory()->create();
    $driver = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    $courier = Courier::factory()->create(['user_id' => $driver->id, 'city_id' => $city->id]);
    $merchant = Merchant::factory()->create(['city_id' => $city->id]);

    $order = Order::factory()->create([
        'city_id' => $city->id,
        'merchant_id' => $merchant->id,
        'courier_id' => $courier->id,
        'status' => Order::STATUS_PICKED_UP,
        'confirmation_code' => Hash::make('6152'),
        'confirmation_attempts' => 0,
        'confirmation_locked' => false,
    ]);

    $token = JWTAuth::fromUser($driver);

    for ($i = 1; $i <= 3; $i++) {
        $res = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/driver/orders/{$order->id}/confirm-delivery", ['code' => '9999'])
            ->assertStatus(422);

        expect($res->getContent())->not->toContain('6152')
            ->and($res->getContent())->not->toContain('9999');
    }

    $order->refresh();
    expect($order->confirmation_attempts)->toBe(3)
        ->and($order->confirmation_locked)->toBeTrue()
        ->and($order->status)->toBe(Order::STATUS_PICKED_UP);

    Queue::assertPushed(AlerterAdminCodeBloque::class);

    // Commande verrouillée + BON code -> toujours refusée et attempts inchangé
    $lockedRes = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/driver/orders/{$order->id}/confirm-delivery", ['code' => '6152'])
        ->assertStatus(422);

    expect($order->fresh()->confirmation_locked)->toBeTrue()
        ->and($order->fresh()->confirmation_attempts)->toBe(3)
        ->and($order->fresh()->status)->toBe(Order::STATUS_PICKED_UP)
        ->and($lockedRes->getContent())->not->toContain('6152');

    $combinedLogs = implode("\n", $loggedMessages);
    expect($combinedLogs)->not->toContain('6152')
        ->and($combinedLogs)->not->toContain('9999');
});

it('D4 rôle interdit: refuse 403 aux rôles non livreurs', function (string $role) {
    $city = City::factory()->create();
    $user = User::factory()->create(['role' => $role, 'city_id' => $city->id]);
    $order = Order::factory()->create([
        'city_id' => $city->id,
        'status' => Order::STATUS_PICKED_UP,
        'confirmation_attempts' => 0,
    ]);

    $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($user))
        ->postJson("/api/v1/driver/orders/{$order->id}/confirm-delivery", ['code' => '1234'])
        ->assertForbidden();

    expect($order->fresh()->confirmation_attempts)->toBe(0);
})->with(['client', 'merchant', 'city_support', 'super_admin']);
