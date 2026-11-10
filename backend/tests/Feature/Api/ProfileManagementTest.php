<?php

use App\Models\City;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\MerchantCategory;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tymon\JWTAuth\Facades\JWTAuth;

// ─── TESTS PROFIL CLIENT ──────────────────────────────────────────────────────

it('client : peut lire, mettre à jour son profil, uploader une photo et remplacer l\'ancienne', function () {
    Storage::fake('public');

    $city = City::factory()->create(['name' => 'Daloa']);
    $client = User::factory()->create([
        'name'  => 'Client Initial',
        'phone' => '0701000010',
        'role'  => 'client',
        'city_id' => $city->id,
    ]);

    $token = JWTAuth::fromUser($client);

    // 1. Lecture profil
    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/client/profile')
        ->assertOk()
        ->assertJsonPath('donnees.name', 'Client Initial')
        ->assertJsonPath('donnees.phone', '0701000010');

    // 2. Mise à jour + upload première photo
    $photo1 = UploadedFile::fake()->image('avatar1.jpg', 300, 300);

    $res1 = $this->withHeader('Authorization', "Bearer {$token}")
        ->post('/api/v1/client/profile', [
            '_method'               => 'PUT',
            'name'                  => 'Client Modifié',
            'phone'                 => '+225 07 01 00 00 99',
            'email'                 => 'client.modifie@golivreur.ci',
            'default_address'       => 'Quartier Lobia, Rue 4',
            'language'              => 'en',
            'notifications_enabled' => '0',
            'photo'                 => $photo1,
        ]);

    $res1->assertOk()
        ->assertJsonPath('succes', true)
        ->assertJsonPath('donnees.name', 'Client Modifié')
        ->assertJsonPath('donnees.phone', '0701000099')
        ->assertJsonPath('donnees.email', 'client.modifie@golivreur.ci')
        ->assertJsonPath('donnees.default_address', 'Quartier Lobia, Rue 4')
        ->assertJsonPath('donnees.language', 'en')
        ->assertJsonPath('donnees.notifications_enabled', false);

    $client->refresh();
    $oldPath = $client->photo_path;
    expect($oldPath)->not->toBeNull();
    Storage::disk('public')->assertExists($oldPath);

    // 3. Remplacement photo -> l'ancien fichier doit être supprimé
    $photo2 = UploadedFile::fake()->image('avatar2.png', 300, 300);
    $this->withHeader('Authorization', "Bearer {$token}")
        ->post('/api/v1/client/profile', [
            '_method' => 'PUT',
            'photo'   => $photo2,
        ])
        ->assertOk();

    $client->refresh();
    expect($client->photo_path)->not->toBe($oldPath);
    Storage::disk('public')->assertMissing($oldPath);
    Storage::disk('public')->assertExists($client->photo_path);

    // 4. Cohérence Admin -> Mobile : modification côté DB/Admin reflétée sur GET
    $client->update(['default_address' => 'Quartier Soleil (Modifié Admin)']);
    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/client/profile')
        ->assertOk()
        ->assertJsonPath('donnees.default_address', 'Quartier Soleil (Modifié Admin)');
});

it('client : rejette les données invalides (422) et bloque la modification d\'un autre profil (403)', function () {
    $city = City::factory()->create();
    $client1 = User::factory()->create(['role' => 'client', 'email' => 'c1@golivreur.ci', 'phone' => '0701000021', 'city_id' => $city->id]);
    $client2 = User::factory()->create(['role' => 'client', 'email' => 'c2@golivreur.ci', 'phone' => '0701000022', 'city_id' => $city->id]);
    $driver  = User::factory()->create(['role' => 'driver', 'phone' => '0703000022', 'city_id' => $city->id]);

    $token1 = JWTAuth::fromUser($client1);
    $tokenDriver = JWTAuth::fromUser($driver);

    // Email déjà pris + téléphone non ivoirien -> 422
    $this->withHeader('Authorization', "Bearer {$token1}")
        ->putJson('/api/v1/client/profile', [
            'phone' => '1234',
            'email' => 'c2@golivreur.ci',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['phone', 'email']);

    // Tentative de modification d'un autre user via route paramétrique -> 403
    $this->withHeader('Authorization', "Bearer {$token1}")
        ->putJson("/api/v1/profile/{$client2->id}", ['name' => 'Hack'])
        ->assertForbidden();

    // Tentative avec user_id usurpé dans le payload -> 403
    $this->withHeader('Authorization', "Bearer {$token1}")
        ->putJson('/api/v1/client/profile', ['user_id' => $client2->id, 'name' => 'Hack'])
        ->assertForbidden();
});

it('client : un driver ne peut pas modifier le profil client (403)', function () {
    $city = City::factory()->create();
    $driver = User::factory()->create(['role' => 'driver', 'phone' => '0703000022', 'city_id' => $city->id]);
    $tokenDriver = JWTAuth::fromUser($driver);

    $this->withHeader('Authorization', "Bearer {$tokenDriver}")
        ->putJson('/api/v1/client/profile', ['name' => 'Test'])
        ->assertForbidden();
});

// ─── TESTS PROFIL DRIVER ──────────────────────────────────────────────────────

it('driver : peut lire, mettre à jour véhicule/permis/photo et rejette accès non autorisé (403)', function () {
    Storage::fake('public');

    $city = City::factory()->create();
    $driverUser = User::factory()->create(['role' => 'driver', 'phone' => '0703000010', 'city_id' => $city->id]);
    $courier = Courier::factory()->create([
        'user_id'       => $driverUser->id,
        'city_id'       => $city->id,
        'vehicle_type'  => 'moto',
        'vehicle_plate' => '1111 AB 01',
    ]);
    $otherClient = User::factory()->create(['role' => 'client', 'phone' => '0701000098', 'city_id' => $city->id]);

    $tokenDriver = JWTAuth::fromUser($driverUser);
    $tokenClient = JWTAuth::fromUser($otherClient);

    // Lecture
    $this->withHeader('Authorization', "Bearer {$tokenDriver}")
        ->getJson('/api/v1/driver/profile')
        ->assertOk()
        ->assertJsonPath('donnees.vehicle_plate', '1111 AB 01');

    // Mise à jour + photo
    $photo = UploadedFile::fake()->image('driver.webp', 350, 350);
    $this->withHeader('Authorization', "Bearer {$tokenDriver}")
        ->post('/api/v1/driver/profile', [
            '_method'               => 'PUT',
            'name'                  => 'Koffi Livreur Pro',
            'phone'                 => '0703000088',
            'vehicle_type'          => 'tricycle',
            'vehicle_plate'         => '9999 XY 01',
            'driver_license_number' => 'CI-PERM-2026',
            'photo'                 => $photo,
        ])
        ->assertOk()
        ->assertJsonPath('donnees.name', 'Koffi Livreur Pro')
        ->assertJsonPath('donnees.vehicle_type', 'tricycle')
        ->assertJsonPath('donnees.vehicle_plate', '9999 XY 01')
        ->assertJsonPath('donnees.driver_license_number', 'CI-PERM-2026');

    $courier->refresh();
    expect($courier->photo_path)->toStartWith('livreurs/');
    Storage::disk('public')->assertExists($courier->photo_path);

    // Validation échouée -> 422
    $this->withHeader('Authorization', "Bearer {$tokenDriver}")
        ->putJson('/api/v1/driver/profile', ['email' => 'pas-un-email'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['email']);
});

it('driver : un client ne peut pas accéder au profil driver (403)', function () {
    $city = City::factory()->create();
    $otherClient = User::factory()->create(['role' => 'client', 'phone' => '0701000098', 'city_id' => $city->id]);
    $tokenClient = JWTAuth::fromUser($otherClient);

    $this->withHeader('Authorization', "Bearer {$tokenClient}")
        ->putJson('/api/v1/driver/profile', ['name' => 'Intrusion'])
        ->assertForbidden();
});

// ─── TESTS PROFIL BUSINESS ────────────────────────────────────────────────────

it('business : peut lire, modifier boutique/catégorie/emoji/logo/horaires et bloque autre rôle (403)', function () {
    Storage::fake('public');

    $city = City::factory()->create();
    MerchantCategory::firstOrCreate(
        ['slug' => 'patisserie'],
        ['name' => 'Pâtisseries', 'emoji' => '🍰', 'is_active' => true]
    );

    $merchantUser = User::factory()->create(['role' => 'merchant', 'phone' => '0702000010', 'city_id' => $city->id]);
    $merchant = Merchant::create([
        'user_id'           => $merchantUser->id,
        'city_id'           => $city->id,
        'name'              => 'Ancien Maquis',
        'category'          => 'maquis',
        'emoji'             => '🍖',
        'address'           => 'Rue 1, Daloa',
        'lat'               => 6.88,
        'lng'               => -6.45,
        'prep_time_minutes' => 20,
        'is_active'         => true,
        'is_open'           => true,
    ]);
    $otherUser = User::factory()->create(['role' => 'driver', 'phone' => '0703000077', 'city_id' => $city->id]);

    $tokenBiz = JWTAuth::fromUser($merchantUser);
    $tokenDriver = JWTAuth::fromUser($otherUser);

    // Lecture
    $this->withHeader('Authorization', "Bearer {$tokenBiz}")
        ->getJson('/api/v1/business/profile')
        ->assertOk()
        ->assertJsonPath('donnees.business_name', 'Ancien Maquis');

    // Mise à jour + upload logo & photo vitrine
    $logo = UploadedFile::fake()->image('logo.png', 256, 256);
    $storePhoto = UploadedFile::fake()->image('vitrine.jpg', 640, 480);

    $this->withHeader('Authorization', "Bearer {$tokenBiz}")
        ->post('/api/v1/merchant/profile', [
            '_method'        => 'PUT',
            'name'           => 'Adjoua Gérante',
            'business_name'  => 'Palais du Gâteau',
            'category'       => 'patisserie',
            'emoji'          => '🧁',
            'address'        => 'Avenue Centrale, Daloa',
            'description'    => 'Gâteaux et viennoiseries maison',
            'open_hours'     => '07h00 - 21h00',
            'logo'           => $logo,
            'merchant_photo' => $storePhoto,
        ])
        ->assertOk()
        ->assertJsonPath('donnees.business_name', 'Palais du Gâteau')
        ->assertJsonPath('donnees.category', 'patisserie')
        ->assertJsonPath('donnees.emoji', '🧁')
        ->assertJsonPath('donnees.open_hours_text', '07h00 - 21h00');

    $merchant->refresh();
    expect($merchant->logo_path)->toStartWith('commerces/logos/');
    expect($merchant->photo_path)->toStartWith('commerces/');
    Storage::disk('public')->assertExists($merchant->logo_path);
    Storage::disk('public')->assertExists($merchant->photo_path);

    // Validation échouée -> 422
    $this->withHeader('Authorization', "Bearer {$tokenBiz}")
        ->putJson('/api/v1/business/profile', ['business_name' => 'A'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['business_name']);
});

it('business : un driver ne peut pas modifier le profil business (403)', function () {
    $city = City::factory()->create();
    $otherUser = User::factory()->create(['role' => 'driver', 'phone' => '0703000077', 'city_id' => $city->id]);
    $tokenDriver = JWTAuth::fromUser($otherUser);

    $this->withHeader('Authorization', "Bearer {$tokenDriver}")
        ->putJson('/api/v1/business/profile', ['business_name' => 'Hack'])
        ->assertForbidden();
});

// ─── TEST MOT DE PASSE ────────────────────────────────────────────────────────

it('profil : permet de changer son mot de passe après vérification de l\'ancien', function () {
    $user = User::factory()->create([
        'role'     => 'client',
        'phone'    => '0701000055',
        'password' => Hash::make('AncienPass2026'),
    ]);

    $token = JWTAuth::fromUser($user);

    // Mauvais mot de passe actuel -> 422
    $this->withHeader('Authorization', "Bearer {$token}")
        ->putJson('/api/v1/profile/password', [
            'current_password'      => 'MauvaisMotDePasse',
            'password'              => 'NouveauPass2026',
            'password_confirmation' => 'NouveauPass2026',
        ])
        ->assertStatus(422);

    // Bon mot de passe actuel -> 200
    $this->withHeader('Authorization', "Bearer {$token}")
        ->putJson('/api/v1/profile/password', [
            'current_password'      => 'AncienPass2026',
            'password'              => 'NouveauPass2026',
            'password_confirmation' => 'NouveauPass2026',
        ])
        ->assertOk();

    $user->refresh();
    expect(Hash::check('NouveauPass2026', $user->password))->toBeTrue();
});
