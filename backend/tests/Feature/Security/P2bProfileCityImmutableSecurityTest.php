<?php

use App\Models\City;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\User;
use Tymon\JWTAuth\Facades\JWTAuth;

it('P2b interdit (422) le changement de city_id via /profile pour client, driver et merchant', function (string $role, string $endpoint) {
    $cityA = City::factory()->create(['name' => 'Daloa']);
    $cityB = City::factory()->create(['name' => 'Bouaké']);

    $user = User::factory()->create(['role' => $role, 'city_id' => $cityA->id]);

    $courier = null;
    if ($role === 'driver') {
        $courier = Courier::factory()->create(['user_id' => $user->id, 'city_id' => $cityA->id]);
    }

    $merchant = null;
    if ($role === 'merchant') {
        $merchant = Merchant::factory()->create(['user_id' => $user->id, 'city_id' => $cityA->id]);
    }

    $token = JWTAuth::fromUser($user);

    // 1. Route profil dédiée au rôle
    $this->withHeader('Authorization', "Bearer {$token}")
        ->putJson($endpoint, ['city_id' => $cityB->id])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['city_id']);

    // 2. Route paramétrique /profile/{user}
    $this->withHeader('Authorization', "Bearer {$token}")
        ->putJson("/api/v1/profile/{$user->id}", ['city_id' => $cityB->id])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['city_id']);

    expect($user->fresh()->city_id)->toBe($cityA->id);

    if ($courier) {
        expect($courier->fresh()->city_id)->toBe($cityA->id);
    }
    if ($merchant) {
        expect($merchant->fresh()->city_id)->toBe($cityA->id);
    }
})->with([
    'client' => ['client', '/api/v1/client/profile'],
    'driver' => ['driver', '/api/v1/driver/profile'],
    'merchant (/merchant/profile)' => ['merchant', '/api/v1/merchant/profile'],
    'merchant (/business/profile)' => ['merchant', '/api/v1/business/profile'],
]);
