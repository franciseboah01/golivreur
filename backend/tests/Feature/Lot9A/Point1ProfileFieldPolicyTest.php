<?php

use App\Models\City;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tymon\JWTAuth\Facades\JWTAuth;

it('Point 1 Client : nom et avatar sont librement modifiables, champs admin rejetes en 403 field_admin_only', function () {
    Storage::fake('public');

    $city = City::factory()->create(['name' => 'Daloa']);
    $client = User::factory()->create([
        'name' => 'Kouassi Jean',
        'phone' => '0701900001',
        'role' => 'client',
        'city_id' => $city->id,
    ]);
    $token = JWTAuth::fromUser($client);

    // Nom et avatar libres
    $photo = UploadedFile::fake()->image('avatar.jpg', 200, 200);
    $this->withHeader('Authorization', "Bearer {$token}")
        ->post('/api/v1/client/profile', [
            '_method' => 'PUT',
            'name' => 'Kouassi Jean-Marc',
            'photo' => $photo,
        ])
        ->assertOk()
        ->assertJsonPath('donnees.name', 'Kouassi Jean-Marc');

    expect($client->fresh()->name)->toBe('Kouassi Jean-Marc')
        ->and($client->fresh()->photo_path)->not->toBeNull();

    // Champ admin seul -> 403 field_admin_only (jamais ignoré en silence)
    $this->withHeader('Authorization', "Bearer {$token}")
        ->putJson('/api/v1/client/profile', [
            'city_id' => $city->id + 1,
        ])
        ->assertStatus(403)
        ->assertJsonPath('code', 'field_admin_only');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->putJson('/api/v1/client/profile', [
            'is_active' => false,
        ])
        ->assertStatus(403)
        ->assertJsonPath('code', 'field_admin_only');
});

it('Point 1 Livreur : lecture seule sur nom, pieces, vehicule, ville, photo, statut -> 403 field_admin_only', function () {
    Storage::fake('public');

    $city = City::factory()->create(['name' => 'Daloa']);
    $driverUser = User::factory()->create([
        'name' => 'Koné Bakary',
        'phone' => '0703900001',
        'role' => 'driver',
        'city_id' => $city->id,
    ]);
    Courier::factory()->create([
        'user_id' => $driverUser->id,
        'city_id' => $city->id,
        'vehicle_type' => 'moto',
        'vehicle_plate' => '1111 AB 01',
    ]);
    $token = JWTAuth::fromUser($driverUser);

    foreach ([
        ['name' => 'Autre Nom'],
        ['vehicle_type' => 'tricycle'],
        ['vehicle_plate' => '9999 ZZ 01'],
        ['driver_license_number' => 'CI-NEW-123'],
        ['id_card_number' => 'CI-CNI-999'],
        ['city_id' => $city->id],
        ['is_active' => false],
        ['status' => 'suspended'],
    ] as $payload) {
        $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/v1/driver/profile', $payload)
            ->assertStatus(403)
            ->assertJsonPath('code', 'field_admin_only');
    }

    // Photo livreur également réservée à l'admin
    $photo = UploadedFile::fake()->image('driver.jpg', 200, 200);
    $this->withHeader('Authorization', "Bearer {$token}")
        ->post('/api/v1/driver/profile', [
            '_method' => 'PUT',
            'photo' => $photo,
        ])
        ->assertStatus(403)
        ->assertJsonPath('code', 'field_admin_only');

    expect($driverUser->fresh()->name)->toBe('Koné Bakary');
});

it('Point 1 Commercant : description et horaires libres ; nom, categorie, quartier, adresse, lat, lng, documents -> 403 field_admin_only', function () {
    $city = City::factory()->create(['name' => 'Daloa']);
    $merchantUser = User::factory()->create([
        'name' => 'Awa Gérante',
        'phone' => '0702900001',
        'role' => 'merchant',
        'city_id' => $city->id,
    ]);
    $merchant = Merchant::factory()->create([
        'user_id' => $merchantUser->id,
        'city_id' => $city->id,
        'name' => 'Maquis Le Palmier',
        'category' => 'maquis',
        'address' => 'Quartier Lobia',
        'lat' => 6.8800,
        'lng' => -6.4500,
        'is_active' => true,
        'is_open' => true,
    ]);
    $token = JWTAuth::fromUser($merchantUser);

    // Description & horaires libres
    $this->withHeader('Authorization', "Bearer {$token}")
        ->putJson('/api/v1/business/profile', [
            'description' => 'Spécialités grillades et attiéké',
            'open_hours' => ['always_open' => true],
        ])
        ->assertOk()
        ->assertJsonPath('donnees.description', 'Spécialités grillades et attiéké');

    // Champs réservés admin -> 403 field_admin_only
    foreach ([
        ['business_name' => 'Nouveau Nom'],
        ['merchant_name' => 'Nouveau Nom'],
        ['category' => 'pharmacie'],
        ['quartier' => 'Tazibouo'],
        ['neighborhood_id' => 1],
        ['address' => 'Nouvelle Adresse'],
        ['lat' => 6.8850],
        ['lng' => -6.4550],
        ['latitude' => 6.8850],
        ['longitude' => -6.4550],
        ['documents' => 'doc.pdf'],
        ['city_id' => $city->id],
    ] as $payload) {
        $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/v1/business/profile', $payload)
            ->assertStatus(403)
            ->assertJsonPath('code', 'field_admin_only');
    }

    $merchant->refresh();
    expect($merchant->name)->toBe('Maquis Le Palmier')
        ->and((float) $merchant->lat)->toBe(6.8800)
        ->and((float) $merchant->lng)->toBe(-6.4500);
});
