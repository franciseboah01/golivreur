<?php

use App\Filament\Pages\PendingValidationsPage;
use App\Filament\Pages\Widgets\PendingCouriersWidget;
use App\Models\City;
use App\Models\Courier;
use App\Models\User;
use Livewire\Livewire;

it('affiche les livreurs en attente de validation sur la page des validations', function () {
    $city = City::factory()->create();
    $admin = User::factory()->create(['role' => 'super_admin', 'city_id' => $city->id]);
    $driverUser = User::factory()->create(['name' => 'Drissa Testeur', 'role' => 'driver', 'city_id' => $city->id]);
    $courier = Courier::factory()->create([
        'user_id' => $driverUser->id,
        'city_id' => $city->id,
        'is_active' => false,
    ]);

    $this->actingAs($admin);

    Livewire::test(PendingCouriersWidget::class)
        ->assertSee('Drissa Testeur')
        ->assertSee('Livreurs Partenaires en Attente de Validation');
});

it('permet de basculer entre les onglets livreurs et commerces sur la page de validation', function () {
    $city = City::factory()->create();
    $admin = User::factory()->create(['role' => 'super_admin', 'city_id' => $city->id]);
    $this->actingAs($admin);

    Livewire::test(PendingValidationsPage::class)
        ->assertSuccessful()
        ->call('setTab', 'couriers')
        ->assertSet('activeTab', 'couriers')
        ->call('setTab', 'merchants')
        ->assertSet('activeTab', 'merchants')
        ->call('setTab', 'all')
        ->assertSet('activeTab', 'all');
});
