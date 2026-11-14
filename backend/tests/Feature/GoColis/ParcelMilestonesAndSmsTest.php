<?php

use App\Models\City;
use App\Models\Courier;
use App\Models\Notification;
use App\Models\Parcel;
use App\Models\ParcelPhoto;
use App\Models\User;
use App\Models\Wallet;
use App\Services\SmsService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tymon\JWTAuth\Facades\JWTAuth;

function makeTestJpeg(): UploadedFile
{
    $img = imagecreatetruecolor(20, 20);
    ob_start();
    imagejpeg($img, null, 85);
    $bytes = (string) ob_get_clean();
    imagedestroy($img);

    return UploadedFile::fake()->createWithContent('proof.jpg', $bytes);
}

it('P7 Point 3: impose ordre at-sender -> photo -> code -> at-recipient -> photo -> code et notifie expediteur/destinataire', function () {
    Queue::fake();
    Storage::fake('s3');

    $logged = [];
    Log::listen(function ($entry) use (&$logged) {
        $logged[] = $entry->message.' '.json_encode($entry->context);
    });

    $cityDaloa = City::factory()->create(['name' => 'Daloa']);
    $cityBouake = City::factory()->create(['name' => 'Bouaké']);

    $sender = User::factory()->create(['role' => 'client', 'city_id' => $cityDaloa->id, 'phone' => '0701111111']);
    $driverUser = User::factory()->create(['role' => 'driver', 'city_id' => $cityDaloa->id]);
    $courier = Courier::factory()->create(['user_id' => $driverUser->id, 'city_id' => $cityDaloa->id]);
    Wallet::create(['owner_type' => 'courier', 'owner_id' => $courier->id, 'balance' => 20000]);

    $otherDriverUser = User::factory()->create(['role' => 'driver', 'city_id' => $cityDaloa->id]);
    Courier::factory()->create(['user_id' => $otherDriverUser->id, 'city_id' => $cityDaloa->id]);

    $bouakeDriverUser = User::factory()->create(['role' => 'driver', 'city_id' => $cityBouake->id]);
    Courier::factory()->create(['user_id' => $bouakeDriverUser->id, 'city_id' => $cityBouake->id]);

    $senderCodePlain = '4321';
    $recipientCodePlain = '8765';

    $parcel = Parcel::factory()->create([
        'sender_id' => $sender->id,
        'courier_id' => $courier->id,
        'city_id' => $cityDaloa->id,
        'status' => Parcel::STATUS_ACCEPTED,
        'recipient_phone' => '0502222222',
        'payment_method' => 'cash',
        'shipping_fee' => 1500,
        'insurance_amount' => 0,
        'courier_amount' => 1125,
        'platform_commission' => 375,
        'sender_code' => Hash::make($senderCodePlain),
        'recipient_code' => Hash::make($recipientCodePlain),
    ]);

    $smsSpy = Mockery::mock(SmsService::class)->makePartial();
    $smsSpy->shouldReceive('envoyerNotificationColisRamasse')
        ->once()
        ->with('0502222222', Mockery::on(fn ($p) => $p->id === $parcel->id))
        ->andReturn(true);
    $smsSpy->shouldReceive('envoyerNotificationColisLivre')
        ->once()
        ->with('0502222222', Mockery::on(fn ($p) => $p->id === $parcel->id))
        ->andReturn(true);
    $smsSpy->shouldNotReceive('envoyerCodeDestinataire');
    $this->app->instance(SmsService::class, $smsSpy);

    // Controle IDOR sur jalons : autre livreur -> 403, autre ville -> 404, client -> 403
    Auth::forgetGuards();
    app()->forgetScopedInstances();
    JWTAuth::unsetToken();
    $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($otherDriverUser))
        ->postJson("/api/v1/driver/parcels/{$parcel->id}/at-sender")
        ->assertStatus(403);

    Auth::forgetGuards();
    app()->forgetScopedInstances();
    JWTAuth::unsetToken();
    $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($bouakeDriverUser))
        ->postJson("/api/v1/driver/parcels/{$parcel->id}/at-sender")
        ->assertStatus(404);

    Auth::forgetGuards();
    app()->forgetScopedInstances();
    JWTAuth::unsetToken();
    $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($sender))
        ->postJson("/api/v1/driver/parcels/{$parcel->id}/at-sender")
        ->assertStatus(403);

    // Livreur assigne -> controles d'ordre stricts
    Auth::forgetGuards();
    app()->forgetScopedInstances();
    JWTAuth::unsetToken();
    $driverToken = JWTAuth::fromUser($driverUser);

    // Impossible d'appeler at-recipient tant que le colis n'est pas picked_up / in_transit
    $this->withHeader('Authorization', "Bearer {$driverToken}")
        ->postJson("/api/v1/driver/parcels/{$parcel->id}/at-recipient")
        ->assertStatus(422);

    // Photo refusee avant at-sender
    $this->withHeader('Authorization', "Bearer {$driverToken}")
        ->post("/api/v1/driver/parcels/{$parcel->id}/photos", [
            'type' => 'pickup',
            'photo' => makeTestJpeg(),
        ], ['Accept' => 'application/json'])
        ->assertStatus(422);

    // 1) at-sender -> STATUS_AT_SENDER
    $this->withHeader('Authorization', "Bearer {$driverToken}")
        ->postJson("/api/v1/driver/parcels/{$parcel->id}/at-sender")
        ->assertOk()
        ->assertJsonPath('donnees.status', Parcel::STATUS_AT_SENDER);

    // Re-appel at-sender refuse (deja at_sender)
    $this->withHeader('Authorization', "Bearer {$driverToken}")
        ->postJson("/api/v1/driver/parcels/{$parcel->id}/at-sender")
        ->assertStatus(422);

    // verify-sender-code refuse sans photo
    $this->withHeader('Authorization', "Bearer {$driverToken}")
        ->postJson("/api/v1/parcels/{$parcel->id}/verify-sender-code", ['code' => $senderCodePlain])
        ->assertStatus(422);

    // 2) Photo pickup -> 201
    $this->withHeader('Authorization', "Bearer {$driverToken}")
        ->post("/api/v1/driver/parcels/{$parcel->id}/photos", [
            'type' => 'pickup',
            'photo' => makeTestJpeg(),
        ], ['Accept' => 'application/json'])
        ->assertCreated();

    // 3) Code expediteur -> picked_up + notif expediteur (in-app, sans SMS) + SMS destinataire
    $this->withHeader('Authorization', "Bearer {$driverToken}")
        ->postJson("/api/v1/parcels/{$parcel->id}/verify-sender-code", ['code' => $senderCodePlain])
        ->assertOk()
        ->assertJsonPath('donnees.status', Parcel::STATUS_PICKED_UP);

    expect(Notification::where('user_id', $sender->id)->where('type', 'parcel_picked_up')->exists())->toBeTrue();

    // Photo delivery refusee avant at-recipient
    $this->withHeader('Authorization', "Bearer {$driverToken}")
        ->post("/api/v1/driver/parcels/{$parcel->id}/photos", [
            'type' => 'delivery',
            'photo' => makeTestJpeg(),
        ], ['Accept' => 'application/json'])
        ->assertStatus(422);

    // 4) at-recipient -> STATUS_AT_RECIPIENT
    $this->withHeader('Authorization', "Bearer {$driverToken}")
        ->postJson("/api/v1/driver/parcels/{$parcel->id}/at-recipient")
        ->assertOk()
        ->assertJsonPath('donnees.status', Parcel::STATUS_AT_RECIPIENT);

    // verify-recipient-code refuse sans photo delivery
    $this->withHeader('Authorization', "Bearer {$driverToken}")
        ->postJson("/api/v1/parcels/{$parcel->id}/verify-recipient-code", ['code' => $recipientCodePlain])
        ->assertStatus(422);

    // 5) Photo delivery -> 201
    $this->withHeader('Authorization', "Bearer {$driverToken}")
        ->post("/api/v1/driver/parcels/{$parcel->id}/photos", [
            'type' => 'delivery',
            'photo' => makeTestJpeg(),
        ], ['Accept' => 'application/json'])
        ->assertCreated();

    // 6) Code destinataire -> delivered + notif expediteur (in-app) + SMS destinataire
    $this->withHeader('Authorization', "Bearer {$driverToken}")
        ->postJson("/api/v1/parcels/{$parcel->id}/verify-recipient-code", ['code' => $recipientCodePlain])
        ->assertOk()
        ->assertJsonPath('donnees.status', Parcel::STATUS_DELIVERED);

    expect(Notification::where('user_id', $sender->id)->where('type', 'parcel_delivered')->exists())->toBeTrue();

    $logDump = implode("\n", $logged);
    expect($logDump)->not->toContain($senderCodePlain)
        ->and($logDump)->not->toContain($recipientCodePlain);
});

it('P7 Point 3 compatibilite: /verify-* acceptent toujours les anciens statuts sans jalon at_sender/at_recipient', function () {
    Queue::fake();

    $city = City::factory()->create();
    $sender = User::factory()->create(['role' => 'client', 'city_id' => $city->id]);
    $driverUser = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    $courier = Courier::factory()->create(['user_id' => $driverUser->id, 'city_id' => $city->id]);
    Wallet::create(['owner_type' => 'courier', 'owner_id' => $courier->id, 'balance' => 20000]);

    $parcel = Parcel::factory()->create([
        'sender_id' => $sender->id,
        'courier_id' => $courier->id,
        'city_id' => $city->id,
        'status' => Parcel::STATUS_ACCEPTED,
        'payment_method' => 'cash',
        'shipping_fee' => 1500,
        'insurance_amount' => 0,
        'courier_amount' => 1125,
        'platform_commission' => 375,
        'sender_code' => Hash::make('1111'),
        'recipient_code' => Hash::make('2222'),
    ]);

    ParcelPhoto::create(['parcel_id' => $parcel->id, 'type' => 'pickup', 'photo_url' => 'https://cdn.example/p.jpg']);
    ParcelPhoto::create(['parcel_id' => $parcel->id, 'type' => 'delivery', 'photo_url' => 'https://cdn.example/d.jpg']);

    $token = JWTAuth::fromUser($driverUser);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/parcels/{$parcel->id}/verify-sender-code", ['code' => '1111'])
        ->assertOk()
        ->assertJsonPath('donnees.status', Parcel::STATUS_PICKED_UP);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/parcels/{$parcel->id}/verify-recipient-code", ['code' => '2222'])
        ->assertOk()
        ->assertJsonPath('donnees.status', Parcel::STATUS_DELIVERED);
});
