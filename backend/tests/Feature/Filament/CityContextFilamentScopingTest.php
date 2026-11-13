<?php

use App\Filament\Resources\ClientResource;
use App\Filament\Resources\CourierResource;
use App\Filament\Resources\MerchantResource;
use App\Filament\Resources\OrderResource;
use App\Filament\Resources\ParcelResource;
use App\Models\City;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Parcel;
use App\Models\User;

it('cloisonne strictement les ressources Filament par ville pour un admin local', function () {
    $daloa = City::factory()->create(['name' => 'Daloa '.uniqid()]);
    $bouake = City::factory()->create(['name' => 'Bouaké '.uniqid()]);

    $cityManager = User::factory()->create([
        'role' => 'city_manager',
        'city_id' => $daloa->id,
    ]);

    // Données Daloa
    $clientDaloa = User::factory()->create(['role' => 'client', 'city_id' => $daloa->id]);
    $merchantUserDaloa = User::factory()->create(['role' => 'merchant', 'city_id' => $daloa->id]);
    $merchantDaloa = Merchant::factory()->create(['user_id' => $merchantUserDaloa->id, 'city_id' => $daloa->id, 'is_active' => false]);
    $courierUserDaloa = User::factory()->create(['role' => 'driver', 'city_id' => $daloa->id]);
    $courierDaloa = Courier::factory()->create(['user_id' => $courierUserDaloa->id, 'city_id' => $daloa->id, 'is_active' => false]);
    $orderDaloa = Order::factory()->create(['merchant_id' => $merchantDaloa->id, 'city_id' => $daloa->id]);
    $parcelDaloa = Parcel::factory()->create(['city_id' => $daloa->id]);

    // Données Bouaké (autre ville)
    $clientBouake = User::factory()->create(['role' => 'client', 'city_id' => $bouake->id]);
    $merchantUserBouake = User::factory()->create(['role' => 'merchant', 'city_id' => $bouake->id]);
    $merchantBouake = Merchant::factory()->create(['user_id' => $merchantUserBouake->id, 'city_id' => $bouake->id, 'is_active' => false]);
    $courierUserBouake = User::factory()->create(['role' => 'driver', 'city_id' => $bouake->id]);
    $courierBouake = Courier::factory()->create(['user_id' => $courierUserBouake->id, 'city_id' => $bouake->id, 'is_active' => false]);
    $orderBouake = Order::factory()->create(['merchant_id' => $merchantBouake->id, 'city_id' => $bouake->id]);
    $parcelBouake = Parcel::factory()->create(['city_id' => $bouake->id]);

    // 1. Authentification en City Manager Daloa
    auth()->login($cityManager);

    // Vérification OrderResource
    $orders = OrderResource::getEloquentQuery()->pluck('id');
    expect($orders)->toContain($orderDaloa->id)
        ->not->toContain($orderBouake->id);

    // Vérification ParcelResource
    $parcels = ParcelResource::getEloquentQuery()->pluck('id');
    expect($parcels)->toContain($parcelDaloa->id)
        ->not->toContain($parcelBouake->id);

    // Vérification MerchantResource
    $merchants = MerchantResource::getEloquentQuery()->pluck('id');
    expect($merchants)->toContain($merchantDaloa->id)
        ->not->toContain($merchantBouake->id);

    // Vérification CourierResource
    $couriers = CourierResource::getEloquentQuery()->pluck('id');
    expect($couriers)->toContain($courierDaloa->id)
        ->not->toContain($courierBouake->id);

    // Vérification ClientResource
    $clients = ClientResource::getEloquentQuery()->pluck('id');
    expect($clients)->toContain($clientDaloa->id)
        ->not->toContain($clientBouake->id);

    // 2. Authentification en Super Admin : voit toutes les villes
    $superAdmin = User::factory()->create([
        'role' => 'super_admin',
        'city_id' => null,
    ]);
    auth()->login($superAdmin);

    expect(OrderResource::getEloquentQuery()->pluck('id'))
        ->toContain($orderDaloa->id)
        ->toContain($orderBouake->id);

    expect(ParcelResource::getEloquentQuery()->pluck('id'))
        ->toContain($parcelDaloa->id)
        ->toContain($parcelBouake->id);

    expect(MerchantResource::getEloquentQuery()->pluck('id'))
        ->toContain($merchantDaloa->id)
        ->toContain($merchantBouake->id);

    expect(CourierResource::getEloquentQuery()->pluck('id'))
        ->toContain($courierDaloa->id)
        ->toContain($courierBouake->id);

    expect(ClientResource::getEloquentQuery()->pluck('id'))
        ->toContain($clientDaloa->id)
        ->toContain($clientBouake->id);
});
