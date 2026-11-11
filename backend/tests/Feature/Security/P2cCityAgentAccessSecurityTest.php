<?php

use App\Models\City;
use App\Models\User;
use Tymon\JWTAuth\Facades\JWTAuth;

it('P2c refuse 403 au rôle city_agent sur /admin/orders et /admin/dashboard', function () {
    $city = City::factory()->create(['name' => 'Daloa']);
    $agent = User::factory()->create(['role' => 'city_agent', 'city_id' => $city->id]);
    $token = JWTAuth::fromUser($agent);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/admin/orders')
        ->assertStatus(403)
        ->assertJsonPath('succes', false);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/admin/dashboard')
        ->assertStatus(403)
        ->assertJsonPath('succes', false);
});

it('P2c conserve l\'accès 200 aux rôles admin habilités (city_support, city_finance, city_manager, super_admin)', function (string $role) {
    $city = City::factory()->create(['name' => 'Daloa']);
    $user = User::factory()->create([
        'role' => $role,
        'city_id' => $role === 'super_admin' ? null : $city->id,
    ]);
    $token = JWTAuth::fromUser($user);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/admin/orders')
        ->assertOk()
        ->assertJsonPath('succes', true);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/admin/dashboard')
        ->assertOk()
        ->assertJsonPath('succes', true);
})->with(['city_support', 'city_finance', 'city_manager', 'super_admin']);
