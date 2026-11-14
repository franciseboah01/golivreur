<?php

use App\Jobs\AlerterAdminPasDelivreur;
use App\Jobs\NotifierLivreurGoShop;
use App\Jobs\SendPushNotification;
use App\Models\City;
use App\Models\Courier;
use App\Models\Notification;
use App\Models\Order;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Queue;

it('P6 Point 2: POST /driver/orders/{order}/refuse incremente consecutive_refusals, applique la penalite apres 3 refus et relance le livreur suivant', function () {
    Queue::fake([SendPushNotification::class, NotifierLivreurGoShop::class, AlerterAdminPasDelivreur::class]);

    $city = City::factory()->create();
    $driver1 = User::factory()->create(['role' => 'driver', 'city_id' => $city->id, 'device_token' => 'tok-d1']);
    $courier1 = Courier::factory()->create([
        'user_id' => $driver1->id,
        'city_id' => $city->id,
        'is_online' => true,
        'is_active' => true,
        'consecutive_refusals' => 0,
        'current_lat' => 6.8770,
        'current_lng' => -6.4502,
    ]);

    $driver2 = User::factory()->create(['role' => 'driver', 'city_id' => $city->id, 'device_token' => 'tok-d2']);
    Courier::factory()->create([
        'user_id' => $driver2->id,
        'city_id' => $city->id,
        'is_online' => true,
        'is_active' => true,
        'current_lat' => 6.8800,
        'current_lng' => -6.4510,
    ]);

    $o1 = Order::factory()->create([
        'city_id' => $city->id,
        'status' => Order::STATUS_ACCEPTED,
        'pickup_lat' => 6.8770,
        'pickup_lng' => -6.4502,
    ]);
    $o2 = Order::factory()->create([
        'city_id' => $city->id,
        'status' => Order::STATUS_ACCEPTED,
        'pickup_lat' => 6.8770,
        'pickup_lng' => -6.4502,
    ]);
    $o3 = Order::factory()->create([
        'city_id' => $city->id,
        'status' => Order::STATUS_READY,
        'pickup_lat' => 6.8770,
        'pickup_lng' => -6.4502,
    ]);

    // 1er refus -> consecutive_refusals = 1, toujours en ligne, offre transmise a driver2
    $this->actingAs($driver1, 'api')
        ->postJson("/api/v1/driver/orders/{$o1->id}/refuse", ['raison' => 'Trop loin'])
        ->assertOk()
        ->assertJsonPath('succes', true);

    expect($courier1->fresh()->consecutive_refusals)->toBe(1)
        ->and($courier1->fresh()->is_online)->toBeTrue()
        ->and(Notification::where('user_id', $driver2->id)->where('type', 'course_offer_goshop')->exists())->toBeTrue();

    Queue::assertPushed(NotifierLivreurGoShop::class, fn (NotifierLivreurGoShop $job): bool => $job->orderId === $o1->id && $job->tentative === 2);

    // 2e refus
    $this->actingAs($driver1, 'api')
        ->postJson("/api/v1/driver/orders/{$o2->id}/refuse")
        ->assertOk();

    expect($courier1->fresh()->consecutive_refusals)->toBe(2)
        ->and($courier1->fresh()->is_online)->toBeTrue();

    // 3e refus -> hors ligne 15 min + penalite + impossible d'accepter ensuite
    $before = now();
    $this->actingAs($driver1, 'api')
        ->postJson("/api/v1/driver/orders/{$o3->id}/refuse")
        ->assertOk()
        ->assertJsonPath('succes', true);

    $courier1->refresh();
    expect($courier1->consecutive_refusals)->toBe(3)
        ->and($courier1->is_online)->toBeFalse()
        ->and($courier1->penalty_until)->not->toBeNull()
        ->and((int) round($before->diffInMinutes($courier1->penalty_until, false)))->toBeGreaterThanOrEqual(14)
        ->and((int) round($before->diffInMinutes($courier1->penalty_until, false)))->toBeLessThanOrEqual(15);

    $o4 = Order::factory()->create(['city_id' => $city->id, 'status' => Order::STATUS_ACCEPTED]);
    $this->actingAs($driver1, 'api')
        ->postJson("/api/v1/driver/orders/{$o4->id}/accept")
        ->assertStatus(403);
});

it('P6 Point 2 settings: refuseOrder lit refus_avant_penalite et duree_penalite_minutes depuis SettingsService', function () {
    Queue::fake([SendPushNotification::class, NotifierLivreurGoShop::class, AlerterAdminPasDelivreur::class]);

    $city = City::factory()->create();
    app(SettingsService::class)->set('delais_regles', [
        'refus_avant_penalite' => 2,
        'duree_penalite_minutes' => 25,
    ], $city->id);

    $driver = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    $courier = Courier::factory()->create([
        'user_id' => $driver->id,
        'city_id' => $city->id,
        'is_online' => true,
        'is_active' => true,
        'consecutive_refusals' => 0,
    ]);

    $o1 = Order::factory()->create(['city_id' => $city->id, 'status' => Order::STATUS_ACCEPTED]);
    $o2 = Order::factory()->create(['city_id' => $city->id, 'status' => Order::STATUS_ACCEPTED]);

    $this->actingAs($driver, 'api')->postJson("/api/v1/driver/orders/{$o1->id}/refuse")->assertOk();
    expect($courier->fresh()->is_online)->toBeTrue();

    $before = now();
    $this->actingAs($driver, 'api')->postJson("/api/v1/driver/orders/{$o2->id}/refuse")->assertOk();

    $courier->refresh();
    expect($courier->consecutive_refusals)->toBe(2)
        ->and($courier->is_online)->toBeFalse()
        ->and((int) round($before->diffInMinutes($courier->penalty_until, false)))->toBeGreaterThanOrEqual(24)
        ->and((int) round($before->diffInMinutes($courier->penalty_until, false)))->toBeLessThanOrEqual(25);
});

it('P6 Point 2 securite: refuseOrder rejette autre ville 404, deja assignee 409, mauvais statut 422 et hors ligne 403', function () {
    $city1 = City::factory()->create();
    $city2 = City::factory()->create();

    $driver = User::factory()->create(['role' => 'driver', 'city_id' => $city1->id]);
    $courier = Courier::factory()->create(['user_id' => $driver->id, 'city_id' => $city1->id, 'is_online' => true, 'is_active' => true]);

    $otherCourier = Courier::factory()->create(['city_id' => $city1->id, 'is_online' => true, 'is_active' => true]);

    $otherCityOrder = Order::factory()->create(['city_id' => $city2->id, 'status' => Order::STATUS_ACCEPTED]);
    $this->actingAs($driver, 'api')
        ->postJson("/api/v1/driver/orders/{$otherCityOrder->id}/refuse")
        ->assertStatus(404);

    $assignedOtherOrder = Order::factory()->create([
        'city_id' => $city1->id,
        'status' => Order::STATUS_ACCEPTED,
        'courier_id' => $otherCourier->id,
    ]);
    $this->actingAs($driver, 'api')
        ->postJson("/api/v1/driver/orders/{$assignedOtherOrder->id}/refuse")
        ->assertStatus(409);

    $pendingOrder = Order::factory()->create(['city_id' => $city1->id, 'status' => Order::STATUS_PENDING]);
    $this->actingAs($driver, 'api')
        ->postJson("/api/v1/driver/orders/{$pendingOrder->id}/refuse")
        ->assertStatus(422);

    $courier->update(['is_online' => false]);
    $acceptedOrder = Order::factory()->create(['city_id' => $city1->id, 'status' => Order::STATUS_ACCEPTED]);
    $this->actingAs($driver, 'api')
        ->postJson("/api/v1/driver/orders/{$acceptedOrder->id}/refuse")
        ->assertStatus(403);
});
