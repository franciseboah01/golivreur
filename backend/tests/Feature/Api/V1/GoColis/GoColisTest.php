<?php

use App\Models\City;
use App\Models\Courier;
use App\Models\Parcel;
use App\Models\ParcelPhoto;
use App\Models\User;
use App\Modules\GoShop\Services\CommissionService;
use Illuminate\Support\Facades\Hash;
use Tymon\JWTAuth\Facades\JWTAuth;

// ─── Création d'expédition ────────────────────────────────────────────────────

it('un client peut créer une expédition GoColis', function () {
    $city = City::factory()->create(['name' => 'Daloa']);
    $client = User::factory()->create(['role' => 'client', 'city_id' => $city->id]);
    $token = JWTAuth::fromUser($client);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/client/parcels', [
            'recipient_name' => 'Koné Mariam',
            'recipient_phone' => '0709000001',
            'delivery_address' => 'Quartier Résidentiel, Daloa',
            'delivery_lat' => 6.8800,
            'delivery_lng' => -6.4510,
            'pickup_lat' => 6.8821,
            'pickup_lng' => -6.4478,
            'size' => 'moyen',
            'declared_value' => 25000,
            'description' => 'Vêtements et chaussures',
            'payment_method' => 'orange_money',
            'distance_km' => 3.5,
        ]);

    $response->assertCreated()
        ->assertJsonPath('succes', true)
        ->assertJsonStructure([
            'donnees' => ['id', 'sender_code_plain', 'shipping_fee', 'has_insurance'],
        ]);

    // Le code expéditeur doit être retourné en clair UNE SEULE FOIS
    $senderCodePlain = $response->json('donnees.sender_code_plain');
    expect($senderCodePlain)->toBeString()
        ->toHaveLength(4);

    // En base, le code doit être hashé
    $parcel = Parcel::find($response->json('donnees.id'));
    expect(Hash::check($senderCodePlain, $parcel->sender_code))->toBeTrue();

    // Le code destinataire ne doit JAMAIS apparaître dans la réponse
    $responseBody = json_encode($response->json());
    expect($responseBody)->not->toContain($parcel->recipient_code);
});

it('calcule correctement l\'assurance pour une valeur déclarée > 20000 F CFA', function () {
    $city = City::factory()->create();
    $client = User::factory()->create(['role' => 'client', 'city_id' => $city->id]);
    $token = JWTAuth::fromUser($client);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/client/parcels', [
            'recipient_name' => 'Test Destinataire',
            'recipient_phone' => '0709000002',
            'delivery_address' => 'Adresse test',
            'size' => 'grand',
            'declared_value' => 30000,  // > 20000 → assurance obligatoire
            'payment_method' => 'cash',
            'distance_km' => 2.0,
        ]);

    $response->assertCreated();
    $parcel = Parcel::find($response->json('donnees.id'));

    expect($parcel->has_insurance)->toBeTrue()
        ->and($parcel->insurance_amount)->toBe(900); // 3% de 30000
});

it('ne calcule pas d\'assurance pour une valeur déclarée <= 20000 F CFA', function () {
    $city = City::factory()->create();
    $client = User::factory()->create(['role' => 'client', 'city_id' => $city->id]);
    $token = JWTAuth::fromUser($client);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/client/parcels', [
            'recipient_name' => 'Test',
            'recipient_phone' => '0709000003',
            'delivery_address' => 'Adresse test',
            'size' => 'petit',
            'declared_value' => 15000, // ≤ 20000 → pas d'assurance
            'payment_method' => 'cash',
            'distance_km' => 1.5,
        ]);

    $parcel = Parcel::find($response->json('donnees.id'));
    expect($parcel->has_insurance)->toBeFalse()
        ->and($parcel->insurance_amount)->toBe(0);
});

// ─── Validation code expéditeur ───────────────────────────────────────────────

it('le livreur peut valider le code expéditeur correct après avoir pris la photo', function () {
    $city = City::factory()->create();
    $driver = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    $courier = Courier::factory()->create(['user_id' => $driver->id, 'city_id' => $city->id]);

    $codeClair = '4829';
    $parcel = Parcel::factory()->create([
        'courier_id' => $courier->id,
        'city_id' => $city->id,
        'status' => Parcel::STATUS_AT_SENDER,
        'sender_code' => Hash::make($codeClair),
    ]);

    // La photo de ramassage doit exister
    ParcelPhoto::create([
        'parcel_id' => $parcel->id,
        'type' => 'pickup',
        'photo_url' => 'https://spaces.do.com/photos/colis/1/pickup/photo.jpg',
    ]);

    $token = JWTAuth::fromUser($driver);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/parcels/{$parcel->id}/verify-sender-code", [
            'code' => $codeClair,
        ])->assertOk()
        ->assertJsonPath('succes', true);

    $this->assertDatabaseHas('parcels', [
        'id' => $parcel->id,
        'status' => Parcel::STATUS_PICKED_UP,
    ]);
});

it('le livreur ne peut pas valider le code expéditeur sans avoir pris la photo', function () {
    $city = City::factory()->create();
    $driver = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    $courier = Courier::factory()->create(['user_id' => $driver->id, 'city_id' => $city->id]);
    $parcel = Parcel::factory()->create([
        'courier_id' => $courier->id,
        'status' => Parcel::STATUS_AT_SENDER,
        'sender_code' => Hash::make('1234'),
    ]);

    // Pas de photo créée intentionnellement

    $token = JWTAuth::fromUser($driver);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/parcels/{$parcel->id}/verify-sender-code", ['code' => '1234'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['photo']);
});

it('bloque l\'expédition après 3 codes expéditeur incorrects', function () {
    $city = City::factory()->create();
    $driver = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    $courier = Courier::factory()->create(['user_id' => $driver->id, 'city_id' => $city->id]);
    $parcel = Parcel::factory()->create([
        'courier_id' => $courier->id,
        'status' => Parcel::STATUS_AT_SENDER,
        'sender_code' => Hash::make('9999'),
        'sender_code_attempts' => 2, // Déjà 2 tentatives
    ]);

    ParcelPhoto::create(['parcel_id' => $parcel->id, 'type' => 'pickup', 'photo_url' => 'url']);

    $token = JWTAuth::fromUser($driver);

    // 3ème tentative avec code incorrect
    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/parcels/{$parcel->id}/verify-sender-code", ['code' => '0000'])
        ->assertStatus(422);

    $this->assertDatabaseHas('parcels', [
        'id' => $parcel->id,
        'sender_code_locked' => true,
    ]);
});

// ─── Validation code destinataire ─────────────────────────────────────────────

it('valide correctement le code destinataire et crédite le livreur', function () {
    $city = City::factory()->create();
    $driver = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    $courier = Courier::factory()->create(['user_id' => $driver->id, 'city_id' => $city->id]);

    $codeClair = '3847';
    $parcel = Parcel::factory()->create([
        'courier_id' => $courier->id,
        'city_id' => $city->id,
        'status' => Parcel::STATUS_AT_RECIPIENT,
        'recipient_code' => Hash::make($codeClair),
        'courier_amount' => 1125,
        'picked_up_at' => now()->subMinutes(30),
    ]);

    ParcelPhoto::create(['parcel_id' => $parcel->id, 'type' => 'delivery', 'photo_url' => 'url']);

    $token = JWTAuth::fromUser($driver);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/parcels/{$parcel->id}/verify-recipient-code", [
            'code' => $codeClair,
        ])->assertOk()
        ->assertJsonPath('succes', true);

    $this->assertDatabaseHas('parcels', ['id' => $parcel->id, 'status' => Parcel::STATUS_DELIVERED]);
    $this->assertDatabaseHas('wallets', ['owner_type' => 'courier', 'owner_id' => $courier->id, 'balance' => 1125]);
});

// ─── Taille du colis visible avant acceptation ────────────────────────────────

it('le livreur voit la taille du colis avant d\'accepter une course GoColis', function () {
    $city = City::factory()->create();
    $driver = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    Courier::factory()->create(['user_id' => $driver->id, 'city_id' => $city->id, 'is_online' => true]);
    $parcel = Parcel::factory()->create(['city_id' => $city->id, 'status' => Parcel::STATUS_PENDING, 'size' => 'grand']);

    $token = JWTAuth::fromUser($driver);

    // La notification de course disponible doit contenir la taille
    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/v1/driver/parcels/{$parcel->id}");

    $response->assertOk()
        ->assertJsonPath('donnees.size', 'grand')
        ->assertJsonPath('donnees.size_label', 'Grand (max 15 kg)');
});

// ─── Calcul des frais d'expédition ───────────────────────────────────────────

it('calcule les frais d\'expédition GoColis selon la taille et la distance', function () {
    $service = app(CommissionService::class);

    // Petit colis à 2km (base)
    expect($service->calculerFraisExpedition('petit', 2.0))->toBe(800.0);

    // Petit colis à 4km (base + 2km × 200)
    expect($service->calculerFraisExpedition('petit', 4.0))->toBe(1200.0);

    // Moyen colis à 2km (base)
    expect($service->calculerFraisExpedition('moyen', 2.0))->toBe(1200.0);

    // Grand colis à 5km (base + 3km × 400)
    expect($service->calculerFraisExpedition('grand', 5.0))->toBe(3200.0);
});
