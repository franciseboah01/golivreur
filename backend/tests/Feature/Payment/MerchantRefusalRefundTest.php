<?php

use App\Models\City;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Refund;
use App\Models\User;
use Tymon\JWTAuth\Facades\JWTAuth;

it('P5 Point 7: refus commerce d\'une commande déjà payée crée automatiquement un remboursement requested avec cost_bearer=platform', function () {
    $city = City::factory()->create(['name' => 'Daloa']);
    $client = User::factory()->create(['role' => 'client', 'city_id' => $city->id]);
    $merchantUser = User::factory()->create(['role' => 'merchant', 'city_id' => $city->id]);
    $merchant = Merchant::factory()->create([
        'user_id' => $merchantUser->id,
        'city_id' => $city->id,
        'is_active' => true,
        'is_open' => true,
    ]);

    $order = Order::factory()->create([
        'client_id' => $client->id,
        'merchant_id' => $merchant->id,
        'city_id' => $city->id,
        'status' => Order::STATUS_PENDING,
        'products_total' => 5000,
        'delivery_fee' => 800,
        'payment_method' => 'orange_money',
        'payment_status' => 'paid',
    ]);

    $token = JWTAuth::fromUser($merchantUser);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/merchant/orders/{$order->id}/refuse", [
            'raison' => 'Rupture de stock poisson',
        ])
        ->assertOk()
        ->assertJsonPath('succes', true);

    expect($order->fresh()->status)->toBe(Order::STATUS_REFUSED);

    $refund = Refund::where('order_id', $order->id)->first();
    expect($refund)->not->toBeNull()
        ->and($refund->city_id)->toBe($city->id)
        ->and($refund->client_id)->toBe($client->id)
        ->and((int) $refund->amount)->toBe(5800)
        ->and($refund->status)->toBe(Refund::STATUS_REQUESTED)
        ->and($refund->cost_bearer)->toBe(Refund::COST_PLATFORM)
        ->and($refund->requested_by)->toBe($merchantUser->id);

    $this->assertDatabaseHas('activity_logs', [
        'action' => 'refund_requested_auto_merchant_refusal',
        'entity_type' => 'refund',
        'entity_id' => $refund->id,
    ]);
});

it('P5 Point 7: refus commerce d\'une commande cash non payée ne crée aucun remboursement (inerte tant que cash seul actif)', function () {
    $city = City::factory()->create(['name' => 'Daloa']);
    $client = User::factory()->create(['role' => 'client', 'city_id' => $city->id]);
    $merchantUser = User::factory()->create(['role' => 'merchant', 'city_id' => $city->id]);
    $merchant = Merchant::factory()->create([
        'user_id' => $merchantUser->id,
        'city_id' => $city->id,
        'is_active' => true,
        'is_open' => true,
    ]);

    $cashOrder = Order::factory()->cash()->create([
        'client_id' => $client->id,
        'merchant_id' => $merchant->id,
        'city_id' => $city->id,
        'status' => Order::STATUS_PENDING,
        'products_total' => 3000,
        'delivery_fee' => 500,
    ]);

    $token = JWTAuth::fromUser($merchantUser);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/merchant/orders/{$cashOrder->id}/refuse", [
            'raison' => 'Cuisine fermée',
        ])
        ->assertOk();

    expect($cashOrder->fresh()->status)->toBe(Order::STATUS_REFUSED)
        ->and(Refund::where('order_id', $cashOrder->id)->exists())->toBeFalse();
});
