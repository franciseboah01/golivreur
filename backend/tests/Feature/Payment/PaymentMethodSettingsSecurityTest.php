<?php

use App\Enums\PaymentMethod;
use App\Models\City;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\User;
use App\Services\SettingsService;
use Tymon\JWTAuth\Facades\JWTAuth;

it('P5 D-PAIEMENT-1: paiements.modes_actifs a pour valeur par défaut ["cash"] et utilise l\'enum central PaymentMethod', function () {
    expect(enum_exists(PaymentMethod::class))->toBeTrue();

    $defaults = SettingsService::DEFAULTS;
    expect($defaults['paiements']['modes_actifs'] ?? null)->toBe(['cash']);

    $paiements = app(SettingsService::class)->get('paiements');
    expect($paiements['modes_actifs'] ?? null)->toBe(['cash']);

    // Cohérence de l'identifiant canonique MTN (mtn_momo, sans divergence mtn/mtn_momo)
    expect(PaymentMethod::settingsOptions())->toHaveKey('mtn_momo')
        ->not->toHaveKey('mtn')
        ->and(PaymentMethod::resourceOptions())->toHaveKey('mtn_momo')
        ->not->toHaveKey('mtn');
});

it('P5 ClientController::createOrder n\'accepte que cash par défaut et réagit dynamiquement à paiements.modes_actifs', function () {
    $city = City::factory()->create(['name' => 'Daloa']);
    $client = User::factory()->create(['role' => 'client', 'city_id' => $city->id]);
    $merchant = Merchant::factory()->create([
        'city_id' => $city->id,
        'is_active' => true,
        'is_open' => true,
        'lat' => 6.8770,
        'lng' => -6.4502,
    ]);
    $product = Product::factory()->create([
        'merchant_id' => $merchant->id,
        'price' => 2500,
        'is_available' => true,
    ]);
    $token = JWTAuth::fromUser($client);

    $basePayload = [
        'merchant_id' => $merchant->id,
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
        'delivery_address' => 'Quartier Commerce, Daloa',
        'delivery_lat' => 6.8800,
        'delivery_lng' => -6.4510,
    ];

    foreach (['orange_money', 'wave', 'mtn_momo', 'mtn', 'moov', 'crypto'] as $rejectedMethod) {
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/client/orders', [
                ...$basePayload,
                'payment_method' => $rejectedMethod,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('payment_method');
    }

    // Réactivation dynamique d'Orange Money et MTN MoMo sans redéploiement
    app(SettingsService::class)->set('paiements', [
        'modes_actifs' => ['cash', 'orange_money', 'mtn_momo'],
    ]);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/client/orders', [
            ...$basePayload,
            'payment_method' => 'orange_money',
        ])
        ->assertCreated()
        ->assertJsonPath('donnees.payment_method', 'orange_money');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/client/orders', [
            ...$basePayload,
            'payment_method' => 'mtn_momo',
        ])
        ->assertCreated()
        ->assertJsonPath('donnees.payment_method', 'mtn_momo');

    // L'ancien identifiant non canonique 'mtn' reste rejeté
    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/client/orders', [
            ...$basePayload,
            'payment_method' => 'mtn',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('payment_method');
});

it('P5 ParcelController::store n\'accepte que cash par défaut et réagit dynamiquement à paiements.modes_actifs', function () {
    $city = City::factory()->create(['name' => 'Daloa']);
    $client = User::factory()->create(['role' => 'client', 'city_id' => $city->id]);
    $token = JWTAuth::fromUser($client);

    $basePayload = [
        'recipient_name' => 'Koné Mariam',
        'recipient_phone' => '0709000001',
        'delivery_address' => 'Quartier Résidentiel, Daloa',
        'delivery_lat' => 6.8800,
        'delivery_lng' => -6.4510,
        'pickup_lat' => 6.8821,
        'pickup_lng' => -6.4478,
        'size' => 'moyen',
        'weight_kg' => 2.0,
        'declared_value' => 10000,
    ];

    foreach (['orange_money', 'wave', 'mtn_momo', 'mtn', 'moov'] as $rejectedMethod) {
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/client/parcels', [
                ...$basePayload,
                'payment_method' => $rejectedMethod,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('payment_method');
    }

    app(SettingsService::class)->set('paiements', [
        'modes_actifs' => ['cash', 'orange_money'],
    ]);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/client/parcels', [
            ...$basePayload,
            'payment_method' => 'orange_money',
        ])
        ->assertCreated();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/client/parcels', [
            ...$basePayload,
            'payment_method' => 'wave',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('payment_method');
});
