<?php

use App\Models\Courier;
use App\Models\Merchant;
use App\Models\User;
use Tymon\JWTAuth\Facades\JWTAuth;

it('refuse avec 403 un client desactive disposant dun jeton deja emis', function () {
    $user = User::factory()->create([
        'role' => 'client',
        'is_active' => true,
    ]);

    // Génération du token pendant que le compte est actif
    $token = JWTAuth::fromUser($user);

    // Vérification initiale : accès accordé
    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/client/profile')
        ->assertOk();

    // Désactivation du compte
    $user->update(['is_active' => false]);

    // Même jeton déjà émis -> refusé avec 403 et message
    $res = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/client/profile');

    $res->assertStatus(403)
        ->assertJsonPath('succes', false);
    expect($res->json('message'))->toContain('désactivé');
});

it('refuse avec 403 un livreur desactive disposant dun jeton deja emis', function () {
    $user = User::factory()->create([
        'role' => 'driver',
        'is_active' => true,
    ]);
    Courier::factory()->create([
        'user_id' => $user->id,
        'is_active' => true,
    ]);

    $token = JWTAuth::fromUser($user);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/driver/courses')
        ->assertOk();

    // Désactivation
    $user->update(['is_active' => false]);

    $res = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/driver/courses');

    $res->assertStatus(403)
        ->assertJsonPath('succes', false);
    expect($res->json('message'))->toContain('désactivé');
});

it('refuse le renouvellement de token refresh pour un compte desactive', function () {
    $user = User::factory()->create([
        'role' => 'client',
        'is_active' => true,
    ]);

    $token = JWTAuth::fromUser($user);

    // Désactivation
    $user->update(['is_active' => false]);

    $res = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/auth/refresh');

    $res->assertStatus(403)
        ->assertJsonPath('succes', false);
});
