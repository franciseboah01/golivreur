<?php

use App\Models\City;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\User;
use Carbon\Carbon;
use Tymon\JWTAuth\Facades\JWTAuth;

afterEach(function () {
    Carbon::setTestNow();
});

it('P6 Point 6: rejette 422 si commande GoShop hors open_hours configure et autorise dedans ou sans horaires', function () {
    Carbon::setTestNow(Carbon::create(2026, 11, 16, 23, 15, 0, 'Africa/Abidjan')); // Lundi 23h15

    $city = City::factory()->create(['emergency_mode_active' => false]);
    $client = User::factory()->create(['role' => 'client', 'city_id' => $city->id]);
    $merchant = Merchant::factory()->create([
        'city_id' => $city->id,
        'is_open' => true,
        'is_active' => true,
        'lat' => 6.8770,
        'lng' => -6.4502,
        'open_hours' => [
            'monday' => '08:00 - 21:00',
            'tuesday' => '08:00 - 21:00',
            'sunday' => 'closed',
        ],
    ]);
    $product = Product::factory()->create([
        'merchant_id' => $merchant->id,
        'price' => 2000,
        'is_available' => true,
    ]);

    $token = JWTAuth::fromUser($client);
    $payload = [
        'merchant_id' => $merchant->id,
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
        'delivery_address' => 'Quartier Lobia',
        'delivery_lat' => 6.8800,
        'delivery_lng' => -6.4510,
        'payment_method' => 'cash',
    ];

    // 1. Lundi 23h15 hors plage 08:00 - 21:00 -> 422
    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/client/orders', $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['merchant_id']);

    // 2. Dimanche marque 'closed' -> 422 meme en journee
    Carbon::setTestNow(Carbon::create(2026, 11, 15, 12, 0, 0, 'Africa/Abidjan')); // Dimanche 12h00
    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/client/orders', $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['merchant_id']);

    // 3. Lundi 12h30 dans la plage 08:00 - 21:00 -> 201
    Carbon::setTestNow(Carbon::create(2026, 11, 16, 12, 30, 0, 'Africa/Abidjan')); // Lundi 12h30
    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/client/orders', $payload)
        ->assertStatus(201);

    // 4. Commerce sans open_hours configure (null ou []) reste non bloque
    $merchant->update(['open_hours' => null]);
    Carbon::setTestNow(Carbon::create(2026, 11, 16, 23, 45, 0, 'Africa/Abidjan'));
    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/client/orders', $payload)
        ->assertStatus(201);
});
