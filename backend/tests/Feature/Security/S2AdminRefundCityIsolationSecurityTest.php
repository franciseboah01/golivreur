<?php

use App\Models\City;
use App\Models\Courier;
use App\Models\Order;
use App\Models\Parcel;
use App\Models\User;
use Tymon\JWTAuth\Facades\JWTAuth;

it('S2 autre ville (order refund): refuse 404 sans fuite si un rôle local tente de rembourser une commande d\'une autre ville', function (string $role) {
    $cityDaloa = City::factory()->create(['name' => 'Daloa']);
    $cityBouake = City::factory()->create(['name' => 'Bouaké']);

    $adminDaloa = User::factory()->create(['role' => $role, 'city_id' => $cityDaloa->id]);
    $driverBouake = User::factory()->create(['role' => 'driver', 'city_id' => $cityBouake->id]);
    $courierBouake = Courier::factory()->create(['user_id' => $driverBouake->id, 'city_id' => $cityBouake->id]);

    $orderBouake = Order::factory()->create([
        'city_id' => $cityBouake->id,
        'courier_id' => $courierBouake->id,
    ]);

    $response = $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($adminDaloa))
        ->postJson("/api/v1/admin/orders/{$orderBouake->id}/refund", [
            'montant' => 1500,
            'raison' => 'Test inter-ville',
            'owner_type' => 'courier',
            'owner_id' => $courierBouake->id,
        ])
        ->assertStatus(404)
        ->assertJsonPath('succes', false);

    expect($response->json('message'))->not->toContain('Bouaké');
    $this->assertDatabaseMissing('wallet_transactions', [
        'type' => 'refund',
        'processed_by' => $adminDaloa->id,
    ]);
})->with(['city_support', 'city_finance', 'city_manager']);

it('S2 autre ville (parcel refund): refuse 404 sans fuite si un rôle local tente d\'indemniser un colis d\'une autre ville', function (string $role) {
    $cityDaloa = City::factory()->create(['name' => 'Daloa']);
    $cityBouake = City::factory()->create(['name' => 'Bouaké']);

    $adminDaloa = User::factory()->create(['role' => $role, 'city_id' => $cityDaloa->id]);
    $driverBouake = User::factory()->create(['role' => 'driver', 'city_id' => $cityBouake->id]);
    $courierBouake = Courier::factory()->create(['user_id' => $driverBouake->id, 'city_id' => $cityBouake->id]);

    $parcelBouake = Parcel::factory()->create([
        'city_id' => $cityBouake->id,
        'courier_id' => $courierBouake->id,
        'declared_value' => 2000,
        'status' => Parcel::STATUS_IN_TRANSIT,
    ]);

    $response = $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($adminDaloa))
        ->postJson("/api/v1/admin/parcels/{$parcelBouake->id}/refund", [
            'montant' => 1500,
            'raison' => 'Test inter-ville',
            'owner_type' => 'courier',
            'owner_id' => $courierBouake->id,
        ])
        ->assertStatus(404)
        ->assertJsonPath('succes', false);

    expect($response->json('message'))->not->toContain('Bouaké')
        ->and($parcelBouake->fresh()->status)->toBe(Parcel::STATUS_IN_TRANSIT);
})->with(['city_support', 'city_finance', 'city_manager']);

it('S2 national (order et parcel refund): autorise un rôle financier national sur toute ville', function () {
    $cityBouake = City::factory()->create(['name' => 'Bouaké']);
    $natFinance = User::factory()->create(['role' => 'national_finance', 'city_id' => null]);
    $driverBouake = User::factory()->create(['role' => 'driver', 'city_id' => $cityBouake->id]);
    $courierBouake = Courier::factory()->create(['user_id' => $driverBouake->id, 'city_id' => $cityBouake->id]);

    $orderBouake = Order::factory()->create([
        'city_id' => $cityBouake->id,
        'courier_id' => $courierBouake->id,
    ]);

    $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($natFinance))
        ->postJson("/api/v1/admin/orders/{$orderBouake->id}/refund", [
            'montant' => 5000,
            'raison' => 'Accord national',
            'owner_type' => 'courier',
            'owner_id' => $courierBouake->id,
        ])
        ->assertOk()
        ->assertJsonPath('succes', true);
});
