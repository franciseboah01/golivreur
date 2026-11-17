<?php

namespace App\Services;

use App\Console\Commands\SweepOrderTimeoutsCommand;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;

/**
 * Évalue l'état opérationnel des dépendances critiques pour /health
 * sans jamais exposer de secrets, chemins sensibles ou piles d'erreur.
 */
class HealthCheckService
{
    private const SCHEDULER_MAX_AGE_SECONDS = 180;

    /**
     * @return array{
     *     status: 'ok'|'degraded',
     *     timestamp: string,
     *     checks: array<string, array<string, mixed>>,
     * }
     */
    public function evaluate(): array
    {
        $redisCheck = $this->checkRedis();

        $checks = [
            'database' => $this->checkDatabase(),
            'redis' => $redisCheck,
            'queues' => $this->checkQueues($redisCheck['status'] === 'ok'),
            'scheduler' => $this->checkScheduler(),
            'sms' => $this->checkSms(),
            'fcm' => $this->checkFcm(),
        ];

        $isDegraded = false;
        foreach ($checks as $check) {
            if (($check['status'] ?? 'error') !== 'ok') {
                $isDegraded = true;
                break;
            }
        }

        return [
            'status' => $isDegraded ? 'degraded' : 'ok',
            'timestamp' => now()->toIso8601String(),
            'checks' => $checks,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkDatabase(): array
    {
        $start = hrtime(true);

        try {
            DB::select('SELECT 1');

            return [
                'status' => 'ok',
                'latency_ms' => (int) round((hrtime(true) - $start) / 1_000_000),
            ];
        } catch (\Throwable) {
            return [
                'status' => 'error',
                'message' => 'Base de données inaccessible.',
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function checkRedis(): array
    {
        $start = hrtime(true);

        try {
            Redis::connection('default')->ping();

            return [
                'status' => 'ok',
                'latency_ms' => (int) round((hrtime(true) - $start) / 1_000_000),
            ];
        } catch (\Throwable) {
            return [
                'status' => 'error',
                'message' => 'Redis inaccessible.',
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function checkQueues(bool $redisOk): array
    {
        $defaultConnection = (string) config('queue.default', 'sync');

        if ($defaultConnection === 'redis' && ! $redisOk) {
            return [
                'status' => 'error',
                'connection' => $defaultConnection,
                'message' => 'Files indisponibles tant que Redis est hors ligne.',
            ];
        }

        try {
            $sizes = [
                'high' => Queue::size('high'),
                'default' => Queue::size('default'),
                'low' => Queue::size('low'),
            ];

            return [
                'status' => 'ok',
                'connection' => $defaultConnection,
                'sizes' => $sizes,
            ];
        } catch (\Throwable) {
            return [
                'status' => 'error',
                'connection' => $defaultConnection,
                'message' => 'Impossible d\'inspecter les files d\'attente.',
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function checkScheduler(): array
    {
        try {
            $lastRun = Cache::get(SweepOrderTimeoutsCommand::LAST_RUN_CACHE_KEY);
        } catch (\Throwable) {
            return [
                'status' => 'degraded',
                'last_run_at' => null,
                'age_seconds' => null,
                'message' => 'Cache planificateur inaccessible.',
            ];
        }

        if (! is_string($lastRun) || trim($lastRun) === '') {
            return [
                'status' => 'degraded',
                'last_run_at' => null,
                'age_seconds' => null,
                'message' => 'Aucun passage récent de orders:sweep-timeouts.',
            ];
        }

        try {
            $parsed = Carbon::parse($lastRun);
        } catch (\Throwable) {
            return [
                'status' => 'degraded',
                'last_run_at' => null,
                'age_seconds' => null,
                'message' => 'Horodatage planificateur invalide.',
            ];
        }

        $ageSeconds = max(0, now()->getTimestamp() - $parsed->getTimestamp());

        if ($ageSeconds > self::SCHEDULER_MAX_AGE_SECONDS) {
            return [
                'status' => 'degraded',
                'last_run_at' => $parsed->toIso8601String(),
                'age_seconds' => $ageSeconds,
                'message' => 'Dernier passage orders:sweep-timeouts supérieur à 3 minutes.',
            ];
        }

        return [
            'status' => 'ok',
            'last_run_at' => $parsed->toIso8601String(),
            'age_seconds' => $ageSeconds,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkSms(): array
    {
        $driver = strtolower(trim((string) config('sms.driver', 'log')));

        if (! in_array($driver, ['log', 'yellika'], true)) {
            return [
                'status' => 'error',
                'driver' => $driver,
                'message' => 'Driver SMS inconnu.',
            ];
        }

        if ($driver === 'log' && app()->environment('production')) {
            return [
                'status' => 'error',
                'driver' => $driver,
                'message' => 'Driver SMS log interdit en production.',
            ];
        }

        if ($driver === 'yellika' && trim((string) config('sms.yellika.api_token', '')) === '') {
            return [
                'status' => 'degraded',
                'driver' => $driver,
                'message' => 'Jeton API Yellika manquant.',
            ];
        }

        return [
            'status' => 'ok',
            'driver' => $driver,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkFcm(): array
    {
        $credentialsPath = config('firebase.projects.app.credentials');
        $credentialsPresent = is_string($credentialsPath)
            && trim($credentialsPath) !== ''
            && is_file($credentialsPath)
            && is_readable($credentialsPath);

        if (! $credentialsPresent) {
            return [
                'status' => app()->environment('production') ? 'error' : 'degraded',
                'credentials_present' => false,
                'message' => 'Fichier d\'identifiants Firebase absent ou illisible.',
            ];
        }

        return [
            'status' => 'ok',
            'credentials_present' => true,
        ];
    }
}
