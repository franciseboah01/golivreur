<?php

namespace Tests\Feature\Admin;

use App\Filament\Pages\Widgets\PendingCouriersWidget;
use App\Filament\Pages\Widgets\PendingMerchantsWidget;
use App\Filament\Resources\ClientResource\Pages\ListClients;
use App\Filament\Resources\CourierResource\Pages\ListCouriers;
use App\Filament\Resources\MerchantResource\Pages\ListMerchants;
use App\Filament\Resources\OrderResource\Pages\ListOrders;
use App\Filament\Resources\ParcelResource\Pages\ListParcels;
use App\Filament\Resources\WalletTransactionResource\Pages\ListWalletTransactions;
use App\Filament\Widgets\BlockedCodesAlertWidget;
use App\Filament\Widgets\LatestOrdersWidget;
use App\Filament\Widgets\StatsOverview;
use App\Models\City;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Parcel;
use App\Models\RoleHabilitation;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FilamentCityContextIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RoleHabilitationSeeder::class);
    }

    public function test_city_manager_daloa_sees_no_orders_parcels_merchants_couriers_from_bouake(): void
    {
        $daloa = City::factory()->create(['name' => 'Daloa']);
        $bouake = City::factory()->create(['name' => 'Bouaké']);

        $cmDaloa = User::factory()->create([
            'role' => 'city_manager',
            'city_id' => $daloa->id,
        ]);

        // Données Daloa
        $clientDaloa = User::factory()->create(['role' => 'client', 'city_id' => $daloa->id]);
        $orderDaloa = Order::factory()->create(['city_id' => $daloa->id, 'client_id' => $clientDaloa->id]);
        $parcelDaloa = Parcel::factory()->create(['city_id' => $daloa->id, 'sender_id' => $clientDaloa->id]);
        $merchantDaloa = Merchant::factory()->create(['city_id' => $daloa->id]);
        $courierUserDaloa = User::factory()->create(['role' => 'driver', 'city_id' => $daloa->id]);
        $courierDaloa = Courier::factory()->create(['city_id' => $daloa->id, 'user_id' => $courierUserDaloa->id]);

        // Données Bouaké
        $clientBouake = User::factory()->create(['role' => 'client', 'city_id' => $bouake->id]);
        $orderBouake = Order::factory()->create(['city_id' => $bouake->id, 'client_id' => $clientBouake->id]);
        $parcelBouake = Parcel::factory()->create(['city_id' => $bouake->id, 'sender_id' => $clientBouake->id]);
        $merchantBouake = Merchant::factory()->create(['city_id' => $bouake->id]);
        $courierUserBouake = User::factory()->create(['role' => 'driver', 'city_id' => $bouake->id]);
        $courierBouake = Courier::factory()->create(['city_id' => $bouake->id, 'user_id' => $courierUserBouake->id]);

        $this->actingAs($cmDaloa);

        // 1. Commandes : Daloa visible, Bouaké absent
        Livewire::test(ListOrders::class)
            ->assertCanSeeTableRecords([$orderDaloa])
            ->assertCanNotSeeTableRecords([$orderBouake]);

        // 2. Colis : Daloa visible, Bouaké absent
        Livewire::test(ListParcels::class)
            ->assertCanSeeTableRecords([$parcelDaloa])
            ->assertCanNotSeeTableRecords([$parcelBouake]);

        // 3. Commerces : Daloa visible, Bouaké absent
        Livewire::test(ListMerchants::class)
            ->assertCanSeeTableRecords([$merchantDaloa])
            ->assertCanNotSeeTableRecords([$merchantBouake]);

        // 4. Livreurs : Daloa visible, Bouaké absent
        Livewire::test(ListCouriers::class)
            ->assertCanSeeTableRecords([$courierDaloa])
            ->assertCanNotSeeTableRecords([$courierBouake]);

        // 5. Clients : Daloa visible, Bouaké absent
        Livewire::test(ListClients::class)
            ->assertCanSeeTableRecords([$clientDaloa])
            ->assertCanNotSeeTableRecords([$clientBouake]);
    }

    public function test_national_roles_and_super_admin_see_all_cities(): void
    {
        $daloa = City::factory()->create(['name' => 'Daloa']);
        $bouake = City::factory()->create(['name' => 'Bouaké']);

        $superAdmin = User::factory()->create([
            'role' => 'super_admin',
            'city_id' => null,
        ]);

        $orderDaloa = Order::factory()->create(['city_id' => $daloa->id]);
        $orderBouake = Order::factory()->create(['city_id' => $bouake->id]);

        $this->actingAs($superAdmin);

        Livewire::test(ListOrders::class)
            ->assertCanSeeTableRecords([$orderDaloa, $orderBouake]);
    }

    public function test_stats_overview_adapts_label_and_counts_to_city_context(): void
    {
        $daloa = City::factory()->create(['name' => 'Daloa']);
        $bouake = City::factory()->create(['name' => 'Bouaké']);

        $courierDaloa = Courier::factory()->create(['city_id' => $daloa->id, 'is_online' => true, 'is_active' => true]);
        $courierBouake = Courier::factory()->create(['city_id' => $bouake->id, 'is_online' => true, 'is_active' => true]);

        // 1. City Manager Daloa
        $cmDaloa = User::factory()->create([
            'role' => 'city_manager',
            'city_id' => $daloa->id,
        ]);

        $this->actingAs($cmDaloa);
        $widgetLocal = Livewire::test(StatsOverview::class);
        $widgetLocal->assertSee('Flotte active à Daloa');
        $widgetLocal->assertSee('1 / 1'); // 1 seul livreur à Daloa

        // 2. Super Admin
        $superAdmin = User::factory()->create([
            'role' => 'super_admin',
            'city_id' => null,
        ]);

        $this->actingAs($superAdmin);
        $widgetGlobal = Livewire::test(StatsOverview::class);
        $widgetGlobal->assertSee('Flotte active (Toutes les villes)');
        $widgetGlobal->assertSee('2 / 2'); // 2 livreurs au total
    }

    public function test_city_finance_sees_only_wallet_transactions_from_own_city(): void
    {
        $daloa = City::factory()->create(['name' => 'Daloa']);
        $bouake = City::factory()->create(['name' => 'Bouaké']);

        $cfDaloa = User::factory()->create([
            'role' => 'city_finance',
            'city_id' => $daloa->id,
        ]);

        $merchantDaloa = Merchant::factory()->create(['city_id' => $daloa->id]);
        $walletDaloa = Wallet::create([
            'owner_type' => 'merchant',
            'owner_id' => $merchantDaloa->id,
            'balance' => 50000,
        ]);
        $txnDaloa = WalletTransaction::create([
            'wallet_id' => $walletDaloa->id,
            'type' => 'credit_delivery',
            'amount' => 5000,
            'balance_before' => 45000,
            'balance_after' => 50000,
            'reference' => 'TXN-DALOA-01',
            'status' => 'completed',
        ]);

        $merchantBouake = Merchant::factory()->create(['city_id' => $bouake->id]);
        $walletBouake = Wallet::create([
            'owner_type' => 'merchant',
            'owner_id' => $merchantBouake->id,
            'balance' => 30000,
        ]);
        $txnBouake = WalletTransaction::create([
            'wallet_id' => $walletBouake->id,
            'type' => 'credit_delivery',
            'amount' => 3000,
            'balance_before' => 27000,
            'balance_after' => 30000,
            'reference' => 'TXN-BOUAKE-01',
            'status' => 'completed',
        ]);

        $this->actingAs($cfDaloa);

        Livewire::test(ListWalletTransactions::class)
            ->assertCanSeeTableRecords([$txnDaloa])
            ->assertCanNotSeeTableRecords([$txnBouake]);
    }

    public function test_latest_orders_widget_scopes_by_city_context(): void
    {
        $daloa = City::factory()->create(['name' => 'Daloa']);
        $bouake = City::factory()->create(['name' => 'Bouaké']);

        $cmDaloa = User::factory()->create([
            'role' => 'city_manager',
            'city_id' => $daloa->id,
        ]);

        $orderDaloa = Order::factory()->create(['city_id' => $daloa->id]);
        $orderBouake = Order::factory()->create(['city_id' => $bouake->id]);

        $this->actingAs($cmDaloa);

        Livewire::test(LatestOrdersWidget::class)
            ->assertCanSeeTableRecords([$orderDaloa])
            ->assertCanNotSeeTableRecords([$orderBouake]);
    }

    public function test_pending_widgets_scope_by_city_context(): void
    {
        $daloa = City::factory()->create(['name' => 'Daloa']);
        $bouake = City::factory()->create(['name' => 'Bouaké']);

        $cmDaloa = User::factory()->create([
            'role' => 'city_manager',
            'city_id' => $daloa->id,
        ]);

        $courierDaloa = Courier::factory()->create(['city_id' => $daloa->id, 'is_active' => false]);
        $courierBouake = Courier::factory()->create(['city_id' => $bouake->id, 'is_active' => false]);

        $merchantDaloa = Merchant::factory()->create(['city_id' => $daloa->id, 'is_active' => false]);
        $merchantBouake = Merchant::factory()->create(['city_id' => $bouake->id, 'is_active' => false]);

        $this->actingAs($cmDaloa);

        Livewire::test(PendingCouriersWidget::class)
            ->assertCanSeeTableRecords([$courierDaloa])
            ->assertCanNotSeeTableRecords([$courierBouake]);

        Livewire::test(PendingMerchantsWidget::class)
            ->assertCanSeeTableRecords([$merchantDaloa])
            ->assertCanNotSeeTableRecords([$merchantBouake]);
    }
}
