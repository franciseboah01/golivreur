<?php

use App\Models\City;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tymon\JWTAuth\Facades\JWTAuth;

it('un client peut créer une commande GoShop pour un commerce ouvert', function () {
    $city = City::factory()->create();
    $client = User::factory()->create(['role' => 'client', 'city_id' => $city->id]);
    $merchUsr = User::factory()->create(['role' => 'merchant', 'city_id' => $city->id]);
    $merchant = Merchant::factory()->create([
        'user_id' => $merchUsr->id,
        'city_id' => $city->id,
        'is_open' => true,
        'is_active' => true,
    ]);
    $product = Product::factory()->create([
        'merchant_id' => $merchant->id,
        'price' => 3000,
        'is_available' => true,
    ]);

    $token = JWTAuth::fromUser($client);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/client/orders', [
            'merchant_id' => $merchant->id,
            'items' => [
                ['product_id' => $product->id, 'quantity' => 2],
            ],
            'delivery_address' => 'Quartier Commerce, Daloa',
            'delivery_lat' => 6.8800,
            'delivery_lng' => -6.4510,
            'payment_method' => 'cash',
            'distance_km' => 2.5,
        ]);

    $response->assertCreated()
        ->assertJsonPath('succes', true);

    $orderId = $response->json('donnees.id');
    $this->assertDatabaseHas('orders', [
        'id' => $orderId,
        'client_id' => $client->id,
        'merchant_id' => $merchant->id,
        'products_total' => 6000,
        'status' => Order::STATUS_PENDING,
    ]);
});

it('le commerce peut accepter une commande et passer au statut accepted', function () {
    $city = City::factory()->create();
    $merchUsr = User::factory()->create(['role' => 'merchant', 'city_id' => $city->id]);
    $merchant = Merchant::factory()->create(['user_id' => $merchUsr->id, 'city_id' => $city->id]);
    $order = Order::factory()->create([
        'merchant_id' => $merchant->id,
        'city_id' => $city->id,
        'status' => Order::STATUS_PENDING,
    ]);

    $token = JWTAuth::fromUser($merchUsr);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/merchant/orders/{$order->id}/accept");

    $response->assertOk()
        ->assertJsonPath('succes', true);

    $this->assertDatabaseHas('orders', [
        'id' => $order->id,
        'status' => Order::STATUS_ACCEPTED,
    ]);
});

it('le commerce peut refuser une commande avec motif', function () {
    $city = City::factory()->create();
    $merchUsr = User::factory()->create(['role' => 'merchant', 'city_id' => $city->id]);
    $merchant = Merchant::factory()->create(['user_id' => $merchUsr->id, 'city_id' => $city->id]);
    $order = Order::factory()->create([
        'merchant_id' => $merchant->id,
        'city_id' => $city->id,
        'status' => Order::STATUS_PENDING,
    ]);

    $token = JWTAuth::fromUser($merchUsr);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/merchant/orders/{$order->id}/refuse", [
            'raison' => 'Rupture d\'ingrédients clés',
        ]);

    $response->assertOk()
        ->assertJsonPath('succes', true);

    $this->assertDatabaseHas('orders', [
        'id' => $order->id,
        'status' => Order::STATUS_REFUSED,
        'cancel_reason' => 'Rupture d\'ingrédients clés',
    ]);
});

it('le livreur peut confirmer la livraison avec le code client et créditer les wallets', function () {
    $city = City::factory()->create();
    $merchUsr = User::factory()->create(['role' => 'merchant', 'city_id' => $city->id]);
    $merchant = Merchant::factory()->create(['user_id' => $merchUsr->id, 'city_id' => $city->id]);
    $driver = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    $courier = Courier::factory()->create(['user_id' => $driver->id, 'city_id' => $city->id]);

    $codeClair = '8421';
    $order = Order::factory()->create([
        'merchant_id' => $merchant->id,
        'courier_id' => $courier->id,
        'city_id' => $city->id,
        'status' => 'picked_up',
        'confirmation_code' => Hash::make($codeClair),
        'courier_amount' => 640,
        'merchant_amount' => 4250,
    ]);

    $token = JWTAuth::fromUser($driver);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/driver/orders/{$order->id}/confirm-delivery", [
            'code' => $codeClair,
        ]);

    $response->assertOk()
        ->assertJsonPath('succes', true);

    $this->assertDatabaseHas('orders', [
        'id' => $order->id,
        'status' => Order::STATUS_DELIVERED,
    ]);

    $this->assertDatabaseHas('wallets', [
        'owner_type' => 'courier',
        'owner_id' => $courier->id,
        'balance' => 640,
    ]);

    $this->assertDatabaseHas('wallets', [
        'owner_type' => 'merchant',
        'owner_id' => $merchant->id,
        'balance' => 4250,
    ]);
});
