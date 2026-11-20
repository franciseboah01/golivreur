<?php

use App\Models\ActivityLog;
use App\Models\City;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\Neighborhood;
use App\Models\OtpCode;
use App\Models\Parcel;
use App\Models\ParcelPhoto;
use App\Models\User;
use App\Services\Auth\PasswordResetService;
use App\Services\FirebasePhoneAuthService;
use App\Services\SettingsService;
use App\Services\SmsService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tymon\JWTAuth\Facades\JWTAuth;

// ─── MISSION 1 : MOT DE PASSE OUBLIÉ (SÉCURITÉ STRICTE 2 ESSAIS / 5 MIN) ─────

it('réinitialise le mot de passe via canal Firebase OTP et révoque l\'ancien mot de passe', function () {
    $city = City::factory()->create(['name' => 'Daloa']);
    $client = User::factory()->create([
        'name' => 'Kouassi Jean',
        'phone' => '0700000011',
        'password' => Hash::make('AncienPass1!'),
        'role' => 'client',
        'city_id' => $city->id,
        'is_active' => true,
    ]);

    $smsMock = Mockery::mock(SmsService::class);
    $smsMock->shouldNotReceive('envoyerOtp');
    $this->app->instance(SmsService::class, $smsMock);

    $firebaseMock = Mockery::mock(FirebasePhoneAuthService::class);
    $firebaseMock->shouldReceive('verifyIdToken')
        ->once()
        ->with('valid-reset-firebase-token')
        ->andReturn('0700000011');
    $this->app->instance(FirebasePhoneAuthService::class, $firebaseMock);

    // Étape 1 : demande OTP réinitialisation (canal Firebase)
    $this->postJson('/api/v1/auth/password-reset/request', [
        'phone' => '+225 07 00 00 00 11',
        'use_firebase_otp' => true,
    ])
        ->assertOk()
        ->assertJsonPath('succes', true)
        ->assertJsonPath('donnees.channel', 'firebase')
        ->assertJsonPath('donnees.max_attempts', 2)
        ->assertJsonPath('donnees.remaining_attempts', 2);

    // Étape 2 : confirmation avec Firebase ID Token + nouveau mot de passe
    $this->postJson('/api/v1/auth/password-reset/confirm', [
        'phone' => '0700000011',
        'firebase_id_token' => 'valid-reset-firebase-token',
        'password' => 'NouveauPass2026!',
        'password_confirmation' => 'NouveauPass2026!',
    ])
        ->assertOk()
        ->assertJsonPath('succes', true);

    expect(Hash::check('NouveauPass2026!', (string) $client->fresh()->password))->toBeTrue();
    expect(OtpCode::where('phone', '0700000011')->where('purpose', 'password_reset')->count())->toBe(0);

    // Connexion immédiate avec le nouveau mot de passe -> 200 OK
    $this->postJson('/api/v1/auth/login-password', [
        'phone' => '0700000011',
        'password' => 'NouveauPass2026!',
        'app_type' => 'client',
    ])->assertOk();
});

it('bloque définitivement la réinitialisation après 2 échecs OTP (SMS et Firebase fail-attempt) avec numéro du service client', function () {
    $city = City::factory()->create(['name' => 'Daloa']);
    User::factory()->create([
        'phone' => '0700000022',
        'password' => Hash::make('OldPass123!'),
        'role' => 'client',
        'city_id' => $city->id,
        'is_active' => true,
    ]);

    // Demande OTP en mode SMS modulaire (interchangeable pour la prod)
    $this->postJson('/api/v1/auth/password-reset/request', [
        'phone' => '0700000022',
        'use_firebase_otp' => false,
    ])
        ->assertOk()
        ->assertJsonPath('donnees.channel', 'sms')
        ->assertJsonPath('donnees.remaining_attempts', 2);

    // 1er échec -> 422, reste 1 tentative
    $this->postJson('/api/v1/auth/password-reset/confirm', [
        'phone' => '0700000022',
        'code' => '000000',
        'password' => 'NewPass123!',
        'password_confirmation' => 'NewPass123!',
    ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'PASSWORD_RESET_INVALID_OTP')
        ->assertJsonPath('donnees.remaining_attempts', 1);

    // 2e échec (ou signalé depuis le SDK Firebase via /fail-attempt) -> 429 BLOQUÉ + support_phone
    $this->postJson('/api/v1/auth/password-reset/fail-attempt', [
        'phone' => '0700000022',
    ])
        ->assertStatus(429)
        ->assertJsonPath('code', 'PASSWORD_RESET_LOCKED')
        ->assertJsonPath('message', PasswordResetService::LOCKED_MESSAGE)
        ->assertJsonPath('donnees.locked', true);

    // Toute nouvelle demande pour ce numéro est refusée (rate limiting actif)
    $this->postJson('/api/v1/auth/password-reset/request', [
        'phone' => '0700000022',
        'use_firebase_otp' => true,
    ])
        ->assertStatus(429)
        ->assertJsonPath('code', 'PASSWORD_RESET_LOCKED');

    expect(
        OtpCode::where('phone', '0700000022')
            ->where('purpose', 'password_reset')
            ->where('is_used', false)
            ->count()
    )->toBe(0);
});

it('invalide l\'OTP de réinitialisation après 5 minutes et ne prolonge pas le délai lors d\'un renvoi', function () {
    $city = City::factory()->create(['name' => 'Abidjan']);
    User::factory()->create([
        'phone' => '0500000033',
        'password' => Hash::make('OldPass123!'),
        'role' => 'client',
        'city_id' => $city->id,
        'is_active' => true,
    ]);

    $this->postJson('/api/v1/auth/password-reset/request', [
        'phone' => '0500000033',
        'use_firebase_otp' => true,
    ])->assertOk();

    $initialOtp = OtpCode::where('phone', '0500000033')
        ->where('purpose', 'password_reset')
        ->firstOrFail();
    $initialExpiresAt = $initialOtp->expires_at->toIso8601String();

    // Un renvoi conserve exactement la fenêtre initiale de 5 min
    $this->travel(1)->minutes();
    $resendRes = $this->postJson('/api/v1/auth/password-reset/request', [
        'phone' => '0500000033',
        'use_firebase_otp' => true,
    ])->assertOk();
    expect($resendRes->json('donnees.expires_at'))->toBe($initialExpiresAt);

    // Après expiration des 5 minutes -> 429 BLOQUÉ
    $this->travel(5)->minutes();
    $this->postJson('/api/v1/auth/password-reset/confirm', [
        'phone' => '0500000033',
        'code' => '123456',
        'password' => 'NewPass123!',
        'password_confirmation' => 'NewPass123!',
    ])
        ->assertStatus(429)
        ->assertJsonPath('code', 'PASSWORD_RESET_LOCKED')
        ->assertJsonPath('message', PasswordResetService::LOCKED_MESSAGE);
});

// ─── MISSION 2 : NOUVELLE EXPÉDITION (QUARTIER ADMIN + PHOTO + STRATEGY PAIEMENT) ─

it('crée une expédition GoColis avec quartier dynamique, photo du colis et paiement Mobile Money simulé (Pattern Strategy)', function () {
    Storage::fake('parcel_photos');

    $city = City::factory()->create([
        'name' => 'Daloa',
        'center_lat' => 6.8770,
        'center_lng' => -6.4502,
    ]);
    $otherCity = City::factory()->create(['name' => 'Abidjan']);

    $neighborhood = Neighborhood::create([
        'city_id' => $city->id,
        'name' => 'Commerce',
        'is_active' => true,
        'distance_km' => 2.5,
        'lat' => 6.8810,
        'lng' => -6.4490,
    ]);
    $foreignNeighborhood = Neighborhood::create([
        'city_id' => $otherCity->id,
        'name' => 'Cocody',
        'is_active' => true,
        'distance_km' => 4.0,
        'lat' => 5.3484,
        'lng' => -3.9869,
    ]);

    $client = User::factory()->create([
        'name' => 'Expéditeur Test',
        'role' => 'client',
        'city_id' => $city->id,
        'is_active' => true,
    ]);
    $token = JWTAuth::fromUser($client);

    // 1. Quartier d'une autre ville refusé -> 422
    $this->withHeader('Authorization', "Bearer {$token}")
        ->post('/api/v1/client/parcels', [
            'recipient_name' => 'Koné Mariam',
            'recipient_phone' => '0709000001',
            'neighborhood_id' => $foreignNeighborhood->id,
            'pickup_lat' => 6.8780,
            'pickup_lng' => -6.4500,
            'size' => 'moyen',
            'payment_method' => 'wave',
            'simulate_payment' => '1',
        ], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('neighborhood_id');

    // 2. Création complète avec quartier de la ville + photo du colis + paiement Wave simulé
    $photo = UploadedFile::fake()->image('colis.jpg', 640, 480);

    $res = $this->withHeader('Authorization', "Bearer {$token}")
        ->post('/api/v1/client/parcels', [
            'recipient_name' => 'Koné Mariam',
            'recipient_phone' => '0709000001',
            'neighborhood_id' => $neighborhood->id,
            'pickup_lat' => 6.8780,
            'pickup_lng' => -6.4500,
            'pickup_address' => 'Quartier Tazibouo, Daloa',
            'size' => 'moyen',
            'description' => 'Chaussures neuves',
            'declared_value' => 25000,
            'payment_method' => 'wave',
            'simulate_payment' => '1',
            'photo' => $photo,
        ], ['Accept' => 'application/json']);

    $res->assertCreated()
        ->assertJsonPath('succes', true)
        ->assertJsonPath('donnees.payment_method', 'wave')
        ->assertJsonPath('donnees.payment_status', 'paid')
        ->assertJsonPath('donnees.has_insurance', true);

    expect($res->json('donnees.payment_reference'))->toStartWith('SIM-WAVE-');

    $parcel = Parcel::with('photos')->findOrFail($res->json('donnees.id'));
    expect($parcel->delivery_address)->toBe('Commerce');
    expect($parcel->photos->where('type', 'parcel')->count())->toBe(1);
    expect($parcel->pickupPhoto()->count())->toBe(0); // distinct de la preuve livreur

    /** @var ParcelPhoto $savedPhoto */
    $savedPhoto = $parcel->photos->firstWhere('type', 'parcel');
    Storage::disk(ParcelPhoto::storageDisk())->assertExists((string) $savedPhoto->storage_path);

    expect(
        ActivityLog::where('action', 'parcel_payment_simulated')
            ->where('entity_id', $parcel->id)
            ->exists()
    )->toBeTrue();
});

// ─── NUMÉRO DE SERVICE CLIENT PAR VILLE (ADMIN + 3 APPS) ─────────────────────

it('stocke un numéro de service client par ville et l\'expose dans /cities, /city-status et les 3 profils mobiles', function () {
    $city = City::factory()->create(['name' => 'Sirasso', 'is_active' => true]);
    app(SettingsService::class)->setCitySupportPhone($city->id, '0708091011');

    expect($city->fresh()->support_phone)->toBe('+2250708091011');

    // 1. Endpoint public /cities
    $citiesRes = $this->getJson('/api/v1/cities')->assertOk();
    $sirasso = collect($citiesRes->json('donnees'))->firstWhere('id', $city->id);
    expect($sirasso['support_phone'] ?? null)->toBe('+2250708091011');

    // 2. Endpoint public /client/city-status
    $this->getJson("/api/v1/client/city-status?city_id={$city->id}")
        ->assertOk()
        ->assertJsonPath('donnees.support_phone', '+2250708091011');

    // 3. Profil Client + Forgot Password renvoient le numéro de service de la ville
    $client = User::factory()->create(['role' => 'client', 'city_id' => $city->id, 'phone' => '0700000044', 'is_active' => true]);
    $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($client))
        ->getJson('/api/v1/client/profile')
        ->assertOk()
        ->assertJsonPath('donnees.support_phone', '+2250708091011');

    $this->postJson('/api/v1/auth/password-reset/request', [
        'phone' => '0700000044',
        'use_firebase_otp' => true,
    ])
        ->assertOk()
        ->assertJsonPath('donnees.support_phone', '+2250708091011');

    // 4. Profil Business
    auth('api')->logout();
    $merchantUser = User::factory()->create(['role' => 'merchant', 'city_id' => $city->id, 'is_active' => true]);
    Merchant::factory()->create(['user_id' => $merchantUser->id, 'city_id' => $city->id, 'is_active' => true]);
    $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($merchantUser))
        ->getJson('/api/v1/merchant/profile')
        ->assertOk()
        ->assertJsonPath('donnees.support_phone', '+2250708091011');

    // 5. Profil Driver
    auth('api')->logout();
    $driverUser = User::factory()->create(['role' => 'driver', 'city_id' => $city->id, 'is_active' => true]);
    Courier::factory()->create(['user_id' => $driverUser->id, 'city_id' => $city->id, 'is_active' => true]);
    $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($driverUser))
        ->getJson('/api/v1/driver/profile')
        ->assertOk()
        ->assertJsonPath('donnees.support_phone', '+2250708091011');
});

// ─── PÉRIMÈTRE GPS VILLE (45 KM + QUARTIERS + DIAGNOSTIC CLAIR) ──────────────

it('accepte les coordonnées dans un rayon élargi de 45 km ou proche d\'un quartier actif, et retourne un diagnostic précis hors ville', function () {
    $abidjan = City::factory()->create([
        'name' => 'Abidjan',
        'is_active' => true,
        'center_lat' => 5.3600,
        'center_lng' => -4.0083,
    ]);
    $daloa = City::factory()->create([
        'name' => 'Daloa',
        'is_active' => true,
        'center_lat' => 6.8770,
        'center_lng' => -6.4502,
    ]);

    $grandBassamZone = Neighborhood::create([
        'city_id' => $abidjan->id,
        'name' => 'Grand-Bassam',
        'is_active' => true,
        'distance_km' => 30.0,
        'lat' => 5.2118,
        'lng' => -3.7388,
    ]);

    // 1. ~34 km du centre d'Abidjan (Grand-Bassam) -> accepté (rayon élargi 45 km)
    expect($abidjan->estDansLaVille(5.2118, -3.7388))->toBeTrue();

    // 2. Point situé à ~55 km du centre mais à 18 km d'un quartier actif d'Abidjan -> accepté
    expect($abidjan->estDansLaVille(5.1500, -3.6000))->toBeTrue();

    // 3. Point situé à Daloa (~310 km) -> refusé avec diagnostic précis nommant les deux villes
    $client = User::factory()->create([
        'role' => 'client',
        'city_id' => $abidjan->id,
        'is_active' => true,
    ]);
    $token = JWTAuth::fromUser($client);

    $res = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/client/parcels', [
            'recipient_name' => 'Yao Konan',
            'recipient_phone' => '0701020304',
            'neighborhood_id' => $grandBassamZone->id,
            'pickup_lat' => 6.8770,
            'pickup_lng' => -6.4502,
            'size' => 'petit',
            'payment_method' => 'cash',
        ]);

    $res->assertStatus(422)
        ->assertJsonValidationErrors(['pickup_lat']);

    $errorMsg = (string) ($res->json('errors.pickup_lat.0') ?? '');
    expect($errorMsg)->toContain('Abidjan')->toContain('Daloa');
});
