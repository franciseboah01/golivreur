<?php

use App\Models\City;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Parcel;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Tymon\JWTAuth\Facades\JWTAuth;

it('P7 Point 5 GoShop: GET /client/orders/{order}/courier-location autorise seulement proprietaire sur commande en cours et throttle 1/5s', function () {
    $city = City::factory()->create();
    $client = User::factory()->create(['role' => 'client', 'city_id' => $city->id]);
    $otherClient = User::factory()->create(['role' => 'client', 'city_id' => $city->id]);
    $driverUser = User::factory()->create(['role' => 'driver', 'city_id' => $city->id, 'phone' => '0799887766']);

    $updatedAt = now()->subSeconds(8)->startOfSecond();
    $courier = Courier::factory()->create([
        'user_id' => $driverUser->id,
        'city_id' => $city->id,
        'current_lat' => 6.881234,
        'current_lng' => -6.453210,
        'location_updated_at' => $updatedAt,
    ]);
    $merchant = Merchant::factory()->create(['city_id' => $city->id]);

    $order = Order::factory()->create([
        'client_id' => $client->id,
        'merchant_id' => $merchant->id,
        'courier_id' => $courier->id,
        'city_id' => $city->id,
        'status' => Order::STATUS_IN_TRANSIT,
    ]);

    // Middleware de throttle declare sur la route
    $route = collect(Route::getRoutes()->getRoutes())
        ->first(fn ($r) => $r->uri() === 'api/v1/client/orders/{order}/courier-location');
    $middlewares = $route ? $route->gatherMiddleware() : [];
    expect(collect($middlewares)->contains(fn ($m) => str_contains((string) $m, 'client-courier-location') || str_contains((string) $m, '12,1')))->toBeTrue();

    // Tiers -> 403
    Auth::forgetGuards();
    app()->forgetScopedInstances();
    JWTAuth::unsetToken();
    $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($otherClient))
        ->getJson("/api/v1/client/orders/{$order->id}/courier-location")
        ->assertStatus(403);

    Auth::forgetGuards();
    app()->forgetScopedInstances();
    JWTAuth::unsetToken();
    $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($driverUser))
        ->getJson("/api/v1/client/orders/{$order->id}/courier-location")
        ->assertStatus(403);

    Auth::forgetGuards();
    app()->forgetScopedInstances();
    JWTAuth::unsetToken();
    $clientToken = JWTAuth::fromUser($client);

    // Hors statut actif -> 422
    foreach ([Order::STATUS_PENDING, Order::STATUS_DELIVERED, Order::STATUS_CANCELLED, Order::STATUS_REFUSED] as $inactiveStatus) {
        $order->update(['status' => $inactiveStatus]);
        $this->withHeader('Authorization', "Bearer {$clientToken}")
            ->getJson("/api/v1/client/orders/{$order->id}/courier-location")
            ->assertStatus(422);
    }

    // Commande active mais sans livreur -> 422
    $order->update(['status' => Order::STATUS_ACCEPTED, 'courier_id' => null]);
    $this->withHeader('Authorization', "Bearer {$clientToken}")
        ->getJson("/api/v1/client/orders/{$order->id}/courier-location")
        ->assertStatus(422);

    // Nominal : commande en cours avec livreur assigne -> 200 (lat, lng, updated_at uniquement)
    $order->update(['status' => Order::STATUS_IN_TRANSIT, 'courier_id' => $courier->id]);
    RateLimiter::clear("client_courier_location:{$client->id}:order:{$order->id}");

    $res1 = $this->withHeader('Authorization', "Bearer {$clientToken}")
        ->getJson("/api/v1/client/orders/{$order->id}/courier-location")
        ->assertOk()
        ->assertJsonPath('succes', true)
        ->assertJsonPath('donnees.lat', 6.881234)
        ->assertJsonPath('donnees.lng', -6.45321);

    expect($res1->json('donnees.updated_at'))->not->toBeNull()
        ->and($res1->json('donnees'))->toHaveKeys(['lat', 'lng', 'updated_at'])
        ->and($res1->getContent())->not->toContain('0799887766');

    // Seconde requete immediate (< 5s) -> 429
    $this->withHeader('Authorization', "Bearer {$clientToken}")
        ->getJson("/api/v1/client/orders/{$order->id}/courier-location")
        ->assertStatus(429)
        ->assertJsonPath('succes', false);

    // Apres 6 secondes -> autorisee a nouveau (200)
    $this->travel(6)->seconds();
    $this->withHeader('Authorization', "Bearer {$clientToken}")
        ->getJson("/api/v1/client/orders/{$order->id}/courier-location")
        ->assertOk();
});

it('P7 Point 5 GoColis: GET /client/parcels/{parcel}/courier-location autorise seulement expediteur sur colis en cours et throttle 1/5s', function () {
    $city = City::factory()->create();
    $sender = User::factory()->create(['role' => 'client', 'city_id' => $city->id]);
    $otherClient = User::factory()->create(['role' => 'client', 'city_id' => $city->id]);
    $driverUser = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);

    $courier = Courier::factory()->create([
        'user_id' => $driverUser->id,
        'city_id' => $city->id,
        'current_lat' => 6.8755,
        'current_lng' => -6.4491,
        'location_updated_at' => now()->subSeconds(3),
    ]);

    $parcel = Parcel::factory()->create([
        'sender_id' => $sender->id,
        'courier_id' => $courier->id,
        'city_id' => $city->id,
        'status' => Parcel::STATUS_PICKED_UP,
    ]);

    // Tiers -> 403
    Auth::forgetGuards();
    app()->forgetScopedInstances();
    JWTAuth::unsetToken();
    $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($otherClient))
        ->getJson("/api/v1/client/parcels/{$parcel->id}/courier-location")
        ->assertStatus(403);

    Auth::forgetGuards();
    app()->forgetScopedInstances();
    JWTAuth::unsetToken();
    $senderToken = JWTAuth::fromUser($sender);

    // Colis non actif -> 422
    foreach ([Parcel::STATUS_PENDING, Parcel::STATUS_WAITING, Parcel::STATUS_DELIVERED, Parcel::STATUS_CANCELLED, Parcel::STATUS_LOST] as $inactiveStatus) {
        $parcel->update(['status' => $inactiveStatus]);
        $this->withHeader('Authorization', "Bearer {$senderToken}")
            ->getJson("/api/v1/client/parcels/{$parcel->id}/courier-location")
            ->assertStatus(422);
    }

    // Colis actif -> 200 puis throttle 429 < 5s
    $parcel->update(['status' => Parcel::STATUS_PICKED_UP]);
    RateLimiter::clear("client_courier_location:{$sender->id}:parcel:{$parcel->id}");

    $res = $this->withHeader('Authorization', "Bearer {$senderToken}")
        ->getJson("/api/v1/client/parcels/{$parcel->id}/courier-location")
        ->assertOk()
        ->assertJsonPath('succes', true)
        ->assertJsonPath('donnees.lat', 6.8755)
        ->assertJsonPath('donnees.lng', -6.4491);

    expect($res->json('donnees.updated_at'))->not->toBeNull();

    $this->withHeader('Authorization', "Bearer {$senderToken}")
        ->getJson("/api/v1/client/parcels/{$parcel->id}/courier-location")
        ->assertStatus(429)
        ->assertJsonPath('succes', false);

    $this->travel(6)->seconds();
    $this->withHeader('Authorization', "Bearer {$senderToken}")
        ->getJson("/api/v1/client/parcels/{$parcel->id}/courier-location")
        ->assertOk();
});
