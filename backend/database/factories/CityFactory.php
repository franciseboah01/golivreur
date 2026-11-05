<?php

namespace Database\Factories;

use App\Models\City;
use Illuminate\Database\Eloquent\Factories\Factory;

class CityFactory extends Factory
{
    protected $model = City::class;

    public function definition(): array
    {
        return [
            'name'                  => fake()->unique()->city(),
            'is_active'             => true,
            'center_lat'            => 6.8770,
            'center_lng'            => -6.4502,
            'rain_mode_active'      => false,
            'emergency_mode_active' => false,
        ];
    }
}

