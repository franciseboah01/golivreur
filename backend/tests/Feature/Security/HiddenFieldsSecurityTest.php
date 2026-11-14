<?php

use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('masque device_token lors de la serialisation du modele User', function () {
    $user = User::factory()->create([
        'device_token' => 'fcm-secret-token-12345',
    ]);

    $array = $user->toArray();
    $json = $user->toJson();

    expect($array)->not->toHaveKey('device_token');
    expect($json)->not->toContain('fcm-secret-token-12345');
});

it('masque code_hash lors de la serialisation du modele OtpCode', function () {
    $otp = OtpCode::create([
        'phone' => '0701020304',
        'code_hash' => Hash::make('1234'),
        'purpose' => 'login',
        'expires_at' => now()->addMinutes(5),
    ]);

    $array = $otp->toArray();
    $json = $otp->toJson();

    expect($array)->not->toHaveKey('code_hash');
    expect($json)->not->toContain('code_hash');
});
