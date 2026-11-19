<?php

use App\Models\City;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\MerchantCategory;
use App\Models\User;
use App\Services\CityGeolocationService;
use Illuminate\Support\Facades\RateLimiter;
use Tymon\JWTAuth\Facades\JWTAuth;

beforeEach(function () {
    $this->daloa = City::factory()->create([
        'name' => 'Daloa',
        'is_active' => true,
        'center_lat' => 6.8770,
        'center_lng' => -6.4502,
    ]);

    $this->abidjan = City::factory()->create([
        'name' => 'Abidjan',
        'is_active' => true,
        'center_lat' => 5.3600,
        'center_lng' => -4.0083,
    ]);
});

it('detecte automatiquement la nouvelle ville du client par GPS et rattache commerces, livraisons et expeditions', function () {
    $client = User::factory()->create([
        'role' => 'client',
        'city_id' => $this->daloa->id,
        'is_active' => true,
    ]);

    $merchantDaloa = Merchant::factory()->create([
        'city_id' => $this->daloa->id,
        'name' => 'Maquis Daloa Test',
        'is_active' => true,
        'is_open' => true,
        'lat' => 6.8780,
        'lng' => -6.4510,
    ]);

    $merchantAbidjan = Merchant::factory()->create([
        'city_id' => $this->abidjan->id,
        'name' => 'Resto Abidjan Plateau',
        'is_active' => true,
        'is_open' => true,
        'lat' => 5.3550,
        'lng' => -4.0100,
    ]);

    $token = JWTAuth::fromUser($client);

    // 1. Le client se déplace à Abidjan -> détection GPS automatique
    $detectRes = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/client/detect-city', [
            'lat' => 5.3580,
            'lng' => -4.0090,
        ]);

    $detectRes->assertOk()
        ->assertJsonPath('succes', true)
        ->assertJsonPath('donnees.detected', true)
        ->assertJsonPath('donnees.changed', true)
        ->assertJsonPath('donnees.city_id', $this->abidjan->id)
        ->assertJsonPath('donnees.city_name', 'Abidjan');

    expect($client->fresh()->city_id)->toBe($this->abidjan->id);

    // 2. Les commerces listés sont désormais rattachés à Abidjan (et non plus Daloa)
    $merchantsRes = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/client/merchants');

    $merchantsRes->assertOk();
    $merchantNames = collect($merchantsRes->json('donnees'))->pluck('name')->all();
    expect($merchantNames)->toContain($merchantAbidjan->name)
        ->not->toContain($merchantDaloa->name);

    // 3. Le statut ville & tarifs sont rattachés à Abidjan
    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/client/city-status')
        ->assertOk()
        ->assertJsonPath('donnees.city_id', $this->abidjan->id)
        ->assertJsonPath('donnees.city_name', 'Abidjan');

    // 4. Une expédition Colis créée par le client est automatiquement rattachée à Abidjan
    $parcelRes = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/client/parcels', [
            'recipient_name' => 'Kouame Jean',
            'recipient_phone' => '0701020304',
            'pickup_address' => 'Cocody Riviera',
            'pickup_lat' => 5.3620,
            'pickup_lng' => -4.0050,
            'delivery_address' => 'Plateau Centre',
            'delivery_lat' => 5.3300,
            'delivery_lng' => -4.0200,
            'size' => 'petit',
            'weight_kg' => 0.8,
            'payment_method' => 'cash',
        ]);

    $parcelRes->assertCreated();
    $this->assertDatabaseHas('parcels', [
        'id' => $parcelRes->json('donnees.id'),
        'city_id' => $this->abidjan->id,
        'sender_id' => $client->id,
    ]);

    // 5. La route PUT /client/profile continue d'interdire la falsification directe de city_id
    $this->withHeader('Authorization', "Bearer {$token}")
        ->putJson('/api/v1/client/profile', [
            'city_id' => $this->daloa->id,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['city_id']);
});

it('autorise un commerce a enregistrer son GPS dans sa ville mais interdit de changer de ville', function () {
    $merchantUser = User::factory()->create([
        'role' => 'merchant',
        'city_id' => $this->daloa->id,
        'is_active' => true,
    ]);
    $merchant = Merchant::factory()->create([
        'user_id' => $merchantUser->id,
        'city_id' => $this->daloa->id,
        'is_active' => true,
        'lat' => 6.8770,
        'lng' => -6.4502,
    ]);

    $token = JWTAuth::fromUser($merchantUser);

    // 1. Coordonnées GPS valides dans Daloa -> accepté et retourné dans le profil
    $this->withHeader('Authorization', "Bearer {$token}")
        ->putJson('/api/v1/merchant/profile', [
            'lat' => 6.8812,
            'lng' => -6.4534,
        ])
        ->assertOk()
        ->assertJsonPath('succes', true)
        ->assertJsonPath('donnees.lat', 6.8812)
        ->assertJsonPath('donnees.lng', -6.4534);

    expect((float) $merchant->fresh()->lat)->toEqualWithDelta(6.8812, 0.0001);

    // 2. Tentative de déplacer le commerce à Abidjan via GPS -> bloqué 422 MERCHANT_CITY_CHANGE_FORBIDDEN
    $this->withHeader('Authorization', "Bearer {$token}")
        ->putJson('/api/v1/merchant/profile', [
            'lat' => 5.3600,
            'lng' => -4.0083,
        ])
        ->assertStatus(422)
        ->assertJsonPath('succes', false)
        ->assertJsonPath('code', 'MERCHANT_CITY_CHANGE_FORBIDDEN');

    // Les coordonnées restent inchangées sur Daloa
    expect((float) $merchant->fresh()->lat)->toEqualWithDelta(6.8812, 0.0001);

    // 3. Pré-inscription d'un nouveau commerce avec GPS hors de la ville choisie -> 422
    MerchantCategory::firstOrCreate(
        ['slug' => 'maquis'],
        ['name' => 'Maquis', 'emoji' => '🍖', 'is_active' => true]
    );

    $this->postJson('/api/v1/auth/register-merchant', [
        'name' => 'Boutique Hors Zone',
        'phone' => '0709887766',
        'password' => 'Secret123!',
        'city_id' => $this->daloa->id,
        'category' => 'maquis',
        'address' => 'Quartier Commerce',
        'lat' => 5.3600,
        'lng' => -4.0083,
    ])->assertStatus(422);
});

it('bloque la prise de service dun livreur qui change de ville jusqua reidentification par un admin', function () {
    $driverUser = User::factory()->create([
        'role' => 'driver',
        'city_id' => $this->daloa->id,
        'is_active' => true,
    ]);
    $courier = Courier::factory()->create([
        'user_id' => $driverUser->id,
        'city_id' => $this->daloa->id,
        'is_active' => true,
        'is_online' => true,
    ]);
    $admin = User::factory()->create([
        'role' => 'super_admin',
        'is_active' => true,
    ]);

    $token = JWTAuth::fromUser($driverUser);
    RateLimiter::clear("driver_location:{$courier->id}");

    // 1. Le livreur affecté à Daloa envoie une position GPS à Abidjan -> déconnecté + bloqué 403 CITY_REIDENTIFICATION_REQUIRED
    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/driver/location', [
            'lat' => 5.3600,
            'lng' => -4.0083,
        ])
        ->assertStatus(403)
        ->assertJsonPath('succes', false)
        ->assertJsonPath('code', 'CITY_REIDENTIFICATION_REQUIRED')
        ->assertJsonPath('donnees.detected_city_name', 'Abidjan');

    expect($courier->fresh()->is_online)->toBeFalse();

    // 2. Le profil livreur expose le statut de réidentification obligatoire
    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/driver/profile')
        ->assertOk()
        ->assertJsonPath('donnees.city_reidentification_required', true)
        ->assertJsonPath('donnees.city_reidentification_info.detected_city_name', 'Abidjan');

    // 3. Impossible de repasser en ligne tant que l'admin n'a pas réidentifié le livreur
    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/driver/availability', ['is_online' => true])
        ->assertStatus(403)
        ->assertJsonPath('code', 'CITY_REIDENTIFICATION_REQUIRED');

    expect($courier->fresh()->is_online)->toBeFalse();

    // 4. L'administrateur réidentifie le livreur et valide sa nouvelle ville (Abidjan)
    app(CityGeolocationService::class)->clearCourierReidentification(
        courier: $courier->fresh(),
        adminUserId: $admin->id,
        newCityId: $this->abidjan->id,
        ipAddress: '127.0.0.1',
    );

    expect($courier->fresh()->city_id)->toBe($this->abidjan->id)
        ->and($driverUser->fresh()->city_id)->toBe($this->abidjan->id);

    // 5. Le livreur peut désormais prendre service à Abidjan avec son GPS Abidjan
    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/driver/availability', [
            'is_online' => true,
            'lat' => 5.3590,
            'lng' => -4.0090,
        ])
        ->assertOk()
        ->assertJsonPath('succes', true)
        ->assertJsonPath('donnees.is_online', true);

    expect($courier->fresh()->is_online)->toBeTrue();
});
