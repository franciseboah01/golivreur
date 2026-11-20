<?php

use App\Jobs\AlerterAdminPasDelivreur;
use App\Jobs\NotifierLivreurGoColis;
use App\Jobs\NotifierLivreurGoShop;
use App\Jobs\SendPushNotification;
use App\Models\City;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Parcel;
use App\Models\Product;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\Queue;

it('expose la proposition GoShop exclusivement au livreur cible dans GET /driver/courses, bloque le vol par un autre livreur et bascule au suivant sur refus', function () {
    Queue::fake([SendPushNotification::class, NotifierLivreurGoShop::class, AlerterAdminPasDelivreur::class]);
    $this->travelTo(now()->setTime(10, 0));

    $city = City::factory()->create([
        'name' => 'Daloa',
        'center_lat' => 6.8770,
        'center_lng' => -6.4502,
    ]);

    $client = User::factory()->create(['role' => 'client', 'city_id' => $city->id]);
    $merchantUser = User::factory()->create(['role' => 'merchant', 'city_id' => $city->id]);
    $merchant = Merchant::factory()->create([
        'user_id' => $merchantUser->id,
        'city_id' => $city->id,
        'is_active' => true,
        'is_open' => true,
        'lat' => 6.8770,
        'lng' => -6.4502,
    ]);
    $product = Product::factory()->create([
        'merchant_id' => $merchant->id,
        'name' => 'Attiéké Poisson Braisé',
        'price' => 2500,
        'is_available' => true,
    ]);

    $driver1 = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    $courier1 = Courier::factory()->create([
        'user_id' => $driver1->id,
        'city_id' => $city->id,
        'is_online' => true,
        'is_active' => true,
        'current_lat' => 6.8775,
        'current_lng' => -6.4505,
        'location_updated_at' => now(),
    ]);
    Wallet::create(['owner_type' => 'courier', 'owner_id' => $courier1->id, 'balance' => 25000]);

    $driver2 = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    $courier2 = Courier::factory()->create([
        'user_id' => $driver2->id,
        'city_id' => $city->id,
        'is_online' => true,
        'is_active' => true,
        'current_lat' => 6.8850,
        'current_lng' => -6.4550,
        'location_updated_at' => now(),
    ]);
    Wallet::create(['owner_type' => 'courier', 'owner_id' => $courier2->id, 'balance' => 25000]);

    // 1. Le client passe sa commande en espèces
    $createRes = $this->actingAs($client, 'api')
        ->postJson('/api/v1/client/orders', [
            'merchant_id' => $merchant->id,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'delivery_address' => 'Quartier Commerce, Daloa',
            'delivery_lat' => 6.8810,
            'delivery_lng' => -6.4490,
            'payment_method' => 'cash',
        ])
        ->assertCreated();

    $orderId = (int) $createRes->json('donnees.id');

    // 2. Le commerce accepte la commande -> déclenche l'offre exclusive à courier1 (le plus proche)
    $this->actingAs($merchantUser, 'api')
        ->postJson("/api/v1/merchant/orders/{$orderId}/accept")
        ->assertOk();

    // 3. courier1 voit l'offre dans GET /driver/courses -> donnees.offers.orders
    $coursesD1 = $this->actingAs($driver1, 'api')
        ->getJson('/api/v1/driver/courses')
        ->assertOk();

    expect($coursesD1->json('donnees.orders'))->toBeEmpty()
        ->and($coursesD1->json('donnees.offers.orders'))->toHaveCount(1)
        ->and($coursesD1->json('donnees.offers.orders.0.id'))->toBe($orderId)
        ->and($coursesD1->json('donnees.offers.orders.0.offer_remaining_seconds'))->toBeGreaterThan(0);

    // 4. courier2 NE voit PAS l'offre et ne peut PAS l'accepter pendant les 30s exclusives de courier1
    $coursesD2 = $this->actingAs($driver2, 'api')
        ->getJson('/api/v1/driver/courses')
        ->assertOk();

    expect($coursesD2->json('donnees.offers.orders'))->toBeEmpty();

    $this->actingAs($driver2, 'api')
        ->postJson("/api/v1/driver/orders/{$orderId}/accept")
        ->assertStatus(409);

    // 5. courier1 refuse -> l'offre passe immédiatement à courier2
    $this->actingAs($driver1, 'api')
        ->postJson("/api/v1/driver/orders/{$orderId}/refuse", ['raison' => 'Indisponible'])
        ->assertOk();

    $coursesD1AfterRefuse = $this->actingAs($driver1, 'api')
        ->getJson('/api/v1/driver/courses')
        ->assertOk();
    expect($coursesD1AfterRefuse->json('donnees.offers.orders'))->toBeEmpty();

    $coursesD2AfterRefuse = $this->actingAs($driver2, 'api')
        ->getJson('/api/v1/driver/courses')
        ->assertOk();
    expect($coursesD2AfterRefuse->json('donnees.offers.orders'))->toHaveCount(1)
        ->and($coursesD2AfterRefuse->json('donnees.offers.orders.0.id'))->toBe($orderId);

    // 6. courier2 accepte -> la course est verrouillée dans donnees.orders et disparaît des offres
    $this->actingAs($driver2, 'api')
        ->postJson("/api/v1/driver/orders/{$orderId}/accept")
        ->assertOk();

    $coursesD2AfterAccept = $this->actingAs($driver2, 'api')
        ->getJson('/api/v1/driver/courses')
        ->assertOk();
    expect($coursesD2AfterAccept->json('donnees.offers.orders'))->toBeEmpty()
        ->and($coursesD2AfterAccept->json('donnees.orders'))->toHaveCount(1)
        ->and($coursesD2AfterAccept->json('donnees.orders.0.id'))->toBe($orderId);

    expect(Order::findOrFail($orderId)->courier_id)->toBe($courier2->id);
});

it('expose l offre GoColis avec la taille du colis dans GET /driver/courses et relance automatiquement le suivant apres expiration 30s', function () {
    Queue::fake([SendPushNotification::class, NotifierLivreurGoColis::class, AlerterAdminPasDelivreur::class]);

    $city = City::factory()->create([
        'name' => 'Daloa',
        'center_lat' => 6.8770,
        'center_lng' => -6.4502,
    ]);

    $sender = User::factory()->create(['role' => 'client', 'city_id' => $city->id]);

    $driver1 = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    $courier1 = Courier::factory()->create([
        'user_id' => $driver1->id,
        'city_id' => $city->id,
        'is_online' => true,
        'is_active' => true,
        'current_lat' => 6.8772,
        'current_lng' => -6.4502,
        'location_updated_at' => now(),
    ]);
    Wallet::create(['owner_type' => 'courier', 'owner_id' => $courier1->id, 'balance' => 20000]);

    $driver2 = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    $courier2 = Courier::factory()->create([
        'user_id' => $driver2->id,
        'city_id' => $city->id,
        'is_online' => true,
        'is_active' => true,
        'current_lat' => 6.8830,
        'current_lng' => -6.4530,
        'location_updated_at' => now(),
    ]);
    Wallet::create(['owner_type' => 'courier', 'owner_id' => $courier2->id, 'balance' => 20000]);

    $parcelRes = $this->actingAs($sender, 'api')
        ->postJson('/api/v1/client/parcels', [
            'recipient_name' => 'Kouamé Yao',
            'recipient_phone' => '0708091011',
            'pickup_address' => 'Tazibouo, Daloa',
            'pickup_lat' => 6.8770,
            'pickup_lng' => -6.4502,
            'delivery_address' => 'Lobia, Daloa',
            'delivery_lat' => 6.8820,
            'delivery_lng' => -6.4520,
            'size' => 'moyen',
            'payment_method' => 'cash',
        ])
        ->assertCreated();

    $parcelId = (int) $parcelRes->json('donnees.id');

    // courier1 voit la proposition GoColis avec size et size_label avant d'accepter
    $d1Res = $this->actingAs($driver1, 'api')
        ->getJson('/api/v1/driver/courses')
        ->assertOk();

    expect($d1Res->json('donnees.offers.parcels'))->toHaveCount(1)
        ->and($d1Res->json('donnees.offers.parcels.0.id'))->toBe($parcelId)
        ->and($d1Res->json('donnees.offers.parcels.0.size'))->toBe('moyen')
        ->and($d1Res->json('donnees.offers.parcels.0.size_label'))->toContain('Moyen');

    // Apres 35 secondes sans reponse (meme sans queue worker), l'appel de courier2 rattrape l'expiration et lui transfere l'offre !
    $this->travel(35)->seconds();
    $courier2->update(['location_updated_at' => now()]);

    $d2Res = $this->actingAs($driver2, 'api')
        ->getJson('/api/v1/driver/courses')
        ->assertOk();

    expect($d2Res->json('donnees.offers.parcels'))->toHaveCount(1)
        ->and($d2Res->json('donnees.offers.parcels.0.id'))->toBe($parcelId)
        ->and(Parcel::findOrFail($parcelId)->assignment_attempts)->toBe(2);
});
