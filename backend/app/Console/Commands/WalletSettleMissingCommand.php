<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\Parcel;
use App\Services\DeliverySettlementService;
use Illuminate\Console\Command;

class WalletSettleMissingCommand extends Command
{
    protected $signature = 'wallet:settle-missing {--confirm : Exécute réellement le règlement idempotent (sinon simulation dry-run)}';

    protected $description = 'Détecte les livraisons terminées sans règlement portefeuille (dry-run par défaut ; --confirm requis pour exécuter).';

    public function handle(DeliverySettlementService $settlementService): int
    {
        $confirm = (bool) $this->option('confirm');

        if (! $confirm) {
            $this->warn('Mode DRY-RUN (simulation uniquement, aucune écriture en base). Passez --confirm pour exécuter.');
        }

        $missingCount = 0;
        $settledCount = 0;
        $createdTxCount = 0;

        Order::where('status', Order::STATUS_DELIVERED)
            ->orderBy('id')
            ->chunkById(100, function ($orders) use ($settlementService, $confirm, &$missingCount, &$settledCount, &$createdTxCount): void {
                foreach ($orders as $order) {
                    $audit = $settlementService->auditerLivraison($order);
                    if ($audit['is_settled']) {
                        continue;
                    }

                    $missingCount++;
                    $this->line("[order #{$order->id}] ".implode(' ; ', $audit['discrepancies']));

                    if ($confirm) {
                        $res = $settlementService->reglerLivraison($order);
                        if ($res['created_transactions'] > 0) {
                            $settledCount++;
                            $createdTxCount += $res['created_transactions'];
                        }
                    }
                }
            });

        Parcel::where('status', Parcel::STATUS_DELIVERED)
            ->orderBy('id')
            ->chunkById(100, function ($parcels) use ($settlementService, $confirm, &$missingCount, &$settledCount, &$createdTxCount): void {
                foreach ($parcels as $parcel) {
                    $audit = $settlementService->auditerLivraison($parcel);
                    if ($audit['is_settled']) {
                        continue;
                    }

                    $missingCount++;
                    $this->line("[parcel #{$parcel->id}] ".implode(' ; ', $audit['discrepancies']));

                    if ($confirm) {
                        $res = $settlementService->reglerLivraison($parcel);
                        if ($res['created_transactions'] > 0) {
                            $settledCount++;
                            $createdTxCount += $res['created_transactions'];
                        }
                    }
                }
            });

        if ($confirm) {
            $this->info("Rattrapage terminé : {$settledCount}/{$missingCount} livraison(s) réglée(s), {$createdTxCount} transaction(s) créée(s).");
        } else {
            $this->info("DRY-RUN terminé : {$missingCount} livraison(s) sans règlement complet détectée(s). Aucune écriture effectuée.");
        }

        return self::SUCCESS;
    }
}
