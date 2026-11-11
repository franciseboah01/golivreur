<?php

use App\Models\City;
use App\Models\Courier;
use App\Models\Neighborhood;
use App\Models\Parcel;
use App\Models\User;
use Tymon\JWTAuth\Facades\JWTAuth;

it('D5 nominal (assigné): le livreur assigné de la même ville voit les détails complets sans les codes secrets', function () {
    $city = City::factory()->create(['name' => 'Daloa']);
    $driver = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    $courier = Courier::factory()->create(['user_id' => $driver->id, 'city_id' => $city->id]);

    $parcel = Parcel::factory()->create([
        'city_id' => $city->id,
        'courier_id' => $courier->id,
        'status' => Parcel::STATUS_ACCEPTED,
        'recipient_name' => 'Konan Yao',
        'recipient_phone' => '0707070707',
        'pickup_address' => 'Quartier Lobia, rue 12',
        'delivery_address' => 'Quartier Tazibouo, porte 4',
    ]);

    $response = $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($driver))
        ->getJson("/api/v1/driver/parcels/{$parcel->id}")
        ->assertOk()
        ->assertJsonPath('succes', true)
        ->assertJsonPath('donnees.id', $parcel->id)
        ->assertJsonPath('donnees.recipient_name', 'Konan Yao')
        ->assertJsonPath('donnees.recipient_phone', '0707070707')
        ->assertJsonPath('donnees.delivery_address', 'Quartier Tazibouo, porte 4');

    $data = $response->json('donnees');
    expect($data)->not->toHaveKeys(['sender_code', 'recipient_code', 'sender_code_plain']);
});

it('D5 nominal (non assigné proposé): retourne uniquement taille, distance, frais et zones approximatives SANS PII ni adresse exacte ni codes', function (string $status) {
    $city = City::factory()->create(['name' => 'Daloa']);
    Neighborhood::create([
        'city_id' => $city->id,
        'name' => 'Lobia',
        'is_active' => true,
        'lat' => 6.8820,
        'lng' => -6.4475,
    ]);
    Neighborhood::create([
        'city_id' => $city->id,
        'name' => 'Tazibouo',
        'is_active' => true,
        'lat' => 6.8600,
        'lng' => -6.4300,
    ]);

    $driver = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    Courier::factory()->create(['user_id' => $driver->id, 'city_id' => $city->id]);

    $parcel = Parcel::factory()->create([
        'city_id' => $city->id,
        'courier_id' => null,
        'status' => $status,
        'size' => 'moyen',
        'shipping_fee' => 1500,
        'courier_amount' => 1125,
        'recipient_name' => 'Secret Destinataire',
        'recipient_phone' => '0505050505',
        'pickup_address' => 'Immeuble Koffi porte 18B exacte',
        'delivery_address' => 'Villa Rouge numéro 99 exacte',
        'pickup_lat' => 6.8821,
        'pickup_lng' => -6.4478,
        'delivery_lat' => 6.8605,
        'delivery_lng' => -6.4302,
    ]);

    $response = $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($driver))
        ->getJson("/api/v1/driver/parcels/{$parcel->id}")
        ->assertOk()
        ->assertJsonPath('succes', true)
        ->assertJsonPath('donnees.size', 'moyen')
        ->assertJsonPath('donnees.size_label', 'Moyen (max 5 kg)')
        ->assertJsonPath('donnees.pickup_zone', 'Lobia')
        ->assertJsonPath('donnees.delivery_zone', 'Tazibouo');

    $data = $response->json('donnees');
    expect($data['shipping_fee'])->toEqual(1500)
        ->and($data['courier_amount'])->toEqual(1125)
        ->and($data['distance_km'])->toBeGreaterThan(0)
        ->and($data)->not->toHaveKeys([
            'recipient_name',
            'recipient_phone',
            'pickup_address',
            'delivery_address',
            'pickup_lat',
            'pickup_lng',
            'delivery_lat',
            'delivery_lng',
            'sender_code',
            'recipient_code',
            'sender_code_plain',
        ]);

    $raw = $response->getContent();
    expect($raw)->not->toContain('Secret Destinataire')
        ->and($raw)->not->toContain('0505050505')
        ->and($raw)->not->toContain('Immeuble Koffi')
        ->and($raw)->not->toContain('Villa Rouge');
})->with([Parcel::STATUS_PENDING, Parcel::STATUS_WAITING]);

it('D5 autre ville: refuse 404 sans fuite pour un colis d\'une autre ville (assigné ou non)', function (bool $assigned) {
    $cityDaloa = City::factory()->create(['name' => 'Daloa']);
    $cityBouake = City::factory()->create(['name' => 'Bouaké']);

    $driverDaloa = User::factory()->create(['role' => 'driver', 'city_id' => $cityDaloa->id]);
    $courierDaloa = Courier::factory()->create(['user_id' => $driverDaloa->id, 'city_id' => $cityDaloa->id]);

    $parcelBouake = Parcel::factory()->create([
        'city_id' => $cityBouake->id,
        'courier_id' => $assigned ? $courierDaloa->id : null,
        'status' => $assigned ? Parcel::STATUS_ACCEPTED : Parcel::STATUS_PENDING,
        'recipient_name' => 'Bouake Confidential',
    ]);

    $response = $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($driverDaloa))
        ->getJson("/api/v1/driver/parcels/{$parcelBouake->id}")
        ->assertStatus(404)
        ->assertJsonPath('succes', false);

    expect($response->getContent())->not->toContain('Bouaké')
        ->and($response->getContent())->not->toContain('Bouake Confidential');
})->with([
    'non assigné hors ville' => [false],
    'assigné hors ville' => [true],
]);

it('D5 mauvais propriétaire: refuse 403 pour un colis de la même ville assigné à un autre livreur', function () {
    $city = City::factory()->create();

    $driverA = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    Courier::factory()->create(['user_id' => $driverA->id, 'city_id' => $city->id]);

    $driverB = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    $courierB = Courier::factory()->create(['user_id' => $driverB->id, 'city_id' => $city->id]);

    $parcel = Parcel::factory()->create([
        'city_id' => $city->id,
        'courier_id' => $courierB->id,
        'status' => Parcel::STATUS_PICKED_UP,
        'recipient_name' => 'Private Recipient',
    ]);

    $response = $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($driverA))
        ->getJson("/api/v1/driver/parcels/{$parcel->id}")
        ->assertStatus(403)
        ->assertJsonPath('succes', false);

    expect($response->getContent())->not->toContain('Private Recipient');
});

it('D5 mauvais statut: refuse 422 pour un colis non assigné qui n\'est plus en pending ou waiting', function (string $status) {
    $city = City::factory()->create();
    $driver = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    Courier::factory()->create(['user_id' => $driver->id, 'city_id' => $city->id]);

    $parcel = Parcel::factory()->create([
        'city_id' => $city->id,
        'courier_id' => null,
        'status' => $status,
    ]);

    $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($driver))
        ->getJson("/api/v1/driver/parcels/{$parcel->id}")
        ->assertStatus(422)
        ->assertJsonPath('succes', false);
})->with([Parcel::STATUS_DELIVERED, Parcel::STATUS_CANCELLED, Parcel::STATUS_REFUSED]);

it('D5 rôle interdit: refuse 403 aux rôles non livreurs', function (string $role) {
    $city = City::factory()->create();
    $user = User::factory()->create(['role' => $role, 'city_id' => $city->id]);
    $parcel = Parcel::factory()->create(['city_id' => $city->id, 'status' => Parcel::STATUS_PENDING]);

    $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($user))
        ->getJson("/api/v1/driver/parcels/{$parcel->id}")
        ->assertForbidden();
})->with(['client', 'merchant', 'city_support', 'city_manager', 'super_admin']);
