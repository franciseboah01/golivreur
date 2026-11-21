<?php

use App\Models\City;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\User;
use App\Services\SettingsService;
use App\Services\ShopAvailability;
use Carbon\Carbon;
use Illuminate\Support\Facades\Queue;
use Tymon\JWTAuth\Facades\JWTAuth;

it('Point 7 : ShopAvailability gere 24/7 par defaut, 1-2 intervalles par jour, passage minuit 22:00->02:00 en Africa/Abidjan et nextOpening', function () {
    $city = City::factory()->create(['center_lat' => 6.8770, 'center_lng' => -6.4502]);

    // 1. Par défaut (open_hours = null) -> 24/7 ouvert
    $merchantDefault = Merchant::factory()->create([
        'city_id' => $city->id,
        'is_active' => true,
        'is_open' => true,
        'open_hours' => null,
    ]);
    expect(ShopAvailability::isOpen($merchantDefault, Carbon::parse('2026-11-23 03:30', 'Africa/Abidjan')))->toBeTrue();

    // 2. Intervalles avec passage de minuit (lundi 22:00 -> 02:00, mardi 08:00 -> 14:00 et 16:00 -> 20:00)
    $merchant = Merchant::factory()->create([
        'city_id' => $city->id,
        'is_active' => true,
        'is_open' => true,
        'open_hours' => [
            'always_open' => false,
            'days' => [
                'mon' => [['open' => '22:00', 'close' => '02:00']],
                'tue' => [
                    ['open' => '08:00', 'close' => '14:00'],
                    ['open' => '16:00', 'close' => '20:00'],
                ],
                'wed' => [],
            ],
        ],
    ]);

    // Lundi 23:00 -> ouvert (dans 22:00-02:00)
    expect(ShopAvailability::isOpen($merchant, Carbon::parse('2026-11-23 23:00', 'Africa/Abidjan')))->toBeTrue();
    // Mardi 01:30 -> ouvert (débordement de lundi 22:00-02:00 après minuit !)
    expect(ShopAvailability::isOpen($merchant, Carbon::parse('2026-11-24 01:30', 'Africa/Abidjan')))->toBeTrue();
    // Mardi 02:30 -> fermé, nextOpening = mardi 08:00
    $tue0230 = Carbon::parse('2026-11-24 02:30', 'Africa/Abidjan');
    expect(ShopAvailability::isOpen($merchant, $tue0230))->toBeFalse();
    $next = ShopAvailability::nextOpening($merchant, $tue0230);
    expect($next)->not->toBeNull()
        ->and($next->format('H:i'))->toBe('08:00');
    // Mardi 15:00 -> fermé entre les 2 créneaux, nextOpening = 16:00
    $tue1500 = Carbon::parse('2026-11-24 15:00', 'Africa/Abidjan');
    expect(ShopAvailability::isOpen($merchant, $tue1500))->toBeFalse();
    expect(ShopAvailability::nextOpening($merchant, $tue1500)?->format('H:i'))->toBe('16:00');
});

it('Point 7 : catalogue masque les produits quand la boutique est fermee et renvoie is_open/next_opening ; commande renvoie 422 shop_closed', function () {
    Queue::fake();

    $city = City::factory()->create(['center_lat' => 6.8770, 'center_lng' => -6.4502]);
    app(SettingsService::class)->set('paiements', ['modes_actifs' => ['cash']], $city->id);

    $client = User::factory()->create(['role' => 'client', 'city_id' => $city->id]);
    $merchant = Merchant::factory()->create([
        'city_id' => $city->id,
        'lat' => 6.8770,
        'lng' => -6.4502,
        'is_active' => true,
        'is_open' => true,
        'open_hours' => [
            'always_open' => false,
            'days' => [
                'mon' => [],
                'tue' => [],
                'wed' => [],
                'thu' => [],
                'fri' => [],
                'sat' => [],
                'sun' => [],
            ],
        ],
    ]);
    $product = Product::factory()->create(['merchant_id' => $merchant->id, 'price' => 2500, 'is_available' => true]);

    // Catalogue -> is_open = false, produits non listés (vide)
    $catRes = $this->getJson("/api/v1/client/merchants/{$merchant->id}/catalog")
        ->assertOk()
        ->assertJsonPath('donnees.is_open', false);
    expect($catRes->json('donnees.products'))->toBeEmpty();

    // Création de commande -> 422 code shop_closed
    $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($client))
        ->postJson('/api/v1/client/orders', [
            'merchant_id' => $merchant->id,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'delivery_address' => 'Lobia',
            'delivery_lat' => 6.8810,
            'delivery_lng' => -6.4530,
            'payment_method' => 'cash',
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'shop_closed');
});
