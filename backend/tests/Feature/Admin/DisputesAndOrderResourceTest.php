<?php

use App\Filament\Pages\DisputesPage;
use App\Filament\Resources\OrderResource\Pages\ListOrders;
use App\Models\ActivityLog;
use App\Models\City;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\User;
use Livewire\Livewire;

it('affiche id, total_client et libelles reels sur DisputesPage sans propriete inexistante', function () {
    $city = City::factory()->create();
    $admin = User::factory()->create(['role' => 'super_admin', 'city_id' => $city->id]);
    $client = User::factory()->create(['name' => 'Mariam Traoré', 'role' => 'client', 'city_id' => $city->id]);
    $driverUser = User::factory()->create(['name' => 'Bakary Koné', 'role' => 'driver', 'city_id' => $city->id]);
    $courier = Courier::factory()->create(['user_id' => $driverUser->id, 'city_id' => $city->id]);
    $merchant = Merchant::factory()->create(['name' => 'Maquis Central', 'city_id' => $city->id]);

    $order = Order::factory()->create([
        'client_id' => $client->id,
        'merchant_id' => $merchant->id,
        'courier_id' => $courier->id,
        'city_id' => $city->id,
        'products_total' => 4000,
        'delivery_fee' => 800,
        'confirmation_locked' => true,
        'confirmation_attempts' => 3,
    ]);

    $this->actingAs($admin);

    Livewire::test(DisputesPage::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$order])
        ->assertSee("#{$order->id}")
        ->assertTableColumnFormattedStateSet('id', "#{$order->id}", $order)
        ->assertTableColumnStateSet('total_client', 4800.0, $order)
        ->searchTable((string) $order->id)
        ->assertCanSeeTableRecords([$order])
        ->callTableAction('unlockCode', $order)
        ->assertHasNoTableActionErrors()
        ->assertNotified("Commande #{$order->id} débloquée");

    expect($order->fresh()->confirmation_locked)->toBeFalse();

    $log = ActivityLog::where('action', 'order_code_unlocked')
        ->where('entity_id', $order->id)
        ->latest('id')
        ->first();
    expect($log)->not->toBeNull();
    expect($log->properties['order_id'] ?? null)->toBe($order->id);
});

it('affiche et enregistre les notifications et cancel_reason reels dans OrderResource', function () {
    $city = City::factory()->create();
    $admin = User::factory()->create(['role' => 'super_admin', 'city_id' => $city->id]);
    $client = User::factory()->create(['role' => 'client', 'city_id' => $city->id]);
    $driverUser = User::factory()->create(['name' => 'Issouf Ouattara', 'role' => 'driver', 'city_id' => $city->id]);
    $courier = Courier::factory()->create(['user_id' => $driverUser->id, 'city_id' => $city->id, 'is_active' => true]);
    $merchant = Merchant::factory()->create(['city_id' => $city->id]);

    $orderCancel = Order::factory()->create([
        'client_id' => $client->id,
        'merchant_id' => $merchant->id,
        'city_id' => $city->id,
        'status' => Order::STATUS_PENDING,
    ]);

    $orderReassign = Order::factory()->create([
        'client_id' => $client->id,
        'merchant_id' => $merchant->id,
        'city_id' => $city->id,
        'status' => Order::STATUS_ACCEPTED,
        'confirmation_locked' => true,
    ]);

    $this->actingAs($admin);

    Livewire::test(ListOrders::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$orderCancel, $orderReassign])
        ->callTableAction('cancelOrder', $orderCancel, data: [
            'cancellation_reason' => 'Client injoignable par téléphone',
        ])
        ->assertHasNoTableActionErrors()
        ->assertNotified("Commande #{$orderCancel->id} annulée")
        ->callTableAction('reassignCourier', $orderReassign, data: [
            'courier_id' => $courier->id,
        ])
        ->assertHasNoTableActionErrors()
        ->assertNotified("Commande #{$orderReassign->id} réassignée")
        ->callTableAction('unlockCode', $orderReassign)
        ->assertHasNoTableActionErrors()
        ->assertNotified("Code débloqué pour commande #{$orderReassign->id}");

    $freshCancelled = $orderCancel->fresh();
    expect($freshCancelled->status)->toBe('cancelled');
    expect($freshCancelled->cancel_reason)->toBe('Client injoignable par téléphone');
    expect($freshCancelled->cancelled_at)->not->toBeNull();
    expect($orderReassign->fresh()->courier_id)->toBe($courier->id);
    expect($orderReassign->fresh()->confirmation_locked)->toBeFalse();
});
