<?php

namespace Database\Factories;

use App\Models\Merchant;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            'merchant_id'  => Merchant::factory(),
            'name'         => fake()->words(2, true),
            'description'  => fake()->sentence(),
            'price'        => fake()->numberBetween(1000, 10000),
            'is_available' => true,
            'category'     => 'Plats',
            'sort_order'   => 0,
        ];
    }
}

