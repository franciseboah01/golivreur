<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'phone' => '07'.fake()->numerify('########'),
            'role' => 'client',
            'city_id' => null,
            'is_active' => true,
            'phone_verified_at' => now(),
            'password' => Hash::make('password'),
        ];
    }
}
