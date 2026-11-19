<?php

use App\Models\City;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\MerchantCategory;
use App\Models\Parcel;
use App\Models\User;
use App\Models\VehicleType;
use Illuminate\Support\Facades\Hash;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

it('synchronise automatiquement users.is_active quand un commerce pré-inscrit est activé et autorise la connexion', function () {
    $city = City::factory()->create(['name' => 'Bouaké', 'is_active' => true]);
    MerchantCategory::firstOrCreate(
        ['slug' => 'alimentation'],
        [
            'name' => 'Alimentation',
            'emoji' => '🛒',
            'is_active' => true,
        ]
    );

    $this->postJson('/api/v1/auth/register-merchant', [
        'name' => 'Superette Bouaké',
        'manager_name' => 'Kouamé Yao',
        'phone' => '0709112233',
        'password' => 'Secret123!',
        'city_id' => $city->id,
        'category' => 'alimentation',
        'address' => 'Quartier Air France',
    ])->assertCreated();

    $merchant = Merchant::where('name', 'Superette Bouaké')->firstOrFail();
    expect($merchant->is_active)->toBeFalse();
    expect($merchant->user->is_active)->toBeFalse();

    // Tant qu'il n'est pas validé, la connexion est refusée en 403
    $this->postJson('/api/v1/auth/login-password', [
        'phone' => '0709112233',
        'password' => 'Secret123!',
    ])->assertStatus(403);

    // Simulation de l'activation depuis la page Modifier (EditMerchant)
    $merchant->update(['is_active' => true, 'activated_at' => now()]);

    expect($merchant->fresh()->user->is_active)->toBeTrue();

    // Connexion réussie + portefeuille vierge à 0 FCFA
    $loginResponse = $this->postJson('/api/v1/auth/login-password', [
        'phone' => '0709112233',
        'password' => 'Secret123!',
    ])->assertOk()
        ->assertJsonPath('donnees.user.role', 'merchant')
        ->assertJsonPath('donnees.user.city_name', 'Bouaké');

    $token = $loginResponse->json('donnees.access_token');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/merchant/wallet')
        ->assertOk()
        ->assertJsonPath('donnees.wallet.balance', 0)
        ->assertJsonPath('donnees.stats.ca_aujourdhui', 0)
        ->assertJsonPath('donnees.stats.commandes_aujourdhui', 0)
        ->assertJsonPath('donnees.stats.ca_semaine', 0)
        ->assertJsonPath('donnees.stats.ca_mois', 0)
        ->assertJsonCount(0, 'donnees.versements_recents');
});

it('synchronise automatiquement users.is_active quand un livreur pré-inscrit est activé et renvoie un portefeuille à 0 FCFA sans course', function () {
    $city = City::factory()->create(['name' => 'Korhogo', 'is_active' => true]);
    VehicleType::firstOrCreate(
        ['slug' => 'moto'],
        [
            'name' => 'Moto',
            'is_active' => true,
        ]
    );

    $this->postJson('/api/v1/auth/register-driver', [
        'name' => 'Coulibaly Moussa',
        'phone' => '0508223344',
        'password' => 'DriverPass9!',
        'city_id' => $city->id,
        'vehicle_type' => 'moto',
        'vehicle_plate' => 'CI-9911-KG',
    ])->assertCreated();

    $courier = Courier::where('vehicle_plate', 'CI-9911-KG')->firstOrFail();
    expect($courier->is_active)->toBeFalse();
    expect($courier->user->is_active)->toBeFalse();

    // Activation depuis EditCourier
    $courier->update(['is_active' => true, 'activated_at' => now()]);
    expect($courier->fresh()->user->is_active)->toBeTrue();

    $loginResponse = $this->postJson('/api/v1/auth/login-password', [
        'phone' => '0508223344',
        'password' => 'DriverPass9!',
    ])->assertOk();

    $token = $loginResponse->json('donnees.access_token');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/driver/wallet')
        ->assertOk()
        ->assertJsonPath('donnees.wallet.balance', 0)
        ->assertJsonPath('donnees.stats.courses_today', 0)
        ->assertJsonPath('donnees.stats.earnings_today', 0)
        ->assertJsonPath('donnees.stats.earnings_week', 0)
        ->assertJsonCount(0, 'donnees.transactions.data')
        ->assertJsonCount(0, 'donnees.recent_courses');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/driver/courses')
        ->assertOk()
        ->assertJsonCount(0, 'donnees.orders')
        ->assertJsonCount(0, 'donnees.parcels');
});

it('isole strictement les commerces et colis d\'un client selon sa ville sans jamais renvoyer de données de Daloa', function () {
    $daloa = City::factory()->create(['name' => 'Daloa', 'is_active' => true]);
    $sanPedro = City::factory()->create(['name' => 'San-Pédro', 'is_active' => true]);

    $merchantUserDaloa = User::factory()->create([
        'role' => 'merchant',
        'city_id' => $daloa->id,
        'password' => Hash::make('secret123'),
    ]);
    Merchant::factory()->create([
        'user_id' => $merchantUserDaloa->id,
        'city_id' => $daloa->id,
        'name' => 'Maquis Daloa',
        'is_active' => true,
        'is_open' => true,
    ]);

    $merchantUserSp = User::factory()->create([
        'role' => 'merchant',
        'city_id' => $sanPedro->id,
        'password' => Hash::make('secret123'),
    ]);
    Merchant::factory()->create([
        'user_id' => $merchantUserSp->id,
        'city_id' => $sanPedro->id,
        'name' => 'Restaurant San-Pédro',
        'is_active' => true,
        'is_open' => true,
    ]);

    $clientSp = User::factory()->create([
        'role' => 'client',
        'city_id' => $sanPedro->id,
        'is_active' => true,
    ]);
    $token = JWTAuth::fromUser($clientSp);

    // Sans query param city_id : auth('api')->user()->city_id doit filtrer sur San-Pédro !
    $res = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/client/merchants')
        ->assertOk();

    $names = collect($res->json('donnees'))->pluck('name')->all();
    expect($names)->toBe(['Restaurant San-Pédro']);

    // Historique colis client vierge -> 200 OK avec tableau vide (plus de 404 !)
    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/client/parcels')
        ->assertOk()
        ->assertJsonCount(0, 'donnees');

    // Crée un colis pour ce client -> doit apparaître dans GET /api/v1/client/parcels
    Parcel::factory()->create([
        'sender_id' => $clientSp->id,
        'city_id' => $sanPedro->id,
        'recipient_name' => 'Bamba Ali',
    ]);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/client/parcels')
        ->assertOk()
        ->assertJsonCount(1, 'donnees')
        ->assertJsonPath('donnees.0.recipient_name', 'Bamba Ali');
});
