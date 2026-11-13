<?php

use App\Models\City;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\User;
use Tymon\JWTAuth\Facades\JWTAuth;

it('S5 refuse 404 le catalogue et l\'ajout en favori d\'un commerce inactif', function () {
    $city = City::factory()->create();
    $client = User::factory()->create(['role' => 'client', 'city_id' => $city->id]);
    $inactiveMerchant = Merchant::factory()->create([
        'city_id' => $city->id,
        'is_active' => false,
        'is_open' => true,
        'name' => 'Commerce Suspendu',
    ]);

    $this->getJson("/api/v1/client/merchants/{$inactiveMerchant->id}/catalog")
        ->assertStatus(404)
        ->assertJsonPath('succes', false);

    $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($client))
        ->postJson("/api/v1/client/favorites/{$inactiveMerchant->id}/toggle")
        ->assertStatus(404)
        ->assertJsonPath('succes', false);
});

it('S5 commande GoShop: refuse 422 un produit indisponible ou d\'un autre commerce et aligne city_id sur le commerce', function () {
    $cityDaloa = City::factory()->create(['name' => 'Daloa']);
    $cityBouake = City::factory()->create(['name' => 'Bouaké']);

    $client = User::factory()->create(['role' => 'client', 'city_id' => $cityBouake->id]);

    $merchantA = Merchant::factory()->create(['city_id' => $cityDaloa->id, 'is_active' => true, 'is_open' => true]);
    $merchantB = Merchant::factory()->create(['city_id' => $cityDaloa->id, 'is_active' => true, 'is_open' => true]);

    $unavailableProduct = Product::factory()->create([
        'merchant_id' => $merchantA->id,
        'price' => 2000,
        'is_available' => false,
    ]);
    $otherProduct = Product::factory()->create([
        'merchant_id' => $merchantB->id,
        'price' => 1500,
        'is_available' => true,
    ]);
    $validProduct = Product::factory()->create([
        'merchant_id' => $merchantA->id,
        'price' => 3000,
        'is_available' => true,
    ]);

    $token = JWTAuth::fromUser($client);

    // Produit indisponible -> 422
    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/client/orders', [
            'merchant_id' => $merchantA->id,
            'items' => [['product_id' => $unavailableProduct->id, 'quantity' => 1]],
            'delivery_address' => 'Quartier Commerce',
            'delivery_lat' => 6.8800,
            'delivery_lng' => -6.4510,
            'payment_method' => 'cash',
            'distance_km' => 2.0,
        ])
        ->assertStatus(422);

    // Produit d'un autre commerce -> 422 (au lieu d'un 404/crash interne non contrôlé)
    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/client/orders', [
            'merchant_id' => $merchantA->id,
            'items' => [['product_id' => $otherProduct->id, 'quantity' => 1]],
            'delivery_address' => 'Quartier Commerce',
            'delivery_lat' => 6.8800,
            'delivery_lng' => -6.4510,
            'payment_method' => 'cash',
            'distance_km' => 2.0,
        ])
        ->assertStatus(422);

    // Commande valide -> city_id alignée sur la ville du commerce ($cityDaloa->id)
    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/client/orders', [
            'merchant_id' => $merchantA->id,
            'items' => [['product_id' => $validProduct->id, 'quantity' => 1]],
            'delivery_address' => 'Quartier Commerce',
            'delivery_lat' => 6.8800,
            'delivery_lng' => -6.4510,
            'payment_method' => 'cash',
            'distance_km' => 2.0,
        ])
        ->assertCreated()
        ->assertJsonPath('donnees.city_id', $cityDaloa->id);
});
