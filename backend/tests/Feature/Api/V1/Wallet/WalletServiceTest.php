<?php

use App\Models\{Courier, Merchant, Order, Parcel, User, Wallet, WalletTransaction};
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ─── Créditement livreur GoShop ───────────────────────────────────────────────

it('crédite correctement le wallet du livreur après une livraison GoShop', function () {
    $city    = \App\Models\City::factory()->create();
    $driver  = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    $courier = Courier::factory()->create(['user_id' => $driver->id, 'city_id' => $city->id]);

    $order = Order::factory()->create([
        'courier_id'     => $courier->id,
        'courier_amount' => 640, // 800 F CFA - 20%
        'status'         => 'delivered',
    ]);

    $service = app(WalletService::class);
    $transaction = $service->crediterLivreur($order);

    expect($transaction->amount)->toBe(640.0)
        ->and($transaction->type)->toBe('credit_delivery')
        ->and($transaction->balance_after)->toBe(640.0)
        ->and($transaction->balance_before)->toBe(0.0);

    $this->assertDatabaseHas('wallets', [
        'owner_type' => 'courier',
        'owner_id'   => $courier->id,
        'balance'    => 640,
    ]);
});

it('accumule correctement plusieurs crédits sur le même wallet', function () {
    $city    = \App\Models\City::factory()->create();
    $driver  = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    $courier = Courier::factory()->create(['user_id' => $driver->id, 'city_id' => $city->id]);

    $order1 = Order::factory()->create(['courier_id' => $courier->id, 'courier_amount' => 640]);
    $order2 = Order::factory()->create(['courier_id' => $courier->id, 'courier_amount' => 960]);

    $service = app(WalletService::class);
    $service->crediterLivreur($order1);
    $tx2 = $service->crediterLivreur($order2);

    expect($tx2->balance_before)->toBe(640.0)
        ->and($tx2->balance_after)->toBe(1600.0);

    $this->assertDatabaseHas('wallets', ['owner_type' => 'courier', 'balance' => 1600]);
});

// ─── Créditement merchant GoShop ──────────────────────────────────────────────

it('crédite correctement le wallet du merchant après une livraison GoShop', function () {
    $city     = \App\Models\City::factory()->create();
    $user     = User::factory()->create(['role' => 'merchant', 'city_id' => $city->id]);
    $merchant = Merchant::factory()->create(['user_id' => $user->id, 'city_id' => $city->id]);

    $order = Order::factory()->create([
        'merchant_id'     => $merchant->id,
        'merchant_amount' => 3400, // 4000 F CFA - 15%
    ]);

    $service = app(WalletService::class);
    $service->crediterMerchant($order);

    $this->assertDatabaseHas('wallets', [
        'owner_type' => 'merchant',
        'owner_id'   => $merchant->id,
        'balance'    => 3400,
    ]);
});

// ─── Créditement livreur GoColis ──────────────────────────────────────────────

it('crédite correctement le wallet du livreur après une livraison GoColis', function () {
    $city    = \App\Models\City::factory()->create();
    $driver  = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    $courier = Courier::factory()->create(['user_id' => $driver->id, 'city_id' => $city->id]);

    $parcel = Parcel::factory()->create([
        'courier_id'     => $courier->id,
        'courier_amount' => 1125, // 1500 F CFA × 75%
    ]);

    $service = app(WalletService::class);
    $tx = $service->crediterLivreurGoColis($parcel);

    expect($tx->amount)->toBe(1125.0)
        ->and($tx->type)->toBe('credit_shipping');

    $this->assertDatabaseHas('wallets', ['owner_type' => 'courier', 'balance' => 1125]);
});

// ─── Atomicité des transactions ───────────────────────────────────────────────

it('crée exactement une WalletTransaction par opération', function () {
    $city    = \App\Models\City::factory()->create();
    $driver  = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    $courier = Courier::factory()->create(['user_id' => $driver->id, 'city_id' => $city->id]);
    $order   = Order::factory()->create(['courier_id' => $courier->id, 'courier_amount' => 640]);

    $countAvant = WalletTransaction::count();

    app(WalletService::class)->crediterLivreur($order);

    expect(WalletTransaction::count())->toBe($countAvant + 1);
});

it('chaque transaction a une référence unique', function () {
    $city    = \App\Models\City::factory()->create();
    $driver  = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    $courier = Courier::factory()->create(['user_id' => $driver->id, 'city_id' => $city->id]);

    $order1 = Order::factory()->create(['courier_id' => $courier->id, 'courier_amount' => 640]);
    $order2 = Order::factory()->create(['courier_id' => $courier->id, 'courier_amount' => 800]);

    $service = app(WalletService::class);
    $tx1 = $service->crediterLivreur($order1);
    $tx2 = $service->crediterLivreur($order2);

    expect($tx1->reference)->not->toBe($tx2->reference);
});

// ─── Les clients n'ont pas de wallet ──────────────────────────────────────────

it('ne crée pas de wallet pour un client', function () {
    // Le WalletService ne peut être appelé qu'avec owner_type 'merchant' ou 'courier'
    // Cette contrainte est validée par la contrainte CHECK PostgreSQL
    $this->expectException(\Illuminate\Database\QueryException::class);

    \DB::table('wallets')->insert([
        'owner_type'   => 'client', // Violera la contrainte CHECK
        'owner_id'     => 1,
        'balance'      => 0,
        'total_earned' => 0,
        'total_withdrawn' => 0,
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);
});

// ─── Remboursement par plafond admin ──────────────────────────────────────────

it('un City Support peut valider un remboursement inférieur à 2000 F CFA', function () {
    $citySupport = User::factory()->create(['role' => 'city_support']);
    $city        = \App\Models\City::factory()->create();
    $driver      = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    $courier     = Courier::factory()->create(['user_id' => $driver->id, 'city_id' => $city->id]);

    // Pré-alimenter le wallet
    \DB::table('wallets')->insert([
        'owner_type' => 'courier', 'owner_id' => $courier->id,
        'balance' => 5000, 'total_earned' => 5000, 'total_withdrawn' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $tx = app(WalletService::class)->effectuerRemboursement(
        ownerType: 'courier',
        ownerId: $courier->id,
        montant: 1500,
        description: 'Remboursement commande annulée #42',
        adminId: $citySupport->id,
    );

    expect($tx->amount)->toBe(1500.0)
        ->and($tx->type)->toBe('refund')
        ->and($tx->processed_by)->toBe($citySupport->id);
});
