<?php

namespace Database\Factories;

use App\Models\City;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        return [
            'client_id' => User::factory(['role' => 'client']),
            'merchant_id' => Merchant::factory(),
            'courier_id' => null,
            'city_id' => City::factory(),
            'status' => 'pending',
            'products_total' => 5000,
            'delivery_fee' => 800,
            'platform_commission' => 750,
            'merchant_amount' => 4250,
            'courier_amount' => 640,
            'rain_mode_applied' => false,
            'peak_hours_applied' => false,
            'payment_method' => 'cash',
            'payment_status' => 'pending',
            'confirmation_code' => Hash::make('1234'),
            'delivery_address' => fake()->address(),
            'delivery_lat' => 6.8800,
            'delivery_lng' => -6.4510,
            'pickup_lat' => 6.8770,
            'pickup_lng' => -6.4502,
        ];
    }
}
