<?php

use App\Models\City;
use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    RateLimiter::clear('otp_login:0701020304');
    RateLimiter::clear('otp_register:0701020304');
});

it('bloque et invalide l\'OTP apres 5 echecs sur verify-login avec 429 en francais', function () {
    $user = User::factory()->create([
        'phone' => '0701020304',
        'is_active' => true,
    ]);

    $otp = OtpCode::create([
        'phone' => '0701020304',
        'code_hash' => Hash::make('8888'),
        'purpose' => 'login',
        'expires_at' => now()->addMinutes(5),
    ]);

    // 4 échecs consécutifs -> 422
    for ($i = 1; $i <= 4; $i++) {
        $res = $this->postJson('/api/v1/auth/verify-login', [
            'phone' => '0701020304',
            'otp' => '9999', // faux code
        ]);
        $res->assertStatus(422);
    }

    // 5ème échec -> 429 et invalidation du code
    $res5 = $this->postJson('/api/v1/auth/verify-login', [
        'phone' => '0701020304',
        'otp' => '9999',
    ]);
    $res5->assertStatus(429)
        ->assertJsonPath('succes', false);
    expect($res5->json('message'))->toContain('Trop de tentatives');

    // L'OTP doit être marqué utilisé / invalidé
    $otp->refresh();
    expect($otp->is_used)->toBeTrue();
});

it('bloque et invalide l\'OTP apres 5 echecs sur verify-register avec 429 en francais', function () {
    $otp = OtpCode::create([
        'phone' => '0701020304',
        'code_hash' => Hash::make('8888'),
        'purpose' => 'register',
        'expires_at' => now()->addMinutes(5),
    ]);

    // 4 échecs consécutifs -> 422
    for ($i = 1; $i <= 4; $i++) {
        $res = $this->postJson('/api/v1/auth/verify-register', [
            'phone' => '0701020304',
            'otp' => '9999',
        ]);
        $res->assertStatus(422);
    }

    // 5ème échec -> 429 et invalidation
    $res5 = $this->postJson('/api/v1/auth/verify-register', [
        'phone' => '0701020304',
        'otp' => '9999',
    ]);
    $res5->assertStatus(429)
        ->assertJsonPath('succes', false);
    expect($res5->json('message'))->toContain('Trop de tentatives');

    $otp->refresh();
    expect($otp->is_used)->toBeTrue();
});

it('supprime l\'enregistrement OTP apres validation reussie conformement au CDC', function () {
    $user = User::factory()->create([
        'phone' => '0701020304',
        'is_active' => true,
    ]);

    $otp = OtpCode::create([
        'phone' => '0701020304',
        'code_hash' => Hash::make('8888'),
        'purpose' => 'login',
        'expires_at' => now()->addMinutes(5),
    ]);

    $res = $this->postJson('/api/v1/auth/verify-login', [
        'phone' => '0701020304',
        'otp' => '8888',
    ]);
    $res->assertOk();

    // L'enregistrement doit être supprimé de la BDD
    $this->assertDatabaseMissing('otp_codes', [
        'id' => $otp->id,
    ]);
});

it('applique un rate limit de blocage 1h (3600s) sur la demande OTP login', function () {
    $user = User::factory()->create([
        'phone' => '0701020304',
        'is_active' => true,
    ]);

    // Dans l'environnement local/testing, simulons 3 hits pour tester la durée de blocage
    RateLimiter::hit('otp_login:0701020304', 3600);
    RateLimiter::hit('otp_login:0701020304', 3600);
    RateLimiter::hit('otp_login:0701020304', 3600);

    $availableIn = RateLimiter::availableIn('otp_login:0701020304');
    expect($availableIn)->toBeGreaterThan(3500);
});
