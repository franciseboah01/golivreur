<?php

use App\Models\City;
use App\Models\ClientAddress;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Parcel;
use App\Models\ParcelPhoto;
use App\Models\Product;
use App\Models\User;
use App\Models\WebhookLog;
use Illuminate\Support\Facades\Hash;
use Tymon\JWTAuth\Facades\JWTAuth;

it('balayage IDOR horizontal & vertical famille CLIENT (orders, parcels, addresses, notifications, ratings)', function () {
    $city = City::factory()->create();
    $clientA = User::factory()->create(['role' => 'client', 'city_id' => $city->id]);
    $clientB = User::factory()->create(['role' => 'client', 'city_id' => $city->id]);

    $orderB = Order::factory()->create(['city_id' => $city->id, 'client_id' => $clientB->id]);
    $parcelB = Parcel::factory()->create(['city_id' => $city->id, 'sender_id' => $clientB->id]);
    $addressB = ClientAddress::create([
        'user_id' => $clientB->id,
        'city_id' => $city->id,
        'label' => 'Domicile B',
        'neighborhood' => 'Lobia',
        'address_line' => 'Rue B',
        'is_default' => true,
    ]);
    $notifB = Notification::create([
        'user_id' => $clientB->id,
        'type' => 'order_accepted',
        'title' => 'Info',
        'body' => 'Message privé B',
        'is_read' => false,
    ]);

    // Horizontal IDOR : clientA ne peut accéder à aucune ressource de clientB
    $this->actingAs($clientA, 'api')
        ->getJson("/api/v1/client/orders/{$orderB->id}")->assertStatus(403);
    $this->actingAs($clientA, 'api')
        ->getJson("/api/v1/client/parcels/{$parcelB->id}")->assertStatus(403);
    $this->actingAs($clientA, 'api')
        ->putJson("/api/v1/client/addresses/{$addressB->id}", ['label' => 'Vol'])->assertStatus(403);
    $this->actingAs($clientA, 'api')
        ->postJson("/api/v1/client/addresses/{$addressB->id}/default")->assertStatus(403);
    $this->actingAs($clientA, 'api')
        ->deleteJson("/api/v1/client/addresses/{$addressB->id}")->assertStatus(403);
    $this->actingAs($clientA, 'api')
        ->putJson("/api/v1/client/notifications/{$notifB->id}/read")->assertStatus(403);

    expect($notifB->fresh()->is_read)->toBeFalse();

    // Vertical IDOR : clientA ne peut appeler aucun endpoint merchant, driver ou admin
    $webhook = WebhookLog::create([
        'source' => 'hub2',
        'event_type' => 'payment',
        'payload' => ['transaction_id' => 'TXN-VERTICAL'],
        'signature_valid' => true,
        'status' => 'failed',
    ]);

    $this->actingAs($clientA, 'api')->getJson('/api/v1/merchant/orders')->assertStatus(403);
    $this->actingAs($clientA, 'api')->getJson('/api/v1/driver/courses')->assertStatus(403);
    $this->actingAs($clientA, 'api')->getJson('/api/v1/admin/dashboard')->assertStatus(403);
    $this->actingAs($clientA, 'api')->getJson('/api/v1/admin/orders')->assertStatus(403);
    $this->actingAs($clientA, 'api')->postJson("/api/v1/admin/orders/{$orderB->id}/refund", ['montant' => 500])->assertStatus(403);
    $this->actingAs($clientA, 'api')->postJson("/api/v1/admin/parcels/{$parcelB->id}/refund", ['montant' => 500])->assertStatus(403);
    $this->actingAs($clientA, 'api')->postJson("/api/v1/admin/cities/{$city->id}/emergency-mode", ['activer' => true])->assertStatus(403);
    $this->actingAs($clientA, 'api')->postJson("/api/v1/admin/webhooks/{$webhook->id}/replay")->assertStatus(403);
    $this->actingAs($clientA, 'api')->postJson("/api/v1/orders/{$orderB->id}/unlock-code")->assertStatus(403);
});

it('balayage IDOR horizontal & vertical famille MERCHANT (orders/*, products/*, status)', function () {
    $city = City::factory()->create();
    $merchUserA = User::factory()->create(['role' => 'merchant', 'city_id' => $city->id]);
    Merchant::factory()->create(['user_id' => $merchUserA->id, 'city_id' => $city->id]);

    $merchUserB = User::factory()->create(['role' => 'merchant', 'city_id' => $city->id]);
    $merchantB = Merchant::factory()->create(['user_id' => $merchUserB->id, 'city_id' => $city->id]);

    $orderB = Order::factory()->create(['city_id' => $city->id, 'merchant_id' => $merchantB->id, 'status' => Order::STATUS_PENDING]);
    $productB = Product::factory()->create(['merchant_id' => $merchantB->id, 'price' => 2500]);

    // Horizontal IDOR : merchantA sur commandes & produits de merchantB -> 403
    $this->actingAs($merchUserA, 'api')
        ->postJson("/api/v1/merchant/orders/{$orderB->id}/accept")->assertStatus(403);
    $this->actingAs($merchUserA, 'api')
        ->postJson("/api/v1/merchant/orders/{$orderB->id}/refuse", ['raison' => 'Sabotage'])->assertStatus(403);
    $this->actingAs($merchUserA, 'api')
        ->postJson("/api/v1/merchant/orders/{$orderB->id}/ready")->assertStatus(403);
    $this->actingAs($merchUserA, 'api')
        ->putJson("/api/v1/merchant/products/{$productB->id}", ['price' => 100])->assertStatus(403);
    $this->actingAs($merchUserA, 'api')
        ->deleteJson("/api/v1/merchant/products/{$productB->id}")->assertStatus(403);

    expect($orderB->fresh()->status)->toBe(Order::STATUS_PENDING)
        ->and($productB->fresh()->price)->toBe(2500);

    // Vertical IDOR : client/driver vers merchant, merchant vers admin
    $client = User::factory()->create(['role' => 'client', 'city_id' => $city->id]);
    $this->actingAs($client, 'api')
        ->postJson('/api/v1/merchant/products', ['name' => 'Pirate', 'price' => 500])->assertStatus(403);
    $this->actingAs($merchUserA, 'api')
        ->getJson('/api/v1/admin/orders')->assertStatus(403);
});

it('balayage IDOR horizontal & vertical famille DRIVER (orders/*, parcels/*, verify-codes)', function () {
    $city = City::factory()->create();

    $driverA = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    Courier::factory()->create(['user_id' => $driverA->id, 'city_id' => $city->id]);

    $driverB = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    $courierB = Courier::factory()->create(['user_id' => $driverB->id, 'city_id' => $city->id]);

    $orderB = Order::factory()->create([
        'city_id' => $city->id,
        'courier_id' => $courierB->id,
        'status' => Order::STATUS_PICKED_UP,
        'confirmation_code' => Hash::make('1234'),
    ]);
    $parcelB = Parcel::factory()->create([
        'city_id' => $city->id,
        'courier_id' => $courierB->id,
        'status' => Parcel::STATUS_AT_SENDER,
        'sender_code' => Hash::make('1234'),
        'recipient_code' => Hash::make('5678'),
    ]);
    ParcelPhoto::create(['parcel_id' => $parcelB->id, 'type' => 'pickup', 'photo_url' => 'https://cdn.test/p.jpg']);

    // Horizontal IDOR : driverA agit sur la course/colis de driverB
    $this->actingAs($driverA, 'api')
        ->postJson("/api/v1/driver/orders/{$orderB->id}/accept")->assertStatus(409);
    $this->actingAs($driverA, 'api')
        ->postJson("/api/v1/driver/orders/{$orderB->id}/confirm-pickup")->assertStatus(403);
    $this->actingAs($driverA, 'api')
        ->postJson("/api/v1/driver/orders/{$orderB->id}/confirm-delivery", ['code' => '1234'])->assertStatus(403);
    $this->actingAs($driverA, 'api')
        ->getJson("/api/v1/driver/parcels/{$parcelB->id}")->assertStatus(403);
    $this->actingAs($driverA, 'api')
        ->postJson("/api/v1/parcels/{$parcelB->id}/verify-sender-code", ['code' => '1234'])->assertStatus(403);

    // Vertical IDOR : client/merchant vers driver & codes, driver vers admin
    $client = User::factory()->create(['role' => 'client', 'city_id' => $city->id]);
    $this->actingAs($client, 'api')
        ->postJson("/api/v1/parcels/{$parcelB->id}/verify-sender-code", ['code' => '1234'])->assertStatus(403);
    $this->actingAs($driverA, 'api')
        ->postJson("/api/v1/orders/{$orderB->id}/unlock-code")->assertStatus(403);
});

it('balayage IDOR horizontal & vertical famille PROFILE et ADMIN', function () {
    $cityA = City::factory()->create(['name' => 'Daloa']);
    $cityB = City::factory()->create(['name' => 'Bouaké']);

    $clientA = User::factory()->create(['role' => 'client', 'city_id' => $cityA->id]);
    $clientB = User::factory()->create(['role' => 'client', 'city_id' => $cityB->id]);
    $driver = User::factory()->create(['role' => 'driver', 'city_id' => $cityA->id]);
    $cityAgent = User::factory()->create(['role' => 'city_agent', 'city_id' => $cityA->id]);

    // Horizontal IDOR profile/{user} -> 403
    $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($clientA))
        ->getJson("/api/v1/profile/{$clientB->id}")->assertStatus(403);
    $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($clientA))
        ->putJson("/api/v1/profile/{$clientB->id}", ['name' => 'Modif'])->assertStatus(403);

    // Vertical IDOR vers profils spécialisés -> 403
    $this->actingAs($clientA, 'api')->getJson('/api/v1/driver/profile')->assertStatus(403);
    $this->actingAs($clientA, 'api')->getJson('/api/v1/merchant/profile')->assertStatus(403);
    $this->actingAs($clientA, 'api')->getJson('/api/v1/business/profile')->assertStatus(403);
    $this->actingAs($driver, 'api')->getJson('/api/v1/client/profile')->assertStatus(403);

    // Vertical hiérarchie admin : city_agent bloqué (403) sur refund, emergency-mode, unlock-code, replay
    $orderA = Order::factory()->create(['city_id' => $cityA->id]);
    $parcelA = Parcel::factory()->create(['city_id' => $cityA->id]);
    $webhook = WebhookLog::create([
        'source' => 'hub2',
        'event_type' => 'payment',
        'payload' => ['transaction_id' => 'TXN-ADMIN-V'],
        'signature_valid' => true,
        'status' => 'failed',
    ]);

    $this->actingAs($cityAgent, 'api')
        ->postJson("/api/v1/admin/orders/{$orderA->id}/refund", ['montant' => 500])->assertStatus(403);
    $this->actingAs($cityAgent, 'api')
        ->postJson("/api/v1/admin/parcels/{$parcelA->id}/refund", ['montant' => 500])->assertStatus(403);
    $this->actingAs($cityAgent, 'api')
        ->postJson("/api/v1/admin/cities/{$cityA->id}/emergency-mode", ['activer' => true])->assertStatus(403);
    $this->actingAs($cityAgent, 'api')
        ->postJson("/api/v1/orders/{$orderA->id}/unlock-code")->assertStatus(403);
    $this->actingAs($cityAgent, 'api')
        ->postJson("/api/v1/admin/webhooks/{$webhook->id}/replay")->assertStatus(403);
});
