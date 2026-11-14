<?php

namespace App\Services;

use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Dispatcher partagé garantissant le lancement des jobs strictement après commit DB,
 * tout en isolant toute panne Redis/queue après commit (Log::critical sans rollback).
 */
class AsyncJobDispatcher
{
    /**
     * @param  callable(): mixed  $dispatchCallback
     * @param  array<string, mixed>  $contexte
     */
    public static function dispatchAfterCommit(
        callable $dispatchCallback,
        string $operation = 'async_job_dispatch',
        array $contexte = [],
    ): void {
        DB::afterCommit(static function () use ($dispatchCallback, $operation, $contexte): void {
            try {
                self::executerEtDetruire($dispatchCallback);
            } catch (\Throwable $e) {
                Log::critical("Échec dispatch queue ({$operation}) : {$e->getMessage()}", [
                    'operation' => $operation,
                    'exception' => $e::class,
                    ...$contexte,
                ]);
            }
        });
    }

    /**
     * Force la destruction de PendingDispatch dans le try/catch
     * afin de capturer les exceptions émises par PendingDispatch::__destruct().
     *
     * @param  callable(): mixed  $dispatchCallback
     */
    private static function executerEtDetruire(callable $dispatchCallback): void
    {
        $pending = $dispatchCallback();
        if ($pending instanceof PendingDispatch) {
            unset($pending);
        }
    }
}
