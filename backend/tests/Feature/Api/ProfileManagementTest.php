<?php

use App\Models\City;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\MerchantCategory;
use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tymon\JWTAuth\Facades\JWTAuth;

// ─── TESTS PROFIL CLIENT ──────────────────────────────────────────────────────

it('client : autorise nom/avatar, exige un OTP pour changer téléphone ou email, et permet photo/adresse/préférences', function () {
    Storage::fake('public');
    Mail::fake();

    $city = City::factory()->create(['name' => 'Daloa']);
    $client = User::factory()->create([
        'name' => 'Client Initial',
        'phone' => '0701000010',
        'email' => null,
        'role' => 'client',
        'city_id' => $city->id,
    ]);

    $token = JWTAuth::fromUser($client);

    // 1. Lecture profil
    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/client/profile')
        ->assertOk()
        ->assertJsonPath('donnees.name', 'Client Initial')
        ->assertJsonPath('donnees.phone', '0701000010');

    // 2. Modification libre du nom (Lot 9A Point 1 : nom et avatar libres pour le client)
    $this->withHeader('Authorization', "Bearer {$token}")
        ->putJson('/api/v1/client/profile', ['name' => 'Client Modifié'])
        ->assertOk()
        ->assertJsonPath('donnees.name', 'Client Modifié');

    // 3. Tentative de modification directe du téléphone sans OTP -> 422 PHONE_OTP_REQUIRED
    $this->withHeader('Authorization', "Bearer {$token}")
        ->putJson('/api/v1/client/profile', ['phone' => '+225 07 01 00 00 99'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'PHONE_OTP_REQUIRED');

    // 4. Tentative d'ajout direct d'email sans code de confirmation -> 422 EMAIL_OTP_REQUIRED
    $this->withHeader('Authorization', "Bearer {$token}")
        ->putJson('/api/v1/client/profile', ['email' => 'client.modifie@golivreur.ci'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'EMAIL_OTP_REQUIRED');

    // 5. Changement de numéro sécurisé via OTP envoyé au NOUVEAU numéro
    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/client/profile/phone/request-otp', [
            'phone' => '+225 07 01 00 00 99',
        ])
        ->assertOk()
        ->assertJsonPath('succes', true);

    $phoneOtp = OtpCode::where('phone', '0701000099')
        ->where('purpose', "phone_chg_{$client->id}")
        ->where('is_used', false)
        ->latest('id')
        ->firstOrFail();

    // Mauvais code -> 422
    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/client/profile/phone/confirm', [
            'phone' => '0701000099',
            'code' => '000000',
        ])
        ->assertStatus(422);

    // Code valide -> téléphone mis à jour
    $phoneOtp->update(['code_hash' => Hash::make('482910'), 'attempts' => 0]);
    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/client/profile/phone/confirm', [
            'phone' => '+225 07 01 00 00 99',
            'code' => '482910',
        ])
        ->assertOk()
        ->assertJsonPath('donnees.phone', '0701000099');

    // 6. Ajout d'email sécurisé via code de confirmation envoyé par email
    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/client/profile/email/request-otp', [
            'email' => 'client.modifie@golivreur.ci',
        ])
        ->assertOk()
        ->assertJsonPath('succes', true);

    $emailOtp = OtpCode::where('purpose', "email_chg_{$client->id}")
        ->where('is_used', false)
        ->latest('id')
        ->firstOrFail();
    $emailOtp->update(['code_hash' => Hash::make('739104'), 'attempts' => 0]);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/client/profile/email/confirm', [
            'email' => 'client.modifie@golivreur.ci',
            'code' => '739104',
        ])
        ->assertOk()
        ->assertJsonPath('donnees.email', 'client.modifie@golivreur.ci');

    // 7. Mise à jour adresse + préférences + upload photo (sans changer nom/téléphone/email)
    $photo1 = UploadedFile::fake()->image('avatar1.jpg', 300, 300);

    $res1 = $this->withHeader('Authorization', "Bearer {$token}")
        ->post('/api/v1/client/profile', [
            '_method' => 'PUT',
            'default_address' => 'Quartier Lobia, Rue 4',
            'language' => 'en',
            'notifications_enabled' => '0',
            'photo' => $photo1,
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

    // 8. Remplacement photo -> l'ancien fichier doit être supprimé
    $photo2 = UploadedFile::fake()->image('avatar2.png', 300, 300);
    $this->withHeader('Authorization', "Bearer {$token}")
        ->post('/api/v1/client/profile', [
            '_method' => 'PUT',
            'photo' => $photo2,
        ])
        ->assertOk();

    $client->refresh();
    expect($client->photo_path)->not->toBe($oldPath);
    Storage::disk('public')->assertMissing($oldPath);
    Storage::disk('public')->assertExists($client->photo_path);

    // 9. Cohérence Admin -> Mobile : modification côté DB/Admin reflétée sur GET
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
    $driver = User::factory()->create(['role' => 'driver', 'phone' => '0703000022', 'city_id' => $city->id]);

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

it('driver : lecture seule sur identite/vehicule/permis/photo (403 field_admin_only) et rejette accès non autorisé', function () {
    Storage::fake('public');

    $city = City::factory()->create();
    $driverUser = User::factory()->create(['role' => 'driver', 'phone' => '0703000010', 'city_id' => $city->id]);
    $courier = Courier::factory()->create([
        'user_id' => $driverUser->id,
        'city_id' => $city->id,
        'vehicle_type' => 'moto',
        'vehicle_plate' => '1111 AB 01',
    ]);

    $tokenDriver = JWTAuth::fromUser($driverUser);

    // Lecture
    $this->withHeader('Authorization', "Bearer {$tokenDriver}")
        ->getJson('/api/v1/driver/profile')
        ->assertOk()
        ->assertJsonPath('donnees.vehicle_plate', '1111 AB 01');

    // Champs réservés admin -> 403 field_admin_only (Lot 9A Point 1)
    $photo = UploadedFile::fake()->image('driver.webp', 350, 350);
    $this->withHeader('Authorization', "Bearer {$tokenDriver}")
        ->post('/api/v1/driver/profile', [
            '_method' => 'PUT',
            'name' => 'Koffi Livreur Pro',
            'vehicle_type' => 'tricycle',
            'vehicle_plate' => '9999 XY 01',
            'driver_license_number' => 'CI-PERM-2026',
            'photo' => $photo,
        ])
        ->assertStatus(403)
        ->assertJsonPath('code', 'field_admin_only');

    // Préférences de langue autorisées
    $this->withHeader('Authorization', "Bearer {$tokenDriver}")
        ->putJson('/api/v1/driver/profile', ['language' => 'en'])
        ->assertOk();

    // Validation échouée sur langue invalide -> 422
    $this->withHeader('Authorization', "Bearer {$tokenDriver}")
        ->putJson('/api/v1/driver/profile', ['language' => 'zz'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['language']);
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

it('business : peut lire, modifier description/emoji/logo/horaires et rejette champs admin (403 field_admin_only)', function () {
    Storage::fake('public');

    $city = City::factory()->create();
    MerchantCategory::firstOrCreate(
        ['slug' => 'patisserie'],
        ['name' => 'Pâtisseries', 'emoji' => '🍰', 'is_active' => true]
    );

    $merchantUser = User::factory()->create(['role' => 'merchant', 'phone' => '0702000010', 'city_id' => $city->id]);
    $merchant = Merchant::create([
        'user_id' => $merchantUser->id,
        'city_id' => $city->id,
        'name' => 'Ancien Maquis',
        'category' => 'maquis',
        'emoji' => '🍖',
        'address' => 'Rue 1, Daloa',
        'lat' => 6.88,
        'lng' => -6.45,
        'prep_time_minutes' => 20,
        'is_active' => true,
        'is_open' => true,
    ]);

    $tokenBiz = JWTAuth::fromUser($merchantUser);

    // Lecture
    $this->withHeader('Authorization', "Bearer {$tokenBiz}")
        ->getJson('/api/v1/business/profile')
        ->assertOk()
        ->assertJsonPath('donnees.business_name', 'Ancien Maquis');

    // Mise à jour champs libres + logo & photo vitrine
    $logo = UploadedFile::fake()->image('logo.png', 256, 256);
    $storePhoto = UploadedFile::fake()->image('vitrine.jpg', 640, 480);

    $this->withHeader('Authorization', "Bearer {$tokenBiz}")
        ->post('/api/v1/merchant/profile', [
            '_method' => 'PUT',
            'emoji' => '🧁',
            'description' => 'Gâteaux et viennoiseries maison',
            'open_hours' => '07h00 - 21h00',
            'logo' => $logo,
            'merchant_photo' => $storePhoto,
        ])
        ->assertOk()
        ->assertJsonPath('donnees.business_name', 'Ancien Maquis')
        ->assertJsonPath('donnees.emoji', '🧁')
        ->assertJsonPath('donnees.open_hours_text', '07h00 - 21h00');

    $merchant->refresh();
    expect($merchant->logo_path)->toStartWith('commerces/logos/');
    expect($merchant->photo_path)->toStartWith('commerces/');
    Storage::disk('public')->assertExists($merchant->logo_path);
    Storage::disk('public')->assertExists($merchant->photo_path);

    // Champs réservés admin -> 403 field_admin_only (Lot 9A Point 1)
    $this->withHeader('Authorization', "Bearer {$tokenBiz}")
        ->putJson('/api/v1/business/profile', ['business_name' => 'Palais du Gâteau'])
        ->assertStatus(403)
        ->assertJsonPath('code', 'field_admin_only');
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
        'role' => 'client',
        'phone' => '0701000055',
        'password' => Hash::make('AncienPass2026'),
    ]);

    $token = JWTAuth::fromUser($user);

    // Mauvais mot de passe actuel -> 422
    $this->withHeader('Authorization', "Bearer {$token}")
        ->putJson('/api/v1/profile/password', [
            'current_password' => 'MauvaisMotDePasse',
            'password' => 'NouveauPass2026',
            'password_confirmation' => 'NouveauPass2026',
        ])
        ->assertStatus(422);

    // Bon mot de passe actuel -> 200
    $this->withHeader('Authorization', "Bearer {$token}")
        ->putJson('/api/v1/profile/password', [
            'current_password' => 'AncienPass2026',
            'password' => 'NouveauPass2026',
            'password_confirmation' => 'NouveauPass2026',
        ])
        ->assertOk();

    $user->refresh();
    expect(Hash::check('NouveauPass2026', $user->password))->toBeTrue();
});
