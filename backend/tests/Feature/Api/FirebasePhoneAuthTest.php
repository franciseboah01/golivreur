<?php

use App\Models\ActivityLog;
use App\Models\City;
use App\Models\OtpCode;
use App\Models\User;
use App\Services\FirebasePhoneAuthService;
use App\Services\SmsService;
use Illuminate\Support\Facades\Hash;
use Tymon\JWTAuth\Facades\JWTAuth;

it('normalise correctement les numéros ivoiriens E.164 et locaux dans FirebasePhoneAuthService', function () {
    expect(FirebasePhoneAuthService::normalizeIvorianPhone('+2250700000001'))->toBe('0700000001');
    expect(FirebasePhoneAuthService::normalizeIvorianPhone('00225 05 00 00 00 02'))->toBe('0500000002');
    expect(FirebasePhoneAuthService::normalizeIvorianPhone('2250100000003'))->toBe('0100000003');
    expect(FirebasePhoneAuthService::normalizeIvorianPhone('07 01 02 03 04'))->toBe('0701020304');
});

it('inscrit un client via Firebase Phone Auth sans passer par SmsService', function () {
    $city = City::factory()->create(['name' => 'Daloa']);

    $smsMock = Mockery::mock(SmsService::class);
    $smsMock->shouldNotReceive('envoyerOtp');
    $this->app->instance(SmsService::class, $smsMock);

    $firebaseMock = Mockery::mock(FirebasePhoneAuthService::class);
    $firebaseMock->shouldReceive('verifyIdToken')
        ->once()
        ->with('valid-firebase-register-token')
        ->andReturn('0700000001');
    $this->app->instance(FirebasePhoneAuthService::class, $firebaseMock);

    // Étape 1 : pré-validation + mise en cache des métadonnées sans appel SMS legacy
    $this->postJson('/api/v1/auth/register', [
        'phone' => '+225 07 00 00 00 01',
        'name' => 'Kouassi Yao',
        'city_id' => $city->id,
        'password' => 'Secret123!',
        'use_firebase_otp' => true,
    ])
        ->assertOk()
        ->assertJsonPath('succes', true)
        ->assertJsonPath('donnees.firebase_otp', true)
        ->assertJsonPath('donnees.phone_e164', '+2250700000001');

    expect(OtpCode::where('phone', '0700000001')->count())->toBe(0);

    // Étape 2 : vérification du Firebase ID Token et création du compte
    $res = $this->postJson('/api/v1/auth/verify-register', [
        'phone' => '0700000001',
        'firebase_id_token' => 'valid-firebase-register-token',
    ])
        ->assertCreated()
        ->assertJsonPath('succes', true)
        ->assertJsonPath('donnees.user.name', 'Kouassi Yao')
        ->assertJsonPath('donnees.user.phone', '0700000001')
        ->assertJsonPath('donnees.user.city_id', $city->id);

    expect($res->json('donnees.access_token'))->toBeString()->not->toBeEmpty();
    expect($res->json('donnees.refresh_token'))->toBeString()->not->toBeEmpty();

    $created = User::where('phone', '0700000001')->first();
    expect($created)->not->toBeNull();
    expect($created->phone_verified_at)->not->toBeNull();
    expect(Hash::check('Secret123!', (string) $created->password))->toBeTrue();

    expect(
        ActivityLog::where('user_id', $created->id)
            ->where('action', 'client_registered_firebase_otp')
            ->exists()
    )->toBeTrue();
});

it('bloque toute usurpation de numéro ou jeton Firebase invalide à l\'inscription', function () {
    $firebaseMock = Mockery::mock(FirebasePhoneAuthService::class);
    $firebaseMock->shouldReceive('verifyIdToken')
        ->once()
        ->with('token-for-other-phone')
        ->andReturn('0799999999');
    $firebaseMock->shouldReceive('verifyIdToken')
        ->once()
        ->with('expired-firebase-token')
        ->andThrow(new InvalidArgumentException('Vérification SMS Firebase invalide ou expirée.'));
    $this->app->instance(FirebasePhoneAuthService::class, $firebaseMock);

    // Numéro vérifié différent du numéro demandé -> 422
    $this->postJson('/api/v1/auth/verify-register', [
        'phone' => '0700000001',
        'firebase_id_token' => 'token-for-other-phone',
        'name' => 'Tentative Usurpation',
    ])
        ->assertStatus(422)
        ->assertJsonPath('succes', false);

    // Jeton expiré / invalide -> 422
    $this->postJson('/api/v1/auth/verify-register', [
        'phone' => '0700000001',
        'firebase_id_token' => 'expired-firebase-token',
        'name' => 'Tentative Expirée',
    ])
        ->assertStatus(422)
        ->assertJsonPath('succes', false);

    expect(User::where('phone', '0700000001')->exists())->toBeFalse();
});

it('connecte un utilisateur existant par Firebase Phone Auth OTP', function () {
    $city = City::factory()->create(['name' => 'Abidjan']);
    $client = User::factory()->create([
        'name' => 'Awa Koné',
        'phone' => '0500000002',
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
        ->with('valid-firebase-login-token')
        ->andReturn('0500000002');
    $firebaseMock->shouldReceive('verifyIdToken')
        ->once()
        ->with('wrong-phone-login-token')
        ->andReturn('0100000003');
    $this->app->instance(FirebasePhoneAuthService::class, $firebaseMock);

    // Étape 1 : numéro inconnu -> 404
    $this->postJson('/api/v1/auth/login', [
        'phone' => '0788888888',
        'use_firebase_otp' => true,
    ])->assertNotFound();

    // Étape 1 : numéro actif -> 200 sans SMS legacy
    $this->postJson('/api/v1/auth/login', [
        'phone' => '+225 05 00 00 00 02',
        'use_firebase_otp' => true,
    ])
        ->assertOk()
        ->assertJsonPath('donnees.firebase_otp', true)
        ->assertJsonPath('donnees.phone_e164', '+2250500000002');

    // Étape 2 : mauvais numéro dans le jeton -> 422
    $this->postJson('/api/v1/auth/verify-login', [
        'phone' => '0500000002',
        'firebase_id_token' => 'wrong-phone-login-token',
    ])->assertStatus(422);

    // Étape 2 : jeton valide -> 200 + JWT
    $res = $this->postJson('/api/v1/auth/verify-login', [
        'phone' => '0500000002',
        'firebase_id_token' => 'valid-firebase-login-token',
    ])
        ->assertOk()
        ->assertJsonPath('succes', true)
        ->assertJsonPath('donnees.user.id', $client->id);

    expect($res->json('donnees.access_token'))->toBeString()->not->toBeEmpty();
});

it('permet au client de changer de numéro via Firebase Phone Auth OTP', function () {
    $city = City::factory()->create(['name' => 'Daloa']);
    $client = User::factory()->create([
        'name' => 'Jean Kouadio',
        'phone' => '0701000050',
        'role' => 'client',
        'city_id' => $city->id,
        'is_active' => true,
    ]);
    $jwt = JWTAuth::fromUser($client);

    $smsMock = Mockery::mock(SmsService::class);
    $smsMock->shouldNotReceive('envoyerOtp');
    $this->app->instance(SmsService::class, $smsMock);

    $firebaseMock = Mockery::mock(FirebasePhoneAuthService::class);
    $firebaseMock->shouldReceive('verifyIdToken')
        ->once()
        ->with('spoofed-phone-token')
        ->andReturn('0701000098');
    $firebaseMock->shouldReceive('verifyIdToken')
        ->once()
        ->with('valid-phone-change-token')
        ->andReturn('0100000003');
    $this->app->instance(FirebasePhoneAuthService::class, $firebaseMock);

    // Étape 1 : demande OTP Firebase
    $this->withHeader('Authorization', "Bearer {$jwt}")
        ->postJson('/api/v1/client/profile/phone/request-otp', [
            'phone' => '+225 01 00 00 00 03',
            'use_firebase_otp' => true,
        ])
        ->assertOk()
        ->assertJsonPath('donnees.firebase_otp', true)
        ->assertJsonPath('donnees.phone_e164', '+2250100000003');

    // Étape 2 : tentative avec jeton d'un autre numéro -> 422
    $this->withHeader('Authorization', "Bearer {$jwt}")
        ->postJson('/api/v1/client/profile/phone/confirm', [
            'phone' => '0100000003',
            'firebase_id_token' => 'spoofed-phone-token',
        ])
        ->assertStatus(422);

    expect($client->fresh()->phone)->toBe('0701000050');

    // Étape 2 : jeton Firebase valide -> numéro mis à jour
    $this->withHeader('Authorization', "Bearer {$jwt}")
        ->postJson('/api/v1/client/profile/phone/confirm', [
            'phone' => '0100000003',
            'firebase_id_token' => 'valid-phone-change-token',
        ])
        ->assertOk()
        ->assertJsonPath('succes', true)
        ->assertJsonPath('donnees.phone', '0100000003');

    expect($client->fresh()->phone)->toBe('0100000003');
});
