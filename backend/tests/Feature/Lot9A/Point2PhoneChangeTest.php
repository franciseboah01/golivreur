<?php

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Models\ActivityLog;
use App\Models\City;
use App\Models\Courier;
use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tymon\JWTAuth\Facades\JWTAuth;

it('Point 2 : POST /me/phone/request et /me/phone/confirm fonctionnent pour client/livreur/commercant, refusent doublon (y compris soft-deleted) et revoquent autres sessions', function () {
    $city = City::factory()->create(['name' => 'Daloa']);

    // Compte soft-deleted occupant un numéro
    $deletedUser = User::factory()->create(['phone' => '0709998888', 'role' => 'client', 'city_id' => $city->id]);
    $deletedUser->delete();

    // Driver avec 2 sessions/refresh
    $driver = User::factory()->create([
        'phone' => '0703112233',
        'password' => Hash::make('Secret2026'),
        'role' => 'driver',
        'city_id' => $city->id,
        'is_active' => true,
    ]);
    Courier::factory()->create(['user_id' => $driver->id, 'city_id' => $city->id]);

    $oldToken = JWTAuth::fromUser($driver);
    $oldJti = (string) JWTAuth::setToken($oldToken)->getPayload()->get('jti');
    $driver->update(['session_token' => $oldJti]);
    $oldRefresh = AuthController::issueRotatingRefreshToken($driver);

    // 1. Refus si identique
    $this->withHeader('Authorization', "Bearer {$oldToken}")
        ->postJson('/api/v1/me/phone/request', ['new_phone' => '0703112233'])
        ->assertStatus(422);

    // 2. Refus si occupé par un compte soft-deleted
    $this->withHeader('Authorization', "Bearer {$oldToken}")
        ->postJson('/api/v1/me/phone/request', ['new_phone' => '0709998888'])
        ->assertStatus(422);

    // 3. Demande OTP sur un nouveau numéro libre (aucun code dans les logs avec passerelle Yellika)
    config([
        'sms.driver' => 'yellika',
        'sms.yellika.base_url' => 'https://panel.yellikasms.com/api/v3/sms/send',
        'sms.yellika.api_token' => 'test-token',
        'sms.yellika.sender_id' => 'GoLivreur',
        'sms.yellika.type' => 'plain',
    ]);
    Http::fake([
        'https://panel.yellikasms.com/api/v3/sms/send' => Http::response(['status' => 'success', 'uid' => 'msg-1'], 200),
    ]);

    $logs = [];
    Log::listen(function ($entry) use (&$logs): void {
        $logs[] = $entry->message.' '.json_encode($entry->context);
    });

    $this->withHeader('Authorization', "Bearer {$oldToken}")
        ->postJson('/api/v1/me/phone/request', ['new_phone' => '+225 07 03 44 55 66'])
        ->assertOk()
        ->assertJsonPath('succes', true);

    $otp = OtpCode::where('phone', '0703445566')
        ->where('purpose', "phone_chg_{$driver->id}")
        ->where('is_used', false)
        ->latest('id')
        ->firstOrFail();

    $plainCode = $otp->admin_otp_code;
    expect($plainCode)->toMatch('/^\d{6}$/');
    expect(implode("\n", $logs))->not->toContain((string) $plainCode);

    // 4. Confirmation OTP -> nouveau jeton, ancien JWT et ancien refresh révoqués, activity_logs
    $confirmRes = $this->withHeader('Authorization', "Bearer {$oldToken}")
        ->postJson('/api/v1/me/phone/confirm', ['code' => $plainCode])
        ->assertOk()
        ->assertJsonPath('succes', true)
        ->assertJsonPath('donnees.user.phone', '0703445566');

    $newToken = (string) $confirmRes->json('donnees.access_token');
    $newRefresh = (string) $confirmRes->json('donnees.refresh_token');
    expect($newToken)->not->toBeEmpty()
        ->and($newRefresh)->not->toBeEmpty()
        ->and($newRefresh)->not->toBe($oldRefresh);

    expect($driver->fresh()->phone)->toBe('0703445566');
    expect(ActivityLog::where('action', 'user_phone_changed')->where('entity_id', $driver->id)->exists())->toBeTrue();

    // Ancien refresh token révoqué -> 401
    $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $oldRefresh])
        ->assertStatus(401);

    // Ancien JWT révoqué -> 401
    Auth::forgetGuards();
    $this->app->forgetScopedInstances();
    JWTAuth::unsetToken();
    $this->withHeader('Authorization', "Bearer {$oldToken}")
        ->getJson('/api/v1/profile')
        ->assertStatus(401);

    // Nouveau JWT fonctionne -> 200
    Auth::forgetGuards();
    $this->app->forgetScopedInstances();
    JWTAuth::unsetToken();
    $this->withHeader('Authorization', "Bearer {$newToken}")
        ->getJson('/api/v1/profile')
        ->assertOk();
});

it('Point 2 Admin Filament : changer le numero revoque sessions, refresh tokens, device_token, trace activity_logs, bloque ancien numero (401) et autorise nouveau', function () {
    $city = City::factory()->create(['name' => 'Daloa']);
    $admin = User::factory()->create(['role' => 'super_admin', 'city_id' => null]);

    $client = User::factory()->create([
        'phone' => '0701556677',
        'password' => Hash::make('ClientPass2026'),
        'role' => 'client',
        'city_id' => $city->id,
        'is_active' => true,
        'device_token' => 'fcm-device-secret-token',
    ]);

    $oldJwt = JWTAuth::fromUser($client);
    $oldRefresh = AuthController::issueRotatingRefreshToken($client);

    // Simule l'édition du téléphone par un admin (via Eloquent/Filament)
    $this->actingAs($admin);
    $client->update(['phone' => '+225 07 01 88 99 00']);

    $client->refresh();
    expect($client->phone)->toBe('0701889900')
        ->and($client->device_token)->toBeNull();

    expect(ActivityLog::where('action', 'user_phone_changed_by_admin')->where('entity_id', $client->id)->exists())->toBeTrue();

    // Ancien refresh token révoqué -> 401
    $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $oldRefresh])
        ->assertStatus(401);

    // Ancien JWT client révoqué -> 401
    Auth::forgetGuards();
    JWTAuth::unsetToken();
    $this->withHeader('Authorization', "Bearer {$oldJwt}")
        ->getJson('/api/v1/client/profile')
        ->assertStatus(401);

    // Ancien numéro ne peut plus se connecter -> 401 générique
    $this->postJson('/api/v1/auth/login-password', [
        'phone' => '0701556677',
        'password' => 'ClientPass2026',
    ])->assertStatus(401);

    // Nouveau numéro peut se connecter -> 200
    $this->postJson('/api/v1/auth/login-password', [
        'phone' => '0701889900',
        'password' => 'ClientPass2026',
    ])->assertOk()
        ->assertJsonPath('donnees.user.phone', '0701889900');
});
