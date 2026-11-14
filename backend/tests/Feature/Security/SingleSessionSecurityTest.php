<?php

use App\Models\Courier;
use App\Models\Merchant;
use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('invalide l\'ancien JWT lors d\'une nouvelle connexion d\'un livreur et refuse l\'ancien avec 401', function () {
    $user = User::factory()->create([
        'phone' => '0700010203',
        'role' => 'driver',
        'is_active' => true,
    ]);
    Courier::factory()->create([
        'user_id' => $user->id,
        'is_active' => true,
    ]);

    // Première connexion (Appareil A)
    $codeA = '1111';
    OtpCode::create([
        'phone' => $user->phone,
        'code_hash' => Hash::make($codeA),
        'purpose' => 'login',
        'expires_at' => now()->addMinutes(5),
    ]);
    $resA = $this->postJson('/api/v1/auth/verify-login', [
        'phone' => $user->phone,
        'otp' => $codeA,
    ])->assertOk();
    $tokenA = $resA->json('donnees.access_token');

    // Appareil A peut accéder aux courses
    $this->withHeader('Authorization', "Bearer {$tokenA}")
        ->getJson('/api/v1/driver/courses')
        ->assertOk();

    // Seconde connexion (Appareil B)
    $codeB = '2222';
    OtpCode::create([
        'phone' => $user->phone,
        'code_hash' => Hash::make($codeB),
        'purpose' => 'login',
        'expires_at' => now()->addMinutes(5),
    ]);
    $resB = $this->postJson('/api/v1/auth/verify-login', [
        'phone' => $user->phone,
        'otp' => $codeB,
    ])->assertOk();
    $tokenB = $resB->json('donnees.access_token');

    // Appareil B a accès
    $this->withHeader('Authorization', "Bearer {$tokenB}")
        ->getJson('/api/v1/driver/courses')
        ->assertOk();

    // Appareil A avec ancien jeton doit être rejeté en 401
    $resOld = $this->withHeader('Authorization', "Bearer {$tokenA}")
        ->getJson('/api/v1/driver/courses');

    $resOld->assertStatus(401)
        ->assertJsonPath('succes', false);
    expect($resOld->json('message'))->toContain('Session');
});

it('invalide l\'ancien JWT lors d\'une nouvelle connexion d\'un commercant et refuse l\'ancien avec 401', function () {
    $user = User::factory()->create([
        'phone' => '0700040506',
        'role' => 'merchant',
        'is_active' => true,
    ]);
    Merchant::factory()->create([
        'user_id' => $user->id,
        'is_active' => true,
    ]);

    // Première connexion
    $codeA = '3333';
    OtpCode::create([
        'phone' => $user->phone,
        'code_hash' => Hash::make($codeA),
        'purpose' => 'login',
        'expires_at' => now()->addMinutes(5),
    ]);
    $resA = $this->postJson('/api/v1/auth/verify-login', [
        'phone' => $user->phone,
        'otp' => $codeA,
    ])->assertOk();
    $tokenA = $resA->json('donnees.access_token');

    $this->withHeader('Authorization', "Bearer {$tokenA}")
        ->getJson('/api/v1/merchant/status')
        ->assertOk();

    // Deuxième connexion
    $codeB = '4444';
    OtpCode::create([
        'phone' => $user->phone,
        'code_hash' => Hash::make($codeB),
        'purpose' => 'login',
        'expires_at' => now()->addMinutes(5),
    ]);
    $resB = $this->postJson('/api/v1/auth/verify-login', [
        'phone' => $user->phone,
        'otp' => $codeB,
    ])->assertOk();
    $tokenB = $resB->json('donnees.access_token');

    // Nouveau token -> OK
    $this->withHeader('Authorization', "Bearer {$tokenB}")
        ->getJson('/api/v1/merchant/status')
        ->assertOk();

    // Ancien token -> 401
    $resOld = $this->withHeader('Authorization', "Bearer {$tokenA}")
        ->getJson('/api/v1/merchant/status');

    $resOld->assertStatus(401)
        ->assertJsonPath('succes', false);
});

it('autorise le multi-appareils pour les clients sans invalider les anciens tokens', function () {
    $user = User::factory()->create([
        'phone' => '0700070809',
        'role' => 'client',
        'is_active' => true,
    ]);

    // Connexion A
    $codeA = '5555';
    OtpCode::create([
        'phone' => $user->phone,
        'code_hash' => Hash::make($codeA),
        'purpose' => 'login',
        'expires_at' => now()->addMinutes(5),
    ]);
    $resA = $this->postJson('/api/v1/auth/verify-login', [
        'phone' => $user->phone,
        'otp' => $codeA,
    ])->assertOk();
    $tokenA = $resA->json('donnees.access_token');

    // Connexion B
    $codeB = '6666';
    OtpCode::create([
        'phone' => $user->phone,
        'code_hash' => Hash::make($codeB),
        'purpose' => 'login',
        'expires_at' => now()->addMinutes(5),
    ]);
    $resB = $this->postJson('/api/v1/auth/verify-login', [
        'phone' => $user->phone,
        'otp' => $codeB,
    ])->assertOk();
    $tokenB = $resB->json('donnees.access_token');

    // Pour un client, les DEUX tokens restent valides
    $this->withHeader('Authorization', "Bearer {$tokenA}")
        ->getJson('/api/v1/client/profile')
        ->assertOk();

    $this->withHeader('Authorization', "Bearer {$tokenB}")
        ->getJson('/api/v1/client/profile')
        ->assertOk();
});
