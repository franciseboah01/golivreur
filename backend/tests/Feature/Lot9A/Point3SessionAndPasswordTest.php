<?php

use App\Models\City;
use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tymon\JWTAuth\Facades\JWTAuth;

it('Point 3 : refresh token glissant 7j avec rotation et rejet de reutilisation sans OTP', function () {
    $city = City::factory()->create(['name' => 'Daloa']);
    $client = User::factory()->create([
        'phone' => '0701770011',
        'password' => Hash::make('Pass2026!'),
        'role' => 'client',
        'city_id' => $city->id,
        'is_active' => true,
    ]);

    $loginRes = $this->postJson('/api/v1/auth/login-password', [
        'phone' => '0701770011',
        'password' => 'Pass2026!',
    ])->assertOk();

    $refresh1 = (string) $loginRes->json('donnees.refresh_token');
    expect($refresh1)->not->toBeEmpty();

    // Avancer le temps de 3 jours -> le renouvellement repousse l'échéance de 7 jours complets
    $this->travel(3)->days();

    $refreshRes = $this->postJson('/api/v1/auth/refresh', [
        'refresh_token' => $refresh1,
    ])->assertOk();

    $refresh2 = (string) $refreshRes->json('donnees.refresh_token');
    $expiresAt = (string) $refreshRes->json('donnees.refresh_expires_at');
    expect($refresh2)->not->toBeEmpty()
        ->and($refresh2)->not->toBe($refresh1)
        ->and($expiresAt)->not->toBeEmpty();

    // L'échéance glissante est bien à ~7 jours à partir de maintenant (soit J+10 depuis le login)
    $parsedExp = \Illuminate\Support\Carbon::parse($expiresAt);
    expect($parsedExp->diffInDays(now(), true))->toBeGreaterThanOrEqual(6.9);

    // Réutilisation de l'ancien refresh token -> 401 et révocation de toute la famille
    $this->postJson('/api/v1/auth/refresh', [
        'refresh_token' => $refresh1,
    ])->assertStatus(401);

    // Le refresh2 de la même famille a aussi été révoqué par détection de rejeu
    $this->postJson('/api/v1/auth/refresh', [
        'refresh_token' => $refresh2,
    ])->assertStatus(401);
});

it('Point 3 : compte sans mot de passe obtient une session apres OTP, password_set dans profil et POST /me/password', function () {
    $city = City::factory()->create(['name' => 'Daloa']);
    $client = User::factory()->create([
        'phone' => '0701770022',
        'password' => null,
        'role' => 'client',
        'city_id' => $city->id,
        'is_active' => true,
    ]);

    OtpCode::factory()->withCode('4567')->create([
        'phone' => '0701770022',
        'purpose' => 'login',
        'expires_at' => now()->addMinutes(5),
    ]);

    $verifyRes = $this->postJson('/api/v1/auth/verify-login', [
        'phone' => '0701770022',
        'otp' => '4567',
    ])->assertOk()
        ->assertJsonPath('donnees.user.password_set', false);

    $token = (string) $verifyRes->json('donnees.access_token');

    // Profil renvoie password_set = false
    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/client/profile')
        ->assertOk()
        ->assertJsonPath('donnees.password_set', false);

    // Définir un premier mot de passe via POST /me/password sans ancien mot de passe
    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/me/password', [
            'password' => 'MonNouveauPass2026',
            'password_confirmation' => 'MonNouveauPass2026',
        ])
        ->assertOk()
        ->assertJsonPath('donnees.password_set', true);

    expect($client->fresh()->password)->not->toBeNull()
        ->and(Hash::check('MonNouveauPass2026', (string) $client->fresh()->password))->toBeTrue();

    // Profil renvoie maintenant password_set = true
    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/client/profile')
        ->assertOk()
        ->assertJsonPath('donnees.password_set', true);

    // Changer le mot de passe existant exige current_password -> 422 sans current_password
    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/me/password', [
            'password' => 'AutrePass2026!',
            'password_confirmation' => 'AutrePass2026!',
        ])
        ->assertStatus(422);

    // Avec le bon current_password -> 200
    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/me/password', [
            'current_password' => 'MonNouveauPass2026',
            'password' => 'AutrePass2026!',
            'password_confirmation' => 'AutrePass2026!',
        ])
        ->assertOk()
        ->assertJsonPath('donnees.password_set', true);

    expect(Hash::check('AutrePass2026!', (string) $client->fresh()->password))->toBeTrue();
});
