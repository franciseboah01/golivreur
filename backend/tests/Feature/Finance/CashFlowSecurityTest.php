<?php

use App\Jobs\NotifierLivreurGoColis;
use App\Jobs\NotifierLivreurGoShop;
use App\Models\ActivityLog;
use App\Models\City;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Parcel;
use App\Models\ParcelPhoto;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Modules\GoShop\Services\AssignmentService;
use App\Modules\GoShop\Services\CommissionService;
use App\Modules\GoShop\Services\OrderService;
use App\Services\SettingsService;
use App\Services\WalletService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

it('1.1a refuse 422 acceptOrder en espèces si le solde wallet est inférieur au seuil paramétré wallet.solde_minimum_cash', function () {
    $city = City::factory()->create();
    expect(app(SettingsService::class)->get('wallet.solde_minimum_cash'))->toBe(10000);

    $driver = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    $courier = Courier::factory()->create([
        'user_id' => $driver->id,
        'city_id' => $city->id,
        'is_online' => true,
        'is_active' => true,
    ]);
    Wallet::create([
        'owner_type' => 'courier',
        'owner_id' => $courier->id,
        'balance' => 9999,
    ]);

    $order = Order::factory()->cash()->create([
        'city_id' => $city->id,
        'courier_id' => null,
        'status' => Order::STATUS_ACCEPTED,
        'products_total' => 4000,
        'delivery_fee' => 800,
        'courier_amount' => 640,
        'merchant_amount' => 3400,
        'platform_commission' => 760,
    ]);

    $res = $this->actingAs($driver, 'api')
        ->postJson("/api/v1/driver/orders/{$order->id}/accept")
        ->assertStatus(422)
        ->assertJsonPath('succes', false);

    expect($res->json('message'))->not->toBeEmpty()
        ->and($order->fresh()->courier_id)->toBeNull();

    // Seuil dynamique : abaisser settings wallet.solde_minimum_cash à 5000 autorise le livreur à 9999
    app(SettingsService::class)->set('wallet.solde_minimum_cash', 5000, $city->id);

    $this->actingAs($driver, 'api')
        ->postJson("/api/v1/driver/orders/{$order->id}/accept")
        ->assertOk()
        ->assertJsonPath('succes', true);

    expect($order->fresh()->courier_id)->toBe($courier->id);
});

it('1.1b refuse 422 acceptOrder en espèces si le solde disponible après engagements cash existants est inférieur au reversement', function () {
    $city = City::factory()->create();
    app(SettingsService::class)->set('courses.max_actives', 2, $city->id);

    $driver = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    $courier = Courier::factory()->create([
        'user_id' => $driver->id,
        'city_id' => $city->id,
        'is_online' => true,
        'is_active' => true,
    ]);
    Wallet::create([
        'owner_type' => 'courier',
        'owner_id' => $courier->id,
        'balance' => 11000,
    ]);

    // 1re course cash déjà acceptée : à reverser = 4000 + 800 - 640 = 4160 -> reste disponible 6840
    Order::factory()->cash()->create([
        'city_id' => $city->id,
        'courier_id' => $courier->id,
        'status' => Order::STATUS_ACCEPTED,
        'products_total' => 4000,
        'delivery_fee' => 800,
        'courier_amount' => 640,
        'merchant_amount' => 3400,
        'platform_commission' => 760,
    ]);

    // 2e course cash trop élevée : à reverser = 7000 + 1000 - 800 = 7200 > 6840 disponible
    $heavyOrder = Order::factory()->cash()->create([
        'city_id' => $city->id,
        'courier_id' => null,
        'status' => Order::STATUS_READY,
        'products_total' => 7000,
        'delivery_fee' => 1000,
        'courier_amount' => 800,
        'merchant_amount' => 5950,
        'platform_commission' => 1250,
    ]);

    $this->actingAs($driver, 'api')
        ->postJson("/api/v1/driver/orders/{$heavyOrder->id}/accept")
        ->assertStatus(422)
        ->assertJsonPath('succes', false);

    expect($heavyOrder->fresh()->courier_id)->toBeNull();
});

it('1.1 concurrence: deux acceptations simultanées dépassant le solde ne laissent passer qu\'une seule course grâce au verrou wallet', function () {
    $city = City::factory()->create();
    app(SettingsService::class)->set('courses.max_actives', 2, $city->id);

    $driver = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    $courier = Courier::factory()->create([
        'user_id' => $driver->id,
        'city_id' => $city->id,
        'is_online' => true,
        'is_active' => true,
    ]);
    $wallet = Wallet::create([
        'owner_type' => 'courier',
        'owner_id' => $courier->id,
        'balance' => 11000,
    ]);

    $orderA = Order::factory()->cash()->create([
        'city_id' => $city->id,
        'courier_id' => null,
        'status' => Order::STATUS_ACCEPTED,
        'products_total' => 6000,
        'delivery_fee' => 800,
        'courier_amount' => 640, // à reverser = 6160
        'merchant_amount' => 5100,
        'platform_commission' => 1060,
    ]);
    $orderB = Order::factory()->cash()->create([
        'city_id' => $city->id,
        'courier_id' => null,
        'status' => Order::STATUS_ACCEPTED,
        'products_total' => 6000,
        'delivery_fee' => 800,
        'courier_amount' => 640, // à reverser = 6160 -> total 12320 > 11000
        'merchant_amount' => 5100,
        'platform_commission' => 1060,
    ]);

    $sawWalletForUpdate = false;
    DB::listen(function ($query) use (&$sawWalletForUpdate, $orderA, $courier) {
        $sql = strtolower($query->sql);
        if (str_contains($sql, '"wallets"') && str_contains($sql, 'for update')) {
            if (! $sawWalletForUpdate) {
                $sawWalletForUpdate = true;
                // Simule une course concurrente déjà validée sur le wallet
                Order::whereKey($orderA->id)->update(['courier_id' => $courier->id]);
            }
        }
    });

    // Avec orderA engagée à hauteur de 6160, il ne reste que 4840 < 6160 pour orderB
    $res = $this->actingAs($driver, 'api')
        ->postJson("/api/v1/driver/orders/{$orderB->id}/accept")
        ->assertStatus(422)
        ->assertJsonPath('succes', false);

    expect($sawWalletForUpdate)->toBeTrue()
        ->and($orderB->fresh()->courier_id)->toBeNull();
});

it('Arbitrage 2 AssignmentService ne propose pas une course ou un colis cash à un livreur sans solde minimum suffisant', function () {
    Queue::fake();

    $city = City::factory()->create();
    $driverPoor = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    Courier::factory()->create([
        'user_id' => $driverPoor->id,
        'city_id' => $city->id,
        'is_online' => true,
        'is_active' => true,
        'current_lat' => null,
        'current_lng' => null,
    ]);

    $cashOrder = Order::factory()->cash()->create([
        'city_id' => $city->id,
        'pickup_lat' => null,
        'pickup_lng' => null,
        'products_total' => 4000,
        'delivery_fee' => 800,
        'courier_amount' => 640,
    ]);
    $cashParcel = Parcel::factory()->cash()->create([
        'city_id' => $city->id,
        'pickup_lat' => null,
        'pickup_lng' => null,
        'shipping_fee' => 1200,
        'insurance_amount' => 0,
        'courier_amount' => 900,
    ]);

    $assignment = app(AssignmentService::class);
    $assignment->attribuerLivreurGoShop($cashOrder);
    $assignment->attribuerLivreurGoColis($cashParcel);

    Queue::assertNotPushed(NotifierLivreurGoShop::class);
    Queue::assertNotPushed(NotifierLivreurGoColis::class);

    // En orange_money, même sans solde minimum, le livreur est bien notifié
    $omOrder = Order::factory()->create([
        'city_id' => $city->id,
        'pickup_lat' => null,
        'pickup_lng' => null,
    ]);
    $assignment->attribuerLivreurGoShop($omOrder);
    Queue::assertPushed(NotifierLivreurGoShop::class);
});

it('1.2 GoShop espèces 4000 + 800 : débite -4160 livreur, crédite +3400 commerce, aucun +640 livreur, paid_cash et log plateforme 760', function () {
    $city = City::factory()->create();
    $client = User::factory()->create(['role' => 'client', 'city_id' => $city->id]);
    $merchantUser = User::factory()->create(['role' => 'merchant', 'city_id' => $city->id]);
    $merchant = Merchant::factory()->create(['user_id' => $merchantUser->id, 'city_id' => $city->id]);
    $driver = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    $courier = Courier::factory()->create(['user_id' => $driver->id, 'city_id' => $city->id]);

    Wallet::create([
        'owner_type' => 'courier',
        'owner_id' => $courier->id,
        'balance' => 10000,
    ]);
    DB::table('wallets')->where('owner_type', 'courier')->where('owner_id', $courier->id)->update([
        'total_earned' => 20000,
    ]);

    $order = Order::factory()->cash()->create([
        'city_id' => $city->id,
        'client_id' => $client->id,
        'merchant_id' => $merchant->id,
        'courier_id' => $courier->id,
        'status' => Order::STATUS_PICKED_UP,
        'products_total' => 4000,
        'delivery_fee' => 800,
        'platform_commission' => 760,
        'merchant_amount' => 3400,
        'courier_amount' => 640,
        'confirmation_code' => Hash::make('4829'),
    ]);

    $this->actingAs($driver, 'api')
        ->postJson("/api/v1/driver/orders/{$order->id}/confirm-delivery", ['code' => '4829'])
        ->assertOk()
        ->assertJsonPath('succes', true);

    $freshOrder = $order->fresh();
    expect($freshOrder->status)->toBe(Order::STATUS_DELIVERED)
        ->and($freshOrder->payment_status)->toBe('paid_cash');

    $courierWallet = Wallet::where('owner_type', 'courier')->where('owner_id', $courier->id)->firstOrFail();
    $merchantWallet = Wallet::where('owner_type', 'merchant')->where('owner_id', $merchant->id)->firstOrFail();

    expect((int) $courierWallet->balance)->toBe(5840) // 10000 - 4160
        ->and((int) DB::table('wallets')->where('id', $courierWallet->id)->value('total_earned'))->toBe(20000)
        ->and((int) $merchantWallet->balance)->toBe(3400);

    // Transaction de reversement uniquement pour le livreur (aucun +640)
    $courierTxs = WalletTransaction::where('wallet_id', $courierWallet->id)->where('order_id', $order->id)->get();
    expect($courierTxs)->toHaveCount(1)
        ->and($courierTxs->first()->type)->toBe('debit_cash_remittance')
        ->and((int) $courierTxs->first()->amount)->toBe(-4160)
        ->and((int) $courierTxs->first()->balance_before)->toBe(10000)
        ->and((int) $courierTxs->first()->balance_after)->toBe(5840);

    $merchantTx = WalletTransaction::where('wallet_id', $merchantWallet->id)->where('order_id', $order->id)->first();
    expect($merchantTx)->not->toBeNull()
        ->and($merchantTx->type)->toBe('credit_delivery')
        ->and((int) $merchantTx->amount)->toBe(3400);

    // Trace plateforme dans activity_logs (aucun wallet plateforme)
    expect(Wallet::whereNotIn('owner_type', ['merchant', 'courier'])->count())->toBe(0);
    $log = ActivityLog::where('entity_type', 'order')
        ->where('entity_id', $order->id)
        ->where('action', 'cash_remittance_recorded')
        ->first();
    expect($log)->not->toBeNull()
        ->and((int) ($log->properties['platform_commission'] ?? 0))->toBe(760);
});

it('1.3 GoColis espèces (avec et sans assurance) débite part plateforme + assurance et passe payment_status à paid_cash', function () {
    $city = City::factory()->create();
    $sender = User::factory()->create(['role' => 'client', 'city_id' => $city->id]);
    $driver = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    $courier = Courier::factory()->create(['user_id' => $driver->id, 'city_id' => $city->id]);

    Wallet::create([
        'owner_type' => 'courier',
        'owner_id' => $courier->id,
        'balance' => 15000,
    ]);

    // Colis assuré : shipping_fee=1200 (courier=900, platform=300) + insurance=900 -> total=2100, à reverser=1200
    $insuredParcel = Parcel::factory()->cash()->create([
        'city_id' => $city->id,
        'sender_id' => $sender->id,
        'courier_id' => $courier->id,
        'status' => Parcel::STATUS_AT_RECIPIENT,
        'shipping_fee' => 1200,
        'platform_commission' => 300,
        'courier_amount' => 900,
        'declared_value' => 30000,
        'has_insurance' => true,
        'insurance_amount' => 900,
        'recipient_code' => Hash::make('7777'),
    ]);
    ParcelPhoto::create(['parcel_id' => $insuredParcel->id, 'type' => 'delivery', 'photo_url' => 'https://cdn.test/d1.jpg']);

    $this->actingAs($driver, 'api')
        ->postJson("/api/v1/parcels/{$insuredParcel->id}/verify-recipient-code", ['code' => '7777'])
        ->assertOk();

    expect($insuredParcel->fresh()->payment_status)->toBe('paid_cash')
        ->and((int) Wallet::where('owner_type', 'courier')->where('owner_id', $courier->id)->value('balance'))->toBe(13800);

    $tx1 = WalletTransaction::where('parcel_id', $insuredParcel->id)->first();
    expect($tx1)->not->toBeNull()
        ->and($tx1->type)->toBe('debit_cash_remittance')
        ->and((int) $tx1->amount)->toBe(-1200);

    // Colis non assuré : shipping_fee=1200 (courier=900, platform=300), insurance=0 -> à reverser=300 -> 13800-300=13500
    $plainParcel = Parcel::factory()->cash()->create([
        'city_id' => $city->id,
        'sender_id' => $sender->id,
        'courier_id' => $courier->id,
        'status' => Parcel::STATUS_AT_RECIPIENT,
        'shipping_fee' => 1200,
        'platform_commission' => 300,
        'courier_amount' => 900,
        'declared_value' => 0,
        'has_insurance' => false,
        'insurance_amount' => 0,
        'recipient_code' => Hash::make('8888'),
    ]);
    ParcelPhoto::create(['parcel_id' => $plainParcel->id, 'type' => 'delivery', 'photo_url' => 'https://cdn.test/d2.jpg']);

    $this->actingAs($driver, 'api')
        ->postJson("/api/v1/parcels/{$plainParcel->id}/verify-recipient-code", ['code' => '8888'])
        ->assertOk();

    expect($plainParcel->fresh()->payment_status)->toBe('paid_cash')
        ->and((int) Wallet::where('owner_type', 'courier')->where('owner_id', $courier->id)->value('balance'))->toBe(13500);
});

it('1.4 paiement non cash (orange_money) conserve le crédit normal livreur + commerce', function () {
    $city = City::factory()->create();
    $merchant = Merchant::factory()->create(['city_id' => $city->id]);
    $driver = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    $courier = Courier::factory()->create(['user_id' => $driver->id, 'city_id' => $city->id]);

    $order = Order::factory()->create([
        'city_id' => $city->id,
        'merchant_id' => $merchant->id,
        'courier_id' => $courier->id,
        'status' => Order::STATUS_PICKED_UP,
        'payment_method' => 'orange_money',
        'payment_status' => 'paid',
        'products_total' => 4000,
        'delivery_fee' => 800,
        'platform_commission' => 760,
        'merchant_amount' => 3400,
        'courier_amount' => 640,
        'confirmation_code' => Hash::make('2468'),
    ]);

    $this->actingAs($driver, 'api')
        ->postJson("/api/v1/driver/orders/{$order->id}/confirm-delivery", ['code' => '2468'])
        ->assertOk();

    expect($order->fresh()->payment_status)->toBe('paid')
        ->and((int) Wallet::where('owner_type', 'courier')->where('owner_id', $courier->id)->value('balance'))->toBe(640)
        ->and((int) Wallet::where('owner_type', 'merchant')->where('owner_id', $merchant->id)->value('balance'))->toBe(3400);
});

it('1.5 idempotence : double validation et index unique partiel wallet_transactions empêchent tout double mouvement', function () {
    $city = City::factory()->create();
    $merchant = Merchant::factory()->create(['city_id' => $city->id]);
    $driver = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    $courier = Courier::factory()->create(['user_id' => $driver->id, 'city_id' => $city->id]);

    Wallet::create(['owner_type' => 'courier', 'owner_id' => $courier->id, 'balance' => 10000]);

    $order = Order::factory()->cash()->create([
        'city_id' => $city->id,
        'merchant_id' => $merchant->id,
        'courier_id' => $courier->id,
        'status' => Order::STATUS_PICKED_UP,
        'products_total' => 4000,
        'delivery_fee' => 800,
        'platform_commission' => 760,
        'merchant_amount' => 3400,
        'courier_amount' => 640,
        'confirmation_code' => Hash::make('1357'),
    ]);

    $this->actingAs($driver, 'api')
        ->postJson("/api/v1/driver/orders/{$order->id}/confirm-delivery", ['code' => '1357'])
        ->assertOk();

    // 2e validation API refusée
    $this->actingAs($driver, 'api')
        ->postJson("/api/v1/driver/orders/{$order->id}/confirm-delivery", ['code' => '1357'])
        ->assertStatus(422);

    // Rejeu direct de WalletService bloqué proprement par l'index unique partiel sans double mouvement
    $walletService = app(WalletService::class);
    $tx1 = $walletService->debiterReversementCashOrder($order);
    $tx2 = $walletService->debiterReversementCashOrder($order);
    expect($tx1->id)->toBe($tx2->id)
        ->and((int) Wallet::where('owner_type', 'courier')->where('owner_id', $courier->id)->value('balance'))->toBe(5840)
        ->and(WalletTransaction::where('order_id', $order->id)->where('type', 'debit_cash_remittance')->count())->toBe(1);

    // Vérifier présence des index uniques partiels en base + rejet direct DB d'un doublon
    $indexes = collect(DB::select("SELECT indexname FROM pg_indexes WHERE tablename = 'wallet_transactions'"))->pluck('indexname');
    expect($indexes)->toContain('wallet_transactions_wallet_type_order_unique')
        ->and($indexes)->toContain('wallet_transactions_wallet_type_parcel_unique');

    $courierWalletId = (int) Wallet::where('owner_type', 'courier')->where('owner_id', $courier->id)->value('id');
    expect(fn () => DB::transaction(fn () => DB::table('wallet_transactions')->insert([
        'wallet_id' => $courierWalletId,
        'type' => 'debit_cash_remittance',
        'amount' => -4160,
        'balance_before' => 5840,
        'balance_after' => 1680,
        'reference' => 'TXN-DUP-TEST',
        'description' => 'Tentative doublon direct',
        'status' => 'completed',
        'order_id' => $order->id,
        'created_at' => now(),
        'updated_at' => now(),
    ])))->toThrow(UniqueConstraintViolationException::class);
});

it('1.6 invariant comptable global (part commerce + livreur + plateforme = total payé) et rollback atomique si échec crédit commerce', function () {
    $commissionService = app(CommissionService::class);
    $city = City::factory()->create(['rain_mode_active' => false]);
    $merchant = Merchant::factory()->create(['city_id' => $city->id, 'commission_rate' => 15.0]);

    // Cas montants variés (arrondi au franc XOF)
    foreach ([3333.0, 4000.0, 7775.0] as $productsTotal) {
        $deliveryFee = $commissionService->calculerFraisLivraison(3.0, $city);
        $parts = $commissionService->calculerCommissions($productsTotal, $deliveryFee, $merchant);
        $totalClient = (int) round($productsTotal + $deliveryFee);
        expect((int) round($parts['merchant'] + $parts['livreur'] + $parts['plateforme']))->toBe($totalClient);
    }

    foreach (['petit', 'moyen', 'grand'] as $taille) {
        $shippingFee = $commissionService->calculerFraisExpedition($taille, 3.7, $city);
        $parts = $commissionService->calculerCommissionsGoColis($shippingFee, $city);
        expect((int) round($parts['livreur'] + $parts['plateforme']))->toBe((int) round($shippingFee));
    }

    // Test rollback complet si crediterMerchant échoue en milieu de livraison cash
    $driver = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    $courier = Courier::factory()->create(['user_id' => $driver->id, 'city_id' => $city->id]);
    Wallet::create(['owner_type' => 'courier', 'owner_id' => $courier->id, 'balance' => 10000]);

    $order = Order::factory()->cash()->create([
        'city_id' => $city->id,
        'merchant_id' => $merchant->id,
        'courier_id' => $courier->id,
        'status' => Order::STATUS_PICKED_UP,
        'products_total' => 4000,
        'delivery_fee' => 800,
        'platform_commission' => 760,
        'merchant_amount' => 3400,
        'courier_amount' => 640,
        'confirmation_code' => Hash::make('9001'),
    ]);

    $mockWallet = Mockery::mock(WalletService::class)->makePartial();
    $mockWallet->shouldReceive('crediterMerchant')->once()->andThrow(new RuntimeException('Erreur simulée crédit commerce'));
    $this->app->instance(WalletService::class, $mockWallet);

    $this->actingAs($driver, 'api');
    expect(fn () => app(OrderService::class)->validerCodeConfirmation($order, '9001'))
        ->toThrow(RuntimeException::class);

    // Aucun débit partiel ni changement de statut ne doit persister
    expect($order->fresh()->status)->toBe(Order::STATUS_PICKED_UP)
        ->and($order->fresh()->payment_status)->toBe('pending_cash')
        ->and((int) Wallet::where('owner_type', 'courier')->where('owner_id', $courier->id)->value('balance'))->toBe(10000)
        ->and(WalletTransaction::where('order_id', $order->id)->count())->toBe(0);

    expect(Schema::hasColumn('wallet_transactions', 'order_id'))->toBeTrue();
});
