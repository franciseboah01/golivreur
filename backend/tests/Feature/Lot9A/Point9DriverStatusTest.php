<?php

use App\Models\City;
use App\Models\Courier;
use App\Models\Order;
use App\Models\Parcel;
use App\Models\User;
use Tymon\JWTAuth\Facades\JWTAuth;

it('Point 9 : GET /driver/profile renvoie account_status, completed_deliveries et rating sans inventer de niveau', function () {
    $city = City::factory()->create(['name' => 'Daloa']);
    $driverUser = User::factory()->create(['role' => 'driver', 'city_id' => $city->id, 'is_active' => true]);
    $courier = Courier::factory()->create([
        'user_id' => $driverUser->id,
        'city_id' => $city->id,
        'is_active' => true,
        'activated_at' => now()->subDays(5),
        'average_rating' => 4.7,
        'total_ratings' => 12,
    ]);

    Order::factory()->count(2)->create([
        'courier_id' => $courier->id,
        'city_id' => $city->id,
        'status' => Order::STATUS_DELIVERED,
    ]);
    Parcel::factory()->create([
        'courier_id' => $courier->id,
        'city_id' => $city->id,
        'status' => Parcel::STATUS_DELIVERED,
    ]);

    $res = $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($driverUser))
        ->getJson('/api/v1/driver/profile')
        ->assertOk()
        ->assertJsonPath('donnees.account_status', 'actif')
        ->assertJsonPath('donnees.completed_deliveries', 3)
        ->assertJsonPath('donnees.rating', 4.7);

    expect($res->json('donnees'))->not->toHaveKey('level');
});
