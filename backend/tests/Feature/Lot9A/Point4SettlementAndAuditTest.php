<?php

use App\Models\City;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Parcel;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\OrderSweepService;

it('Point 4a/b/c : reglement unique idempotent, wallet:audit et wallet:settle-missing (dry-run vs --confirm)', function () {
    $city = City::factory()->create(['name' => 'Daloa']);
    $client = User::factory()->create(['role' => 'client', 'city_id' => $city->id]);
    $merchantUser = User::factory()->create(['role' => 'merchant', 'city_id' => $city->id]);
    $merchant = Merchant::factory()->create(['user_id' => $merchantUser->id, 'city_id' => $city->id]);
    $driverUser = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    $courier = Courier::factory()->create(['user_id' => $driverUser->id, 'city_id' => $city->id]);

    // Commande livrée en base sans écritures (cas constat 0c)
    $order = Order::factory()->create([
        'client_id' => $client->id,
        'merchant_id' => $merchant->id,
        'courier_id' => $courier->id,
        'city_id' => $city->id,
        'status' => Order::STATUS_DELIVERED,
        'payment_method' => 'orange_money',
        'payment_status' => 'paid',
        'products_total' => 5000,
        'delivery_fee' => 800,
        'merchant_amount' => 4250,
        'courier_amount' => 640,
        'delivered_at' => now()->subMinutes(10),
    ]);

    // 1. wallet:audit détecte les écarts
    $this->artisan("wallet:audit order {$order->id}")
        ->expectsOutputToContain('ÉCARTS DÉTECTÉS')
        ->assertExitCode(1);

    // 2. wallet:settle-missing en dry-run par défaut ne écrit RIEN
    $this->artisan('wallet:settle-missing')
        ->expectsOutputToContain('DRY-RUN')
        ->assertExitCode(0);

    expect(WalletTransaction::where('order_id', $order->id)->count())->toBe(0);

    // 3. wallet:settle-missing --confirm exécute le règlement idempotent
    $this->artisan('wallet:settle-missing --confirm')
        ->assertExitCode(0);

    expect(WalletTransaction::where('order_id', $order->id)->count())->toBe(2);
    expect((int) Wallet::where('owner_type', 'courier')->where('owner_id', $courier->id)->value('balance'))->toBe(640)
        ->and((int) Wallet::where('owner_type', 'merchant')->where('owner_id', $merchant->id)->value('balance'))->toBe(4250);

    // 4. Relancer --confirm est 100% idempotent (aucun doublon)
    $this->artisan('wallet:settle-missing --confirm')
        ->assertExitCode(0);
    expect(WalletTransaction::where('order_id', $order->id)->count())->toBe(2);

    // 5. wallet:audit renvoie maintenant 0 écart
    $this->artisan("wallet:audit order {$order->id}")
        ->expectsOutputToContain('CONFORME')
        ->assertExitCode(0);
});

it('Point 4d : OrderSweepService signale a l admin toute livraison terminee sans reglement depuis plus de 5 min', function () {
    $city = City::factory()->create(['name' => 'Daloa']);
    $admin = User::factory()->create(['role' => 'super_admin', 'city_id' => null, 'is_active' => true]);
    $client = User::factory()->create(['role' => 'client', 'city_id' => $city->id]);
    $driverUser = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    $courier = Courier::factory()->create(['user_id' => $driverUser->id, 'city_id' => $city->id]);

    $parcel = Parcel::factory()->create([
        'sender_id' => $client->id,
        'courier_id' => $courier->id,
        'city_id' => $city->id,
        'status' => Parcel::STATUS_DELIVERED,
        'payment_method' => 'orange_money',
        'payment_status' => 'paid',
        'courier_amount' => 750,
        'delivered_at' => now()->subMinutes(10),
    ]);

    $result = app(OrderSweepService::class)->balayer();
    expect($result['unsettled_alerts'])->toBeGreaterThanOrEqual(1);

    expect(Notification::where('user_id', $admin->id)
        ->where('type', 'admin_unsettled_delivery_alert')
        ->exists())->toBeTrue();
});
