<?php

use App\Filament\Widgets\PaymentMethodsChart;
use App\Models\City;
use App\Models\Order;
use App\Models\Parcel;
use App\Models\User;
use Livewire\Livewire;

it('compte correctement les commandes et colis en espèces, Orange Money (orange_money) et autre mode dans PaymentMethodsChart', function () {
    $city = City::factory()->create();
    $admin = User::factory()->create(['role' => 'super_admin', 'city_id' => null]);

    // 2 commandes en espèces + 1 colis en espèces = 3
    Order::factory()->cash()->count(2)->create(['city_id' => $city->id]);
    Parcel::factory()->cash()->create(['city_id' => $city->id]);

    // 3 commandes Orange Money ('orange_money') + 1 colis Orange Money = 4
    Order::factory()->count(3)->create([
        'city_id' => $city->id,
        'payment_method' => 'orange_money',
        'payment_status' => 'paid',
    ]);
    Parcel::factory()->create([
        'city_id' => $city->id,
        'payment_method' => 'orange_money',
        'payment_status' => 'paid',
    ]);

    // 1 commande Wave (autre mode) = 1
    Order::factory()->create([
        'city_id' => $city->id,
        'payment_method' => 'wave',
        'payment_status' => 'paid',
    ]);

    $this->actingAs($admin);

    $component = Livewire::test(PaymentMethodsChart::class)->assertSuccessful();

    $reflection = new ReflectionMethod(PaymentMethodsChart::class, 'getData');
    $reflection->setAccessible(true);

    /** @var array{datasets: array<int, array{data: array<int, int>}>, labels: array<int, string>} $chartData */
    $chartData = $reflection->invoke($component->instance());

    $compteurs = array_combine($chartData['labels'], $chartData['datasets'][0]['data']);

    expect($compteurs)->toBeArray()
        ->and($compteurs['Espèces à la livraison'] ?? null)->toBe(3)
        ->and($compteurs['Orange Money'] ?? null)->toBe(4)
        ->and($compteurs['Wave Mobile Money'] ?? null)->toBe(1)
        ->and($compteurs['MTN MoMo'] ?? null)->toBe(0);
});
