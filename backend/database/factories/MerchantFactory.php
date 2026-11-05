<?php

namespace Database\Factories;

use App\Models\City;
use App\Models\Merchant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class MerchantFactory extends Factory
{
    protected $model = Merchant::class;

    public function definition(): array
    {
        return [
            'user_id'            => User::factory(['role' => 'merchant']),
            'city_id'            => City::factory(),
            'name'               => fake()->company(),
            'category'           => 'restaurant',
            'description'        => fake()->sentence(),
            'address'            => fake()->address(),
            'lat'                => 6.8770,
            'lng'                => -6.4502,
            'prep_time_minutes'  => 15,
            'is_open'            => true,
            'is_active'          => true,
            'activated_at'       => now(),
            'commission_rate'    => 15.00,
        ];
    }
}

