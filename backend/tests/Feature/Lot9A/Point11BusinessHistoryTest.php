<?php

use App\Models\City;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\User;
use Carbon\Carbon;
use Tymon\JWTAuth\Facades\JWTAuth;

it('Point 11 : GET /business/orders/history gere scope=today, week=YYYY-Www (Africa/Abidjan), totaux merchant_amount des livrees et isolation par commerce', function () {
    $city = City::factory()->create(['name' => 'Daloa']);
    $merchantUserA = User::factory()->create(['role' => 'merchant', 'city_id' => $city->id, 'is_active' => true]);
    $merchantA = Merchant::factory()->create(['user_id' => $merchantUserA->id, 'city_id' => $city->id, 'is_active' => true]);

    $merchantUserB = User::factory()->create(['role' => 'merchant', 'city_id' => $city->id, 'is_active' => true]);
    $merchantB = Merchant::factory()->create(['user_id' => $merchantUserB->id, 'city_id' => $city->id, 'is_active' => true]);

    $nowAbidjan = Carbon::now('Africa/Abidjan');

    $orderDeliveredToday = Order::factory()->create([
        'merchant_id' => $merchantA->id,
        'city_id' => $city->id,
        'status' => Order::STATUS_DELIVERED,
        'merchant_amount' => 4250,
        'delivered_at' => $nowAbidjan->copy(),
        'created_at' => $nowAbidjan->copy(),
    ]);

    $orderRefusedToday = Order::factory()->create([
        'merchant_id' => $merchantA->id,
        'city_id' => $city->id,
        'status' => Order::STATUS_REFUSED,
        'merchant_amount' => 3000,
        'cancelled_at' => $nowAbidjan->copy(),
        'created_at' => $nowAbidjan->copy(),
    ]);

    // Commande d'un autre commerce -> ne doit JAMAIS apparaître
    Order::factory()->create([
        'merchant_id' => $merchantB->id,
        'city_id' => $city->id,
        'status' => Order::STATUS_DELIVERED,
        'merchant_amount' => 9999,
        'created_at' => $nowAbidjan->copy(),
    ]);

    $tokenA = JWTAuth::fromUser($merchantUserA);

    // 1. scope=today
    $todayRes = $this->withHeader('Authorization', "Bearer {$tokenA}")
        ->getJson('/api/v1/business/orders/history?scope=today')
        ->assertOk()
        ->assertJsonPath('succes', true)
        ->assertJsonPath('donnees.orders_count', 2)
        ->assertJsonPath('donnees.delivered_merchant_amount', 4250);

    $ids = collect($todayRes->json('donnees.orders'))->pluck('id')->all();
    expect($ids)->toContain($orderDeliveredToday->id, $orderRefusedToday->id)
        ->and(count($ids))->toBe(2);

    // 2. week=YYYY-Www (semaine ISO courante)
    $isoWeek = $nowAbidjan->format('o-\WW');
    $this->withHeader('Authorization', "Bearer {$tokenA}")
        ->getJson("/api/v1/business/orders/history?week={$isoWeek}")
        ->assertOk()
        ->assertJsonPath('donnees.week', $isoWeek)
        ->assertJsonPath('donnees.orders_count', 2)
        ->assertJsonPath('donnees.delivered_merchant_amount', 4250);
});
