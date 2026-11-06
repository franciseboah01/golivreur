<?php

use App\Models\{City, Courier, Order, Parcel, Rating, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tymon\JWTAuth\Facades\JWTAuth;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->city = City::factory()->create(['name' => 'Daloa']);

    $this->client = User::factory()->create([
        'city_id' => $this->city->id,
        'role'    => 'client',
        'phone'   => '0700000001',
    ]);

    $this->courierUser = User::factory()->create([
        'city_id' => $this->city->id,
        'role'    => 'driver',
        'phone'   => '0700000002',
    ]);

    $this->courier = Courier::factory()->create([
        'user_id'        => $this->courierUser->id,
        'city_id'        => $this->city->id,
        'average_rating' => 4.0,
        'total_ratings'  => 2,
    ]);
});

test('un client peut noter un livreur post-livraison avec recalcul automatique de la note', function () {
    $order = Order::factory()->create([
        'client_id'  => $this->client->id,
        'courier_id' => $this->courier->id,
        'city_id'    => $this->city->id,
        'status'     => Order::STATUS_DELIVERED,
    ]);

    $token = JWTAuth::fromUser($this->client);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/client/ratings', [
            'courier_id' => $this->courier->id,
            'order_id'   => $order->id,
            'rating'     => 5,
            'comment'    => 'Livreur ponctuel et très courtois !',
        ]);

    $response->assertStatus(201)
        ->assertJson([
            'succes' => true,
        ]);

    $this->assertDatabaseHas('ratings', [
        'rater_id'    => $this->client->id,
        'rateable_id' => $this->courier->id,
        'rating'      => 5,
        'comment'     => 'Livreur ponctuel et très courtois !',
    ]);

    // Ancien : (4.0 * 2) = 8. Nouveau : (8 + 5) / 3 = 4.33
    expect($this->courier->fresh()->total_ratings)->toBe(3);
    expect((float) $this->courier->fresh()->average_rating)->toBe(4.33);
});

test('un client ne peut pas noter une commande qui ne lui appartient pas', function () {
    $autreClient = User::factory()->create([
        'city_id' => $this->city->id,
        'role'    => 'client',
    ]);

    $order = Order::factory()->create([
        'client_id'  => $autreClient->id,
        'courier_id' => $this->courier->id,
        'city_id'    => $this->city->id,
    ]);

    $token = JWTAuth::fromUser($this->client);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/client/ratings', [
            'courier_id' => $this->courier->id,
            'order_id'   => $order->id,
            'rating'     => 5,
        ]);

    $response->assertStatus(403);
});

test('un admin habilité peut valider le remboursement de l\'assurance d\'un colis', function () {
    $cityManager = User::factory()->create([
        'city_id' => $this->city->id,
        'role'    => 'city_manager',
    ]);

    $parcel = Parcel::factory()->create([
        'sender_id'       => $this->client->id,
        'courier_id'      => $this->courier->id,
        'city_id'         => $this->city->id,
        'declared_value'    => 35000,
        'has_insurance'     => true,
        'insurance_amount'  => 1050,
        'status'            => Parcel::STATUS_IN_TRANSIT,
    ]);

    $token = JWTAuth::fromUser($cityManager);

    $response = $this->withHeaders([
        'Authorization' => "Bearer {$token}",
        'X-City-ID'     => $this->city->id,
    ])->postJson("/api/v1/admin/parcels/{$parcel->id}/refund", [
        'montant'    => 35000,
        'raison'     => 'Colis égaré durant le trajet - couverture assurance activée',
        'owner_type' => 'courier',
        'owner_id'   => $this->courier->id,
    ]);

    $response->assertStatus(200)
        ->assertJson([
            'succes' => true,
        ]);

    expect($parcel->fresh()->status)->toBe('refunded');
});
