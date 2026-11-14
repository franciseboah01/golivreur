<?php

use App\Filament\Resources\ParcelResource\Pages\ListParcels;
use App\Jobs\AlerterAdminSmsEchec;
use App\Models\ActivityLog;
use App\Models\City;
use App\Models\Courier;
use App\Models\Parcel;
use App\Models\ParcelPhoto;
use App\Models\RoleHabilitation;
use App\Models\User;
use App\Services\SettingsService;
use App\Services\SmsService;
use Database\Seeders\RoleHabilitationSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tymon\JWTAuth\Facades\JWTAuth;

it('P7 Point 4: drapeau sms_failed + alerte admin sans code dans les logs lors echec SMS destinataire', function () {
    Queue::fake();

    $logged = [];
    Log::listen(function ($entry) use (&$logged) {
        $logged[] = $entry->message.' '.json_encode($entry->context);
    });

    $city = City::factory()->create(['center_lat' => 6.8770, 'center_lng' => -6.4502]);
    app(SettingsService::class)->set('paiements', ['modes_actifs' => ['cash']], $city->id);

    $sender = User::factory()->create(['role' => 'client', 'city_id' => $city->id, 'name' => 'Kouame Marc']);

    $capturedRecipientCode = null;
    $smsMock = Mockery::mock(SmsService::class)->makePartial();
    $smsMock->shouldReceive('envoyerCodeDestinataire')
        ->once()
        ->withArgs(function (string $phone, string $code, string $expediteurNom) use (&$capturedRecipientCode) {
            $capturedRecipientCode = $code;

            return $phone === '0701020304' && $expediteurNom === 'Kouame Marc';
        })
        ->andReturn(false);
    $this->app->instance(SmsService::class, $smsMock);

    $res = $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($sender))
        ->postJson('/api/v1/client/parcels', [
            'recipient_name' => 'Awa Kone',
            'recipient_phone' => '0701020304',
            'delivery_address' => 'Quartier Soleil',
            'delivery_lat' => 6.8820,
            'delivery_lng' => -6.4520,
            'pickup_address' => 'Quartier Commerce',
            'pickup_lat' => 6.8770,
            'pickup_lng' => -6.4502,
            'size' => 'petit',
            'weight_kg' => 0.8,
            'payment_method' => 'cash',
        ])
        ->assertCreated();

    $parcel = Parcel::findOrFail($res->json('donnees.id'));

    expect($parcel->sms_failed)->toBeTrue()
        ->and($parcel->sms_failed_at)->not->toBeNull();

    Queue::assertPushed(AlerterAdminSmsEchec::class, fn ($job) => $job->parcel->id === $parcel->id);

    $log = ActivityLog::where('action', 'parcel_recipient_sms_failed')
        ->where('entity_id', $parcel->id)
        ->first();
    expect($log)->not->toBeNull()
        ->and($log->severity)->toBe('critical');

    if ($capturedRecipientCode !== null) {
        expect(json_encode($log->properties))->not->toContain($capturedRecipientCode)
            ->and(implode("\n", $logged))->not->toContain($capturedRecipientCode);
    }
});

it('P7 Point 4: renvoi admin POST /admin/parcels/{id}/resend-recipient-code isole par ville et reinitialise sms_failed', function () {
    Queue::fake();
    $this->seed(RoleHabilitationSeeder::class);

    $logged = [];
    Log::listen(function ($entry) use (&$logged) {
        $logged[] = $entry->message.' '.json_encode($entry->context);
    });

    $cityDaloa = City::factory()->create(['name' => 'Daloa']);
    $cityBouake = City::factory()->create(['name' => 'Bouaké']);

    $sender = User::factory()->create(['role' => 'client', 'city_id' => $cityDaloa->id, 'name' => 'Konan Yao']);
    $driverUser = User::factory()->create(['role' => 'driver', 'city_id' => $cityDaloa->id]);
    $courier = Courier::factory()->create(['user_id' => $driverUser->id, 'city_id' => $cityDaloa->id]);

    $supportDaloa = User::factory()->create([
        'role' => 'city_support',
        'role_habilitation_id' => RoleHabilitation::where('slug', 'city_support')->value('id'),
        'city_id' => $cityDaloa->id,
    ]);
    $agentDaloa = User::factory()->create([
        'role' => 'city_agent',
        'role_habilitation_id' => RoleHabilitation::where('slug', 'city_agent')->value('id'),
        'city_id' => $cityDaloa->id,
    ]);
    $managerBouake = User::factory()->create([
        'role' => 'city_manager',
        'role_habilitation_id' => RoleHabilitation::where('slug', 'city_manager')->value('id'),
        'city_id' => $cityBouake->id,
    ]);
    $superAdmin = User::factory()->create([
        'role' => 'super_admin',
        'role_habilitation_id' => RoleHabilitation::where('slug', 'super_admin')->value('id'),
        'city_id' => null,
    ]);

    $parcel = Parcel::factory()->create([
        'sender_id' => $sender->id,
        'courier_id' => $courier->id,
        'city_id' => $cityDaloa->id,
        'status' => Parcel::STATUS_AT_RECIPIENT,
        'payment_method' => 'mobile_money',
        'recipient_phone' => '0504050607',
        'recipient_code' => Hash::make('9999'),
        'sms_failed' => true,
        'sms_failed_at' => now()->subMinutes(5),
    ]);
    ParcelPhoto::create(['parcel_id' => $parcel->id, 'type' => 'delivery', 'photo_url' => 'https://cdn.example/d.jpg']);

    // Roles non autorises / hors ville
    Auth::forgetGuards();
    app()->forgetScopedInstances();
    JWTAuth::unsetToken();
    $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($sender))
        ->postJson("/api/v1/admin/parcels/{$parcel->id}/resend-recipient-code")
        ->assertStatus(403);

    Auth::forgetGuards();
    app()->forgetScopedInstances();
    JWTAuth::unsetToken();
    $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($agentDaloa))
        ->postJson("/api/v1/admin/parcels/{$parcel->id}/resend-recipient-code")
        ->assertStatus(403);

    Auth::forgetGuards();
    app()->forgetScopedInstances();
    JWTAuth::unsetToken();
    $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($managerBouake))
        ->postJson("/api/v1/admin/parcels/{$parcel->id}/resend-recipient-code")
        ->assertStatus(404);

    // 1er renvoi en echec -> reste sms_failed=true, renvoie 502 + alerte admin
    $capturedFailedCode = null;
    $smsFailMock = Mockery::mock(SmsService::class)->makePartial();
    $smsFailMock->shouldReceive('envoyerCodeDestinataire')
        ->once()
        ->withArgs(function (string $phone, string $code) use (&$capturedFailedCode) {
            $capturedFailedCode = $code;

            return $phone === '0504050607';
        })
        ->andReturn(false);
    $this->app->instance(SmsService::class, $smsFailMock);

    Auth::forgetGuards();
    app()->forgetScopedInstances();
    JWTAuth::unsetToken();
    $failRes = $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($supportDaloa))
        ->postJson("/api/v1/admin/parcels/{$parcel->id}/resend-recipient-code")
        ->assertStatus(502)
        ->assertJsonPath('succes', false);

    expect($failRes->getContent())->not->toContain((string) $capturedFailedCode)
        ->and($parcel->fresh()->sms_failed)->toBeTrue();

    // 2e renvoi reussi -> sms_failed=false, nouveau hash utilisable, aucun code dans logs/JSON
    $capturedOkCode = null;
    $smsOkMock = Mockery::mock(SmsService::class)->makePartial();
    $smsOkMock->shouldReceive('envoyerCodeDestinataire')
        ->twice()
        ->withArgs(function (string $phone, string $code) use (&$capturedOkCode) {
            $capturedOkCode = $code;

            return $phone === '0504050607';
        })
        ->andReturn(true);
    $smsOkMock->shouldReceive('envoyerNotificationColisLivre')->once()->andReturn(true);
    $this->app->instance(SmsService::class, $smsOkMock);

    $okRes = $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($supportDaloa))
        ->postJson("/api/v1/admin/parcels/{$parcel->id}/resend-recipient-code")
        ->assertOk()
        ->assertJsonPath('succes', true)
        ->assertJsonPath('donnees.sms_failed', false);

    $parcel->refresh();
    expect($parcel->sms_failed)->toBeFalse()
        ->and($parcel->sms_failed_at)->toBeNull()
        ->and(Hash::check((string) $capturedOkCode, $parcel->recipient_code))->toBeTrue()
        ->and($okRes->getContent())->not->toContain((string) $capturedOkCode);

    // Action Filament egalement fonctionnelle et tracee
    $parcel->update(['sms_failed' => true, 'sms_failed_at' => now()]);
    $this->actingAs($superAdmin);
    Livewire::test(ListParcels::class)
        ->callTableAction('resendRecipientCode', $parcel)
        ->assertHasNoTableActionErrors();
    expect($parcel->fresh()->sms_failed)->toBeFalse();

    // Livraison fonctionne avec le code renvoye
    Auth::forgetGuards();
    app()->forgetScopedInstances();
    JWTAuth::unsetToken();
    $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($driverUser))
        ->postJson("/api/v1/parcels/{$parcel->id}/verify-recipient-code", ['code' => $capturedOkCode])
        ->assertOk();

    $resentLog = ActivityLog::where('action', 'parcel_recipient_sms_resent')
        ->where('entity_id', $parcel->id)
        ->latest('id')
        ->first();
    expect($resentLog)->not->toBeNull()
        ->and(json_encode($resentLog->properties))->not->toContain((string) $capturedOkCode)
        ->and(implode("\n", $logged))->not->toContain((string) $capturedFailedCode)
        ->and(implode("\n", $logged))->not->toContain((string) $capturedOkCode);
});
