<?php

use App\Filament\Resources\RefundResource\Pages\ListRefunds;
use App\Filament\Resources\RoleHabilitationResource\Pages\CreateRoleHabilitation;
use App\Models\City;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Parcel;
use App\Models\Refund;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Livewire\Livewire;

it('utilise id et total_client sur ListRefunds sans propriete inexistante', function () {
    $city = City::factory()->create();
    $admin = User::factory()->create(['role' => 'super_admin', 'city_id' => $city->id]);
    $client = User::factory()->create(['name' => 'Awa Koné', 'role' => 'client', 'city_id' => $city->id]);
    $driverUser = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    $courier = Courier::factory()->create(['user_id' => $driverUser->id, 'city_id' => $city->id]);
    $merchant = Merchant::factory()->create(['city_id' => $city->id]);

    $order = Order::factory()->create([
        'client_id' => $client->id,
        'merchant_id' => $merchant->id,
        'courier_id' => $courier->id,
        'city_id' => $city->id,
        'status' => Order::STATUS_DELIVERED,
        'payment_status' => 'paid',
        'products_total' => 4000,
        'delivery_fee' => 800,
    ]);

    $parcel = Parcel::factory()->create([
        'sender_id' => $client->id,
        'courier_id' => $courier->id,
        'city_id' => $city->id,
        'status' => Parcel::STATUS_LOST,
        'payment_status' => 'paid',
        'has_insurance' => true,
        'declared_value' => 25000,
    ]);

    $this->actingAs($admin);

    $orderLivewire = Livewire::test(ListRefunds::class)
        ->mountAction('createRefund')
        ->setActionData(['contexte' => 'order']);

    $orderOptions = $orderLivewire->instance()
        ->getMountedActionForm()
        ->getFlatFields()['order_id']
        ->getOptions();
    expect($orderOptions[$order->id] ?? null)->toBe("#{$order->id} - 4800 F CFA (Awa Koné)");

    $orderLivewire
        ->setActionData([
            'contexte' => 'order',
            'order_id' => $order->id,
            'montant' => 1500,
            'motif' => 'Plat renversé',
        ])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $orderRefund = Refund::where('order_id', $order->id)->first();
    expect($orderRefund)->not->toBeNull()
        ->and($orderRefund->amount)->toBe(1500)
        ->and($orderRefund->client_id)->toBe($client->id)
        ->and($orderRefund->status)->toBe(Refund::STATUS_APPROVED)
        ->and($orderRefund->cost_bearer)->toBe(Refund::COST_PLATFORM)
        ->and($orderRefund->reason)->toBe("Remboursement commande #{$order->id} : Plat renversé");

    $parcelLivewire = Livewire::test(ListRefunds::class)
        ->mountAction('createRefund')
        ->setActionData(['contexte' => 'parcel']);

    $parcelOptions = $parcelLivewire->instance()
        ->getMountedActionForm()
        ->getFlatFields()['parcel_id']
        ->getOptions();
    expect($parcelOptions[$parcel->id] ?? null)->toBe("#{$parcel->id} - Déclaré 25000 F CFA");

    $parcelLivewire
        ->setActionData([
            'contexte' => 'parcel',
            'parcel_id' => $parcel->id,
            'montant' => 2000,
            'motif' => 'Colis endommagé',
        ])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $parcelRefund = Refund::where('parcel_id', $parcel->id)->first();
    expect($parcelRefund)->not->toBeNull()
        ->and($parcelRefund->amount)->toBe(2000)
        ->and($parcelRefund->client_id)->toBe($client->id)
        ->and($parcelRefund->cost_bearer)->toBe(Refund::COST_PLATFORM)
        ->and($parcelRefund->reason)->toBe("Indemnisation colis #{$parcel->id} : Colis endommagé")
        ->and(WalletTransaction::where('type', 'refund')->count())->toBe(0)
        ->and(Wallet::count())->toBe(0);
});

it('refuse sur ListRefunds une commande en statut non éligible ou non payée sans créer de refund ni wallet', function () {
    $city = City::factory()->create();
    $admin = User::factory()->create(['role' => 'super_admin', 'city_id' => $city->id]);
    $pendingOrder = Order::factory()->create(['city_id' => $city->id, 'status' => Order::STATUS_PENDING, 'payment_status' => 'paid']);
    $unpaidOrder = Order::factory()->create(['city_id' => $city->id, 'status' => Order::STATUS_DELIVERED, 'payment_status' => 'pending']);

    $this->actingAs($admin);

    Livewire::test(ListRefunds::class)
        ->mountAction('createRefund')
        ->setActionData(['contexte' => 'order', 'order_id' => $pendingOrder->id, 'montant' => 1500, 'motif' => 'Test'])
        ->callMountedAction()
        ->assertNotified('Remboursement refusé');

    Livewire::test(ListRefunds::class)
        ->mountAction('createRefund')
        ->setActionData(['contexte' => 'order', 'order_id' => $unpaidOrder->id, 'montant' => 1500, 'motif' => 'Test'])
        ->callMountedAction()
        ->assertNotified('Remboursement refusé');

    expect(Refund::count())->toBe(0)
        ->and(WalletTransaction::where('type', 'refund')->count())->toBe(0);
});

it('affiche le message validation francais quand aucun onglet nest coche sur RoleHabilitation', function () {
    $admin = User::factory()->create(['role' => 'super_admin']);
    $this->actingAs($admin);

    Livewire::test(CreateRoleHabilitation::class)
        ->fillForm([
            'name' => 'Testeur Habilitation',
            'onglets' => [],
        ])
        ->call('create')
        ->assertHasFormErrors(['onglets'])
        ->assertSee('Veuillez cocher au moins un onglet autorisé.');

    Livewire::test(CreateRoleHabilitation::class)
        ->fillForm([
            'name' => 'Testeur Habilitation Valide',
            'onglets' => ['dashboard'],
        ])
        ->call('create')
        ->assertHasNoFormErrors();
});
