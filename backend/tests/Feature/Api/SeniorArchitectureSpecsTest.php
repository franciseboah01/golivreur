<?php

use App\Http\Middleware\EnsureAdminTwoFactorVerified;
use App\Models\ActivityLog;
use App\Models\City;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\MerchantCategory;
use App\Models\Order;
use App\Models\OtpCode;
use App\Models\Product;
use App\Models\User;
use App\Models\Wallet;
use App\Modules\GoShop\Services\AssignmentService;
use App\Services\CustomerSupportService;
use App\Services\NotificationService;
use App\Services\SettingsService;
use App\Services\SupportAuditService;
use App\Services\WalletService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

it('renvoie uniquement les bannières promotionnelles dans leur fenêtre de validité (auto-expiration)', function () {
    $city = City::factory()->create(['name' => 'Abidjan', 'is_active' => true]);

    $admin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);

    app(SettingsService::class)->set('promotional_banners', [
        [
            'id' => 'active-1',
            'title' => 'Promo Active',
            'subtitle' => 'En cours maintenant',
            'image_path' => 'banners/promo.jpg',
            'deep_link' => 'category:maquis',
            'starts_at' => now()->subHour()->toIso8601String(),
            'ends_at' => now()->addHours(4)->toIso8601String(),
            'is_active' => true,
            'sort_order' => 0,
        ],
        [
            'id' => 'expired-bf',
            'title' => 'Black Friday Expiré',
            'subtitle' => 'Terminé hier',
            'image_path' => null,
            'deep_link' => null,
            'starts_at' => now()->subDays(3)->toIso8601String(),
            'ends_at' => now()->subDay()->toIso8601String(),
            'is_active' => true,
            'sort_order' => 1,
        ],
        [
            'id' => 'future-1',
            'title' => 'Noël Futur',
            'subtitle' => 'Pas encore commencé',
            'image_path' => null,
            'deep_link' => null,
            'starts_at' => now()->addDays(2)->toIso8601String(),
            'ends_at' => now()->addDays(5)->toIso8601String(),
            'is_active' => true,
            'sort_order' => 2,
        ],
    ], $city->id, $admin->id);

    $res = $this->getJson("/api/v1/client/banners?city_id={$city->id}")
        ->assertOk()
        ->assertJsonCount(1, 'donnees')
        ->assertJsonPath('donnees.0.id', 'active-1')
        ->assertJsonPath('donnees.0.deep_link', 'category:maquis');

    expect($res->json('donnees.0.title'))->toBe('Promo Active');
});

it('bloque en 403 ROLE_MISSING un utilisateur sans le rôle requis et permet le cumul multi-rôles sur identité unique', function () {
    $city = City::factory()->create(['name' => 'Bouaké', 'is_active' => true]);
    MerchantCategory::firstOrCreate(['slug' => 'restaurant'], ['name' => 'Restaurant', 'emoji' => '🍽️', 'is_active' => true]);

    $user = User::factory()->create([
        'phone' => '0705556677',
        'password' => Hash::make('UniquePass123!'),
        'role' => 'client',
        'city_id' => $city->id,
        'is_active' => true,
    ]);

    // Tentative de connexion sur GL Driver sans le rôle driver -> 403 ROLE_MISSING
    $this->postJson('/api/v1/auth/login-password', [
        'phone' => '0705556677',
        'password' => 'UniquePass123!',
        'app_type' => 'driver',
    ])->assertStatus(403)
        ->assertJsonPath('code', 'ROLE_MISSING')
        ->assertJsonPath('required_role', 'driver')
        ->assertJsonPath('suggested_action', 'register_driver');

    // Même identité unique ajoute un profil commerçant (pré-inscription multi-rôle)
    $this->postJson('/api/v1/auth/register-merchant', [
        'name' => 'Bistro Multi-Rôle',
        'phone' => '0705556677',
        'password' => 'UniquePass123!',
        'city_id' => $city->id,
        'category' => 'restaurant',
        'address' => 'Quartier Air France',
    ])->assertCreated();

    $merchant = Merchant::where('user_id', $user->id)->firstOrFail();
    $merchant->update(['is_active' => true, 'activated_at' => now()]);

    expect($user->fresh()->effectiveRoles())->toContain('client', 'merchant');

    // Peut désormais se connecter sur GL Business ET GL Client avec le même compte
    $this->postJson('/api/v1/auth/login-password', [
        'phone' => '0705556677',
        'password' => 'UniquePass123!',
        'app_type' => 'business',
    ])->assertOk()
        ->assertJsonPath('donnees.user.id', $user->id);
});

it('filtre invisiblement les propres produits d\'un commerçant-client et bloque toute commande auto-achetée en 403', function () {
    $city = City::factory()->create([
        'name' => 'Yamoussoukro',
        'is_active' => true,
        'center_lat' => 6.8276,
        'center_lng' => -5.2893,
    ]);

    $dualUser = User::factory()->create([
        'role' => 'client',
        'city_id' => $city->id,
        'is_active' => true,
    ]);

    $ownMerchant = Merchant::factory()->create([
        'user_id' => $dualUser->id,
        'city_id' => $city->id,
        'name' => 'Ma Propre Boutique',
        'is_active' => true,
        'is_open' => true,
        'lat' => 6.8276,
        'lng' => -5.2893,
    ]);
    $ownProduct = Product::factory()->create([
        'merchant_id' => $ownMerchant->id,
        'name' => 'Mon Produit',
        'price' => 2500,
        'is_available' => true,
    ]);

    $otherOwner = User::factory()->create(['role' => 'merchant', 'city_id' => $city->id, 'is_active' => true]);
    Merchant::factory()->create([
        'user_id' => $otherOwner->id,
        'city_id' => $city->id,
        'name' => 'Autre Boutique',
        'is_active' => true,
        'is_open' => true,
        'lat' => 6.8276,
        'lng' => -5.2893,
    ]);

    $token = JWTAuth::fromUser($dualUser);

    // 1. Filtre invisible : ne voit que "Autre Boutique"
    $listRes = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/v1/client/merchants?city_id={$city->id}")
        ->assertOk();
    expect(collect($listRes->json('donnees'))->pluck('name')->all())->toBe(['Autre Boutique']);

    // 2. Accès direct au catalogue propre -> 403
    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/v1/client/merchants/{$ownMerchant->id}/catalog")
        ->assertStatus(403);

    // 3. Requête API forgée pour commander son propre produit -> 403
    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/client/orders', [
            'merchant_id' => $ownMerchant->id,
            'items' => [['product_id' => $ownProduct->id, 'quantity' => 1]],
            'delivery_address' => 'Quartier Habitat',
            'delivery_lat' => 6.8280,
            'delivery_lng' => -5.2890,
            'payment_method' => 'cash',
        ])
        ->assertStatus(403);
});

it('priorise le livreur assigné au commerce, interdit l\'auto-assignation commerçant-livreur et isole les wallets', function () {
    Queue::fake();

    $city = City::factory()->create(['name' => 'Korhogo', 'is_active' => true, 'center_lat' => 9.4580, 'center_lng' => -5.6296]);

    // Compte double rôle Commerçant + Livreur
    $merchantDriverUser = User::factory()->create(['role' => 'merchant', 'city_id' => $city->id, 'is_active' => true]);
    $merchant = Merchant::factory()->create([
        'user_id' => $merchantDriverUser->id,
        'city_id' => $city->id,
        'is_active' => true,
        'is_open' => true,
        'lat' => 9.4580,
        'lng' => -5.6296,
    ]);
    $selfCourier = Courier::factory()->create([
        'user_id' => $merchantDriverUser->id,
        'city_id' => $city->id,
        'is_active' => true,
        'is_online' => true,
        'current_lat' => 9.4580,
        'current_lng' => -5.6296,
        'location_updated_at' => now(),
    ]);

    // Livreur tiers prioritaire (un peu plus loin) et livreur tiers plus proche
    $otherCourierUser = User::factory()->create(['role' => 'driver', 'city_id' => $city->id, 'is_active' => true]);
    $priorityCourier = Courier::factory()->create([
        'user_id' => $otherCourierUser->id,
        'city_id' => $city->id,
        'is_active' => true,
        'is_online' => true,
        'current_lat' => 9.4700,
        'current_lng' => -5.6200,
        'location_updated_at' => now(),
    ]);

    $merchantToken = JWTAuth::fromUser($merchantDriverUser);

    // 1. Interdiction de s'auto-assigner son propre profil livreur -> 403
    $this->withHeader('Authorization', "Bearer {$merchantToken}")
        ->putJson('/api/v1/merchant/priority-courier', ['courier_id' => $selfCourier->id])
        ->assertStatus(403)
        ->assertJsonPath('code', 'SELF_ASSIGNMENT_FORBIDDEN');

    // 2. Assignation d'un livreur tiers prioritaire -> 200 OK
    $this->withHeader('Authorization', "Bearer {$merchantToken}")
        ->putJson('/api/v1/merchant/priority-courier', ['courier_id' => $priorityCourier->id])
        ->assertOk()
        ->assertJsonPath('donnees.priority_courier_id', $priorityCourier->id);

    // 3. Le dispatch choisit le livreur prioritaire (et jamais le commerçant-livreur)
    $client = User::factory()->create(['role' => 'client', 'city_id' => $city->id, 'is_active' => true]);
    $order = Order::factory()->create([
        'client_id' => $client->id,
        'merchant_id' => $merchant->id,
        'city_id' => $city->id,
        'courier_id' => null,
        'status' => Order::STATUS_ACCEPTED,
        'payment_method' => 'wave',
        'pickup_lat' => 9.4580,
        'pickup_lng' => -5.6296,
        'merchant_amount' => 4250,
        'courier_amount' => 800,
    ]);

    $offeredCourierId = null;
    $mockNotif = Mockery::mock(NotificationService::class);
    $mockNotif->shouldReceive('proposerCourseGoShop')
        ->once()
        ->andReturnUsing(function (Courier $c) use (&$offeredCourierId): void {
            $offeredCourierId = $c->id;
        });

    $assignmentService = new AssignmentService($mockNotif);
    $assignmentService->attribuerLivreurGoShop($order);

    expect($offeredCourierId)->toBe($priorityCourier->id);

    // 4. Si le commerçant-livreur tente d'accepter manuellement la commande de sa propre boutique -> 403
    $this->withHeader('Authorization', "Bearer {$merchantToken}")
        ->postJson("/api/v1/driver/orders/{$order->id}/accept")
        ->assertStatus(403)
        ->assertJsonPath('code', 'SELF_ASSIGNMENT_FORBIDDEN');

    // 5. Isolation stricte des wallets (merchant vs courier) pour le même utilisateur multi-rôle
    $walletService = app(WalletService::class);
    $order->update(['courier_id' => $priorityCourier->id]);
    $walletService->crediterMerchant($order);

    $otherMerchantOrder = Order::factory()->create([
        'client_id' => $client->id,
        'merchant_id' => Merchant::factory()->create(['city_id' => $city->id])->id,
        'city_id' => $city->id,
        'courier_id' => $selfCourier->id,
        'status' => Order::STATUS_DELIVERED,
        'payment_method' => 'wave',
        'merchant_amount' => 3000,
        'courier_amount' => 950,
    ]);
    $walletService->crediterLivreur($otherMerchantOrder);

    $merchantWallet = Wallet::where('owner_type', 'merchant')->where('owner_id', $merchant->id)->firstOrFail();
    $courierWallet = Wallet::where('owner_type', 'courier')->where('owner_id', $selfCourier->id)->firstOrFail();

    expect((int) $merchantWallet->balance)->toBe(4250);
    expect((int) $courierWallet->balance)->toBe(950);
    expect($merchantWallet->id)->not->toBe($courierWallet->id);
});

it('applique la rotation stricte du refresh token avec détection de réutilisation (TTL 20 min)', function () {
    expect((int) config('jwt.ttl'))->toBeGreaterThanOrEqual(15)->toBeLessThanOrEqual(30);

    $user = User::factory()->create([
        'phone' => '0706667788',
        'password' => Hash::make('TokenSec123!'),
        'role' => 'client',
        'is_active' => true,
    ]);

    $login = $this->postJson('/api/v1/auth/login-password', [
        'phone' => '0706667788',
        'password' => 'TokenSec123!',
    ])->assertOk();

    $refresh1 = $login->json('donnees.refresh_token');

    // Rotation normale : refresh1 -> refresh2
    $rot1 = $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $refresh1])->assertOk();
    $refresh2 = $rot1->json('donnees.refresh_token');
    expect($refresh2)->not->toBe($refresh1);

    // Rejeu frauduleux de refresh1 -> 401 + révocation de toute la famille (refresh2 invalidé aussi !)
    $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $refresh1])->assertStatus(401);
    $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $refresh2])->assertStatus(401);
});

it('sécurise la bascule SSO inter-apps par OAuth2 Authorization Code + PKCE S256 à usage unique', function () {
    $city = City::factory()->create(['is_active' => true]);
    $user = User::factory()->create(['role' => 'client', 'city_id' => $city->id, 'is_active' => true]);
    Merchant::factory()->create(['user_id' => $user->id, 'city_id' => $city->id, 'is_active' => true]);

    $token = JWTAuth::fromUser($user);
    $verifier = Str::random(64);
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

    // 1. Refus si rôle absent (pas livreur)
    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/auth/sso/authorize', [
            'target_app' => 'driver',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ])->assertStatus(403)->assertJsonPath('code', 'ROLE_MISSING');

    // 2. Autorisation valide vers GL Business
    $authRes = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/auth/sso/authorize', [
            'target_app' => 'business',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ])->assertOk();

    $code = $authRes->json('donnees.code');
    expect($authRes->json('donnees.deep_link_uri'))->toStartWith('golivreur-business://sso?code=');

    // 3. Mauvais code_verifier -> 401 (et consomme le code)
    $this->postJson('/api/v1/auth/sso/exchange', [
        'code' => $code,
        'code_verifier' => Str::random(64),
        'target_app' => 'business',
    ])->assertStatus(401);

    // 4. Nouveau code + bon verifier -> 200 OK avec JWT + refresh token, puis rejeu bloqué
    $authRes2 = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/auth/sso/authorize', [
            'target_app' => 'business',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ])->assertOk();
    $code2 = $authRes2->json('donnees.code');

    $this->postJson('/api/v1/auth/sso/exchange', [
        'code' => $code2,
        'code_verifier' => $verifier,
        'target_app' => 'business',
    ])->assertOk()
        ->assertJsonPath('donnees.user.id', $user->id);

    // Rejeu strictement bloqué (usage unique)
    $this->postJson('/api/v1/auth/sso/exchange', [
        'code' => $code2,
        'code_verifier' => $verifier,
        'target_app' => 'business',
    ])->assertStatus(401);
});

it('protège le panneau Admin par IP Whitelisting et 2FA obligatoire', function () {
    $admin = User::factory()->create([
        'role' => 'super_admin',
        'email' => 'admin-sec@golivreur.ci',
        'is_active' => true,
        'two_factor_enabled' => true,
    ]);

    // 1. IP non autorisée -> 403 Forbidden
    config([
        'admin.ip_whitelist_enabled' => true,
        'admin.allowed_ips' => ['10.20.30.0/24'],
        'admin.enforce_2fa' => true,
    ]);

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.55'])
        ->get('/admin')
        ->assertStatus(403);

    // 2. IP autorisée mais 2FA non validée en session -> redirection vers /admin/two-factor
    $this->actingAs($admin)
        ->withServerVariables(['REMOTE_ADDR' => '10.20.30.42'])
        ->get('/admin')
        ->assertRedirect('/admin/two-factor');

    // 3. IP autorisée + session 2FA validée -> 200 OK
    $this->actingAs($admin)
        ->withSession([
            EnsureAdminTwoFactorVerified::SESSION_VERIFIED_AT_KEY => time(),
            EnsureAdminTwoFactorVerified::SESSION_USER_ID_KEY => $admin->id,
        ])
        ->withServerVariables(['REMOTE_ADDR' => '10.20.30.42'])
        ->get('/admin')
        ->assertOk();
});

it('impose la vérification hors bande OTP avant reset mot de passe, force le changement à la connexion et rend les logs d\'audit immuables', function () {
    $advisor = User::factory()->create(['role' => 'national_support', 'is_active' => true]);
    $target = User::factory()->create([
        'phone' => '0708889900',
        'password' => Hash::make('OldPass123!'),
        'role' => 'client',
        'is_active' => true,
    ]);

    $supportService = app(CustomerSupportService::class);

    // 1. Screen Pop instantané
    $fiche = $supportService->rechercherFicheAppelant('0708889900', $advisor, '10.0.0.5');
    expect($fiche)->not->toBeNull();
    expect($fiche['user']->id)->toBe($target->id);
    expect($fiche['identity_verified'])->toBeFalse();

    // 2. Reset sans OTP vérifié -> bloqué (AuthorizationException)
    expect(fn () => $supportService->reinitialiserMotDePasseSecurise($target, $advisor, '10.0.0.5'))
        ->toThrow(AuthorizationException::class);

    // 3. Vérification hors bande OTP -> déverrouille le reset aveugle
    OtpCode::create([
        'phone' => $target->phone,
        'code_hash' => Hash::make('654321'),
        'purpose' => 'support_identity_verify',
        'expires_at' => now()->addMinutes(5),
    ]);

    expect($supportService->verifierIdentiteHorsBande($target, '654321', $advisor, '10.0.0.5'))->toBeTrue();

    $resetResult = $supportService->reinitialiserMotDePasseSecurise($target, $advisor, '10.0.0.5');
    // Le mot de passe temporaire n'est JAMAIS retourné au conseiller
    expect(array_keys($resetResult))->not->toContain('password', 'temporary_password');
    expect($target->fresh()->mustChangePassword())->toBeTrue();

    // 4. Audit logs strictement immuables + redaction des secrets
    $sanitized = app(SupportAuditService::class)->sanitizeProperties([
        'password' => 'SecretClear123',
        'otp_code' => '654321',
        'refresh_token' => 'tok_xyz',
        'action_note' => 'ok',
    ]);
    expect($sanitized['password'])->toBe('[REDACTED]');
    expect($sanitized['otp_code'])->toBe('[REDACTED]');
    expect($sanitized['refresh_token'])->toBe('[REDACTED]');
    expect($sanitized['action_note'])->toBe('ok');

    $log = ActivityLog::latest('id')->firstOrFail();
    expect(fn () => $log->update(['action' => 'tampered']))->toThrow(RuntimeException::class);
    expect(fn () => $log->delete())->toThrow(RuntimeException::class);
});
