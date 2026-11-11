<?php

use App\Models\City;
use App\Models\Courier;
use App\Models\User;
use Tymon\JWTAuth\Facades\JWTAuth;

it('S6 availability: refuse 403 si le livreur est suspendu, pénalisé ou a 3 refus consécutifs', function () {
    $city = City::factory()->create();

    // 1. Livreur suspendu (is_active = false) -> refusé
    $suspendedUser = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    $suspendedCourier = Courier::factory()->create([
        'user_id' => $suspendedUser->id,
        'city_id' => $city->id,
        'is_active' => false,
        'is_online' => false,
    ]);

    $this->actingAs($suspendedUser, 'api')
        ->postJson('/api/v1/driver/availability', ['is_online' => true])
        ->assertStatus(403)
        ->assertJsonPath('succes', false);

    expect($suspendedCourier->fresh()->is_online)->toBeFalse();

    // 2. Livreur pénalisé (penalty_until futur) -> refusé (empêche le contournement de la sanction)
    $penalizedUser = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    $penalizedCourier = Courier::factory()->create([
        'user_id' => $penalizedUser->id,
        'city_id' => $city->id,
        'is_active' => true,
        'is_online' => false,
        'penalty_until' => now()->addMinutes(12),
    ]);

    $this->actingAs($penalizedUser, 'api')
        ->postJson('/api/v1/driver/availability', ['is_online' => true])
        ->assertStatus(403)
        ->assertJsonPath('succes', false);

    expect($penalizedCourier->fresh()->is_online)->toBeFalse();

    // 3. Livreur avec 3 refus consécutifs sans penalty_until -> applique 15 min + refuse 403
    $threeRefusalsUser = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    $threeRefusalsCourier = Courier::factory()->create([
        'user_id' => $threeRefusalsUser->id,
        'city_id' => $city->id,
        'is_active' => true,
        'is_online' => false,
        'consecutive_refusals' => 3,
        'penalty_until' => null,
    ]);

    $this->actingAs($threeRefusalsUser, 'api')
        ->postJson('/api/v1/driver/availability', ['is_online' => true])
        ->assertStatus(403);

    $threeRefusalsCourier->refresh();
    expect($threeRefusalsCourier->is_online)->toBeFalse()
        ->and($threeRefusalsCourier->penalty_until)->not->toBeNull()
        ->and($threeRefusalsCourier->penalty_until->isFuture())->toBeTrue();
});

it('S6 location et nominal: refuse updateLocation si inactif et autorise mise en ligne si pénalité expirée', function () {
    $city = City::factory()->create();

    $inactiveUser = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    Courier::factory()->create(['user_id' => $inactiveUser->id, 'city_id' => $city->id, 'is_active' => false]);

    $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($inactiveUser))
        ->postJson('/api/v1/driver/location', ['lat' => 6.87, 'lng' => -6.45])
        ->assertStatus(403);

    $expiredUser = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    $expiredCourier = Courier::factory()->create([
        'user_id' => $expiredUser->id,
        'city_id' => $city->id,
        'is_active' => true,
        'is_online' => false,
        'consecutive_refusals' => 3,
        'penalty_until' => now()->subMinute(),
    ]);

    $this->actingAs($expiredUser, 'api')
        ->postJson('/api/v1/driver/availability', ['is_online' => true])
        ->assertOk()
        ->assertJsonPath('succes', true);

    expect($expiredCourier->fresh()->is_online)->toBeTrue()
        ->and($expiredCourier->fresh()->consecutive_refusals)->toBe(0)
        ->and($expiredCourier->fresh()->penalty_until)->toBeNull();
});
