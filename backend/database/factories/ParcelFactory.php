<?php

namespace Database\Factories;

use App\Models\City;
use App\Models\Courier;
use App\Models\Parcel;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

class ParcelFactory extends Factory
{
    protected $model = Parcel::class;

    public function definition(): array
    {
        return [
            'sender_id'           => User::factory(['role' => 'client']),
            'courier_id'          => null,
            'city_id'             => City::factory(),
            'status'              => 'pending',
            'size'                => 'moyen',
            'weight_kg'           => 2.5,
            'declared_value'      => 0,
            'has_insurance'       => false,
            'insurance_amount'    => 0,
            'sender_code'         => Hash::make('1234'),
            'recipient_code'      => Hash::make('5678'),
            'recipient_name'      => fake()->name(),
            'recipient_phone'     => '07' . fake()->numerify('########'),
            'delivery_address'    => fake()->address(),
            'delivery_lat'        => 6.8800,
            'delivery_lng'        => -6.4510,
            'pickup_address'      => fake()->address(),
            'pickup_lat'          => 6.8821,
            'pickup_lng'          => -6.4478,
            'description'         => fake()->sentence(),
            'shipping_fee'        => 1000,
            'platform_commission' => 250,
            'courier_amount'      => 750,
            'payment_method'      => 'cash',
            'payment_status'      => 'pending',
            'assignment_attempts' => 0,
        ];
    }
}
