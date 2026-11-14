<?php

use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tymon\JWTAuth\Facades\JWTAuth;

// ─── Inscription ───────────────────────────────────────────────────────────────

it('un client peut demander un OTP pour s\'inscrire', function () {
    $response = $this->postJson('/api/v1/auth/register', [
        'phone' => '0700000001',
    ]);

    $response->assertOk()
        ->assertJsonPath('succes', true)
        ->assertJsonPath('message', fn ($msg) => str_contains($msg, '07****'));

    $this->assertDatabaseHas('otp_codes', [
        'phone' => '0700000001',
        'purpose' => 'register',
        'is_used' => false,
    ]);
});

it('un numéro déjà existant ne peut pas s\'inscrire à nouveau', function () {
    User::factory()->create(['phone' => '0700000002', 'role' => 'client']);

    $this->postJson('/api/v1/auth/register', ['phone' => '0700000002'])
        ->assertStatus(409)
        ->assertJsonPath('succes', false);
});

it('un client peut vérifier son OTP et créer son compte', function () {
    $phone = '0700000003';
    $code = '5821';

    OtpCode::create([
        'phone' => $phone,
        'code_hash' => Hash::make($code),
        'purpose' => 'register',
        'expires_at' => now()->addMinutes(5),
    ]);

    $response = $this->postJson('/api/v1/auth/verify-register', [
        'phone' => $phone,
        'otp' => $code,
        'name' => 'Kouassi Ama',
    ]);

    $response->assertCreated()
        ->assertJsonPath('succes', true)
        ->assertJsonPath('donnees.user.phone', $phone)
        ->assertJsonPath('donnees.user.role', 'client')
        ->assertJsonStructure(['donnees' => ['access_token', 'refresh_token']]);

    $this->assertDatabaseHas('users', [
        'phone' => $phone,
        'role' => 'client',
    ]);

    // L'OTP doit être supprimé après validation (CDC 11.4 / Lot P3)
    $this->assertDatabaseMissing('otp_codes', [
        'phone' => $phone,
    ]);
});

it('un OTP incorrect est refusé', function () {
    $phone = '0700000004';

    OtpCode::create([
        'phone' => $phone,
        'code_hash' => Hash::make('1111'),
        'purpose' => 'register',
        'expires_at' => now()->addMinutes(5),
    ]);

    $this->postJson('/api/v1/auth/verify-register', [
        'phone' => $phone,
        'otp' => '9999',
        'name' => 'Test',
    ])->assertStatus(422)
        ->assertJsonPath('succes', false);
});

it('un OTP expiré est refusé', function () {
    $phone = '0700000005';
    $code = '3333';

    OtpCode::create([
        'phone' => $phone,
        'code_hash' => Hash::make($code),
        'purpose' => 'register',
        'expires_at' => now()->subMinutes(6), // Expiré depuis 6 minutes
    ]);

    $this->postJson('/api/v1/auth/verify-register', [
        'phone' => $phone,
        'otp' => $code,
        'name' => 'Test',
    ])->assertStatus(422)
        ->assertJsonPath('succes', false);
});

// ─── Rate Limiting OTP ────────────────────────────────────────────────────────

it('le rate limiting bloque après 3 tentatives d\'inscription', function () {
    // Simuler 3 tentatives
    for ($i = 0; $i < 3; $i++) {
        $this->postJson('/api/v1/auth/register', ['phone' => '0700000099']);
    }

    // La 4ème tentative doit être bloquée
    $this->postJson('/api/v1/auth/register', ['phone' => '0700000099'])
        ->assertStatus(429)
        ->assertJsonPath('succes', false);
});

// ─── Connexion ────────────────────────────────────────────────────────────────

it('un utilisateur existant peut demander un OTP de connexion', function () {
    User::factory()->create(['phone' => '0708000001', 'role' => 'client', 'is_active' => true]);

    $this->postJson('/api/v1/auth/login', ['phone' => '0708000001'])
        ->assertOk()
        ->assertJsonPath('succes', true);

    $this->assertDatabaseHas('otp_codes', [
        'phone' => '0708000001',
        'purpose' => 'login',
    ]);
});

it('un numéro inconnu ne peut pas se connecter', function () {
    $this->postJson('/api/v1/auth/login', ['phone' => '0700099999'])
        ->assertStatus(404)
        ->assertJsonPath('succes', false);
});

// ─── Session unique Merchant ───────────────────────────────────────────────────

it('la connexion d\'un merchant révoque l\'ancienne session', function () {
    $merchant = User::factory()->create([
        'phone' => '0708000010',
        'role' => 'merchant',
        'is_active' => true,
        'session_token' => 'ancien-token-xyz',
        'device_token' => 'fcm-token-ancien',
    ]);

    $code = '7711';
    OtpCode::create([
        'phone' => $merchant->phone,
        'code_hash' => Hash::make($code),
        'purpose' => 'login',
        'expires_at' => now()->addMinutes(5),
    ]);

    $this->postJson('/api/v1/auth/verify-login', [
        'phone' => $merchant->phone,
        'otp' => $code,
    ])->assertOk();

    // L'ancien session_token doit être remplacé par le nouveau jti de session (Point 4 - Session unique)
    $merchant->refresh();
    expect($merchant->session_token)->not->toBe('ancien-token-xyz')
        ->not->toBeNull();
});

// ─── Refresh Token ────────────────────────────────────────────────────────────

it('un access token peut être renouvelé avec un refresh token valide', function () {
    $user = User::factory()->create(['role' => 'client', 'is_active' => true]);
    $token = JWTAuth::fromUser($user);

    $res = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/auth/refresh')
        ->assertOk()
        ->assertJsonPath('succes', true)
        ->assertJsonStructure(['donnees' => ['access_token']]);

    $newToken = $res->json('donnees.access_token');
    expect($newToken)->not->toBe($token);

    $this->withHeader('Authorization', "Bearer {$newToken}")
        ->getJson('/api/v1/client/profile')
        ->assertOk();
});

// ─── Logout ───────────────────────────────────────────────────────────────────

it('un utilisateur connecté peut se déconnecter', function () {
    $user = User::factory()->create(['role' => 'client', 'is_active' => true]);
    $token = JWTAuth::fromUser($user);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/auth/logout')
        ->assertOk()
        ->assertJsonPath('succes', true);

    // Le token ne doit plus être valide
    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/client/profile')
        ->assertStatus(401);
});

it('conserve le nom du client lors de l\'inscription même si verify-register ne le renvoie pas', function () {
    $phone = '0705112233';

    $reg = $this->postJson('/api/v1/auth/register', [
        'phone' => "+225 {$phone}",
        'name' => 'Ahou Koffi',
    ]);
    $reg->assertOk();
    $code = $reg->json('debug_otp') ?? '1234';

    $verify = $this->postJson('/api/v1/auth/verify-register', [
        'phone' => $phone,
        'code' => $code,
    ]);

    $verify->assertCreated()
        ->assertJsonPath('donnees.user.name', 'Ahou Koffi')
        ->assertJsonPath('donnees.user.phone', $phone);

    $this->assertDatabaseHas('users', [
        'phone' => $phone,
        'name' => 'Ahou Koffi',
        'role' => 'client',
    ]);
});

it('réinitialise le rate limit de connexion après vérification OTP réussie', function () {
    $user = User::factory()->create([
        'name' => 'Serge Yao',
        'phone' => '0708998877',
        'role' => 'client',
        'is_active' => true,
    ]);

    for ($i = 0; $i < 4; $i++) {
        $loginRes = $this->postJson('/api/v1/auth/login', ['phone' => $user->phone])->assertOk();
        $code = $loginRes->json('debug_otp') ?? '1234';

        $this->postJson('/api/v1/auth/verify-login', [
            'phone' => $user->phone,
            'otp' => $code,
        ])->assertOk()
            ->assertJsonPath('donnees.user.id', $user->id)
            ->assertJsonPath('donnees.user.name', 'Serge Yao');
    }
});
