<?php

use App\Filament\Resources\CourierResource\Pages\CreateCourier;
use App\Jobs\AlerterAdminPasDelivreur;
use App\Jobs\NotifierLivreurGoColis;
use App\Jobs\NotifierLivreurGoShop;
use App\Jobs\SendPushNotification;
use App\Models\City;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Parcel;
use App\Models\User;
use App\Modules\GoShop\Services\AssignmentService;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

it('Point C1: CourierFactory et CreateCourier laissent current_lat, current_lng et location_updated_at a null sans GPS', function () {
    $city = City::factory()->create([
        'name' => 'Daloa',
        'center_lat' => 6.8770,
        'center_lng' => -6.4502,
    ]);

    // 1. Factory par defaut -> pas de coordonnees centre-ville
    $factoryCourier = Courier::factory()->create(['city_id' => $city->id]);
    expect($factoryCourier->current_lat)->toBeNull()
        ->and($factoryCourier->current_lng)->toBeNull()
        ->and($factoryCourier->location_updated_at)->toBeNull();

    // 2. Creation via Filament CreateCourier sans GPS -> reste null
    $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
    $driverUser = User::factory()->create(['role' => 'driver', 'city_id' => $city->id, 'is_active' => true]);

    $this->actingAs($admin);
    Livewire::test(CreateCourier::class)
        ->fillForm([
            'user_id' => $driverUser->id,
            'city_id' => $city->id,
            'vehicle_type' => 'moto',
            'vehicle_plate' => 'AB-7788-CI',
            'is_active' => true,
            'is_online' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $createdCourier = Courier::where('user_id', $driverUser->id)->firstOrFail();
    expect($createdCourier->current_lat)->toBeNull()
        ->and($createdCourier->current_lng)->toBeNull()
        ->and($createdCourier->location_updated_at)->toBeNull();
});

it('Point C2: AssignmentService exclut les livreurs sans GPS ou dont location_updated_at > 2 min (parametrable dans SettingsService)', function () {
    Queue::fake([SendPushNotification::class, NotifierLivreurGoShop::class, NotifierLivreurGoColis::class, AlerterAdminPasDelivreur::class]);

    $city = City::factory()->create(['name' => 'Daloa']);
    $merchant = Merchant::factory()->create([
        'city_id' => $city->id,
        'lat' => 6.8770,
        'lng' => -6.4502,
    ]);

    // 1. Livreur sans position -> exclu
    $noGps = Courier::factory()->create([
        'city_id' => $city->id,
        'is_online' => true,
        'is_active' => true,
        'current_lat' => null,
        'current_lng' => null,
        'location_updated_at' => null,
    ]);

    // 2. Livreur avec GPS mais location_updated_at null -> exclu
    $noTimestamp = Courier::factory()->create([
        'city_id' => $city->id,
        'is_online' => true,
        'is_active' => true,
        'current_lat' => 6.8771,
        'current_lng' => -6.4503,
        'location_updated_at' => null,
    ]);

    // 3. Livreur avec GPS vieux de 125s (> 2 min) -> exclu
    $staleCourier = Courier::factory()->create([
        'city_id' => $city->id,
        'is_online' => true,
        'is_active' => true,
        'current_lat' => 6.8771,
        'current_lng' => -6.4503,
        'location_updated_at' => now()->subSeconds(125),
    ]);

    $order = Order::factory()->create([
        'city_id' => $city->id,
        'merchant_id' => $merchant->id,
        'status' => Order::STATUS_ACCEPTED,
        'payment_method' => 'orange_money',
        'payment_status' => 'paid',
        'pickup_lat' => 6.8770,
        'pickup_lng' => -6.4502,
    ]);

    app(AssignmentService::class)->attribuerLivreurGoShop($order);
    Queue::assertNotPushed(NotifierLivreurGoShop::class);

    // 4. Livreur avec GPS recent (< 2 min, 45s) -> propose !
    $freshCourier = Courier::factory()->create([
        'city_id' => $city->id,
        'is_online' => true,
        'is_active' => true,
        'current_lat' => 6.8780,
        'current_lng' => -6.4510,
        'location_updated_at' => now()->subSeconds(45),
    ]);

    $orderFresh = Order::factory()->create([
        'city_id' => $city->id,
        'merchant_id' => $merchant->id,
        'status' => Order::STATUS_ACCEPTED,
        'payment_method' => 'orange_money',
        'payment_status' => 'paid',
        'pickup_lat' => 6.8770,
        'pickup_lng' => -6.4502,
    ]);

    app(AssignmentService::class)->attribuerLivreurGoShop($orderFresh);
    Queue::assertPushed(NotifierLivreurGoShop::class, fn (NotifierLivreurGoShop $job): bool => $job->orderId === $orderFresh->id
        && $job->courierId === $freshCourier->id);

    // 5. GoColis exclut egalement le livreur perime et retient le frais
    $parcel = Parcel::factory()->create([
        'city_id' => $city->id,
        'status' => Parcel::STATUS_PENDING,
        'payment_method' => 'orange_money',
        'payment_status' => 'paid',
        'pickup_lat' => 6.8770,
        'pickup_lng' => -6.4502,
    ]);

    app(AssignmentService::class)->attribuerLivreurGoColis($parcel);
    Queue::assertPushed(NotifierLivreurGoColis::class, fn (NotifierLivreurGoColis $job): bool => $job->parcelId === $parcel->id
        && $job->courierId === $freshCourier->id);

    // 6. Surcharge par ville via SettingsService : gps_fraicheur_minutes = 5 -> 4 min devient valide
    $freshCourier->update(['is_online' => false]);
    $staleCourier->update(['location_updated_at' => now()->subMinutes(4)]);
    app(SettingsService::class)->set('delais_regles', ['gps_fraicheur_minutes' => 5], $city->id);

    $orderSetting = Order::factory()->create([
        'city_id' => $city->id,
        'merchant_id' => $merchant->id,
        'status' => Order::STATUS_ACCEPTED,
        'payment_method' => 'orange_money',
        'payment_status' => 'paid',
        'pickup_lat' => 6.8770,
        'pickup_lng' => -6.4502,
    ]);

    app(AssignmentService::class)->attribuerLivreurGoShop($orderSetting);
    Queue::assertPushed(NotifierLivreurGoShop::class, fn (NotifierLivreurGoShop $job): bool => $job->orderId === $orderSetting->id
        && $job->courierId === $staleCourier->id);
});
