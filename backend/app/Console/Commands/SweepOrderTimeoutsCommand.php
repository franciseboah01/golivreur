<?php

namespace App\Console\Commands;

use App\Services\OrderSweepService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SweepOrderTimeoutsCommand extends Command
{
    public const LAST_RUN_CACHE_KEY = 'scheduler:orders_sweep:last_run_at';

    protected $signature = 'orders:sweep-timeouts';

    protected $description = 'Balaye en filet de secours les commandes pending expirées et relance les attributions livreur sans réponse.';

    public function handle(OrderSweepService $sweepService): int
    {
        $stats = $sweepService->balayer();

        try {
            Cache::put(self::LAST_RUN_CACHE_KEY, now()->toIso8601String(), now()->addHour());
        } catch (\Throwable $e) {
            Log::warning('Impossible d\'enregistrer le heartbeat du scheduler orders:sweep-timeouts', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }

        $this->info(sprintf(
            'Balayage terminé : %d commande(s) pending annulée(s), %d réattribution(s) relancée(s).',
            $stats['pending_cancelled'],
            $stats['assignment_retried'],
        ));

        return self::SUCCESS;
    }
}
