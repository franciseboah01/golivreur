<?php

namespace App\Console\Commands;

use App\Services\OrderSweepService;
use Illuminate\Console\Command;

class SweepOrderTimeoutsCommand extends Command
{
    protected $signature = 'orders:sweep-timeouts';

    protected $description = 'Balaye en filet de secours les commandes pending expirées et relance les attributions livreur sans réponse.';

    public function handle(OrderSweepService $sweepService): int
    {
        $stats = $sweepService->balayer();

        $this->info(sprintf(
            'Balayage terminé : %d commande(s) pending annulée(s), %d réattribution(s) relancée(s).',
            $stats['pending_cancelled'],
            $stats['assignment_retried'],
        ));

        return self::SUCCESS;
    }
}

