<?php

use App\Models\City;
use App\Models\Courier;
use App\Models\Parcel;
use App\Models\ParcelPhoto;
use App\Models\RoleHabilitation;
use App\Models\User;
use App\Services\ParcelPhotoStorageService;
use Database\Seeders\RoleHabilitationSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Tymon\JWTAuth\Facades\JWTAuth;

function creerJpegPhotoDisqueTest(int $largeur = 24, int $hauteur = 24): UploadedFile
{
    $img = imagecreatetruecolor($largeur, $hauteur);
    $couleur = imagecolorallocate($img, 20, 110, 210);
    imagefill($img, 0, 0, $couleur);

    ob_start();
    imagejpeg($img, null, 85);
    $bytes = (string) ob_get_clean();
    imagedestroy($img);

    return UploadedFile::fake()->createWithContent('preuve.jpg', $bytes);
}

it('configure un disque parcel_photos prive hors public avec surcharge via PARCEL_PHOTOS_DISK', function () {
    $diskConfig = config('filesystems.disks.parcel_photos');

    expect(config('filesystems.parcel_photos_disk'))->toBe('parcel_photos')
        ->and($diskConfig)->toBeArray()
        ->and($diskConfig['driver'] ?? null)->toBe('local')
        ->and($diskConfig['root'] ?? null)->toBe(storage_path('app/private/parcel_photos'))
        ->and($diskConfig['serve'] ?? null)->toBeTrue()
        ->and($diskConfig['throw'] ?? null)->toBeTrue()
        ->and($diskConfig['visibility'] ?? null)->toBe('private');

    $normalizedRoot = str_replace('\\', '/', (string) realpath(dirname((string) ($diskConfig['root'] ?? storage_path('app/private/parcel_photos')))));
    $normalizedPublic = str_replace('\\', '/', (string) realpath(public_path()));
    $normalizedPublicStorage = str_replace('\\', '/', storage_path('app/public'));

    expect(str_starts_with($normalizedRoot, $normalizedPublic))->toBeFalse()
        ->and(str_starts_with($normalizedRoot, $normalizedPublicStorage))->toBeFalse()
        ->and(ParcelPhoto::storageDisk())->toBe('parcel_photos');

    config(['filesystems.parcel_photos_disk' => 's3']);
    expect(ParcelPhoto::storageDisk())->toBe('s3');
    config(['filesystems.parcel_photos_disk' => 'parcel_photos']);
});

it('stocke la photo en prive sur parcel_photos, sert via temporaryUrl 15 min et rejette URL invalide ou expiree', function () {
    $this->seed(RoleHabilitationSeeder::class);

    $city = City::factory()->create(['name' => 'Daloa']);
    $sender = User::factory()->create(['role' => 'client', 'city_id' => $city->id]);
    $driverUser = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    $courier = Courier::factory()->create(['user_id' => $driverUser->id, 'city_id' => $city->id]);
    $admin = User::factory()->create([
        'role' => 'city_manager',
        'role_habilitation_id' => RoleHabilitation::where('slug', 'city_manager')->value('id'),
        'city_id' => $city->id,
    ]);

    $parcel = Parcel::factory()->create([
        'sender_id' => $sender->id,
        'courier_id' => $courier->id,
        'city_id' => $city->id,
        'status' => Parcel::STATUS_AT_SENDER,
    ]);

    try {
        $driverToken = JWTAuth::fromUser($driverUser);
        $beforeUploadTimestamp = now()->timestamp;

        $res = $this->withHeader('Authorization', "Bearer {$driverToken}")
            ->post("/api/v1/driver/parcels/{$parcel->id}/photos", [
                'type' => 'pickup',
                'photo' => creerJpegPhotoDisqueTest(),
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('succes', true);

        /** @var ParcelPhoto $photo */
        $photo = ParcelPhoto::findOrFail((int) $res->json('donnees.id'));
        $signedUrl = (string) $res->json('donnees.signed_url');

        Storage::disk('parcel_photos')->assertExists($photo->storage_path);
        expect(file_exists(public_path($photo->storage_path)))->toBeFalse()
            ->and(file_exists(public_path('storage/'.$photo->storage_path)))->toBeFalse();

        $parts = parse_url($signedUrl);
        parse_str($parts['query'] ?? '', $query);

        expect($parts['path'] ?? '')->not->toBe('')
            ->and($query)->toHaveKeys(['expires', 'signature']);

        $expires = (int) ($query['expires'] ?? 0);
        expect($expires - $beforeUploadTimestamp)
            ->toBeGreaterThanOrEqual(890)
            ->toBeLessThanOrEqual(910);

        $relativeSigned = ($parts['path'] ?? '').'?'.($parts['query'] ?? '');
        $unsignedPath = (string) ($parts['path'] ?? '');
        $tamperedSigned = $unsignedPath.'?expires='.$expires.'&signature=invalid_signature_value';

        // URL signée valide -> 200 avec image valide
        $validResponse = $this->get($relativeSigned);
        $validResponse->assertOk();
        $servedBytes = $validResponse->streamedContent();
        expect(@getimagesizefromstring($servedBytes))->not->toBeFalse();

        // URL sans signature -> rejetée (403)
        $this->get($unsignedPath)->assertStatus(403);

        // URL avec signature falsifiée -> rejetée (403)
        $this->get($tamperedSigned)->assertStatus(403);

        // URL expirée après 16 minutes -> rejetée (403)
        $this->travel(16)->minutes();
        $this->get($relativeSigned)->assertStatus(403);
        $this->travelBack();

        // Vérification accès expéditeur et admin ville via signed_url
        Auth::forgetGuards();
        app()->forgetScopedInstances();
        JWTAuth::unsetToken();
        $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($sender))
            ->getJson("/api/v1/client/parcels/{$parcel->id}")
            ->assertOk()
            ->assertJsonPath('donnees.photos.0.id', $photo->id);

        Auth::forgetGuards();
        app()->forgetScopedInstances();
        JWTAuth::unsetToken();
        $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($admin))
            ->getJson("/api/v1/admin/parcels/{$parcel->id}/photos")
            ->assertOk()
            ->assertJsonPath('donnees.photos.0.id', $photo->id);
    } finally {
        Storage::disk('parcel_photos')->deleteDirectory("photos/colis/{$parcel->id}");
    }
});

it('fonctionne avec Storage::fake parcel_photos pour upload et temporaryUrl', function () {
    Storage::fake('parcel_photos');

    $city = City::factory()->create();
    $sender = User::factory()->create(['role' => 'client', 'city_id' => $city->id]);
    $driverUser = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    $courier = Courier::factory()->create(['user_id' => $driverUser->id, 'city_id' => $city->id]);
    $parcel = Parcel::factory()->create([
        'sender_id' => $sender->id,
        'courier_id' => $courier->id,
        'city_id' => $city->id,
        'status' => Parcel::STATUS_AT_SENDER,
    ]);

    $storagePath = app(ParcelPhotoStorageService::class)
        ->reencoderEtStocker($parcel, creerJpegPhotoDisqueTest(), 'pickup');

    Storage::disk('parcel_photos')->assertExists($storagePath);

    $photo = ParcelPhoto::create([
        'parcel_id' => $parcel->id,
        'type' => 'pickup',
        'photo_url' => '',
        'storage_path' => $storagePath,
    ]);

    expect((string) $photo->signed_url)->toContain('expiration=');
});
