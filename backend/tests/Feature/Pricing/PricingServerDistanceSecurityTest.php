<?php

use App\Models\City;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Parcel;
use App\Models\Product;
use App\Models\User;
use App\Models\Wallet;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Hash;
use Tymon\JWTAuth\Facades\JWTAuth;

it('calcule la distance cote serveur par Haversine et ignore la distance envoyee par le client', function () {
    // Ville Daloa : centre (6.8770, -6.4502)
    $city = City::factory()->create([
        'name' => 'Daloa '.uniqid(),
        'center_lat' => 6.8770,
        'center_lng' => -6.4502,
    ]);

    $client = User::factory()->create(['role' => 'client', 'city_id' => $city->id]);
    $merchantUser = User::factory()->create(['role' => 'merchant', 'city_id' => $city->id]);

    // Merchant situé près du centre
    $merchant = Merchant::factory()->create([
        'user_id' => $merchantUser->id,
        'city_id' => $city->id,
        'is_open' => true,
        'is_active' => true,
        'lat' => 6.8770,
        'lng' => -6.4502,
    ]);

    $product = Product::factory()->create([
        'merchant_id' => $merchant->id,
        'price' => 2000,
        'is_available' => true,
    ]);

    $token = JWTAuth::fromUser($client);

    // Client situé à ~10 km au nord-est (6.9500, -6.3900)
    // Distance Haversine réelle ≈ 10.4 km -> tranche > 7 km = 1500 F CFA
    // Le client malveillant tente d'envoyer distance_km = 0.1 pour payer 500 F CFA
    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/client/orders', [
            'merchant_id' => $merchant->id,
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1],
            ],
            'delivery_address' => 'Zone Nord 10km, Daloa',
            'delivery_lat' => 6.9500,
            'delivery_lng' => -6.3900,
            'distance_km' => 0.1, // Doit être IGNORÉ par le serveur
            'payment_method' => 'cash',
        ]);

    $response->assertCreated();
    $order = Order::find($response->json('donnees.id'));

    // Vérifie que les frais de livraison appliqués sont bien ceux de la tranche > 7km (1500 F CFA) et NON 500 F
    expect((float) $order->delivery_fee)->toBe(1500.0)
        ->and((float) $order->distance_km)->toBeGreaterThan(9.0);
});

it('refuse avec une 422 si les coordonnees sont absentes ou hors ville', function () {
    $city = City::factory()->create([
        'name' => 'Daloa '.uniqid(),
        'center_lat' => 6.8770,
        'center_lng' => -6.4502,
    ]);

    $client = User::factory()->create(['role' => 'client', 'city_id' => $city->id]);
    $merchantUser = User::factory()->create(['role' => 'merchant', 'city_id' => $city->id]);
    $merchant = Merchant::factory()->create([
        'user_id' => $merchantUser->id,
        'city_id' => $city->id,
        'is_open' => true,
        'is_active' => true,
        'lat' => 6.8770,
        'lng' => -6.4502,
    ]);
    $product = Product::factory()->create([
        'merchant_id' => $merchant->id,
        'price' => 1000,
    ]);

    $token = JWTAuth::fromUser($client);

    // 1. Coordonnées absentes -> 422
    $resNoCoords = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/client/orders', [
            'merchant_id' => $merchant->id,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'delivery_address' => 'Sans coordonnees',
            'payment_method' => 'cash',
        ]);
    $resNoCoords->assertStatus(422)
        ->assertJsonValidationErrors(['delivery_lat', 'delivery_lng']);

    // 2. Coordonnées hors ville (Abidjan : 5.3600, -4.0083) -> 422
    $resHorsVille = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/client/orders', [
            'merchant_id' => $merchant->id,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'delivery_address' => 'Abidjan',
            'delivery_lat' => 5.3600,
            'delivery_lng' => -4.0083,
            'payment_method' => 'cash',
        ]);
    $resHorsVille->assertStatus(422)
        ->assertJsonValidationErrors(['delivery_lat']);
});

it('valide le poids et le telephone destinataire pour GoColis', function () {
    $city = City::factory()->create([
        'name' => 'Daloa '.uniqid(),
        'center_lat' => 6.8770,
        'center_lng' => -6.4502,
    ]);
    $client = User::factory()->create(['role' => 'client', 'city_id' => $city->id]);
    $token = JWTAuth::fromUser($client);

    $basePayload = [
        'recipient_name' => 'Koffi Paul',
        'recipient_phone' => '0701020304',
        'pickup_address' => 'Depot Daloa',
        'pickup_lat' => 6.8770,
        'pickup_lng' => -6.4502,
        'delivery_address' => 'Arrivee Daloa',
        'delivery_lat' => 6.8850,
        'delivery_lng' => -6.4420,
        'size' => 'petit',
        'weight_kg' => 0.8,
        'payment_method' => 'cash',
    ];

    // 1. Poids négatif (-5) -> 422
    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/client/parcels', array_merge($basePayload, ['weight_kg' => -5]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['weight_kg']);

    // 2. Poids 500 kg -> 422
    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/client/parcels', array_merge($basePayload, ['weight_kg' => 500]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['weight_kg']);

    // 3. Poids 25 kg en "petit" (max petit = 1.0 kg) -> 422
    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/client/parcels', array_merge($basePayload, ['size' => 'petit', 'weight_kg' => 25]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['weight_kg']);

    // 4. Téléphone invalide "pas_un_numero" -> 422
    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/client/parcels', array_merge($basePayload, ['recipient_phone' => 'pas_un_numero']))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['recipient_phone']);
});

it('applique la surcharge de ville specifique sur GoColis', function () {
    $city = City::factory()->create([
        'name' => 'Daloa '.uniqid(),
        'center_lat' => 6.8770,
        'center_lng' => -6.4502,
    ]);

    // Surcharge spécifique pour cette ville : base petit = 1500 F (au lieu de 800)
    $settingsService = app(SettingsService::class);
    $settingsService->set('tarifs_gocolis', [
        'bareme' => [
            'petit' => ['base' => 1500, 'par_km' => 300],
            'moyen' => ['base' => 2000, 'par_km' => 400],
            'grand' => ['base' => 3000, 'par_km' => 500],
        ],
        'seuil_assurance' => 20000,
        'taux_assurance' => 3.0,
    ], $city->id);

    $client = User::factory()->create(['role' => 'client', 'city_id' => $city->id]);
    $token = JWTAuth::fromUser($client);

    // Trajet court (< 2 km) -> tarif de base ville = 1500 F CFA
    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/client/parcels', [
            'recipient_name' => 'Konan Serge',
            'recipient_phone' => '0701020304',
            'pickup_address' => 'Depart',
            'pickup_lat' => 6.8770,
            'pickup_lng' => -6.4502,
            'delivery_address' => 'Arrivee',
            'delivery_lat' => 6.8820,
            'delivery_lng' => -6.4490, // ~0.5 km
            'size' => 'petit',
            'weight_kg' => 0.5,
            'payment_method' => 'cash',
        ]);

    $response->assertCreated();
    $parcel = Parcel::find($response->json('donnees.id'));
    expect((float) $parcel->shipping_fee)->toBe(1500.0);
});

it('renvoie une 422 propre si le solde du livreur est insuffisant pour la livraison cash', function () {
    $city = City::factory()->create(['name' => 'Daloa '.uniqid()]);
    $client = User::factory()->create(['role' => 'client', 'city_id' => $city->id]);
    $merchantUser = User::factory()->create(['role' => 'merchant', 'city_id' => $city->id]);
    $merchant = Merchant::factory()->create([
        'user_id' => $merchantUser->id,
        'city_id' => $city->id,
        'is_open' => true,
        'is_active' => true,
    ]);
    $courierUser = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    $courier = Courier::factory()->create([
        'user_id' => $courierUser->id,
        'city_id' => $city->id,
        'is_active' => true,
    ]);

    // Wallet du livreur avec solde = 0 (insuffisant pour couvrir le reversement espèces)
    $wallet = Wallet::firstOrCreate(
        ['owner_type' => 'courier', 'owner_id' => $courier->id],
        ['balance' => 0]
    );
    $wallet->update(['balance' => 0]);

    $codeClair = '4829';
    $order = Order::factory()->create([
        'client_id' => $client->id,
        'merchant_id' => $merchant->id,
        'courier_id' => $courier->id,
        'city_id' => $city->id,
        'status' => 'picked_up', // Prêt à être livré
        'payment_method' => 'cash',
        'payment_status' => 'pending',
        'products_total' => 4000,
        'delivery_fee' => 800,
        'courier_amount' => 640,
        'merchant_amount' => 3400,
        'platform_commission' => 760,
        'confirmation_code' => Hash::make($codeClair),
    ]);

    $driverToken = JWTAuth::fromUser($courierUser);

    // Tentative de confirmation livraison avec solde insuffisant
    $response = $this->withHeader('Authorization', "Bearer {$driverToken}")
        ->postJson("/api/v1/driver/orders/{$order->id}/confirm-delivery", [
            'code' => $codeClair,
        ]);

    // Doit retourner 422 (et non 500)
    $response->assertStatus(422)
        ->assertJsonPath('succes', false);

    // La commande ne doit PAS être passée à delivered (rollback effectif)
    $orderFresh = $order->fresh();
    expect($orderFresh->status)->toBe('picked_up');
});
