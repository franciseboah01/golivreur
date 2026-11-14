<?php

use App\Jobs\SendPushNotification;
use App\Models\City;
use App\Models\Courier;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Parcel;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

it('P6 Point 5: notifie le client (DB + push) lors de acceptOrder et confirmPickup sans notifier sur conflit', function () {
    Queue::fake([SendPushNotification::class]);

    $city = City::factory()->create();
    $client = User::factory()->create([
        'role' => 'client',
        'city_id' => $city->id,
        'device_token' => 'tok-client-goshop',
    ]);

    $driver1 = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    Courier::factory()->create([
        'user_id' => $driver1->id,
        'city_id' => $city->id,
        'is_online' => true,
        'is_active' => true,
    ]);

    $driver2 = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    Courier::factory()->create([
        'user_id' => $driver2->id,
        'city_id' => $city->id,
        'is_online' => true,
        'is_active' => true,
    ]);

    $order = Order::factory()->create([
        'city_id' => $city->id,
        'client_id' => $client->id,
        'courier_id' => null,
        'status' => Order::STATUS_READY,
        'payment_method' => 'orange_money',
    ]);

    // 1. Acceptation livreur -> notification DB + push au client
    $this->actingAs($driver1, 'api')
        ->postJson("/api/v1/driver/orders/{$order->id}/accept")
        ->assertOk();

    expect(Notification::where('user_id', $client->id)->where('type', 'order_driver_accepted')->count())->toBe(1);
    Queue::assertPushed(SendPushNotification::class, fn (SendPushNotification $job): bool => $job->deviceToken === 'tok-client-goshop');

    // Conflit 409 par autre livreur -> aucune 2e notification
    $this->actingAs($driver2, 'api')
        ->postJson("/api/v1/driver/orders/{$order->id}/accept")
        ->assertStatus(409);

    expect(Notification::where('user_id', $client->id)->where('type', 'order_driver_accepted')->count())->toBe(1);

    // 2. Ramassage confirm-pickup -> notification order_picked_up + push au client
    $this->actingAs($driver1, 'api')
        ->postJson("/api/v1/driver/orders/{$order->id}/confirm-pickup")
        ->assertOk();

    expect(Notification::where('user_id', $client->id)->where('type', 'order_picked_up')->count())->toBe(1);
    Queue::assertPushed(SendPushNotification::class, 2);
});

it('P6 Point 5 GoColis: notifie le client (DB + push) lorsque acceptParcel reussit et pas sur 409', function () {
    Queue::fake([SendPushNotification::class]);

    $city = City::factory()->create();
    $sender = User::factory()->create([
        'role' => 'client',
        'city_id' => $city->id,
        'device_token' => 'tok-sender-gocolis',
    ]);

    $driver1 = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    Courier::factory()->create([
        'user_id' => $driver1->id,
        'city_id' => $city->id,
        'is_online' => true,
        'is_active' => true,
    ]);

    $driver2 = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    Courier::factory()->create([
        'user_id' => $driver2->id,
        'city_id' => $city->id,
        'is_online' => true,
        'is_active' => true,
    ]);

    $parcel = Parcel::factory()->create([
        'city_id' => $city->id,
        'sender_id' => $sender->id,
        'courier_id' => null,
        'status' => Parcel::STATUS_PENDING,
        'payment_method' => 'orange_money',
    ]);

    $this->actingAs($driver1, 'api')
        ->postJson("/api/v1/driver/parcels/{$parcel->id}/accept")
        ->assertOk();

    expect(Notification::where('user_id', $sender->id)->where('type', 'parcel_driver_accepted')->count())->toBe(1);
    Queue::assertPushed(SendPushNotification::class, fn (SendPushNotification $job): bool => $job->deviceToken === 'tok-sender-gocolis');

    $this->actingAs($driver2, 'api')
        ->postJson("/api/v1/driver/parcels/{$parcel->id}/accept")
        ->assertStatus(409);

    expect(Notification::where('user_id', $sender->id)->where('type', 'parcel_driver_accepted')->count())->toBe(1);
});
