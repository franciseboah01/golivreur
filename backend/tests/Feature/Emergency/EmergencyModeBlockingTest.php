<?php

use App\Models\City;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tymon\JWTAuth\Facades\JWTAuth;

it('bloque la création de commande GoShop et expédition GoColis si la ville est en mode urgence', function () {
    $city = City::factory()->create([
        'name' => 'Daloa Urgence '.uniqid(),
        'emergency_mode_active' => true,
        'emergency_mode_message' => 'Arrêt d\'urgence suite à de violentes inondations.',
    ]);

    $client = User::factory()->create([
        'role' => 'client',
        'city_id' => $city->id,
    ]);

    $merchantUser = User::factory()->create([
        'role' => 'merchant',
        'city_id' => $city->id,
    ]);

    $merchant = Merchant::factory()->create([
        'user_id' => $merchantUser->id,
        'city_id' => $city->id,
        'is_open' => true,
        'is_active' => true,
    ]);

    $product = Product::factory()->create([
        'merchant_id' => $merchant->id,
        'price' => 1500,
        'is_available' => true,
    ]);

    $token = JWTAuth::fromUser($client);

    // 1. Tentative de création de commande GoShop sous mode urgence (bloquée)
    $responseOrder = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/client/orders', [
            'merchant_id' => $merchant->id,
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1],
            ],
            'delivery_address' => 'Quartier Commerce',
            'delivery_lat' => 6.8800,
            'delivery_lng' => -6.4510,
            'payment_method' => 'cash',
            'distance_km' => 2.5,
        ]);

    $responseOrder->assertStatus(503)
        ->assertJsonPath('succes', false)
        ->assertJsonPath('message', 'Arrêt d\'urgence suite à de violentes inondations.');

    // 2. Tentative de création de colis GoColis sous mode urgence (bloquée en 503)
    $responseParcel = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/client/parcels', [
            'recipient_name' => 'Kouamé Jean',
            'recipient_phone' => '0701020304',
            'pickup_address' => 'Gare de Daloa',
            'delivery_address' => 'Marché de Daloa',
            'delivery_lat' => 6.8800,
            'delivery_lng' => -6.4510,
            'pickup_lat' => 6.8770,
            'pickup_lng' => -6.4502,
            'size' => 'petit',
            'weight_kg' => 0.5,
            'payment_method' => 'cash',
            'distance_km' => 3.0,
        ]);

    $responseParcel->assertStatus(503)
        ->assertJsonPath('succes', false)
        ->assertJsonPath('message', 'Arrêt d\'urgence suite à de violentes inondations.');

    // 2b. Une course deja en cours n'est pas bloquee par le mode urgence
    $driverUser = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    $courier = Courier::factory()->create([
        'user_id' => $driverUser->id,
        'city_id' => $city->id,
        'is_online' => true,
        'is_active' => true,
    ]);
    $inProgressOrder = Order::factory()->create([
        'city_id' => $city->id,
        'client_id' => $client->id,
        'merchant_id' => $merchant->id,
        'courier_id' => $courier->id,
        'status' => Order::STATUS_READY,
        'confirmation_code' => Hash::make('4821'),
    ]);

    $this->actingAs($driverUser, 'api')
        ->postJson("/api/v1/driver/orders/{$inProgressOrder->id}/confirm-pickup")
        ->assertOk()
        ->assertJsonPath('donnees.status', Order::STATUS_PICKED_UP);

    $this->actingAs($driverUser, 'api')
        ->postJson("/api/v1/driver/orders/{$inProgressOrder->id}/confirm-delivery", ['code' => '4821'])
        ->assertOk()
        ->assertJsonPath('donnees.status', Order::STATUS_DELIVERED);

    // 3. Levée du mode urgence -> les commandes et expéditions redeviennent autorisées
    $city->update([
        'emergency_mode_active' => false,
        'emergency_mode_message' => null,
    ]);

    $responseOrderSuccess = $this->actingAs($client, 'api')
        ->postJson('/api/v1/client/orders', [
            'merchant_id' => $merchant->id,
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1],
            ],
            'delivery_address' => 'Quartier Commerce',
            'delivery_lat' => 6.8800,
            'delivery_lng' => -6.4510,
            'payment_method' => 'cash',
            'distance_km' => 2.5,
        ]);

    $responseOrderSuccess->assertStatus(201);

    $responseParcelSuccess = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/client/parcels', [
            'recipient_name' => 'Kouamé Jean',
            'recipient_phone' => '0701020304',
            'pickup_address' => 'Gare de Daloa',
            'delivery_address' => 'Marché de Daloa',
            'delivery_lat' => 6.8800,
            'delivery_lng' => -6.4510,
            'pickup_lat' => 6.8770,
            'pickup_lng' => -6.4502,
            'size' => 'petit',
            'weight_kg' => 0.5,
            'payment_method' => 'cash',
            'distance_km' => 3.0,
        ]);

    $responseParcelSuccess->assertStatus(201);
});
