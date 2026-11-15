<?php

use App\Filament\Pages\DisputesPage;
use App\Filament\Pages\EmergencyCenter;
use App\Filament\Pages\PendingValidationsPage;
use App\Filament\Pages\SettingsPage;
use App\Filament\Pages\Widgets\PendingCouriersWidget;
use App\Filament\Pages\Widgets\PendingMerchantsWidget;
use App\Filament\Resources\ActivityLogResource;
use App\Filament\Resources\AdministratorResource;
use App\Filament\Resources\CityResource;
use App\Filament\Resources\ClientResource;
use App\Filament\Resources\CourierResource;
use App\Filament\Resources\CourierResource\Pages\CreateCourier;
use App\Filament\Resources\CourierResource\Pages\ListCouriers;
use App\Filament\Resources\MerchantResource;
use App\Filament\Resources\MerchantResource\Pages\CreateMerchant;
use App\Filament\Resources\MerchantResource\Pages\ListMerchants;
use App\Filament\Resources\NeighborhoodResource;
use App\Filament\Resources\NeighborhoodResource\Pages\CreateNeighborhood;
use App\Filament\Resources\NeighborhoodResource\Pages\ListNeighborhoods;
use App\Filament\Resources\OrderResource;
use App\Filament\Resources\OrderResource\Pages\CreateOrder;
use App\Filament\Resources\OrderResource\Pages\EditOrder;
use App\Filament\Resources\OrderResource\Pages\ListOrders;
use App\Filament\Resources\ParcelResource;
use App\Filament\Resources\ParcelResource\Pages\CreateParcel;
use App\Filament\Resources\ParcelResource\Pages\ListParcels;
use App\Filament\Resources\RefundResource;
use App\Filament\Resources\RefundResource\Pages\ListRefunds;
use App\Filament\Resources\UserResource;
use App\Filament\Resources\UserResource\Pages\CreateUser;
use App\Filament\Resources\WalletTransactionResource;
use App\Filament\Resources\WebhookLogResource;
use App\Filament\Widgets\BlockedCodesAlertWidget;
use App\Filament\Widgets\EmergencyStatsWidget;
use App\Filament\Widgets\HorizonQueueWidget;
use App\Filament\Widgets\LatestOrdersWidget;
use App\Filament\Widgets\PaymentMethodsChart;
use App\Filament\Widgets\StatsOverview;
use App\Filament\Widgets\WeeklyOrdersChart;
use App\Filament\Widgets\WeeklyRevenueChart;
use App\Models\ActivityLog;
use App\Models\City;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\MerchantCategory;
use App\Models\Neighborhood;
use App\Models\Order;
use App\Models\Parcel;
use App\Models\Refund;
use App\Models\RoleHabilitation;
use App\Models\User;
use App\Models\VehicleType;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Models\WebhookLog;
use Database\Seeders\RoleHabilitationSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RoleHabilitationSeeder::class);

    MerchantCategory::firstOrCreate(
        ['slug' => 'restaurant'],
        ['name' => 'Restaurant', 'emoji' => '🍽️', 'is_active' => true]
    );

    VehicleType::firstOrCreate(
        ['slug' => 'moto'],
        ['name' => 'Moto', 'is_active' => true]
    );

    $this->daloa = City::factory()->create(['name' => 'Daloa']);
    $this->bouake = City::factory()->create(['name' => 'Bouaké']);

    $cmHab = RoleHabilitation::where('slug', 'city_manager')->first();
    $csHab = RoleHabilitation::where('slug', 'city_support')->first();
    $cfHab = RoleHabilitation::where('slug', 'city_finance')->first();

    $this->daloaManager = User::factory()->create([
        'role' => 'city_manager',
        'role_habilitation_id' => $cmHab?->id,
        'city_id' => $this->daloa->id,
    ]);

    $this->daloaSupport = User::factory()->create([
        'role' => 'city_support',
        'role_habilitation_id' => $csHab?->id,
        'city_id' => $this->daloa->id,
    ]);

    $this->nullCityManager = User::factory()->create([
        'role' => 'city_manager',
        'role_habilitation_id' => $cmHab?->id,
        'city_id' => null,
    ]);

    $this->nullCityFinance = User::factory()->create([
        'role' => 'city_finance',
        'role_habilitation_id' => $cfHab?->id,
        'city_id' => null,
    ]);

    $this->superAdmin = User::factory()->create([
        'role' => 'super_admin',
        'city_id' => null,
    ]);
});

it('isole UserResource, CityResource, NeighborhoodResource, ActivityLogResource et WebhookLogResource par ville', function () {
    $daloaClient = User::factory()->create(['role' => 'client', 'city_id' => $this->daloa->id, 'name' => 'Client Daloa Only']);
    $bouakeClient = User::factory()->create(['role' => 'client', 'city_id' => $this->bouake->id, 'name' => 'Client Bouake Hidden']);

    $daloaHood = Neighborhood::create(['city_id' => $this->daloa->id, 'name' => 'Quartier Daloa', 'is_active' => true, 'distance_km' => 2.0]);
    $bouakeHood = Neighborhood::create(['city_id' => $this->bouake->id, 'name' => 'Quartier Bouake', 'is_active' => true, 'distance_km' => 3.0]);

    $daloaLog = ActivityLog::create([
        'user_id' => $daloaClient->id,
        'action' => 'test_daloa',
        'entity_type' => 'order',
        'entity_id' => 1,
        'properties' => ['city_id' => $this->daloa->id],
        'severity' => 'info',
    ]);
    $bouakeLog = ActivityLog::create([
        'user_id' => $bouakeClient->id,
        'action' => 'test_bouake',
        'entity_type' => 'order',
        'entity_id' => 2,
        'properties' => ['city_id' => $this->bouake->id],
        'severity' => 'info',
    ]);

    WebhookLog::create([
        'source' => 'hub2',
        'event_type' => 'payment.succeeded',
        'transaction_id' => 'tx_rattrapage_1',
        'payload' => ['test' => true],
        'signature_valid' => true,
        'status' => 'processed',
    ]);

    $this->actingAs($this->daloaManager);

    // UserResource scoped to Daloa
    $userIds = UserResource::getEloquentQuery()->pluck('id')->all();
    expect($userIds)->toContain($daloaClient->id)
        ->not->toContain($bouakeClient->id);

    // CityResource scoped to Daloa + mutations interdites hors ville / création
    expect(CityResource::getEloquentQuery()->pluck('id')->all())->toBe([$this->daloa->id])
        ->and(CityResource::canCreate())->toBeFalse()
        ->and(CityResource::canDelete($this->daloa))->toBeFalse()
        ->and(CityResource::canEdit($this->bouake))->toBeFalse();

    // NeighborhoodResource scoped to Daloa
    $hoodIds = NeighborhoodResource::getEloquentQuery()->pluck('id')->all();
    expect($hoodIds)->toContain($daloaHood->id)
        ->not->toContain($bouakeHood->id);

    // ActivityLogResource scoped to Daloa
    $logIds = ActivityLogResource::getEloquentQuery()->pluck('id')->all();
    expect($logIds)->toContain($daloaLog->id)
        ->not->toContain($bouakeLog->id);

    // WebhookLogResource interdit aux rôles locaux et requete vide
    expect(WebhookLogResource::canAccess())->toBeFalse()
        ->and(WebhookLogResource::getEloquentQuery()->count())->toBe(0);

    // EmergencyCenter & SettingsPage interdits aux rôles locaux (même si on force l'onglet en habilitation)
    $cmHab = RoleHabilitation::where('slug', 'city_manager')->first();
    $cmHab->update(['onglets' => array_unique(array_merge($cmHab->onglets ?? [], ['emergency_center', 'settings', 'webhook_logs']))]);
    $freshManager = $this->daloaManager->fresh();
    $this->actingAs($freshManager);

    expect(EmergencyCenter::canAccess())->toBeFalse()
        ->and(SettingsPage::canAccess())->toBeFalse()
        ->and(WebhookLogResource::canAccess())->toBeFalse();
});

it('isole DisputesPage, PendingValidationsPage, ListRefunds, OrderResource::reassignCourier, widgets et compteurs onglets', function () {
    $daloaClient = User::factory()->create(['role' => 'client', 'city_id' => $this->daloa->id]);
    $bouakeClient = User::factory()->create(['role' => 'client', 'city_id' => $this->bouake->id]);

    $daloaMerchant = Merchant::factory()->create(['city_id' => $this->daloa->id, 'is_active' => false]);
    $bouakeMerchant = Merchant::factory()->create(['city_id' => $this->bouake->id, 'is_active' => false]);

    $daloaDriverUser = User::factory()->create(['role' => 'driver', 'city_id' => $this->daloa->id]);
    $daloaCourier = Courier::factory()->create(['user_id' => $daloaDriverUser->id, 'city_id' => $this->daloa->id, 'is_active' => false]);
    $daloaActiveCourier = Courier::factory()->create(['city_id' => $this->daloa->id, 'is_active' => true]);

    $bouakeDriverUser = User::factory()->create(['role' => 'driver', 'city_id' => $this->bouake->id]);
    $bouakeCourier = Courier::factory()->create(['user_id' => $bouakeDriverUser->id, 'city_id' => $this->bouake->id, 'is_active' => false]);
    $bouakeActiveCourier = Courier::factory()->create(['city_id' => $this->bouake->id, 'is_active' => true]);

    $daloaLockedOrder = Order::factory()->create([
        'client_id' => $daloaClient->id,
        'merchant_id' => $daloaMerchant->id,
        'city_id' => $this->daloa->id,
        'status' => Order::STATUS_ACCEPTED,
        'confirmation_locked' => true,
        'confirmation_attempts' => 3,
    ]);

    $bouakeLockedOrder = Order::factory()->create([
        'client_id' => $bouakeClient->id,
        'merchant_id' => $bouakeMerchant->id,
        'city_id' => $this->bouake->id,
        'status' => Order::STATUS_ACCEPTED,
        'confirmation_locked' => true,
        'confirmation_attempts' => 3,
    ]);

    Parcel::factory()->create(['sender_id' => $daloaClient->id, 'city_id' => $this->daloa->id, 'status' => 'pending']);
    Parcel::factory()->create(['sender_id' => $bouakeClient->id, 'city_id' => $this->bouake->id, 'status' => 'pending']);

    Neighborhood::create(['city_id' => $this->daloa->id, 'name' => 'Zone D1', 'is_active' => true]);
    Neighborhood::create(['city_id' => $this->bouake->id, 'name' => 'Zone B1', 'is_active' => true]);

    $this->actingAs($this->daloaManager);

    // 1. DisputesPage badge + table + unlock cross-city bloqué
    expect(DisputesPage::getNavigationBadge())->toBe('1');

    Livewire::test(DisputesPage::class)
        ->assertCanSeeTableRecords([$daloaLockedOrder])
        ->assertCanNotSeeTableRecords([$bouakeLockedOrder])
        ->callTableAction('unlockCode', $bouakeLockedOrder);

    expect($bouakeLockedOrder->fresh()->confirmation_locked)->toBeTrue()
        ->and($bouakeLockedOrder->fresh()->confirmation_unlocked_by)->toBeNull()
        ->and(ActivityLog::where('action', 'order_code_unlocked')->where('entity_id', $bouakeLockedOrder->id)->exists())->toBeFalse();

    // 2. PendingValidationsPage badge + compteurs
    expect(PendingValidationsPage::getNavigationBadge())->toBe('2'); // 1 courier + 1 merchant Daloa

    $pendingPage = Livewire::test(PendingValidationsPage::class);
    expect($pendingPage->instance()->getPendingCouriersCountProperty())->toBe(1)
        ->and($pendingPage->instance()->getPendingMerchantsCountProperty())->toBe(1);

    // 3. OrderResource::reassignCourier refuse un livreur d'une autre ville
    Livewire::test(ListOrders::class)
        ->callTableAction('reassignCourier', $daloaLockedOrder, [
            'courier_id' => $bouakeActiveCourier->id,
        ]);

    expect($daloaLockedOrder->fresh()->courier_id)->toBeNull();

    // Mais accepte un livreur actif de Daloa
    Livewire::test(ListOrders::class)
        ->callTableAction('reassignCourier', $daloaLockedOrder, [
            'courier_id' => $daloaActiveCourier->id,
        ])
        ->assertHasNoTableActionErrors();

    expect($daloaLockedOrder->fresh()->courier_id)->toBe($daloaActiveCourier->id);

    // 4. ListRefunds::createRefund refuse une commande d'une autre ville
    Livewire::test(ListRefunds::class)
        ->callAction('createRefund', [
            'contexte' => 'order',
            'order_id' => $bouakeLockedOrder->id,
            'montant' => 1000,
            'motif' => 'Tentative cross-ville',
        ]);

    expect(Refund::where('order_id', $bouakeLockedOrder->id)->count())->toBe(0);

    // 5. HorizonQueueWidget interdit aux rôles locaux, EmergencyStatsWidget scopé
    expect(HorizonQueueWidget::canView())->toBeFalse();

    $emergencyWidget = app(EmergencyStatsWidget::class);
    $getStatsMethod = new ReflectionMethod($emergencyWidget, 'getStats');
    $getStatsMethod->setAccessible(true);
    $emergencyStats = $getStatsMethod->invoke($emergencyWidget);
    // Ville(s) surveillée(s) = 1 seule ville pour daloaManager
    expect($emergencyStats[0]->getValue())->toBe('0 / 1 villes');

    // 6. Badges d'onglets List* scopés par ville
    $ordersPage = app(ListOrders::class);
    $parcelsPage = app(ListParcels::class);
    $merchantsPage = app(ListMerchants::class);
    $couriersPage = app(ListCouriers::class);
    $hoodsPage = app(ListNeighborhoods::class);

    expect($ordersPage->getTabs()['all']->getBadge())->toBe(1)
        ->and($parcelsPage->getTabs()['all']->getBadge())->toBe(1)
        ->and($merchantsPage->getTabs()['all']->getBadge())->toBe(1)
        ->and($couriersPage->getTabs()['all']->getBadge())->toBe(2)
        ->and($hoodsPage->getTabs()['all']->getBadge())->toBe(1);
});

it('force city_id cote serveur sur CreateOrder, CreateParcel, CreateMerchant, CreateCourier, CreateNeighborhood et CreateUser', function () {
    $daloaClient = User::factory()->create(['role' => 'client', 'city_id' => $this->daloa->id]);
    $daloaMerchant = Merchant::factory()->create(['city_id' => $this->daloa->id]);
    $daloaMerchantUser = User::factory()->create(['role' => 'merchant', 'city_id' => $this->daloa->id]);
    $daloaDriverUser = User::factory()->create(['role' => 'driver', 'city_id' => $this->daloa->id]);

    $this->actingAs($this->daloaManager);

    // 1. CreateNeighborhood avec city_id forgé = Bouaké
    Livewire::test(CreateNeighborhood::class)
        ->set('data.city_id', $this->bouake->id)
        ->fillForm([
            'name' => 'Quartier Daloa Forcé',
            'is_active' => true,
            'distance_km' => 2.5,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $hood = Neighborhood::where('name', 'Quartier Daloa Forcé')->first();
    expect($hood)->not->toBeNull()
        ->and($hood->city_id)->toBe($this->daloa->id);

    // 2. CreateOrder avec city_id forgé = Bouaké
    Livewire::test(CreateOrder::class)
        ->set('data.city_id', $this->bouake->id)
        ->fillForm([
            'client_id' => $daloaClient->id,
            'merchant_id' => $daloaMerchant->id,
            'status' => 'pending',
            'payment_method' => 'cash',
            'payment_status' => 'pending_cash',
            'products_total' => 2000,
            'delivery_fee' => 500,
            'platform_commission' => 300,
            'merchant_amount' => 1700,
            'courier_amount' => 400,
            'delivery_address' => 'Quartier Commerce Daloa',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $order = Order::where('delivery_address', 'Quartier Commerce Daloa')->first();
    expect($order)->not->toBeNull()
        ->and($order->city_id)->toBe($this->daloa->id);

    // 3. CreateParcel avec city_id forgé = Bouaké
    Livewire::test(CreateParcel::class)
        ->set('data.city_id', $this->bouake->id)
        ->fillForm([
            'sender_id' => $daloaClient->id,
            'size' => 'moyen',
            'recipient_name' => 'Destinataire Test',
            'recipient_phone' => '0707070707',
            'payment_method' => 'cash',
            'payment_status' => 'pending_cash',
            'shipping_fee' => 1000,
            'platform_commission' => 250,
            'courier_amount' => 750,
            'status' => 'pending',
            'pickup_address' => 'Point Collecte Daloa',
            'delivery_address' => 'Point Livraison Daloa',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $parcel = Parcel::where('pickup_address', 'Point Collecte Daloa')->first();
    expect($parcel)->not->toBeNull()
        ->and($parcel->city_id)->toBe($this->daloa->id);

    // 4. CreateMerchant avec city_id forgé = Bouaké
    Livewire::test(CreateMerchant::class)
        ->set('data.city_id', $this->bouake->id)
        ->fillForm([
            'user_id' => $daloaMerchantUser->id,
            'category' => 'restaurant',
            'emoji' => '🍗',
            'name' => 'Maquis Daloa Forcé',
            'address' => 'Rue Principale Daloa',
            'commission_rate' => 15,
            'prep_time_minutes' => 20,
            'is_open' => true,
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $merchant = Merchant::where('name', 'Maquis Daloa Forcé')->first();
    expect($merchant)->not->toBeNull()
        ->and($merchant->city_id)->toBe($this->daloa->id);

    // 5. CreateCourier avec city_id forgé = Bouaké
    Livewire::test(CreateCourier::class)
        ->set('data.city_id', $this->bouake->id)
        ->fillForm([
            'user_id' => $daloaDriverUser->id,
            'vehicle_type' => 'moto',
            'vehicle_plate' => 'CI-9999-DL',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $courier = Courier::where('vehicle_plate', 'CI-9999-DL')->first();
    expect($courier)->not->toBeNull()
        ->and($courier->city_id)->toBe($this->daloa->id);

    // 6. CreateUser avec city_id forgé = Bouaké (UserResource utilise l'onglet 'clients')
    Livewire::test(CreateUser::class)
        ->set('data.city_id', $this->bouake->id)
        ->fillForm([
            'name' => 'Nouveau Client Daloa',
            'phone' => '0711223344',
            'role' => 'client',
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $newUser = User::where('phone', '0711223344')->first();
    expect($newUser)->not->toBeNull()
        ->and($newUser->city_id)->toBe($this->daloa->id);

    // 7. EditOrder avec city_id forgé = Bouaké reste verrouillé sur Daloa
    Livewire::test(EditOrder::class, ['record' => $order->getRouteKey()])
        ->set('data.city_id', $this->bouake->id)
        ->call('save')
        ->assertHasNoFormErrors();

    expect($order->fresh()->city_id)->toBe($this->daloa->id);

    // Vérification super_admin peut librement créer sur Bouaké
    $this->actingAs($this->superAdmin);
    Livewire::test(CreateNeighborhood::class)
        ->fillForm([
            'city_id' => $this->bouake->id,
            'name' => 'Quartier Bouaké SA',
            'is_active' => true,
            'distance_km' => 3.0,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Neighborhood::where('name', 'Quartier Bouaké SA')->first()?->city_id)->toBe($this->bouake->id);
});

it('est fail-closed (0 enregistrement, 0 stat, 403 mutation et policy) si un admin local a city_id null', function () {
    $daloaClient = User::factory()->create(['role' => 'client', 'city_id' => $this->daloa->id]);
    $nullCityClient = User::factory()->create(['role' => 'client', 'city_id' => null]);

    $merchant = Merchant::factory()->create(['city_id' => $this->daloa->id, 'is_active' => false]);
    $courier = Courier::factory()->create(['city_id' => $this->daloa->id, 'is_active' => false, 'is_online' => true]);
    $order = Order::factory()->create([
        'client_id' => $daloaClient->id,
        'merchant_id' => $merchant->id,
        'courier_id' => $courier->id,
        'city_id' => $this->daloa->id,
        'status' => Order::STATUS_DELIVERED,
        'products_total' => 5000,
        'delivery_fee' => 1000,
        'platform_commission' => 750,
        'confirmation_locked' => true,
        'delivered_at' => now(),
    ]);
    $parcel = Parcel::factory()->create([
        'sender_id' => $daloaClient->id,
        'city_id' => $this->daloa->id,
        'status' => 'delivered',
        'shipping_fee' => 1500,
        'delivered_at' => now(),
    ]);
    Refund::create([
        'order_id' => $order->id,
        'client_id' => $daloaClient->id,
        'city_id' => $this->daloa->id,
        'amount' => 1000,
        'reason' => 'Test fail-closed',
        'cost_bearer' => Refund::COST_PLATFORM,
        'status' => Refund::STATUS_REQUESTED,
    ]);
    $wallet = Wallet::create([
        'owner_type' => 'courier',
        'owner_id' => $courier->id,
        'balance' => 1000,
    ]);
    WalletTransaction::create([
        'wallet_id' => $wallet->id,
        'order_id' => $order->id,
        'type' => 'credit_delivery',
        'amount' => 1000,
        'balance_before' => 0,
        'balance_after' => 1000,
        'reference' => 'TX-RATTRAPAGE-NULL-1',
        'description' => 'Test fail-closed tx',
        'status' => 'completed',
    ]);
    Neighborhood::create(['city_id' => $this->daloa->id, 'name' => 'Zone Null Check', 'is_active' => true]);
    ActivityLog::create([
        'user_id' => $nullCityClient->id,
        'action' => 'null_city_action',
        'entity_type' => 'user',
        'entity_id' => $nullCityClient->id,
        'properties' => [],
        'severity' => 'info',
    ]);

    $this->actingAs($this->nullCityManager);

    // 1. Toutes les resources retournent 0 enregistrement
    expect(OrderResource::getEloquentQuery()->count())->toBe(0)
        ->and(ParcelResource::getEloquentQuery()->count())->toBe(0)
        ->and(MerchantResource::getEloquentQuery()->count())->toBe(0)
        ->and(CourierResource::getEloquentQuery()->count())->toBe(0)
        ->and(ClientResource::getEloquentQuery()->count())->toBe(0)
        ->and(WalletTransactionResource::getEloquentQuery()->count())->toBe(0)
        ->and(RefundResource::getEloquentQuery()->count())->toBe(0)
        ->and(CityResource::getEloquentQuery()->count())->toBe(0)
        ->and(NeighborhoodResource::getEloquentQuery()->count())->toBe(0)
        ->and(UserResource::getEloquentQuery()->count())->toBe(0)
        ->and(AdministratorResource::getEloquentQuery()->count())->toBe(0)
        ->and(ActivityLogResource::getEloquentQuery()->count())->toBe(0)
        ->and(WebhookLogResource::getEloquentQuery()->count())->toBe(0);

    // 2. Pages & Badges = 0 / null
    expect(DisputesPage::getNavigationBadge())->toBeNull()
        ->and(PendingValidationsPage::getNavigationBadge())->toBeNull();

    Livewire::test(DisputesPage::class)->assertCanNotSeeTableRecords([$order]);
    $pendingPage = Livewire::test(PendingValidationsPage::class);
    expect($pendingPage->instance()->getPendingCouriersCountProperty())->toBe(0)
        ->and($pendingPage->instance()->getPendingMerchantsCountProperty())->toBe(0);

    // 3. Tous les Widgets du Dashboard sont fail-closed
    expect(BlockedCodesAlertWidget::canView())->toBeFalse()
        ->and(HorizonQueueWidget::canView())->toBeFalse();

    $statsWidget = app(StatsOverview::class);
    $statsMethod = new ReflectionMethod($statsWidget, 'getStats');
    $statsMethod->setAccessible(true);
    $stats = $statsMethod->invoke($statsWidget);
    expect($stats[0]->getValue())->toBe(0)
        ->and($stats[1]->getValue())->toBe('0 F CFA')
        ->and($stats[4]->getValue())->toBe('0 / 0');

    $emergencyWidget = app(EmergencyStatsWidget::class);
    $emMethod = new ReflectionMethod($emergencyWidget, 'getStats');
    $emMethod->setAccessible(true);
    $emStats = $emMethod->invoke($emergencyWidget);
    expect($emStats[0]->getValue())->toBe('0 / 0 villes')
        ->and($emStats[2]->getValue())->toBe('0 course(s)');

    foreach ([WeeklyOrdersChart::class, WeeklyRevenueChart::class, PaymentMethodsChart::class] as $chartClass) {
        $chart = app($chartClass);
        $dataMethod = new ReflectionMethod($chart, 'getData');
        $dataMethod->setAccessible(true);
        $chartData = $dataMethod->invoke($chart);
        $sum = array_sum(array_map('array_sum', array_column($chartData['datasets'] ?? [], 'data')));
        expect((float) $sum)->toBe(0.0);
    }

    Livewire::test(LatestOrdersWidget::class)->assertCanNotSeeTableRecords([$order]);
    Livewire::test(PendingCouriersWidget::class)->assertCanNotSeeTableRecords([$courier]);
    Livewire::test(PendingMerchantsWidget::class)->assertCanNotSeeTableRecords([$merchant]);

    // 4. UserPolicy interdit même quand cible et admin ont tous deux city_id = null
    expect($this->nullCityManager->can('view', $nullCityClient))->toBeFalse()
        ->and($this->nullCityManager->can('update', $nullCityClient))->toBeFalse()
        ->and($this->nullCityManager->can('delete', $nullCityClient))->toBeFalse();

    // 5. Create* par un admin local sans ville échoue en 403
    Livewire::test(CreateNeighborhood::class)
        ->assertForbidden();
    Livewire::test(CreateOrder::class)
        ->assertForbidden();
});
