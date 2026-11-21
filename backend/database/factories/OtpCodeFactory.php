<?php

namespace Database\Factories;

use App\Models\OtpCode;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<OtpCode>
 */
class OtpCodeFactory extends Factory
{
    protected $model = OtpCode::class;

    public function definition(): array
    {
        return [
            'phone' => '07'.fake()->numerify('########'),
            'code_hash' => Hash::make('5821'),
            'code_enc' => Crypt::encryptString('5821'),
            'purpose' => 'login',
            'attempts' => 0,
            'expires_at' => now()->addMinutes(5),
            'is_used' => false,
        ];
    }

    public function withCode(string $code): static
    {
        return $this->state(fn (): array => [
            'code_hash' => Hash::make($code),
            'code_enc' => Crypt::encryptString($code),
        ]);
    }
}
