<?php

use App\Models\City;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Tymon\JWTAuth\Facades\JWTAuth;

it('P2d autorise uniquement GET profile et GET status pour un commerce suspendu (is_active=false) et renvoie 403 sur toutes les autres routes merchant/*', function () {
    $city = City::factory()->create();
    $merchantUser = User::factory()->create(['role' => 'merchant', 'city_id' => $city->id]);
    $merchant = Merchant::factory()->create([
        'user_id' => $merchantUser->id,
        'city_id' => $city->id,
        'is_active' => false,
        'is_open' => true,
    ]);

    $pendingOrder = Order::factory()->create(['city_id' => $city->id, 'merchant_id' => $merchant->id, 'status' => Order::STATUS_PENDING]);
    $acceptedOrder = Order::factory()->create(['city_id' => $city->id, 'merchant_id' => $merchant->id, 'status' => Order::STATUS_ACCEPTED]);
    $product = Product::factory()->create(['merchant_id' => $merchant->id, 'price' => 2000]);

    $token = JWTAuth::fromUser($merchantUser);
    $headers = ['Authorization' => "Bearer {$token}"];

    // Seuls profile et status en lecture restent accessibles (200)
    $this->withHeaders($headers)->getJson('/api/v1/merchant/profile')->assertOk()->assertJsonPath('succes', true);
    $this->withHeaders($headers)->getJson('/api/v1/business/profile')->assertOk()->assertJsonPath('succes', true);
    $this->withHeaders($headers)->getJson('/api/v1/merchant/status')->assertOk()->assertJsonPath('donnees.is_active', false);

    // Toutes les autres routes merchant/* et écritures renvoient 403 avec message français
    $blockedCalls = [
        ['PUT', '/api/v1/merchant/profile', ['business_name' => 'Modif']],
        ['POST', '/api/v1/merchant/profile', ['business_name' => 'Modif']],
        ['PUT', '/api/v1/business/profile', ['business_name' => 'Modif']],
        ['PUT', "/api/v1/profile/{$merchantUser->id}", ['business_name' => 'Modif']],
        ['POST', '/api/v1/merchant/status', ['is_open' => false]],
        ['GET', '/api/v1/merchant/orders', []],
        ['POST', "/api/v1/merchant/orders/{$pendingOrder->id}/accept", []],
        ['POST', "/api/v1/merchant/orders/{$pendingOrder->id}/refuse", ['raison' => 'test']],
        ['POST', "/api/v1/merchant/orders/{$acceptedOrder->id}/ready", []],
        ['GET', '/api/v1/merchant/products', []],
        ['POST', '/api/v1/merchant/products', ['name' => 'Nouveau', 'price' => 1500]],
        ['PUT', "/api/v1/merchant/products/{$product->id}", ['price' => 1800]],
        ['DELETE', "/api/v1/merchant/products/{$product->id}", []],
    ];

    foreach ($blockedCalls as [$method, $uri, $payload]) {
        $res = $this->withHeaders($headers)->json($method, $uri, $payload);
        $res->assertStatus(403)
            ->assertJsonPath('succes', false);
        expect($res->json('message'))->not->toBeEmpty();
    }

    expect($pendingOrder->fresh()->status)->toBe(Order::STATUS_PENDING)
        ->and($acceptedOrder->fresh()->status)->toBe(Order::STATUS_ACCEPTED)
        ->and($merchant->fresh()->is_open)->toBeTrue();
});
