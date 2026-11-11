<?php

use App\Filament\Resources\ParcelResource\Pages\EditParcel;
use App\Jobs\AlerterAdminPasDelivreur;
use App\Jobs\NotifierLivreurGoColis;
use App\Jobs\SendPushNotification;
use App\Models\City;
use App\Models\Courier;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Parcel;
use App\Models\User;
use App\Models\Wallet;
use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tymon\JWTAuth\Facades\JWTAuth;

it('GoColis accept nominal: un livreur actif et en ligne accepte un colis pending ou waiting avec lockForUpdate et update conditionnel', function (string $status) {
    $city = City::factory()->create();
    $driver = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    $courier = Courier::factory()->create([
        'user_id' => $driver->id,
        'city_id' => $city->id,
        'is_online' => true,
        'is_active' => true,
        'consecutive_refusals' => 2,
    ]);

    $parcel = Parcel::factory()->create([
        'city_id' => $city->id,
        'courier_id' => null,
        'status' => $status,
        'shipping_fee' => 1500,
        'courier_amount' => 1125,
    ]);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($driver))
        ->postJson("/api/v1/driver/parcels/{$parcel->id}/accept")
        ->assertOk()
        ->assertJsonPath('succes', true)
        ->assertJsonPath('donnees.courier_id', $courier->id)
        ->assertJsonPath('donnees.status', Parcel::STATUS_ACCEPTED);

    $queries = collect(DB::getQueryLog())->pluck('query')->implode("\n");
    DB::disableQueryLog();

    expect(strtolower($queries))->toContain('for update')
        ->and(strtolower($queries))->toContain('"courier_id" is null');

    expect($parcel->fresh()->courier_id)->toBe($courier->id)
        ->and($parcel->fresh()->status)->toBe(Parcel::STATUS_ACCEPTED)
        ->and($courier->fresh()->consecutive_refusals)->toBe(0);
})->with([Parcel::STATUS_PENDING, Parcel::STATUS_WAITING]);

it('GoColis accept hors ville: refuse 404 sans fuite de nom de ville', function () {
    $cityDaloa = City::factory()->create(['name' => 'Daloa']);
    $cityBouake = City::factory()->create(['name' => 'Bouaké']);

    $driverDaloa = User::factory()->create(['role' => 'driver', 'city_id' => $cityDaloa->id]);
    Courier::factory()->create([
        'user_id' => $driverDaloa->id,
        'city_id' => $cityDaloa->id,
        'is_online' => true,
        'is_active' => true,
    ]);

    $parcelBouake = Parcel::factory()->create([
        'city_id' => $cityBouake->id,
        'courier_id' => null,
        'status' => Parcel::STATUS_PENDING,
    ]);

    $res = $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($driverDaloa))
        ->postJson("/api/v1/driver/parcels/{$parcelBouake->id}/accept")
        ->assertStatus(404)
        ->assertJsonPath('succes', false);

    expect($res->json('message'))->not->toContain('Bouaké')
        ->and($parcelBouake->fresh()->courier_id)->toBeNull();
});

it('GoColis accept déjà pris et concurrence: retourne 409 sans écraser le premier livreur', function () {
    $city = City::factory()->create();

    $driverA = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    $courierA = Courier::factory()->create(['user_id' => $driverA->id, 'city_id' => $city->id, 'is_online' => true, 'is_active' => true]);

    $driverB = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    $courierB = Courier::factory()->create(['user_id' => $driverB->id, 'city_id' => $city->id, 'is_online' => true, 'is_active' => true]);

    // 1. Déjà pris séquentiellement
    $parcel1 = Parcel::factory()->create(['city_id' => $city->id, 'courier_id' => null, 'status' => Parcel::STATUS_PENDING]);

    $this->actingAs($driverA, 'api')
        ->postJson("/api/v1/driver/parcels/{$parcel1->id}/accept")
        ->assertOk();

    $this->actingAs($driverB, 'api')
        ->postJson("/api/v1/driver/parcels/{$parcel1->id}/accept")
        ->assertStatus(409);

    $this->actingAs($driverA, 'api')
        ->postJson("/api/v1/driver/parcels/{$parcel1->id}/accept")
        ->assertStatus(409);

    expect($parcel1->fresh()->courier_id)->toBe($courierA->id);

    // 2. Race concurrente simulée pendant le verrouillage
    $parcel2 = Parcel::factory()->create(['city_id' => $city->id, 'courier_id' => null, 'status' => Parcel::STATUS_PENDING]);
    $injected = false;

    DB::listen(function ($query) use (&$injected, $parcel2, $courierB) {
        $sql = strtolower($query->sql);
        if (! $injected && str_contains($sql, '"parcels"') && str_contains($sql, 'for update')) {
            $injected = true;
            DB::table('parcels')->where('id', $parcel2->id)->update([
                'courier_id' => $courierB->id,
                'status' => Parcel::STATUS_ACCEPTED,
            ]);
        }
    });

    $this->actingAs($driverA, 'api')
        ->postJson("/api/v1/driver/parcels/{$parcel2->id}/accept")
        ->assertStatus(409);

    expect($injected)->toBeTrue()
        ->and($parcel2->fresh()->courier_id)->toBe($courierB->id);
});

it('GoColis accept disponibilité: refuse 403 si livreur inactif, pénalisé ou hors ligne', function (bool $courierActive, bool $userActive, bool $isOnline, ?int $penaltyMinutes, int $refusals) {
    $city = City::factory()->create();
    $parcel = Parcel::factory()->create(['city_id' => $city->id, 'courier_id' => null, 'status' => Parcel::STATUS_PENDING]);

    $driver = User::factory()->create(['role' => 'driver', 'city_id' => $city->id, 'is_active' => $userActive]);
    Courier::factory()->create([
        'user_id' => $driver->id,
        'city_id' => $city->id,
        'is_active' => $courierActive,
        'is_online' => $isOnline,
        'penalty_until' => $penaltyMinutes !== null ? now()->addMinutes($penaltyMinutes) : null,
        'consecutive_refusals' => $refusals,
    ]);

    $this->actingAs($driver, 'api')
        ->postJson("/api/v1/driver/parcels/{$parcel->id}/accept")
        ->assertStatus(403)
        ->assertJsonPath('succes', false);

    expect($parcel->fresh()->courier_id)->toBeNull();
})->with([
    'courier inactif' => [false, true, true, null, 0],
    'user inactif' => [true, false, true, null, 0],
    'hors ligne' => [true, true, false, null, 0],
    'pénalité future active' => [true, true, true, 10, 0],
    '3 refus consécutifs' => [true, true, true, null, 3],
]);

it('GoColis accept courses.max_actives: refuse 409 si une course active (GoShop ou GoColis) existe déjà', function () {
    $city = City::factory()->create();
    $driver = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    $courier = Courier::factory()->create([
        'user_id' => $driver->id,
        'city_id' => $city->id,
        'is_online' => true,
        'is_active' => true,
    ]);

    // Course GoShop active existante
    Order::factory()->create([
        'city_id' => $city->id,
        'courier_id' => $courier->id,
        'status' => Order::STATUS_IN_TRANSIT,
    ]);

    $parcel = Parcel::factory()->create(['city_id' => $city->id, 'courier_id' => null, 'status' => Parcel::STATUS_PENDING]);

    $this->actingAs($driver, 'api')
        ->postJson("/api/v1/driver/parcels/{$parcel->id}/accept")
        ->assertStatus(409)
        ->assertJsonPath('succes', false);

    expect($parcel->fresh()->courier_id)->toBeNull();

    // Si courses.max_actives = 2, la 2e course passe
    app(SettingsService::class)->set('courses.max_actives', 2, $city->id, $driver->id, 'Test multi-courses GoColis');

    $this->actingAs($driver, 'api')
        ->postJson("/api/v1/driver/parcels/{$parcel->id}/accept")
        ->assertOk();

    expect($parcel->fresh()->courier_id)->toBe($courier->id);
});

it('GoColis accept espèces: refuse 422 sans solde minimum/disponible suffisant et autorise 200 avec solde suffisant', function () {
    $city = City::factory()->create();

    // Livreur sans solde minimum (9 999 < 10 000 + reversement 300)
    $poorDriver = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    $poorCourier = Courier::factory()->create([
        'user_id' => $poorDriver->id,
        'city_id' => $city->id,
        'is_online' => true,
        'is_active' => true,
    ]);
    Wallet::create(['owner_type' => 'courier', 'owner_id' => $poorCourier->id, 'balance' => 9999]);

    $cashParcel = Parcel::factory()->cash()->create([
        'city_id' => $city->id,
        'courier_id' => null,
        'status' => Parcel::STATUS_PENDING,
        'shipping_fee' => 1200,
        'platform_commission' => 300,
        'courier_amount' => 900,
        'insurance_amount' => 0,
    ]);

    $this->actingAs($poorDriver, 'api')
        ->postJson("/api/v1/driver/parcels/{$cashParcel->id}/accept")
        ->assertStatus(422)
        ->assertJsonPath('succes', false);

    expect($cashParcel->fresh()->courier_id)->toBeNull();

    // Livreur solvable : 10 300 F (>= 10 000 F min + 300 F reversement) -> 200
    $richDriver = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    $richCourier = Courier::factory()->create([
        'user_id' => $richDriver->id,
        'city_id' => $city->id,
        'is_online' => true,
        'is_active' => true,
    ]);
    Wallet::create(['owner_type' => 'courier', 'owner_id' => $richCourier->id, 'balance' => 10300]);

    $this->actingAs($richDriver, 'api')
        ->postJson("/api/v1/driver/parcels/{$cashParcel->id}/accept")
        ->assertOk()
        ->assertJsonPath('donnees.courier_id', $richCourier->id);
});

it('GoColis refuse: 3 refus consécutifs appliquent la pénalité 15 min hors ligne et relancent le livreur suivant', function () {
    Queue::fake([SendPushNotification::class, NotifierLivreurGoColis::class, AlerterAdminPasDelivreur::class]);

    $city = City::factory()->create();
    $driver1 = User::factory()->create(['role' => 'driver', 'city_id' => $city->id, 'device_token' => 'tok-drv-1']);
    $courier1 = Courier::factory()->create([
        'user_id' => $driver1->id,
        'city_id' => $city->id,
        'is_online' => true,
        'is_active' => true,
        'consecutive_refusals' => 0,
    ]);

    $driver2 = User::factory()->create(['role' => 'driver', 'city_id' => $city->id, 'device_token' => 'tok-drv-2']);
    Courier::factory()->create([
        'user_id' => $driver2->id,
        'city_id' => $city->id,
        'is_online' => true,
        'is_active' => true,
    ]);

    $p1 = Parcel::factory()->create(['city_id' => $city->id, 'status' => Parcel::STATUS_PENDING, 'pickup_lat' => null, 'pickup_lng' => null]);
    $p2 = Parcel::factory()->create(['city_id' => $city->id, 'status' => Parcel::STATUS_PENDING, 'pickup_lat' => null, 'pickup_lng' => null]);
    $p3 = Parcel::factory()->create(['city_id' => $city->id, 'status' => Parcel::STATUS_PENDING, 'pickup_lat' => null, 'pickup_lng' => null]);

    // 1er refus : consecutive_refusals = 1, toujours en ligne, proposition envoyée à driver2
    $this->actingAs($driver1, 'api')
        ->postJson("/api/v1/driver/parcels/{$p1->id}/refuse")
        ->assertOk();

    expect($courier1->fresh()->consecutive_refusals)->toBe(1)
        ->and($courier1->fresh()->is_online)->toBeTrue()
        ->and(Notification::where('user_id', $driver2->id)->where('type', 'course_offer_gocolis')->exists())->toBeTrue();

    // 2e refus
    $this->actingAs($driver1, 'api')
        ->postJson("/api/v1/driver/parcels/{$p2->id}/refuse")
        ->assertOk();
    expect($courier1->fresh()->consecutive_refusals)->toBe(2)
        ->and($courier1->fresh()->is_online)->toBeTrue();

    // 3e refus -> hors ligne 15 min + impossible d'accepter ensuite (403)
    $this->actingAs($driver1, 'api')
        ->postJson("/api/v1/driver/parcels/{$p3->id}/refuse")
        ->assertOk();

    $courier1->refresh();
    expect($courier1->consecutive_refusals)->toBe(3)
        ->and($courier1->is_online)->toBeFalse()
        ->and($courier1->penalty_until)->not->toBeNull()
        ->and($courier1->penalty_until->isFuture())->toBeTrue();

    $this->actingAs($driver1, 'api')
        ->postJson("/api/v1/driver/parcels/{$p1->id}/accept")
        ->assertStatus(403);
});

it('GoColis notifications réelles: NotifierLivreurGoColis envoie la même notification que GoShop et escalade après 30s', function () {
    Queue::fake([SendPushNotification::class, AlerterAdminPasDelivreur::class]);

    $city = City::factory()->create();
    $driver1 = User::factory()->create(['role' => 'driver', 'city_id' => $city->id, 'device_token' => 'device-token-driver-1']);
    $courier1 = Courier::factory()->create([
        'user_id' => $driver1->id,
        'city_id' => $city->id,
        'is_online' => true,
        'is_active' => true,
        'current_lat' => 6.8820,
        'current_lng' => -6.4475,
    ]);

    $driver2 = User::factory()->create(['role' => 'driver', 'city_id' => $city->id, 'device_token' => 'device-token-driver-2']);
    $courier2 = Courier::factory()->create([
        'user_id' => $driver2->id,
        'city_id' => $city->id,
        'is_online' => true,
        'is_active' => true,
        'current_lat' => 6.8900,
        'current_lng' => -6.4500,
    ]);

    $parcel = Parcel::factory()->create([
        'city_id' => $city->id,
        'courier_id' => null,
        'status' => Parcel::STATUS_PENDING,
        'size' => 'grand',
        'pickup_lat' => 6.8821,
        'pickup_lng' => -6.4478,
    ]);

    // 1. Appel direct de NotifierLivreurGoColis -> Notification DB + SendPushNotification (comme GoShop)
    NotifierLivreurGoColis::dispatchSync($parcel->id, $courier1->id, 1, [$courier1->id]);

    expect(Notification::where('user_id', $driver1->id)->where('type', 'course_offer_gocolis')->exists())->toBeTrue();
    Queue::assertPushed(SendPushNotification::class, fn (SendPushNotification $job): bool => $job->deviceToken === 'device-token-driver-1');

    // 2. Expiration 30s sans acceptation -> escalade vers le 2e livreur le plus proche
    NotifierLivreurGoColis::dispatchSync(
        $parcel->id,
        $courier1->id,
        1,
        [$courier1->id],
        now()->subSeconds(35)->toIso8601String(),
    );

    expect(Notification::where('user_id', $driver2->id)->where('type', 'course_offer_gocolis')->exists())->toBeTrue();
    Queue::assertPushed(SendPushNotification::class, fn (SendPushNotification $job): bool => $job->deviceToken === 'device-token-driver-2');

    // 3. Expiration après 3 tentatives -> statut waiting + AlerterAdminPasDelivreur + notif client
    NotifierLivreurGoColis::dispatchSync(
        $parcel->id,
        $courier2->id,
        3,
        [$courier1->id, $courier2->id],
        now()->subSeconds(35)->toIso8601String(),
    );

    expect($parcel->fresh()->status)->toBe(Parcel::STATUS_WAITING);
    Queue::assertPushed(AlerterAdminPasDelivreur::class);
    expect(Notification::where('user_id', $parcel->sender_id)->where('type', 'parcel_waiting')->exists())->toBeTrue();
});

it('GoColis Filament: l\'affectation manuelle d\'un livreur sur ParcelResource continue de fonctionner', function () {
    $city = City::factory()->create();
    $admin = User::factory()->create(['role' => 'super_admin', 'city_id' => null]);
    $driver = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    $courier = Courier::factory()->create(['user_id' => $driver->id, 'city_id' => $city->id, 'is_online' => true, 'is_active' => true]);

    $parcel = Parcel::factory()->create([
        'city_id' => $city->id,
        'courier_id' => null,
        'status' => Parcel::STATUS_WAITING,
    ]);

    $this->actingAs($admin);

    Livewire::test(EditParcel::class, ['record' => $parcel->getKey()])
        ->fillForm([
            'courier_id' => $courier->id,
            'status' => Parcel::STATUS_ACCEPTED,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($parcel->fresh()->courier_id)->toBe($courier->id)
        ->and($parcel->fresh()->status)->toBe(Parcel::STATUS_ACCEPTED);
});

it('GoColis refuse après acceptation: un colis jugé interdit ou suspect passe au statut refused (CDC Annexe B)', function () {
    $city = City::factory()->create();
    $driver = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    $courier = Courier::factory()->create([
        'user_id' => $driver->id,
        'city_id' => $city->id,
        'is_online' => true,
        'is_active' => true,
    ]);

    $parcel = Parcel::factory()->create([
        'city_id' => $city->id,
        'courier_id' => $courier->id,
        'status' => Parcel::STATUS_AT_SENDER,
    ]);

    $this->actingAs($driver, 'api')
        ->postJson("/api/v1/driver/parcels/{$parcel->id}/refuse", [
            'raison' => 'Contenu jugé suspect au contrôle visuel',
        ])
        ->assertOk()
        ->assertJsonPath('donnees.status', Parcel::STATUS_REFUSED);

    expect($parcel->fresh()->status)->toBe(Parcel::STATUS_REFUSED)
        ->and($courier->fresh()->consecutive_refusals)->toBe(0);
});
