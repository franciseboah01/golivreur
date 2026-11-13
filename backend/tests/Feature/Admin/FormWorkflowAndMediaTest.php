<?php

use App\Filament\Pages\PendingValidationsPage;
use App\Filament\Pages\SettingsPage;
use App\Filament\Resources\AdministratorResource\Pages\CreateAdministrator;
use App\Filament\Resources\ClientResource\Pages\ListClients;
use App\Filament\Resources\CourierResource\Pages\CreateCourier;
use App\Filament\Resources\CourierResource\Pages\ListCouriers;
use App\Filament\Resources\MerchantCategoryResource;
use App\Filament\Resources\MerchantCategoryResource\Pages\CreateMerchantCategory;
use App\Filament\Resources\MerchantCategoryResource\Pages\ListMerchantCategories;
use App\Filament\Resources\MerchantResource\Pages\CreateMerchant;
use App\Filament\Resources\MerchantResource\Pages\ListMerchants;
use App\Filament\Resources\OrderResource\Pages\CreateOrder;
use App\Filament\Resources\ParcelResource\Pages\CreateParcel;
use App\Filament\Resources\RefundResource\Pages\ListRefunds;
use App\Filament\Resources\UserResource\Pages\CreateUser;
use App\Filament\Resources\VehicleTypeResource;
use App\Filament\Resources\VehicleTypeResource\Pages\ListVehicleTypes;
use App\Filament\Resources\WalletTransactionResource\Pages\ListWalletTransactions;
use App\Models\City;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\MerchantCategory;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

it('masque Categories Activite et Types de Vehicules du menu principal et les rattache aux sections partenaires', function () {
    expect(MerchantCategoryResource::shouldRegisterNavigation())->toBeFalse();
    expect(VehicleTypeResource::shouldRegisterNavigation())->toBeFalse();

    $admin = User::factory()->create(['role' => 'super_admin']);
    $this->actingAs($admin);

    Livewire::test(ListMerchants::class)
        ->assertSuccessful()
        ->assertSee('Catégories d\'Activité');

    Livewire::test(ListCouriers::class)
        ->assertSuccessful()
        ->assertSee('Types de Véhicules');

    Livewire::test(ListMerchantCategories::class)->assertSuccessful();
    Livewire::test(ListVehicleTypes::class)->assertSuccessful();
});

it('permet de créer une catégorie d\'activité avec emoji et logo uploadé exposés par l\'API', function () {
    Storage::fake('public');

    $admin = User::factory()->create(['role' => 'super_admin']);
    $this->actingAs($admin);

    $logo = UploadedFile::fake()->image('cat-glacier.png', 256, 256);

    Livewire::test(CreateMerchantCategory::class)
        ->fillForm([
            'emoji' => '🍨',
            'name' => 'Glacier Test Live',
            'slug' => 'glacier-test-live',
            'logo_path' => $logo,
            'description' => 'Glaces et sorbets maison',
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $cat = MerchantCategory::where('slug', 'glacier-test-live')->first();
    expect($cat)->not->toBeNull();
    expect($cat->emoji)->toBe('🍨');
    expect($cat->logo_path)->not->toBeNull();
    Storage::disk('public')->assertExists($cat->logo_path);

    $apiRes = $this->getJson('/api/v1/client/categories');
    $apiRes->assertOk();
    $found = collect($apiRes->json('donnees'))->firstWhere('slug', 'glacier-test-live');
    expect($found)->not->toBeNull();
    expect($found['name'])->toBe('Glacier Test Live');
    expect($found['emoji'])->toBe('🍨');
    expect($found['logo_url'])->toContain('storage/'.$cat->logo_path);
});

it('permet de créer un commerce avec nouveau gestionnaire, photo, logo et emoji puis l\'expose à l\'app mobile', function () {
    Storage::fake('public');

    $city = City::factory()->create(['name' => 'Daloa Test']);
    $admin = User::factory()->create(['role' => 'super_admin', 'city_id' => $city->id]);
    $this->actingAs($admin);

    // Simulation création du compte gestionnaire depuis le formulaire commerce
    $manager = User::create([
        'name' => 'Gérant Boutique Test',
        'phone' => '0709998877',
        'email' => 'gerant.test@golivreur.ci',
        'password' => Hash::make('Secret2026!'),
        'role' => 'merchant',
        'is_active' => true,
        'phone_verified_at' => now(),
    ]);

    $photo = UploadedFile::fake()->image('vitrine.jpg', 800, 600);
    $logo = UploadedFile::fake()->image('logo.png', 300, 300);

    Livewire::test(CreateMerchant::class)
        ->fillForm([
            'user_id' => $manager->id,
            'city_id' => $city->id,
            'category' => 'restaurant',
            'emoji' => '🍗',
            'name' => 'Rôtisserie Abidjanaise',
            'photo_path' => $photo,
            'logo_path' => $logo,
            'address' => 'Boulevard Principal, Daloa',
            'commission_rate' => 15,
            'prep_time_minutes' => 20,
            'is_open' => true,
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $merchant = Merchant::where('name', 'Rôtisserie Abidjanaise')->first();
    expect($merchant)->not->toBeNull();
    expect($merchant->user_id)->toBe($manager->id);
    expect($merchant->emoji)->toBe('🍗');
    expect($merchant->photo_path)->not->toBeNull();
    expect($merchant->logo_path)->not->toBeNull();
    Storage::disk('public')->assertExists($merchant->photo_path);
    Storage::disk('public')->assertExists($merchant->logo_path);

    $apiRes = $this->getJson("/api/v1/client/merchants?city_id={$city->id}");
    $apiRes->assertOk();
    $found = collect($apiRes->json('donnees'))->firstWhere('name', 'Rôtisserie Abidjanaise');
    expect($found)->not->toBeNull();
    expect($found['emoji'])->toBe('🍗');
    expect($found['logo_url'])->toContain('storage/'.$merchant->logo_path);
    expect($found['photo_url'])->toContain('storage/'.$merchant->photo_path);
});

it('permet de créer un livreur avec upload photo réel', function () {
    Storage::fake('public');

    $city = City::factory()->create();
    $admin = User::factory()->create(['role' => 'super_admin', 'city_id' => $city->id]);
    $driver = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    $this->actingAs($admin);

    $portrait = UploadedFile::fake()->image('livreur.png', 400, 400);

    Livewire::test(CreateCourier::class)
        ->fillForm([
            'user_id' => $driver->id,
            'city_id' => $city->id,
            'vehicle_type' => 'moto',
            'photo_path' => $portrait,
            'vehicle_plate' => '4567 CD 01',
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $courier = Courier::where('user_id', $driver->id)->first();
    expect($courier)->not->toBeNull();
    expect($courier->photo_path)->not->toBeNull();
    Storage::disk('public')->assertExists($courier->photo_path);
});

it('charge tous les formulaires harmonisés avec listes déroulantes sans erreur', function () {
    $admin = User::factory()->create(['role' => 'super_admin']);
    $this->actingAs($admin);

    foreach ([
        CreateAdministrator::class,
        ListClients::class,
        CreateOrder::class,
        CreateParcel::class,
        CreateUser::class,
        ListWalletTransactions::class,
        SettingsPage::class,
        ListRefunds::class,
        PendingValidationsPage::class,
    ] as $pageClass) {
        Livewire::test($pageClass)->assertSuccessful();
    }
});
