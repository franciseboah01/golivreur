<?php

use App\Models\City;
use App\Models\Courier;
use App\Models\Order;
use App\Models\Parcel;
use App\Models\User;
use Tymon\JWTAuth\Facades\JWTAuth;

it('S4 refuse de noter une commande non livrée ou avec un mauvais livreur et bloque les doublons', function () {
    $city = City::factory()->create();
    $client = User::factory()->create(['role' => 'client', 'city_id' => $city->id]);

    $driverA = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    $courierA = Courier::factory()->create(['user_id' => $driverA->id, 'city_id' => $city->id, 'average_rating' => 5.0, 'total_ratings' => 1]);

    $driverB = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    $courierB = Courier::factory()->create(['user_id' => $driverB->id, 'city_id' => $city->id, 'average_rating' => 4.0, 'total_ratings' => 1]);

    // 1. Commande non livrée (pending / picked_up / cancelled) -> refus 422
    $pendingOrder = Order::factory()->create([
        'city_id' => $city->id,
        'client_id' => $client->id,
        'courier_id' => $courierA->id,
        'status' => Order::STATUS_PICKED_UP,
    ]);

    $token = JWTAuth::fromUser($client);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/client/ratings', [
            'courier_id' => $courierA->id,
            'order_id' => $pendingOrder->id,
            'rating' => 1,
        ])
        ->assertStatus(422);

    expect($courierA->fresh()->total_ratings)->toBe(1);

    // 2. Commande livrée par courierA mais tentative de noter courierB -> refus 422
    $deliveredOrder = Order::factory()->create([
        'city_id' => $city->id,
        'client_id' => $client->id,
        'courier_id' => $courierA->id,
        'status' => Order::STATUS_DELIVERED,
    ]);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/client/ratings', [
            'courier_id' => $courierB->id,
            'order_id' => $deliveredOrder->id,
            'rating' => 1,
        ])
        ->assertStatus(422);

    expect($courierB->fresh()->total_ratings)->toBe(1);

    // 3. Double notation -> première 201, deuxième 409 (sans erreur 500)
    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/client/ratings', [
            'courier_id' => $courierA->id,
            'order_id' => $deliveredOrder->id,
            'rating' => 3,
        ])
        ->assertCreated();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/client/ratings', [
            'courier_id' => $courierA->id,
            'order_id' => $deliveredOrder->id,
            'rating' => 1,
        ])
        ->assertStatus(409);

    expect($courierA->fresh()->total_ratings)->toBe(2);
});

it('S4 refuse de noter un colis d\'un autre expéditeur, non livré ou avec mauvais livreur', function () {
    $city = City::factory()->create();
    $sender = User::factory()->create(['role' => 'client', 'city_id' => $city->id]);
    $otherClient = User::factory()->create(['role' => 'client', 'city_id' => $city->id]);

    $driver = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    $courier = Courier::factory()->create(['user_id' => $driver->id, 'city_id' => $city->id, 'average_rating' => 4.0, 'total_ratings' => 1]);

    $parcel = Parcel::factory()->create([
        'city_id' => $city->id,
        'sender_id' => $sender->id,
        'courier_id' => $courier->id,
        'status' => Parcel::STATUS_IN_TRANSIT,
    ]);

    // Autre client -> 403
    $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($otherClient))
        ->postJson('/api/v1/client/ratings', [
            'courier_id' => $courier->id,
            'parcel_id' => $parcel->id,
            'rating' => 5,
        ])
        ->assertStatus(403);

    // Non livré -> 422
    $this->actingAs($sender, 'api')
        ->postJson('/api/v1/client/ratings', [
            'courier_id' => $courier->id,
            'parcel_id' => $parcel->id,
            'rating' => 2,
        ])
        ->assertStatus(422);

    expect($courier->fresh()->total_ratings)->toBe(1);
});
