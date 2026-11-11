<?php

namespace Database\Factories;

use App\Models\City;
use App\Models\Order;
use App\Models\Refund;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class RefundFactory extends Factory
{
    protected $model = Refund::class;

    public function definition(): array
    {
        return [
            'city_id' => City::factory(),
            'order_id' => Order::factory(),
            'parcel_id' => null,
            'client_id' => User::factory(['role' => 'client']),
            'amount' => 1500,
            'reason' => 'Remboursement test',
            'status' => Refund::STATUS_REQUESTED,
            'cost_bearer' => Refund::COST_PLATFORM,
            'requested_by' => null,
            'approved_by' => null,
            'approved_at' => null,
            'paid_by' => null,
            'paid_at' => null,
            'payment_reference' => null,
        ];
    }
}
