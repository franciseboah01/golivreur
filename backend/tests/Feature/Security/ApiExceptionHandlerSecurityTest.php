<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Tymon\JWTAuth\Facades\JWTAuth;

it('renvoie un JSON standard 404 pour une route api inexistante', function () {
    $res = $this->getJson('/api/v1/nonexistent-endpoint-xyz');

    $res->assertStatus(404)
        ->assertJson([
            'succes' => false,
            'message' => 'Ressource introuvable.',
        ]);
});

it('renvoie un JSON standard 404 sans fuite de namespace Eloquent pour un ModelNotFound sur commande inexistante', function () {
    $user = User::factory()->create(['role' => 'client', 'is_active' => true]);
    $token = JWTAuth::fromUser($user);

    $res = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/client/orders/999999');

    $res->assertStatus(404)
        ->assertJson([
            'succes' => false,
            'message' => 'Ressource introuvable.',
        ]);

    expect($res->content())->not->toContain('App\\Models\\Order');
});

it('renvoie un JSON standard 500 sans fuite de trace ni chemin en production', function () {
    config(['app.debug' => false]);

    Route::get('/api/v1/test-error-throwable', function () {
        throw new RuntimeException('Erreur interne de base de donnees fatale /var/www/secret/db.php');
    });

    $res = $this->getJson('/api/v1/test-error-throwable');

    $res->assertStatus(500)
        ->assertJson([
            'succes' => false,
            'message' => 'Une erreur interne est survenue.',
        ]);

    expect($res->content())->not->toContain('/var/www/secret')
        ->not->toContain('RuntimeException')
        ->not->toContain('trace');
});

it('maintient fonctionnel le endpoint public /health conforme et non perturbe', function () {
    $res = $this->getJson('/api/v1/health');

    $res->assertOk()
        ->assertJson([
            'status' => 'ok',
            'version' => '5.0',
            'service' => 'GoLivreur API',
        ]);
});
