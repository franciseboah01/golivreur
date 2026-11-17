<?php

use App\Filament\Pages\Widgets\PendingCouriersWidget;
use App\Models\City;
use App\Models\Courier;
use App\Models\User;
use App\Models\VehicleType;
use App\Models\Wallet;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

it('permet a un candidat livreur de se pre-inscrire avec is_active=false sans delivrer de token', function () {
    $city = City::factory()->create(['name' => 'Daloa', 'is_active' => true]);
    VehicleType::firstOrCreate(
        ['slug' => 'moto'],
        ['name' => 'Moto', 'is_active' => true],
    );

    $response = $this->postJson('/api/v1/auth/register-driver', [
        'name' => 'Kouamé Yao',
        'phone' => '+225 07 08 11 22 33',
        'password' => 'DriverSecret2026',
        'city_id' => $city->id,
        'vehicle_type' => 'moto',
        'vehicle_plate' => '4589-JK-01',
    ]);

    $response->assertCreated()
        ->assertJsonPath('succes', true)
        ->assertJsonPath(
            'message',
            'Pré-inscription enregistrée. Passez en agence GoLivreur pour vérifier vos pièces et activer votre compte.'
        );

    expect($response->json('donnees.access_token'))->toBeNull();

    $user = User::where('phone', '0708112233')->first();
    expect($user)->not->toBeNull()
        ->and($user->name)->toBe('Kouamé Yao')
        ->and($user->role)->toBe('driver')
        ->and($user->city_id)->toBe($city->id)
        ->and((bool) $user->is_active)->toBeFalse()
        ->and(Hash::check('DriverSecret2026', (string) $user->password))->toBeTrue();

    $courier = Courier::where('user_id', $user->id)->first();
    expect($courier)->not->toBeNull()
        ->and($courier->city_id)->toBe($city->id)
        ->and($courier->vehicle_type)->toBe('moto')
        ->and($courier->vehicle_plate)->toBe('4589-JK-01')
        ->and((bool) $courier->is_active)->toBeFalse()
        ->and((bool) $courier->is_online)->toBeFalse();

    $wallet = Wallet::where('owner_type', 'courier')->where('owner_id', $courier->id)->first();
    expect($wallet)->not->toBeNull()
        ->and((int) $wallet->balance)->toBe(0);
});

it('bloque la connexion login-password en 403 tant que le dossier livreur nest pas valide en agence puis autorise apres validation Filament', function () {
    $city = City::factory()->create(['name' => 'Daloa', 'is_active' => true]);
    VehicleType::firstOrCreate(
        ['slug' => 'moto'],
        ['name' => 'Moto', 'is_active' => true],
    );

    $this->postJson('/api/v1/auth/register-driver', [
        'name' => 'Bakayoko Ibrahim',
        'phone' => '0708445566',
        'password' => 'MonMdpLivreur2026',
        'city_id' => $city->id,
        'vehicle_type' => 'moto',
        'vehicle_plate' => '7788-CI-01',
    ])->assertCreated();

    // Mauvais mot de passe -> 422
    $this->postJson('/api/v1/auth/login-password', [
        'phone' => '0708445566',
        'password' => 'MauvaisMdp',
    ])->assertStatus(422);

    // Bon mot de passe mais dossier non validé en agence -> 403 explicite
    $this->postJson('/api/v1/auth/login-password', [
        'phone' => '0708445566',
        'password' => 'MonMdpLivreur2026',
    ])->assertStatus(403)
        ->assertJsonPath('succes', false)
        ->assertJsonPath(
            'message',
            'Votre dossier livreur est en attente de validation. Passez en agence GoLivreur pour vérifier vos pièces et activer votre compte.'
        );

    // Validation en 1 clic dans Filament (Validations en attente) par le City Manager de la ville
    $cityManager = User::factory()->create([
        'role' => 'city_manager',
        'city_id' => $city->id,
        'is_active' => true,
    ]);
    $courier = Courier::whereHas('user', fn ($q) => $q->where('phone', '0708445566'))->firstOrFail();

    $this->actingAs($cityManager);
    Livewire::test(PendingCouriersWidget::class)
        ->callTableAction('validateCourier', $courier);

    expect((bool) $courier->fresh()->is_active)->toBeTrue()
        ->and((bool) $courier->user->fresh()->is_active)->toBeTrue();

    // Après validation en agence -> login-password réussit (200 + JWT)
    $loginRes = $this->postJson('/api/v1/auth/login-password', [
        'phone' => '0708445566',
        'password' => 'MonMdpLivreur2026',
    ]);

    $loginRes->assertOk()
        ->assertJsonPath('succes', true)
        ->assertJsonStructure(['donnees' => ['access_token', 'refresh_token', 'user']]);
});

