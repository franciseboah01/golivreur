<?php

use App\Jobs\AlerterAdminPasDelivreur;
use App\Jobs\NotifierLivreurGoColis;
use App\Jobs\NotifierLivreurGoShop;
use App\Jobs\SendPushNotification;
use App\Models\City;
use App\Models\Courier;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Parcel;
use App\Models\User;
use App\Modules\GoShop\Services\AssignmentService;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Queue;

it('P6 Point 3: AssignmentService exclut un livreur ayant deja atteint courses.max_actives pour GoShop et GoColis', function () {
    Queue::fake([SendPushNotification::class, NotifierLivreurGoShop::class, NotifierLivreurGoColis::class, AlerterAdminPasDelivreur::class]);

    $city = City::factory()->create();

    $driver1 = User::factory()->create(['role' => 'driver', 'city_id' => $city->id, 'device_token' => 'tok-c1']);
    $courier1 = Courier::factory()->create([
        'user_id' => $driver1->id,
        'city_id' => $city->id,
        'is_online' => true,
        'is_active' => true,
        'current_lat' => 6.8771,
        'current_lng' => -6.4502,
    ]);

    $driver2 = User::factory()->create(['role' => 'driver', 'city_id' => $city->id, 'device_token' => 'tok-c2']);
    $courier2 = Courier::factory()->create([
        'user_id' => $driver2->id,
        'city_id' => $city->id,
        'is_online' => true,
        'is_active' => true,
        'current_lat' => 6.8900,
        'current_lng' => -6.4600,
    ]);

    // courier1 a deja 1 commande GoShop active -> max_actives = 1 atteint
    Order::factory()->create([
        'city_id' => $city->id,
        'courier_id' => $courier1->id,
        'status' => Order::STATUS_PICKED_UP,
    ]);

    $newOrder = Order::factory()->create([
        'city_id' => $city->id,
        'courier_id' => null,
        'status' => Order::STATUS_ACCEPTED,
        'payment_method' => 'orange_money',
        'pickup_lat' => 6.8770,
        'pickup_lng' => -6.4502,
    ]);

    $assignment = app(AssignmentService::class);
    $assignment->attribuerLivreurGoShop($newOrder);

    expect(Notification::where('user_id', $driver1->id)->where('type', 'course_offer_goshop')->exists())->toBeFalse()
        ->and(Notification::where('user_id', $driver2->id)->where('type', 'course_offer_goshop')->exists())->toBeTrue();

    Queue::assertPushed(NotifierLivreurGoShop::class, fn (NotifierLivreurGoShop $job): bool => $job->orderId === $newOrder->id
        && $job->courierId === $courier2->id);

    // courier2 prend maintenant un colis actif -> les deux sont satures pour un nouveau colis
    Parcel::factory()->create([
        'city_id' => $city->id,
        'courier_id' => $courier2->id,
        'status' => Parcel::STATUS_IN_TRANSIT,
    ]);

    $newParcel = Parcel::factory()->create([
        'city_id' => $city->id,
        'courier_id' => null,
        'status' => Parcel::STATUS_PENDING,
        'payment_method' => 'orange_money',
        'pickup_lat' => 6.8770,
        'pickup_lng' => -6.4502,
    ]);

    $assignment->attribuerLivreurGoColis($newParcel);

    expect($newParcel->fresh()->status)->toBe(Parcel::STATUS_WAITING);
    Queue::assertNotPushed(NotifierLivreurGoColis::class);
    Queue::assertPushed(AlerterAdminPasDelivreur::class, fn (AlerterAdminPasDelivreur $job): bool => $job->type === 'gocolis');
});

it('P6 Point 3 settings: AssignmentService autorise jusqu a courses.max_actives personnalise puis exclut ensuite', function () {
    Queue::fake([SendPushNotification::class, NotifierLivreurGoShop::class, AlerterAdminPasDelivreur::class]);

    $city = City::factory()->create();
    app(SettingsService::class)->set('courses.max_actives', 2, $city->id);

    $driver1 = User::factory()->create(['role' => 'driver', 'city_id' => $city->id, 'device_token' => 'tok-max2']);
    $courier1 = Courier::factory()->create([
        'user_id' => $driver1->id,
        'city_id' => $city->id,
        'is_online' => true,
        'is_active' => true,
        'current_lat' => 6.8771,
        'current_lng' => -6.4502,
    ]);

    // 1 course active (< 2) -> courier1 reste eligible
    Parcel::factory()->create([
        'city_id' => $city->id,
        'courier_id' => $courier1->id,
        'status' => Parcel::STATUS_ACCEPTED,
    ]);

    $o1 = Order::factory()->create([
        'city_id' => $city->id,
        'courier_id' => null,
        'status' => Order::STATUS_ACCEPTED,
        'payment_method' => 'orange_money',
        'pickup_lat' => 6.8770,
        'pickup_lng' => -6.4502,
    ]);

    $assignment = app(AssignmentService::class);
    $assignment->attribuerLivreurGoShop($o1);

    Queue::assertPushed(NotifierLivreurGoShop::class, fn (NotifierLivreurGoShop $job): bool => $job->orderId === $o1->id
        && $job->courierId === $courier1->id);

    // Avec 2 courses actives (1 parcel + 1 order), courier1 est desormais exclu
    Order::factory()->create([
        'city_id' => $city->id,
        'courier_id' => $courier1->id,
        'status' => Order::STATUS_READY,
    ]);

    $o2 = Order::factory()->create([
        'city_id' => $city->id,
        'courier_id' => null,
        'status' => Order::STATUS_ACCEPTED,
        'payment_method' => 'orange_money',
        'pickup_lat' => 6.8770,
        'pickup_lng' => -6.4502,
    ]);

    $assignment->attribuerLivreurGoShop($o2);

    expect($o2->fresh()->status)->toBe(Order::STATUS_WAITING);
    Queue::assertPushed(AlerterAdminPasDelivreur::class, fn (AlerterAdminPasDelivreur $job): bool => $job->type === 'goshop');
});

