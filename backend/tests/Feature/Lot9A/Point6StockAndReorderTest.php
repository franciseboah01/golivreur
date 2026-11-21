<?php

use App\Models\City;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Modules\GoShop\Services\OrderService;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Tymon\JWTAuth\Facades\JWTAuth;

it('Point 6a : decrementation atomique du stock (null=illimite, 0=indisponible), 422 insufficient_stock, retablissement sur refus/annulation', function () {
    Queue::fake();

    $city = City::factory()->create(['center_lat' => 6.8770, 'center_lng' => -6.4502]);
    app(SettingsService::class)->set('paiements', ['modes_actifs' => ['cash']], $city->id);

    $client = User::factory()->create(['role' => 'client', 'city_id' => $city->id]);
    $merchantUser = User::factory()->create(['role' => 'merchant', 'city_id' => $city->id]);
    $merchant = Merchant::factory()->create([
        'user_id' => $merchantUser->id,
        'city_id' => $city->id,
        'lat' => 6.8770,
        'lng' => -6.4502,
        'is_active' => true,
        'is_open' => true,
        'open_hours' => null,
    ]);

    $limitedProduct = Product::factory()->create([
        'merchant_id' => $merchant->id,
        'price' => 2500,
        'quantity' => 3,
        'is_available' => true,
    ]);
    $unlimitedProduct = Product::factory()->create([
        'merchant_id' => $merchant->id,
        'price' => 1000,
        'quantity' => null,
        'is_available' => true,
    ]);

    $token = JWTAuth::fromUser($client);

    // Demander 5 alors que stock = 3 -> 422 insufficient_stock avec product_id et available_quantity
    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/client/orders', [
            'merchant_id' => $merchant->id,
            'items' => [['product_id' => $limitedProduct->id, 'quantity' => 5]],
            'delivery_address' => 'Lobia',
            'delivery_lat' => 6.8810,
            'delivery_lng' => -6.4530,
            'payment_method' => 'cash',
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'insufficient_stock')
        ->assertJsonPath('product_id', $limitedProduct->id)
        ->assertJsonPath('available_quantity', 3);

    // Commander 3 (tout le stock) + 2 du produit illimité -> OK, stock passe à 0 et is_available = false
    $orderRes = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/client/orders', [
            'merchant_id' => $merchant->id,
            'items' => [
                ['product_id' => $limitedProduct->id, 'quantity' => 3],
                ['product_id' => $unlimitedProduct->id, 'quantity' => 2],
            ],
            'delivery_address' => 'Lobia',
            'delivery_lat' => 6.8810,
            'delivery_lng' => -6.4530,
            'payment_method' => 'cash',
        ])
        ->assertCreated();

    $limitedProduct->refresh();
    $unlimitedProduct->refresh();
    expect($limitedProduct->quantity)->toBe(0)
        ->and($limitedProduct->is_available)->toBeFalse()
        ->and($unlimitedProduct->quantity)->toBeNull();

    // Concurrence : une 2e commande sur le même produit à 0 échoue en 422
    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/client/orders', [
            'merchant_id' => $merchant->id,
            'items' => [['product_id' => $limitedProduct->id, 'quantity' => 1]],
            'delivery_address' => 'Lobia',
            'delivery_lat' => 6.8810,
            'delivery_lng' => -6.4530,
            'payment_method' => 'cash',
        ])
        ->assertStatus(422);

    // Refus du commerce -> stock rétabli à 3 et is_available = true
    $order = Order::findOrFail($orderRes->json('donnees.id'));
    app(OrderService::class)->refuserCommande($order, 'Rupture cuisine');

    $limitedProduct->refresh();
    expect($limitedProduct->quantity)->toBe(3)
        ->and($limitedProduct->is_available)->toBeTrue();
});

it('Point 6b : POST /client/orders/{id}/reorder renvoie un panier brouillon revalide sans creer de commande', function () {
    $city = City::factory()->create(['center_lat' => 6.8770, 'center_lng' => -6.4502]);
    $client = User::factory()->create(['role' => 'client', 'city_id' => $city->id]);
    $otherClient = User::factory()->create(['role' => 'client', 'city_id' => $city->id]);
    $merchant = Merchant::factory()->create(['city_id' => $city->id, 'is_active' => true, 'is_open' => true]);

    $p1 = Product::factory()->create(['merchant_id' => $merchant->id, 'name' => 'Garba', 'price' => 2000, 'quantity' => 2, 'is_available' => true]);
    $p2 = Product::factory()->create(['merchant_id' => $merchant->id, 'name' => 'Alloco', 'price' => 1500, 'quantity' => 0, 'is_available' => false]);

    $order = Order::factory()->create([
        'client_id' => $client->id,
        'merchant_id' => $merchant->id,
        'city_id' => $city->id,
        'status' => Order::STATUS_DELIVERED,
    ]);
    $order->items()->create(['product_id' => $p1->id, 'quantity' => 5, 'unit_price' => 1500, 'product_name_snapshot' => 'Garba']);
    $order->items()->create(['product_id' => $p2->id, 'quantity' => 1, 'unit_price' => 1500, 'product_name_snapshot' => 'Alloco']);

    $ordersCountBefore = Order::count();

    // Autre client -> 403
    $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($otherClient))
        ->postJson("/api/v1/client/orders/{$order->id}/reorder")
        ->assertStatus(403);

    // Propriétaire -> 200 panier brouillon revalidé (prix actuel 2000, qté plafonnée à 2, p2 dans unavailable_items)
    Auth::forgetGuards();
    $this->app->forgetScopedInstances();
    JWTAuth::unsetToken();

    $res = $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($client))
        ->postJson("/api/v1/client/orders/{$order->id}/reorder")
        ->assertOk()
        ->assertJsonPath('succes', true)
        ->assertJsonPath('donnees.merchant_id', $merchant->id)
        ->assertJsonPath('donnees.merchant_is_open', true)
        ->assertJsonPath('donnees.items.0.product_id', $p1->id)
        ->assertJsonPath('donnees.items.0.quantity', 2)
        ->assertJsonPath('donnees.items.0.requested_quantity', 5)
        ->assertJsonPath('donnees.items.0.unit_price', 2000)
        ->assertJsonPath('donnees.unavailable_items.0.product_id', $p2->id);

    // Aucune nouvelle commande créée !
    expect(Order::count())->toBe($ordersCountBefore);
});
