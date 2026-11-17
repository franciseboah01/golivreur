<?php

use App\Console\Commands\SweepOrderTimeoutsCommand;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

it('supprime tout secret code en dur dans config/services.php pour health.token', function () {
    $servicesContent = (string) file_get_contents(base_path('config/services.php'));

    expect($servicesContent)->not->toContain('golivreur-secret-health-token');
});

it('renvoie uniquement status sur GET /health public sans exposer les details internes', function () {
    Cache::put(SweepOrderTimeoutsCommand::LAST_RUN_CACHE_KEY, now()->toIso8601String(), now()->addHour());

    $tmpCreds = tempnam(sys_get_temp_dir(), 'fcm_creds_');
    file_put_contents($tmpCreds, '{}');

    try {
        config([
            'services.health.token' => 'secret-health-test-token-123',
            'firebase.projects.app.credentials' => $tmpCreds,
            'sms.driver' => 'log',
        ]);

        Redis::shouldReceive('connection')
            ->with('default')
            ->andReturn(new class
            {
                public function ping(): string
                {
                    return 'PONG';
                }
            });

        // Sans header -> uniquement {"status": "ok"}
        $publicOk = $this->getJson('/health');
        $publicOk->assertOk()
            ->assertExactJson(['status' => 'ok']);
        expect(array_keys($publicOk->json()))->toBe(['status']);

        // Mauvais token -> ne fuite aucun détail
        $wrongToken = $this->withHeader('HEALTH_TOKEN', 'mauvais-token')
            ->getJson('/health');
        $wrongToken->assertOk()
            ->assertExactJson(['status' => 'ok']);
        expect(array_keys($wrongToken->json()))->toBe(['status']);

        // Token vide configuré + envoi d'un header vide -> ne jamais bypasser
        config(['services.health.token' => '']);
        $emptyConfigured = $this->withHeader('HEALTH_TOKEN', '')
            ->getJson('/health');
        $emptyConfigured->assertOk()
            ->assertExactJson(['status' => 'ok']);
    } finally {
        @unlink($tmpCreds);
    }
});

it('renvoie les diagnostics complets sur GET /health avec header HEALTH_TOKEN valide sans fuiter de secrets', function () {
    Cache::put(SweepOrderTimeoutsCommand::LAST_RUN_CACHE_KEY, now()->toIso8601String(), now()->addHour());

    $tmpCreds = tempnam(sys_get_temp_dir(), 'fcm_creds_');
    file_put_contents($tmpCreds, '{"project_id":"test-secret"}');

    try {
        config([
            'services.health.token' => 'secret-health-test-token-123',
            'firebase.projects.app.credentials' => $tmpCreds,
            'sms.driver' => 'yellika',
            'sms.yellika.api_token' => 'super-secret-yellika-token-xyz',
        ]);

        Redis::shouldReceive('connection')
            ->with('default')
            ->andReturn(new class
            {
                public function ping(): string
                {
                    return 'PONG';
                }
            });

        $response = $this->withHeader('HEALTH_TOKEN', 'secret-health-test-token-123')
            ->getJson('/health');

        $response->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.database.status', 'ok')
            ->assertJsonPath('checks.redis.status', 'ok')
            ->assertJsonPath('checks.queues.status', 'ok')
            ->assertJsonPath('checks.scheduler.status', 'ok')
            ->assertJsonPath('checks.sms.status', 'ok')
            ->assertJsonPath('checks.sms.driver', 'yellika')
            ->assertJsonPath('checks.fcm.status', 'ok')
            ->assertJsonPath('checks.fcm.credentials_present', true);

        $body = $response->content();
        expect($body)->not->toContain('super-secret-yellika-token-xyz')
            ->not->toContain('secret-health-test-token-123')
            ->not->toContain($tmpCreds)
            ->not->toContain('test-secret');
    } finally {
        @unlink($tmpCreds);
    }
});

it('passe en degraded si Redis est hors service, scheduler > 3 min ou SMS log en production', function () {
    config([
        'services.health.token' => 'secret-health-test-token-123',
        'firebase.projects.app.credentials' => '/nonexistent/firebase-missing.json',
    ]);

    // 1. Scheduler > 3 min + Redis down
    Cache::put(
        SweepOrderTimeoutsCommand::LAST_RUN_CACHE_KEY,
        now()->subSeconds(185)->toIso8601String(),
        now()->addHour(),
    );

    Redis::shouldReceive('connection')
        ->with('default')
        ->andThrow(new RuntimeException('Connection refused Redis internal password=supersecret'));

    $degraded = $this->withHeader('HEALTH_TOKEN', 'secret-health-test-token-123')
        ->getJson('/health');

    $degraded->assertStatus(503)
        ->assertJsonPath('status', 'degraded')
        ->assertJsonPath('checks.redis.status', 'error')
        ->assertJsonPath('checks.scheduler.status', 'degraded')
        ->assertJsonPath('checks.fcm.credentials_present', false);

    // Ne doit jamais fuiter de message d'exception brut avec secret Redis (et sans header -> payload public uniquement)
    expect($degraded->content())->not->toContain('supersecret')
        ->and($this->flushHeaders()->getJson('/health')->assertStatus(503)->json())->toBe(['status' => 'degraded']);

    // 2. SMS driver=log en environnement production -> error / degraded
    $previousEnv = app()->environment();
    app()->instance('env', 'production');

    try {
        Cache::put(SweepOrderTimeoutsCommand::LAST_RUN_CACHE_KEY, now()->toIso8601String(), now()->addHour());
        config(['sms.driver' => 'log']);

        $prodResp = $this->withHeader('HEALTH_TOKEN', 'secret-health-test-token-123')
            ->getJson('/health');

        $prodResp->assertStatus(503)
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('checks.sms.status', 'error')
            ->assertJsonPath('checks.sms.driver', 'log');
    } finally {
        app()->instance('env', $previousEnv);
    }
});

it('passe en degraded si la base de donnees echoue', function () {
    config(['services.health.token' => 'secret-health-test-token-123']);

    DB::partialMock()
        ->shouldReceive('select')
        ->with('SELECT 1')
        ->andThrow(new RuntimeException('SQLSTATE[08006] password=dbsecret'));

    $response = $this->withHeader('HEALTH_TOKEN', 'secret-health-test-token-123')
        ->getJson('/health');

    $response->assertStatus(503)
        ->assertJsonPath('status', 'degraded')
        ->assertJsonPath('checks.database.status', 'error');

    expect($response->content())->not->toContain('dbsecret');
});
