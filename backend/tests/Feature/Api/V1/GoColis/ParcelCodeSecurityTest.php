<?php

use App\Models\City;
use App\Models\Courier;
use App\Models\Parcel;
use App\Models\ParcelPhoto;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tymon\JWTAuth\Facades\JWTAuth;

it('refuse (403) un livreur sur un colis Parcel sans courier_id', function () {
    $city = City::factory()->create();
    $driver = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    Courier::factory()->create(['user_id' => $driver->id, 'city_id' => $city->id]);

    $parcel = Parcel::factory()->create([
        'city_id' => $city->id,
        'courier_id' => null,
        'status' => Parcel::STATUS_AT_SENDER,
        'sender_code' => Hash::make('4829'),
        'recipient_code' => Hash::make('7429'),
    ]);

    ParcelPhoto::create(['parcel_id' => $parcel->id, 'type' => 'pickup', 'photo_url' => 'https://cdn.test/p.jpg']);
    ParcelPhoto::create(['parcel_id' => $parcel->id, 'type' => 'delivery', 'photo_url' => 'https://cdn.test/d.jpg']);

    $token = JWTAuth::fromUser($driver);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/parcels/{$parcel->id}/verify-sender-code", ['code' => '4829'])
        ->assertStatus(403)
        ->assertJsonPath('succes', false)
        ->assertJsonPath('message', 'Ce colis n\'est attribué à aucun livreur.');

    $parcel->update(['status' => Parcel::STATUS_AT_RECIPIENT]);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/parcels/{$parcel->id}/verify-recipient-code", ['code' => '7429'])
        ->assertStatus(403)
        ->assertJsonPath('succes', false)
        ->assertJsonPath('message', 'Ce colis n\'est attribué à aucun livreur.');
});

it('refuse (403) le livreur A sur un colis Parcel assigné au livreur B', function () {
    $city = City::factory()->create();
    $driverA = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    Courier::factory()->create(['user_id' => $driverA->id, 'city_id' => $city->id]);

    $driverB = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    $courierB = Courier::factory()->create(['user_id' => $driverB->id, 'city_id' => $city->id]);

    $parcel = Parcel::factory()->create([
        'city_id' => $city->id,
        'courier_id' => $courierB->id,
        'status' => Parcel::STATUS_AT_SENDER,
        'sender_code' => Hash::make('4829'),
        'recipient_code' => Hash::make('7429'),
    ]);

    ParcelPhoto::create(['parcel_id' => $parcel->id, 'type' => 'pickup', 'photo_url' => 'https://cdn.test/p.jpg']);
    ParcelPhoto::create(['parcel_id' => $parcel->id, 'type' => 'delivery', 'photo_url' => 'https://cdn.test/d.jpg']);

    $tokenA = JWTAuth::fromUser($driverA);

    $this->withHeader('Authorization', "Bearer {$tokenA}")
        ->postJson("/api/v1/parcels/{$parcel->id}/verify-sender-code", ['code' => '4829'])
        ->assertStatus(403)
        ->assertJsonPath('succes', false)
        ->assertJsonPath('message', 'Ce colis est assigné à un autre livreur.');

    $parcel->update(['status' => Parcel::STATUS_AT_RECIPIENT]);

    $this->withHeader('Authorization', "Bearer {$tokenA}")
        ->postJson("/api/v1/parcels/{$parcel->id}/verify-recipient-code", ['code' => '7429'])
        ->assertStatus(403)
        ->assertJsonPath('succes', false)
        ->assertJsonPath('message', 'Ce colis est assigné à un autre livreur.');
});

it('autorise le livreur assigné et les rôles super_admin et dg_national sur un colis Parcel', function () {
    $city = City::factory()->create();
    $driver = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    $courier = Courier::factory()->create(['user_id' => $driver->id, 'city_id' => $city->id]);

    $parcel = Parcel::factory()->create([
        'city_id' => $city->id,
        'courier_id' => $courier->id,
        'status' => Parcel::STATUS_AT_SENDER,
        'sender_code' => Hash::make('4829'),
        'recipient_code' => Hash::make('7429'),
        'courier_amount' => 1500,
    ]);

    ParcelPhoto::create(['parcel_id' => $parcel->id, 'type' => 'pickup', 'photo_url' => 'https://cdn.test/p.jpg']);
    ParcelPhoto::create(['parcel_id' => $parcel->id, 'type' => 'delivery', 'photo_url' => 'https://cdn.test/d.jpg']);

    $driverToken = JWTAuth::fromUser($driver);

    $this->withHeader('Authorization', "Bearer {$driverToken}")
        ->postJson("/api/v1/parcels/{$parcel->id}/verify-sender-code", ['code' => '4829'])
        ->assertOk()
        ->assertJsonPath('succes', true);

    expect($parcel->fresh()->status)->toBe(Parcel::STATUS_PICKED_UP);

    // super_admin et dg_national gardent leur accès
    $superAdmin = User::factory()->create(['role' => 'super_admin', 'city_id' => null]);
    $dgNational = User::factory()->create(['role' => 'dg_national', 'city_id' => null]);

    $parcel2 = Parcel::factory()->create([
        'city_id' => $city->id,
        'courier_id' => null,
        'status' => Parcel::STATUS_AT_SENDER,
        'sender_code' => Hash::make('1122'),
        'recipient_code' => Hash::make('3344'),
    ]);
    ParcelPhoto::create(['parcel_id' => $parcel2->id, 'type' => 'pickup', 'photo_url' => 'https://cdn.test/p2.jpg']);
    ParcelPhoto::create(['parcel_id' => $parcel2->id, 'type' => 'delivery', 'photo_url' => 'https://cdn.test/d2.jpg']);

    $this->actingAs($superAdmin, 'api')
        ->postJson("/api/v1/parcels/{$parcel2->id}/verify-sender-code", ['code' => '1122'])
        ->assertOk();

    $parcel2->update(['status' => Parcel::STATUS_AT_RECIPIENT]);

    $this->actingAs($dgNational, 'api')
        ->postJson("/api/v1/parcels/{$parcel2->id}/verify-recipient-code", ['code' => '3344'])
        ->assertOk();
});

it('rejette le code destinataire au stade de ramassage d\'un colis Parcel', function () {
    $city = City::factory()->create();
    $driver = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    $courier = Courier::factory()->create(['user_id' => $driver->id, 'city_id' => $city->id]);

    $parcel = Parcel::factory()->create([
        'city_id' => $city->id,
        'courier_id' => $courier->id,
        'status' => Parcel::STATUS_AT_SENDER,
        'sender_code' => Hash::make('4829'),
        'recipient_code' => Hash::make('7429'),
    ]);

    ParcelPhoto::create(['parcel_id' => $parcel->id, 'type' => 'delivery', 'photo_url' => 'https://cdn.test/d.jpg']);

    $token = JWTAuth::fromUser($driver);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/parcels/{$parcel->id}/verify-recipient-code", ['code' => '7429'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['status']);

    expect($parcel->fresh()->status)->toBe(Parcel::STATUS_AT_SENDER)
        ->and($parcel->fresh()->recipient_code_attempts)->toBe(0);
});

it('bloque les deux codes d\'un colis Parcel après 3 mauvais codes et n\'écrit aucun code dans les logs', function () {
    Queue::fake();

    $loggedMessages = [];
    Log::listen(function ($entry) use (&$loggedMessages) {
        $loggedMessages[] = $entry->message.' '.json_encode($entry->context);
    });

    $city = City::factory()->create();
    $driver = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    $courier = Courier::factory()->create(['user_id' => $driver->id, 'city_id' => $city->id]);

    $parcelSender = Parcel::factory()->create([
        'city_id' => $city->id,
        'courier_id' => $courier->id,
        'status' => Parcel::STATUS_AT_SENDER,
        'sender_code' => Hash::make('4829'),
        'recipient_code' => Hash::make('7429'),
    ]);
    ParcelPhoto::create(['parcel_id' => $parcelSender->id, 'type' => 'pickup', 'photo_url' => 'https://cdn.test/p.jpg']);

    $token = JWTAuth::fromUser($driver);

    // 3 mauvais codes expéditeur -> blocage
    for ($i = 0; $i < 3; $i++) {
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/parcels/{$parcelSender->id}/verify-sender-code", ['code' => '0000'])
            ->assertStatus(422);
    }

    expect($parcelSender->fresh()->sender_code_locked)->toBeTrue()
        ->and($parcelSender->fresh()->sender_code_attempts)->toBe(3);

    // Même le bon code doit ensuite échouer tant que bloqué
    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/parcels/{$parcelSender->id}/verify-sender-code", ['code' => '4829'])
        ->assertStatus(422);

    // 3 mauvais codes destinataire -> blocage
    $parcelRecipient = Parcel::factory()->create([
        'city_id' => $city->id,
        'courier_id' => $courier->id,
        'status' => Parcel::STATUS_AT_RECIPIENT,
        'sender_code' => Hash::make('1111'),
        'recipient_code' => Hash::make('8392'),
    ]);
    ParcelPhoto::create(['parcel_id' => $parcelRecipient->id, 'type' => 'delivery', 'photo_url' => 'https://cdn.test/d.jpg']);

    for ($i = 0; $i < 3; $i++) {
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/parcels/{$parcelRecipient->id}/verify-recipient-code", ['code' => '9999'])
            ->assertStatus(422);
    }

    expect($parcelRecipient->fresh()->recipient_code_locked)->toBeTrue()
        ->and($parcelRecipient->fresh()->recipient_code_attempts)->toBe(3);

    // Même le bon code doit échouer tant que bloqué
    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/parcels/{$parcelRecipient->id}/verify-recipient-code", ['code' => '8392'])
        ->assertStatus(422);

    $combinedLogs = implode("\n", $loggedMessages);
    expect($combinedLogs)->not->toContain('4829')
        ->and($combinedLogs)->not->toContain('7429')
        ->and($combinedLogs)->not->toContain('8392')
        ->and($combinedLogs)->not->toContain('0000')
        ->and($combinedLogs)->not->toContain('9999');
});
