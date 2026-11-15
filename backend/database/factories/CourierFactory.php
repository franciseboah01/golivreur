<?php

namespace Database\Factories;

use App\Models\City;
use App\Models\Courier;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class CourierFactory extends Factory
{
    protected $model = Courier::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(['role' => 'driver']),
            'city_id' => City::factory(),
            'is_online' => true,
            'current_lat' => null,
            'current_lng' => null,
            'location_updated_at' => null,
            'vehicle_type' => 'moto',
            'vehicle_plate' => 'AB-123-CD',
            'is_active' => true,
            'activated_at' => now(),
            'consecutive_refusals' => 0,
        ];
    }

    public function withGps(float $lat = 6.8770, float $lng = -6.4502): static
    {
        return $this->state(fn (): array => [
            'current_lat' => $lat,
            'current_lng' => $lng,
            'location_updated_at' => now(),
        ]);
    }
}
