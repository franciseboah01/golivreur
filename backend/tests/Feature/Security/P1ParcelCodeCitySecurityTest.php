<?php

use App\Models\City;
use App\Models\Courier;
use App\Models\Parcel;
use App\Models\ParcelPhoto;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tymon\JWTAuth\Facades\JWTAuth;

it('P1b autre ville: refuse 404 sans consommer tentative si un livreur tente verifySenderCode ou verifyRecipientCode hors ville', function () {
    $cityDaloa = City::factory()->create(['name' => 'Daloa']);
    $cityBouake = City::factory()->create(['name' => 'Bouaké']);

    $driverDaloa = User::factory()->create(['role' => 'driver', 'city_id' => $cityDaloa->id]);
    $courierDaloa = Courier::factory()->create(['user_id' => $driverDaloa->id, 'city_id' => $cityDaloa->id]);

    $parcelBouake = Parcel::factory()->create([
        'city_id' => $cityBouake->id,
        'courier_id' => $courierDaloa->id,
        'status' => Parcel::STATUS_AT_SENDER,
        'sender_code' => Hash::make('4829'),
        'recipient_code' => Hash::make('7429'),
        'sender_code_attempts' => 0,
        'recipient_code_attempts' => 0,
    ]);
    ParcelPhoto::create(['parcel_id' => $parcelBouake->id, 'type' => 'pickup', 'photo_url' => 'https://cdn.test/p.jpg']);
    ParcelPhoto::create(['parcel_id' => $parcelBouake->id, 'type' => 'delivery', 'photo_url' => 'https://cdn.test/d.jpg']);

    $token = JWTAuth::fromUser($driverDaloa);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/parcels/{$parcelBouake->id}/verify-sender-code", ['code' => '0000'])
        ->assertStatus(404)
        ->assertJsonPath('succes', false);

    $parcelBouake->update(['status' => Parcel::STATUS_AT_RECIPIENT]);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/parcels/{$parcelBouake->id}/verify-recipient-code", ['code' => '0000'])
        ->assertStatus(404)
        ->assertJsonPath('succes', false);

    $fresh = $parcelBouake->fresh();
    expect($fresh->status)->toBe(Parcel::STATUS_AT_RECIPIENT)
        ->and($fresh->sender_code_attempts)->toBe(0)
        ->and($fresh->recipient_code_attempts)->toBe(0);
});
