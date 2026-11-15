<?php

use App\Models\City;
use App\Models\Courier;
use App\Models\Parcel;
use App\Models\ParcelPhoto;
use App\Models\RoleHabilitation;
use App\Models\User;
use Database\Seeders\RoleHabilitationSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Tymon\JWTAuth\Facades\JWTAuth;

function makeRawImageWithTrailingPayload(string $format, string $payload = 'EVIL_TRAILING_PAYLOAD'): string
{
    $img = imagecreatetruecolor(24, 24);
    $bg = imagecolorallocate($img, 24, 120, 220);
    imagefill($img, 0, 0, $bg);

    ob_start();
    match ($format) {
        'png' => imagepng($img),
        'webp' => imagewebp($img),
        default => imagejpeg($img, null, 90),
    };
    $bytes = (string) ob_get_clean();
    imagedestroy($img);

    return $bytes.$payload;
}

it('P7 Point 2: refuse .exe, fichiers > 5 Mo et tiers, re-encode JPEG/PNG/WebP en prive sur le disque photo', function () {
    Storage::fake(ParcelPhoto::storageDisk());

    $cityDaloa = City::factory()->create(['name' => 'Daloa']);
    $cityBouake = City::factory()->create(['name' => 'Bouaké']);

    $sender = User::factory()->create(['role' => 'client', 'city_id' => $cityDaloa->id]);
    $driverUser = User::factory()->create(['role' => 'driver', 'city_id' => $cityDaloa->id]);
    $courier = Courier::factory()->create(['user_id' => $driverUser->id, 'city_id' => $cityDaloa->id]);

    $otherDriverUser = User::factory()->create(['role' => 'driver', 'city_id' => $cityDaloa->id]);
    Courier::factory()->create(['user_id' => $otherDriverUser->id, 'city_id' => $cityDaloa->id]);

    $bouakeDriverUser = User::factory()->create(['role' => 'driver', 'city_id' => $cityBouake->id]);
    Courier::factory()->create(['user_id' => $bouakeDriverUser->id, 'city_id' => $cityBouake->id]);

    $parcel = Parcel::factory()->create([
        'sender_id' => $sender->id,
        'courier_id' => $courier->id,
        'city_id' => $cityDaloa->id,
        'status' => Parcel::STATUS_AT_SENDER,
    ]);

    // 1. Tiers non assigne / autre livreur / autre ville / client -> refuses
    Auth::forgetGuards();
    app()->forgetScopedInstances();
    JWTAuth::unsetToken();
    $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($sender))
        ->postJson("/api/v1/driver/parcels/{$parcel->id}/photos", [
            'type' => 'pickup',
            'photo' => UploadedFile::fake()->image('valid.jpg', 20, 20),
        ])
        ->assertStatus(403);

    Auth::forgetGuards();
    app()->forgetScopedInstances();
    JWTAuth::unsetToken();
    $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($otherDriverUser))
        ->post("/api/v1/driver/parcels/{$parcel->id}/photos", [
            'type' => 'pickup',
            'photo' => UploadedFile::fake()->image('valid.jpg', 20, 20),
        ], ['Accept' => 'application/json'])
        ->assertStatus(403);

    Auth::forgetGuards();
    app()->forgetScopedInstances();
    JWTAuth::unsetToken();
    $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($bouakeDriverUser))
        ->post("/api/v1/driver/parcels/{$parcel->id}/photos", [
            'type' => 'pickup',
            'photo' => UploadedFile::fake()->image('valid.jpg', 20, 20),
        ], ['Accept' => 'application/json'])
        ->assertStatus(404);

    // 2. Fichier .exe (reel ou deguise en .jpg) -> refuse 422
    Auth::forgetGuards();
    app()->forgetScopedInstances();
    JWTAuth::unsetToken();
    $driverToken = JWTAuth::fromUser($driverUser);

    $exeFile = UploadedFile::fake()->createWithContent('trojan.exe', "MZ\x90\x00\x03\x00\x00\x00\x04\x00");
    $this->withHeader('Authorization', "Bearer {$driverToken}")
        ->post("/api/v1/driver/parcels/{$parcel->id}/photos", [
            'type' => 'pickup',
            'photo' => $exeFile,
        ], ['Accept' => 'application/json'])
        ->assertStatus(422);

    $spoofedExeAsJpg = UploadedFile::fake()->createWithContent('avatar.jpg', "MZ\x90\x00FAKE_PE_HEADER");
    $this->withHeader('Authorization', "Bearer {$driverToken}")
        ->post("/api/v1/driver/parcels/{$parcel->id}/photos", [
            'type' => 'pickup',
            'photo' => $spoofedExeAsJpg,
        ], ['Accept' => 'application/json'])
        ->assertStatus(422);

    // 3. Fichier > 5 Mo -> refuse 422
    $oversizeFile = UploadedFile::fake()->create('huge.jpg', 5125, 'image/jpeg');
    $this->withHeader('Authorization', "Bearer {$driverToken}")
        ->post("/api/v1/driver/parcels/{$parcel->id}/photos", [
            'type' => 'pickup',
            'photo' => $oversizeFile,
        ], ['Accept' => 'application/json'])
        ->assertStatus(422);

    // 4. JPEG, PNG, WebP valides -> 201 + re-encodes + prive sur disk s3
    foreach ([
        ['ext' => 'jpg', 'mime' => 'image/jpeg', 'fmt' => 'jpeg', 'type' => 'pickup'],
        ['ext' => 'png', 'mime' => 'image/png', 'fmt' => 'png', 'type' => 'pickup'],
    ] as $case) {
        $bytes = makeRawImageWithTrailingPayload($case['fmt'], 'EVIL_PAYLOAD_STRIPPED');
        $upload = UploadedFile::fake()->createWithContent("origine-secret.{$case['ext']}", $bytes);

        $res = $this->withHeader('Authorization', "Bearer {$driverToken}")
            ->post("/api/v1/driver/parcels/{$parcel->id}/photos", [
                'type' => $case['type'],
                'photo' => $upload,
                'lat' => 6.8775,
                'lng' => -6.4508,
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('succes', true);

        $photoId = $res->json('donnees.id');
        /** @var ParcelPhoto $photo */
        $photo = ParcelPhoto::findOrFail($photoId);
        expect($photo->storage_path)->not->toBeNull()
            ->and($photo->storage_path)->not->toContain('origine-secret')
            ->and((string) $photo->photo_url)->not->toStartWith('http');

        Storage::disk(ParcelPhoto::storageDisk())->assertExists($photo->storage_path);
        $storedBytes = Storage::disk(ParcelPhoto::storageDisk())->get($photo->storage_path);
        expect($storedBytes)->not->toContain('EVIL_PAYLOAD_STRIPPED')
            ->and(@getimagesizefromstring($storedBytes))->not->toBeFalse();

        expect($res->json('donnees.signed_url'))->toContain('expiration=')
            ->and($res->json('donnees'))->not->toHaveKey('storage_path');
    }

    // WebP sur livraison (at_recipient)
    $parcel->update(['status' => Parcel::STATUS_AT_RECIPIENT]);
    $webpBytes = makeRawImageWithTrailingPayload('webp', 'EVIL_WEBP_PAYLOAD');
    $webpUpload = UploadedFile::fake()->createWithContent('livraison.webp', $webpBytes);

    $webpRes = $this->withHeader('Authorization', "Bearer {$driverToken}")
        ->post("/api/v1/driver/parcels/{$parcel->id}/photos", [
            'type' => 'delivery',
            'photo' => $webpUpload,
        ], ['Accept' => 'application/json'])
        ->assertCreated();

    $webpPhoto = ParcelPhoto::findOrFail($webpRes->json('donnees.id'));
    $storedWebp = Storage::disk(ParcelPhoto::storageDisk())->get($webpPhoto->storage_path);
    expect($storedWebp)->not->toContain('EVIL_WEBP_PAYLOAD')
        ->and(@getimagesizefromstring($storedWebp))->not->toBeFalse();
});

it('P7 Point 2: impose ordre statut photo et expose URL signees uniquement a expediteur et admin ville', function () {
    Storage::fake(ParcelPhoto::storageDisk());
    $this->seed(RoleHabilitationSeeder::class);

    $cityDaloa = City::factory()->create(['name' => 'Daloa']);
    $cityBouake = City::factory()->create(['name' => 'Bouaké']);

    $sender = User::factory()->create(['role' => 'client', 'city_id' => $cityDaloa->id]);
    $otherClient = User::factory()->create(['role' => 'client', 'city_id' => $cityDaloa->id]);
    $driverUser = User::factory()->create(['role' => 'driver', 'city_id' => $cityDaloa->id]);
    $courier = Courier::factory()->create(['user_id' => $driverUser->id, 'city_id' => $cityDaloa->id]);

    $managerDaloa = User::factory()->create([
        'role' => 'city_manager',
        'role_habilitation_id' => RoleHabilitation::where('slug', 'city_manager')->value('id'),
        'city_id' => $cityDaloa->id,
    ]);
    $managerBouake = User::factory()->create([
        'role' => 'city_manager',
        'role_habilitation_id' => RoleHabilitation::where('slug', 'city_manager')->value('id'),
        'city_id' => $cityBouake->id,
    ]);

    $parcel = Parcel::factory()->create([
        'sender_id' => $sender->id,
        'courier_id' => $courier->id,
        'city_id' => $cityDaloa->id,
        'status' => Parcel::STATUS_ACCEPTED,
    ]);

    $driverToken = JWTAuth::fromUser($driverUser);
    $jpgBytes = makeRawImageWithTrailingPayload('jpeg');

    // Photo pickup refusee tant que statut n'est pas at_sender -> 422
    $this->withHeader('Authorization', "Bearer {$driverToken}")
        ->post("/api/v1/driver/parcels/{$parcel->id}/photos", [
            'type' => 'pickup',
            'photo' => UploadedFile::fake()->createWithContent('p.jpg', $jpgBytes),
        ], ['Accept' => 'application/json'])
        ->assertStatus(422);

    // Apres at_sender -> 201
    $parcel->update(['status' => Parcel::STATUS_AT_SENDER]);
    $this->withHeader('Authorization', "Bearer {$driverToken}")
        ->post("/api/v1/driver/parcels/{$parcel->id}/photos", [
            'type' => 'pickup',
            'photo' => UploadedFile::fake()->createWithContent('p.jpg', $jpgBytes),
        ], ['Accept' => 'application/json'])
        ->assertCreated();

    // Photo delivery refusee avant at_recipient -> 422
    $parcel->update(['status' => Parcel::STATUS_PICKED_UP]);
    $this->withHeader('Authorization', "Bearer {$driverToken}")
        ->post("/api/v1/driver/parcels/{$parcel->id}/photos", [
            'type' => 'delivery',
            'photo' => UploadedFile::fake()->createWithContent('d.jpg', $jpgBytes),
        ], ['Accept' => 'application/json'])
        ->assertStatus(422);

    // showClient expose les photos signees a l'expediteur uniquement
    Auth::forgetGuards();
    app()->forgetScopedInstances();
    JWTAuth::unsetToken();
    $showSender = $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($sender))
        ->getJson("/api/v1/client/parcels/{$parcel->id}")
        ->assertOk();

    expect($showSender->json('donnees.photos'))->toHaveCount(1)
        ->and($showSender->json('donnees.photos.0.signed_url'))->toContain('expiration=')
        ->and($showSender->json('donnees.photos.0'))->not->toHaveKey('storage_path');

    Auth::forgetGuards();
    app()->forgetScopedInstances();
    JWTAuth::unsetToken();
    $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($otherClient))
        ->getJson("/api/v1/client/parcels/{$parcel->id}")
        ->assertStatus(403);

    // Admin ville (meme ville OK, autre ville 404)
    Auth::forgetGuards();
    app()->forgetScopedInstances();
    JWTAuth::unsetToken();
    $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($managerDaloa))
        ->getJson("/api/v1/admin/parcels/{$parcel->id}/photos")
        ->assertOk()
        ->assertJsonPath('succes', true);

    Auth::forgetGuards();
    app()->forgetScopedInstances();
    JWTAuth::unsetToken();
    $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($managerBouake))
        ->getJson("/api/v1/admin/parcels/{$parcel->id}/photos")
        ->assertStatus(404);
});
