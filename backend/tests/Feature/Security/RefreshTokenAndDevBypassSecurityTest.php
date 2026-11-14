<?php

use App\Models\City;
use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tymon\JWTAuth\Facades\JWTAuth;

afterEach(function () {
    app()->detectEnvironment(fn () => 'testing');
    config(['app.env' => 'testing']);
});

it('renouvelle access_token et refresh_token avec un refresh token opaque de 7 jours', function () {
    $user = User::factory()->create(['role' => 'client', 'is_active' => true]);

    $code = '8765';
    OtpCode::create([
        'phone' => $user->phone,
        'code_hash' => Hash::make($code),
        'purpose' => 'login',
        'expires_at' => now()->addMinutes(5),
    ]);

    $loginRes = $this->postJson('/api/v1/auth/verify-login', [
        'phone' => $user->phone,
        'otp' => $code,
    ])->assertOk();

    $accessToken = $loginRes->json('donnees.access_token');
    $refreshToken = $loginRes->json('donnees.refresh_token');

    expect($refreshToken)->not->toBeEmpty();

    // Appel à refresh avec le refresh_token dans Authorization: Bearer (comportement mobile ApiClient)
    $refreshRes = $this->withHeader('Authorization', "Bearer {$refreshToken}")
        ->postJson('/api/v1/auth/refresh');

    $refreshRes->assertOk()
        ->assertJsonPath('succes', true)
        ->assertJsonStructure(['donnees' => ['access_token', 'refresh_token', 'token_type', 'expires_in']]);

    $newAccessToken = $refreshRes->json('donnees.access_token');
    $newRefreshToken = $refreshRes->json('donnees.refresh_token');

    expect($newAccessToken)->not->toBe($accessToken);
    expect($newRefreshToken)->not->toBe($refreshToken);

    // L'ancien refresh token a fait l'objet d'une rotation : il ne doit plus fonctionner
    $replayRes = $this->withHeader('Authorization', "Bearer {$refreshToken}")
        ->postJson('/api/v1/auth/refresh');
    $replayRes->assertStatus(401);

    // Le nouvel access token permet bien d'accéder au profil
    $this->withHeader('Authorization', "Bearer {$newAccessToken}")
        ->getJson('/api/v1/client/profile')
        ->assertOk();
});

it('configure le refresh_ttl par defaut a 10080 minutes (7 jours) selon le CDC 11.4', function () {
    expect(config('jwt.refresh_ttl'))->toBe(10080);
});

it('prouve qu en environnement de production AUCUN bypass dev ne fonctionne', function () {
    // Forcer l'environnement applicatif en 'production'
    app()->detectEnvironment(fn () => 'production');
    config(['app.env' => 'production']);

    $user = User::factory()->create([
        'phone' => '0709998877',
        'password' => Hash::make('vrai-mot-de-passe-ultra-securise'),
        'role' => 'driver',
        'is_active' => true,
    ]);

    // 1. Bypass OTP '1234' sur verify-login sans vrai code en base -> 422
    $resVerifyLogin = $this->postJson('/api/v1/auth/verify-login', [
        'phone' => '0709998877',
        'otp' => '1234',
    ]);
    $resVerifyLogin->assertStatus(422);

    // 2. Bypass OTP '1234' sur verify-register -> 422
    $resVerifyRegister = $this->postJson('/api/v1/auth/verify-register', [
        'phone' => '0709998800',
        'otp' => '1234',
    ]);
    $resVerifyRegister->assertStatus(422);

    // 3. Mots de passe de complaisance dev sur login-password -> 422
    $devPasswords = ['123456', '1234', 'password', '1234-JK-01'];
    foreach ($devPasswords as $devPassword) {
        $resPass = $this->postJson('/api/v1/auth/login-password', [
            'phone' => '0709998877',
            'password' => $devPassword,
        ]);
        $resPass->assertStatus(422)
            ->assertJsonPath('succes', false);
    }

    // 4. Inscription et connexion ne doivent JAMAIS renvoyer debug_otp en production
    $resReg = $this->postJson('/api/v1/auth/register', [
        'phone' => '0709998811',
        'name' => 'Test Prod',
    ]);
    $resReg->assertOk();
    expect($resReg->json())->not->toHaveKey('debug_otp');

    $resLog = $this->postJson('/api/v1/auth/login', [
        'phone' => '0709998877',
    ]);
    $resLog->assertOk();
    expect($resLog->json())->not->toHaveKey('debug_otp');
});
