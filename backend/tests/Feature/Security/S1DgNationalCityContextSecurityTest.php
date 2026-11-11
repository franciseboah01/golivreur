<?php

use App\Models\City;
use App\Models\User;
use Tymon\JWTAuth\Facades\JWTAuth;

it('S1 modèle: dg_national est reconnu admin national, canAccessCity et timeout 120', function () {
    $city = City::factory()->create(['name' => 'Daloa']);
    $dg = User::factory()->create([
        'role' => 'dg_national',
        'city_id' => null,
    ]);

    expect($dg->is_admin)->toBeTrue()
        ->and($dg->is_national_admin)->toBeTrue()
        ->and($dg->canAccessCity($city->id))->toBeTrue()
        ->and($dg->getSessionTimeout())->toBe(120);
});

it('S1 HTTP: dg_national sans city_id accède aux routes admin dashboard et emergency-mode', function () {
    $city = City::factory()->create(['name' => 'Daloa']);
    $dg = User::factory()->create([
        'role' => 'dg_national',
        'city_id' => null,
    ]);

    $token = JWTAuth::fromUser($dg);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/admin/dashboard')
        ->assertOk()
        ->assertJsonPath('succes', true);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/admin/cities/{$city->id}/emergency-mode", ['activer' => true])
        ->assertOk()
        ->assertJsonPath('succes', true);
});
