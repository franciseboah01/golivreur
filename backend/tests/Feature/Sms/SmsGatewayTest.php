<?php

use App\Mail\SmsDailyAlertMail;
use App\Models\ActivityLog;
use App\Models\City;
use App\Models\OtpCode;
use App\Models\Parcel;
use App\Models\SmsLog;
use App\Models\User;
use App\Services\SettingsService;
use App\Services\Sms\Contracts\SmsGateway;
use App\Services\Sms\LogSmsGateway;
use App\Services\Sms\SmsResult;
use App\Services\Sms\YellikaSmsGateway;
use App\Services\SmsService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tymon\JWTAuth\Facades\JWTAuth;

beforeEach(function () {
    Cache::flush();
    config([
        'sms.driver' => 'yellika',
        'sms.yellika.base_url' => 'https://panel.yellikasms.com/api/v3/sms/send',
        'sms.yellika.api_token' => 'test-yellika-token-non-secret',
        'sms.yellika.sender_id' => 'GoLivreur',
        'sms.yellika.type' => 'plain',
        'sms.yellika.timeout' => 10,
    ]);
});

it('envoie un SMS avec succes via Yellika avec Http::fake, ecrit sms_logs sans message et masque le telephone', function () {
    Http::fake([
        'https://panel.yellikasms.com/api/v3/sms/send' => Http::response([
            'status' => 'success',
            'uid' => 'yellika-msg-12345',
            'message' => 'SMS queued',
        ], 200),
    ]);

    $logged = [];
    Log::listen(function ($entry) use (&$logged) {
        $logged[] = $entry->message.' '.json_encode($entry->context);
    });

    $gateway = app(SmsGateway::class);
    expect($gateway)->toBeInstanceOf(YellikaSmsGateway::class);

    $service = app(SmsService::class);
    $ok = $service->envoyerOtp('0701020312', '4829', 'connexion');

    expect($ok)->toBeTrue()
        ->and(Schema::hasTable('sms_logs'))->toBeTrue()
        ->and(Schema::hasColumn('sms_logs', 'message'))->toBeFalse();

    Http::assertSentCount(1);
    Http::assertSent(function (Request $request) {
        return $request->url() === 'https://panel.yellikasms.com/api/v3/sms/send'
            && $request->hasHeader('Authorization', 'Bearer test-yellika-token-non-secret')
            && $request['recipient'] === '2250701020312'
            && $request['sender_id'] === 'GoLivreur'
            && $request['type'] === 'plain'
            && str_contains((string) $request['message'], '4829');
    });

    $logRow = SmsLog::first();
    expect($logRow)->not->toBeNull()
        ->and($logRow->phone_masked)->toBe('225 07 ** ** 12')
        ->and($logRow->motif)->toBe('otp')
        ->and($logRow->driver)->toBe('yellika')
        ->and($logRow->status)->toBe('sent')
        ->and($logRow->provider_message_id)->toBe('yellika-msg-12345');

    $allLogs = implode("\n", $logged);
    expect($allLogs)->toContain('225 07 ** ** 12')
        ->not->toContain('4829')
        ->not->toContain('0701020312')
        ->not->toContain('test-yellika-token-non-secret');
});

it('gere une erreur 4xx Yellika sans reessai et alimente sms_failed sur GoColis', function () {
    Queue::fake();

    Http::fake([
        'https://panel.yellikasms.com/api/v3/sms/send' => Http::response([
            'status' => 'error',
            'message' => 'Sender ID not approved',
        ], 422),
    ]);

    $city = City::factory()->create(['center_lat' => 6.8770, 'center_lng' => -6.4502]);
    app(SettingsService::class)->set('paiements', ['modes_actifs' => ['cash']], $city->id);
    $sender = User::factory()->create(['role' => 'client', 'city_id' => $city->id, 'name' => 'Aya Yao']);

    $res = $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($sender))
        ->postJson('/api/v1/client/parcels', [
            'recipient_name' => 'Kouassi Jean',
            'recipient_phone' => '0708091011',
            'delivery_address' => 'Quartier Lobia',
            'delivery_lat' => 6.8820,
            'delivery_lng' => -6.4520,
            'pickup_address' => 'Quartier Commerce',
            'pickup_lat' => 6.8770,
            'pickup_lng' => -6.4502,
            'size' => 'petit',
            'weight_kg' => 1.0,
            'payment_method' => 'cash',
        ])
        ->assertCreated();

    Http::assertSentCount(1);

    $parcel = Parcel::findOrFail($res->json('donnees.id'));
    expect($parcel->sms_failed)->toBeTrue()
        ->and($parcel->sms_failed_at)->not->toBeNull();

    $smsLog = SmsLog::latest('id')->first();
    expect($smsLog)->not->toBeNull()
        ->and($smsLog->status)->toBe('failed')
        ->and($smsLog->phone_masked)->toBe('225 07 ** ** 11')
        ->and($smsLog->driver)->toBe('yellika');
});

it('retente au maximum 2 fois en cas de delai depasse (ConnectionException) et echoue proprement', function () {
    $attempts = 0;

    Http::fake(function () use (&$attempts) {
        $attempts++;

        throw new ConnectionException('cURL error 28: Operation timed out after 10001 milliseconds');
    });

    /** @var YellikaSmsGateway $gateway */
    $gateway = app(YellikaSmsGateway::class);
    $result = $gateway->send('+2250701020312', 'GoLivreur test timeout');

    expect($result)->toBeInstanceOf(SmsResult::class)
        ->and($result->succes)->toBeFalse()
        ->and($result->erreur)->not->toBeEmpty()
        ->and($attempts)->toBe(2);
});

it('refuse un numero invalide avant tout appel HTTP Yellika et trace echec dans sms_logs', function () {
    Http::fake();

    $ok = app(SmsService::class)->envoyerOtp('12345-invalide', '7744', 'connexion');

    expect($ok)->toBeFalse();
    Http::assertNothingSent();

    $logRow = SmsLog::latest('id')->first();
    expect($logRow)->not->toBeNull()
        ->and($logRow->status)->toBe('failed')
        ->and($logRow->phone_masked)->not->toContain('12345-invalide');
});

it('refuse le pilote log en environnement production et ne journalise jamais le message ni OTP', function () {
    config(['sms.driver' => 'log']);
    app()->detectEnvironment(fn () => 'production');

    $logged = [];
    Log::listen(function ($entry) use (&$logged) {
        $logged[] = $entry->message.' '.json_encode($entry->context);
    });

    try {
        /** @var LogSmsGateway $logGateway */
        $logGateway = app(LogSmsGateway::class);
        $result = $logGateway->send('+2250701020312', 'Code secret OTP 9182');

        expect($result->succes)->toBeFalse()
            ->and($result->erreur)->toContain('production');

        $ok = app(SmsService::class)->envoyerOtp('0701020312', '9182', 'connexion');
        expect($ok)->toBeFalse();

        $allLogs = implode("\n", $logged);
        expect($allLogs)->not->toContain('9182')
            ->not->toContain('0701020312');

        $row = SmsLog::latest('id')->first();
        expect($row)->not->toBeNull()
            ->and($row->status)->toBe('failed')
            ->and($row->driver)->toBe('log');
    } finally {
        app()->detectEnvironment(fn () => 'testing');
    }
});

it('envoie reellement les OTP inscription et connexion via SmsService -> SmsGateway et declenche alerte seuil quotidien', function () {
    Mail::fake();

    Http::fake([
        'https://panel.yellikasms.com/api/v3/sms/send' => Http::sequence()
            ->push(['status' => 'success', 'uid' => 'otp-reg-1'], 200)
            ->push(['status' => 'success', 'uid' => 'otp-log-2'], 200),
    ]);

    // Seuil quotidien configuré à 1 dans les settings -> le 2e SMS dépasse le seuil (> 1)
    app(SettingsService::class)->set('sms', [
        'seuil_alerte_mensuel' => 2500,
        'seuil_alerte_quotidien' => 1,
        'alert_email' => 'admin-sms@golivreur.ci',
    ]);

    // 1. Inscription
    $this->postJson('/api/v1/auth/register', [
        'phone' => '0705060708',
        'name' => 'Kouame Paul',
    ])->assertOk();

    // 2. Connexion d'un utilisateur existant
    User::factory()->create(['phone' => '0709101112', 'role' => 'client', 'is_active' => true]);
    $this->postJson('/api/v1/auth/login', [
        'phone' => '0709101112',
    ])->assertOk();

    Http::assertSentCount(2);
    expect(SmsLog::where('status', 'sent')->where('motif', 'otp')->count())->toBe(2);

    Mail::assertSent(SmsDailyAlertMail::class, 1);
    expect(ActivityLog::where('action', 'sms_daily_threshold_exceeded')->count())->toBe(1);
});

it('autorise le pilote log en local/testing/staging avec message complet et echoue proprement sur auth/login si gateway echoue', function () {
    config(['sms.driver' => 'log']);
    $logged = [];
    Log::listen(function ($entry) use (&$logged) {
        $logged[] = $entry->message.' '.json_encode($entry->context);
    });

    app()->detectEnvironment(fn () => 'staging');
    try {
        $result = app(LogSmsGateway::class)->send('+2250701020312', 'Code staging 6543');
        expect($result->succes)->toBeTrue();
        expect(implode("\n", $logged))->toContain('Code staging 6543')
            ->toContain('225 07 ** ** 12')
            ->not->toContain('0701020312');
    } finally {
        app()->detectEnvironment(fn () => 'testing');
    }

    // Echec Yellika sur login -> 503 sans OTP orphelin en base
    config(['sms.driver' => 'yellika']);
    Http::fake([
        'https://panel.yellikasms.com/api/v3/sms/send' => Http::response(['message' => 'Unauthorized'], 401),
    ]);

    User::factory()->create(['phone' => '0703040506', 'role' => 'client', 'is_active' => true]);
    $this->postJson('/api/v1/auth/login', ['phone' => '0703040506'])
        ->assertStatus(503)
        ->assertJsonPath('succes', false);

    expect(OtpCode::where('phone', '0703040506')->count())->toBe(0);
});
