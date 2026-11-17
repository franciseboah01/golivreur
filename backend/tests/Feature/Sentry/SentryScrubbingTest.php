<?php

use App\Support\SentryEventScrubber;
use Sentry\Breadcrumb;
use Sentry\ClientBuilder;
use Sentry\Event;
use Sentry\ExceptionDataBag;
use Sentry\UserDataBag;

it('configure sentry avec send_default_pii=false, traces_sample_rate=0.1 et before_send cacheable', function () {
    expect(config('sentry.send_default_pii'))->toBeFalse()
        ->and((float) config('sentry.traces_sample_rate'))->toBe(0.1)
        ->and(config('sentry.breadcrumbs.sql_bindings'))->toBeFalse()
        ->and(config('sentry.tracing.sql_bindings'))->toBeFalse()
        ->and(config('sentry.before_send'))->toBe([SentryEventScrubber::class, 'beforeSend'])
        ->and(is_callable(config('sentry.before_send')))->toBeTrue();

    // Vérifie que Sentry ClientBuilder accepte toute la config (sans closure non-sérialisable)
    $builder = ClientBuilder::create([
        'dsn' => null,
        'environment' => 'testing',
        'send_default_pii' => config('sentry.send_default_pii'),
        'traces_sample_rate' => config('sentry.traces_sample_rate'),
        'before_send' => config('sentry.before_send'),
    ]);

    expect($builder->getOptions()->getBeforeSendCallback())->toBeCallable();
});

it('supprime telephone, otp, codes secrets, JWT et Authorization dans SentryEventScrubber::beforeSend', function () {
    $event = Event::createEvent();
    $event->setEnvironment('production');
    $event->setTag('city_id', '1');
    $event->setTag('phone', '0701020304');
    $event->setExtra([
        'order_id' => 42,
        'otp' => '654321',
        'device_token' => 'fcm-secret-device-token-123',
        'session_token' => 'jti-secret-session-token',
        'nested' => [
            'telephone' => '+2250701020304',
            'confirmation_code' => '9988',
            'sender_code' => '1122',
            'status' => 'pending',
        ],
    ]);
    $event->setRequest([
        'url' => 'https://api.golivreur.ci/api/v1/auth/verify-login?token=jwt.abc.def&city_id=2',
        'query_string' => 'token=jwt.abc.def&city_id=2',
        'headers' => [
            'Authorization' => ['Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxIn0.sig'],
            'X-Webhook-Secret' => ['super-secret'],
            'Accept' => ['application/json'],
        ],
        'data' => [
            'phone' => '0708091011',
            'code' => '1234',
            'order_id' => 99,
        ],
    ]);

    $user = new UserDataBag(id: 7, email: 'client@example.com', ipAddress: '10.0.0.5', username: '0708091011');
    $user->setMetadata('phone', '0708091011');
    $user->setMetadata('role', 'client');
    $event->setUser($user);

    $event->setBreadcrumb([
        new Breadcrumb(
            Breadcrumb::LEVEL_INFO,
            Breadcrumb::TYPE_HTTP,
            'http',
            'POST Bearer eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.abc',
            ['phone' => '0708091011', 'otp' => '9999', 'order_id' => 42],
        ),
    ]);

    $event->setMessage('Erreur auth Bearer eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.xyz pour +2250708091011');
    $event->setExceptions([
        new ExceptionDataBag(new RuntimeException('Echec verification OTP 445566 pour 0708091011')),
    ]);

    $scrubbed = SentryEventScrubber::beforeSend($event);

    expect($scrubbed)->not->toBeNull()
        ->and($scrubbed->getEnvironment())->toBe('production')
        ->and($scrubbed->getTags()['city_id'])->toBe('1')
        ->and($scrubbed->getTags()['phone'])->toBe('[Filtered]');

    $extra = $scrubbed->getExtra();
    expect($extra['order_id'])->toBe(42)
        ->and($extra['otp'])->toBe('[Filtered]')
        ->and($extra['device_token'])->toBe('[Filtered]')
        ->and($extra['session_token'])->toBe('[Filtered]')
        ->and($extra['nested']['telephone'])->toBe('[Filtered]')
        ->and($extra['nested']['confirmation_code'])->toBe('[Filtered]')
        ->and($extra['nested']['sender_code'])->toBe('[Filtered]')
        ->and($extra['nested']['status'])->toBe('pending');

    $req = $scrubbed->getRequest();
    expect($req['headers']['Authorization'][0])->toBe('[Filtered]')
        ->and($req['headers']['X-Webhook-Secret'][0])->toBe('[Filtered]')
        ->and($req['headers']['Accept'][0])->toBe('application/json')
        ->and($req['data']['phone'])->toBe('[Filtered]')
        ->and($req['data']['code'])->toBe('[Filtered]')
        ->and($req['data']['order_id'])->toBe(99)
        ->and($req['query_string'])->not->toContain('jwt.abc.def')
        ->and($req['url'])->not->toContain('jwt.abc.def');

    $scrubbedUser = $scrubbed->getUser();
    expect($scrubbedUser?->getId())->toBe(7)
        ->and($scrubbedUser?->getEmail())->toBeNull()
        ->and($scrubbedUser?->getIpAddress())->toBeNull()
        ->and($scrubbedUser?->getUsername())->toBeNull()
        ->and($scrubbedUser?->getMetadata()['phone'] ?? null)->toBe('[Filtered]')
        ->and($scrubbedUser?->getMetadata()['role'] ?? null)->toBe('client');

    $crumb = $scrubbed->getBreadcrumbs()[0];
    expect($crumb->getMessage())->not->toContain('eyJhbGci')
        ->and($crumb->getMetadata()['phone'])->toBe('[Filtered]')
        ->and($crumb->getMetadata()['otp'])->toBe('[Filtered]')
        ->and($crumb->getMetadata()['order_id'])->toBe(42);

    expect($scrubbed->getMessage())->not->toContain('eyJhbGci')
        ->and($scrubbed->getMessage())->not->toContain('0708091011')
        ->and($scrubbed->getExceptions()[0]->getValue())->not->toContain('0708091011');
});

it('branche Sentry Integration::handles dans bootstrap/app.php', function () {
    $bootstrap = (string) file_get_contents(base_path('bootstrap/app.php'));

    expect($bootstrap)->toContain('Integration::handles($exceptions)');
});
