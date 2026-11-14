<?php

use App\Models\Courier;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Tymon\JWTAuth\Facades\JWTAuth;

beforeEach(function () {
    RateLimiter::clear('driver_location:1');
});

it('valide strictement les coordonnees GPS entre -90/90 et -180/180 sur driver/location', function () {
    $user = User::factory()->create(['role' => 'driver', 'is_active' => true]);
    $courier = Courier::factory()->create([
        'user_id' => $user->id,
        'is_active' => true,
    ]);
    $token = JWTAuth::fromUser($user);

    // Latitude > 90 -> 422
    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/driver/location', ['lat' => 90.001, 'lng' => 0])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['lat']);

    // Latitude < -90 -> 422
    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/driver/location', ['lat' => -90.001, 'lng' => 0])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['lat']);

    // Longitude > 180 -> 422
    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/driver/location', ['lat' => 0, 'lng' => 180.001])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['lng']);

    // Longitude < -180 -> 422
    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/driver/location', ['lat' => 0, 'lng' => -180.001])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['lng']);

    // Coordonnées valides -> 200
    RateLimiter::clear("driver_location:{$courier->id}");
    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/driver/location', ['lat' => 6.87, 'lng' => -6.45])
        ->assertOk()
        ->assertJsonPath('succes', true);
});

it('bloque les requetes de positionnement livreur a plus de 1 requete par 5 secondes', function () {
    $user = User::factory()->create(['role' => 'driver', 'is_active' => true]);
    $courier = Courier::factory()->create([
        'user_id' => $user->id,
        'is_active' => true,
    ]);
    $token = JWTAuth::fromUser($user);
    RateLimiter::clear("driver_location:{$courier->id}");

    // Première requête -> OK
    $res1 = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/driver/location', ['lat' => 6.87, 'lng' => -6.45]);
    $res1->assertOk();

    // Deuxième requête immédiate (< 5s) -> 429
    $res2 = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/driver/location', ['lat' => 6.88, 'lng' => -6.46]);
    $res2->assertStatus(429)
        ->assertJsonPath('succes', false);
});

it('configure les throttles de groupe auth a 60 par minute et public a 20 par minute', function () {
    $authRoute = collect(Route::getRoutes()->getRoutes())
        ->first(fn ($r) => $r->uri() === 'api/v1/auth/login');

    $publicRoute = collect(Route::getRoutes()->getRoutes())
        ->first(fn ($r) => $r->uri() === 'api/v1/cities');

    $driverLocationRoute = collect(Route::getRoutes()->getRoutes())
        ->first(fn ($r) => $r->uri() === 'api/v1/driver/location');

    $authMiddlewares = $authRoute ? $authRoute->gatherMiddleware() : [];
    $publicMiddlewares = $publicRoute ? $publicRoute->gatherMiddleware() : [];
    $driverLocationMiddlewares = $driverLocationRoute ? $driverLocationRoute->gatherMiddleware() : [];

    expect(collect($authMiddlewares)->contains(fn ($m) => str_contains((string) $m, '60,1') || str_contains((string) $m, 'throttle:60')))->toBeTrue();
    expect(collect($publicMiddlewares)->contains(fn ($m) => str_contains((string) $m, '20,1') || str_contains((string) $m, 'throttle:20')))->toBeTrue();
    expect(collect($driverLocationMiddlewares)->contains(fn ($m) => str_contains((string) $m, 'driver-location') || str_contains((string) $m, '12,1')))->toBeTrue();
});
