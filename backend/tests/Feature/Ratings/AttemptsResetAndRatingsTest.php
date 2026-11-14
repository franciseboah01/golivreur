<?php

use App\Models\City;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Parcel;
use App\Models\ParcelPhoto;
use App\Models\Rating;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Tymon\JWTAuth\Facades\JWTAuth;

it('P7 Point 6: remet les compteurs confirmation_attempts, sender_code_attempts et recipient_code_attempts a 0 apres succes', function () {
    Queue::fake();

    $city = City::factory()->create();
    $client = User::factory()->create(['role' => 'client', 'city_id' => $city->id]);
    $merchantUser = User::factory()->create(['role' => 'merchant', 'city_id' => $city->id]);
    $merchant = Merchant::factory()->create(['user_id' => $merchantUser->id, 'city_id' => $city->id]);
    $driverUser = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    $courier = Courier::factory()->create(['user_id' => $driverUser->id, 'city_id' => $city->id]);
    Wallet::create(['owner_type' => 'courier', 'owner_id' => $courier->id, 'balance' => 30000]);

    $order = Order::factory()->create([
        'client_id' => $client->id,
        'merchant_id' => $merchant->id,
        'courier_id' => $courier->id,
        'city_id' => $city->id,
        'status' => Order::STATUS_PICKED_UP,
        'payment_method' => 'mobile_money',
        'payment_status' => 'paid',
        'confirmation_code' => Hash::make('1234'),
        'confirmation_attempts' => 0,
        'confirmation_locked' => false,
    ]);

    $driverToken = JWTAuth::fromUser($driverUser);

    // 2 echecs -> attempts = 2, puis succes -> attempts remis a 0
    $this->withHeader('Authorization', "Bearer {$driverToken}")
        ->postJson("/api/v1/driver/orders/{$order->id}/confirm-delivery", ['code' => '0000'])
        ->assertStatus(422);
    $this->withHeader('Authorization', "Bearer {$driverToken}")
        ->postJson("/api/v1/driver/orders/{$order->id}/confirm-delivery", ['code' => '0000'])
        ->assertStatus(422);

    expect($order->fresh()->confirmation_attempts)->toBe(2);

    $this->withHeader('Authorization', "Bearer {$driverToken}")
        ->postJson("/api/v1/driver/orders/{$order->id}/confirm-delivery", ['code' => '1234'])
        ->assertOk();

    expect($order->fresh()->status)->toBe(Order::STATUS_DELIVERED)
        ->and($order->fresh()->confirmation_attempts)->toBe(0);

    // GoColis : sender_code_attempts et recipient_code_attempts remis a 0 apres succes
    $parcel = Parcel::factory()->create([
        'sender_id' => $client->id,
        'courier_id' => $courier->id,
        'city_id' => $city->id,
        'status' => Parcel::STATUS_AT_SENDER,
        'payment_method' => 'mobile_money',
        'sender_code' => Hash::make('4321'),
        'sender_code_attempts' => 0,
        'recipient_code' => Hash::make('8765'),
        'recipient_code_attempts' => 0,
    ]);
    ParcelPhoto::create(['parcel_id' => $parcel->id, 'type' => 'pickup', 'photo_url' => 'https://cdn.example/p.jpg']);
    ParcelPhoto::create(['parcel_id' => $parcel->id, 'type' => 'delivery', 'photo_url' => 'https://cdn.example/d.jpg']);

    $this->withHeader('Authorization', "Bearer {$driverToken}")
        ->postJson("/api/v1/parcels/{$parcel->id}/verify-sender-code", ['code' => '9999'])
        ->assertStatus(422);
    $this->withHeader('Authorization', "Bearer {$driverToken}")
        ->postJson("/api/v1/parcels/{$parcel->id}/verify-sender-code", ['code' => '9999'])
        ->assertStatus(422);
    expect($parcel->fresh()->sender_code_attempts)->toBe(2);

    $this->withHeader('Authorization', "Bearer {$driverToken}")
        ->postJson("/api/v1/parcels/{$parcel->id}/verify-sender-code", ['code' => '4321'])
        ->assertOk();
    expect($parcel->fresh()->sender_code_attempts)->toBe(0);

    $this->withHeader('Authorization', "Bearer {$driverToken}")
        ->postJson("/api/v1/parcels/{$parcel->id}/verify-recipient-code", ['code' => '0000'])
        ->assertStatus(422);
    expect($parcel->fresh()->recipient_code_attempts)->toBe(1);

    $this->withHeader('Authorization', "Bearer {$driverToken}")
        ->postJson("/api/v1/parcels/{$parcel->id}/verify-recipient-code", ['code' => '8765'])
        ->assertOk();
    expect($parcel->fresh()->recipient_code_attempts)->toBe(0);
});

it('P7 Point 6: enregistre type gocolis_courier sur colis et permet la notation du commerce (1-5, livree, unique)', function () {
    $city = City::factory()->create();
    $client = User::factory()->create(['role' => 'client', 'city_id' => $city->id]);
    $otherClient = User::factory()->create(['role' => 'client', 'city_id' => $city->id]);
    $merchant = Merchant::factory()->create([
        'city_id' => $city->id,
        'average_rating' => 4.0,
        'total_ratings' => 2,
    ]);
    $courier = Courier::factory()->create([
        'city_id' => $city->id,
        'average_rating' => 5.0,
        'total_ratings' => 1,
    ]);

    $parcel = Parcel::factory()->create([
        'sender_id' => $client->id,
        'courier_id' => $courier->id,
        'city_id' => $city->id,
        'status' => Parcel::STATUS_DELIVERED,
    ]);

    $pendingOrder = Order::factory()->create([
        'client_id' => $client->id,
        'merchant_id' => $merchant->id,
        'courier_id' => $courier->id,
        'city_id' => $city->id,
        'status' => Order::STATUS_PICKED_UP,
    ]);

    $deliveredOrder = Order::factory()->create([
        'client_id' => $client->id,
        'merchant_id' => $merchant->id,
        'courier_id' => $courier->id,
        'city_id' => $city->id,
        'status' => Order::STATUS_DELIVERED,
    ]);

    $clientToken = JWTAuth::fromUser($client);

    // Notation colis -> type = gocolis_courier
    $this->withHeader('Authorization', "Bearer {$clientToken}")
        ->postJson('/api/v1/client/ratings', [
            'courier_id' => $courier->id,
            'parcel_id' => $parcel->id,
            'rating' => 4,
        ])
        ->assertCreated();

    $parcelRating = Rating::where('parcel_id', $parcel->id)->first();
    expect($parcelRating)->not->toBeNull()
        ->and($parcelRating->type)->toBe('gocolis_courier');

    // Notation commerce refusee hors [1..5] -> 422
    $this->withHeader('Authorization', "Bearer {$clientToken}")
        ->postJson('/api/v1/client/ratings/merchant', [
            'order_id' => $deliveredOrder->id,
            'rating' => 6,
        ])
        ->assertStatus(422);

    $this->withHeader('Authorization', "Bearer {$clientToken}")
        ->postJson('/api/v1/client/ratings/merchant', [
            'order_id' => $deliveredOrder->id,
            'rating' => 0,
        ])
        ->assertStatus(422);

    // Notation commerce refusee si non livree -> 422
    $this->withHeader('Authorization', "Bearer {$clientToken}")
        ->postJson('/api/v1/client/ratings/merchant', [
            'order_id' => $pendingOrder->id,
            'rating' => 5,
        ])
        ->assertStatus(422);

    // Notation commerce refusee pour un tiers -> 403
    Auth::forgetGuards();
    app()->forgetScopedInstances();
    JWTAuth::unsetToken();
    $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($otherClient))
        ->postJson('/api/v1/client/ratings/merchant', [
            'order_id' => $deliveredOrder->id,
            'rating' => 5,
        ])
        ->assertStatus(403);

    // Notation commerce nominale -> 201 + type goshop_merchant + mise a jour moyenne
    Auth::forgetGuards();
    app()->forgetScopedInstances();
    JWTAuth::unsetToken();
    $this->withHeader('Authorization', "Bearer {$clientToken}")
        ->postJson('/api/v1/client/ratings/merchant', [
            'order_id' => $deliveredOrder->id,
            'rating' => 5,
            'comment' => 'Repas chaud et bien emballe',
        ])
        ->assertCreated()
        ->assertJsonPath('succes', true);

    $merchantRating = Rating::where('order_id', $deliveredOrder->id)
        ->where('type', 'goshop_merchant')
        ->first();
    expect($merchantRating)->not->toBeNull()
        ->and((int) $merchantRating->rateable_id)->toBe($merchant->id);

    // (4.0 * 2 + 5) / 3 = 4.33
    expect($merchant->fresh()->total_ratings)->toBe(3)
        ->and((float) $merchant->fresh()->average_rating)->toBe(4.33);

    // Double notation du commerce sur la meme commande -> 409
    $this->withHeader('Authorization', "Bearer {$clientToken}")
        ->postJson('/api/v1/client/ratings/merchant', [
            'order_id' => $deliveredOrder->id,
            'rating' => 3,
        ])
        ->assertStatus(409);

    // Notation du livreur sur la MEME commande reste autorisee -> 201 (type goshop_courier)
    $this->withHeader('Authorization', "Bearer {$clientToken}")
        ->postJson('/api/v1/client/ratings', [
            'courier_id' => $courier->id,
            'order_id' => $deliveredOrder->id,
            'rating' => 5,
        ])
        ->assertCreated();

    expect(Rating::where('order_id', $deliveredOrder->id)->where('type', 'goshop_courier')->exists())->toBeTrue();
});
