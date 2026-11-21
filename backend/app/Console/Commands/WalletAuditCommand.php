<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\Parcel;
use App\Services\DeliverySettlementService;
use Illuminate\Console\Command;

class WalletAuditCommand extends Command
{
    protected $signature = 'wallet:audit {type : order ou parcel} {id : Identifiant de la commande ou du colis}';

    protected $description = 'Audite les écritures attendues et réelles sur les portefeuilles pour une commande GoShop ou un colis GoColis.';

    public function handle(DeliverySettlementService $settlementService): int
    {
        $type = strtolower(trim((string) $this->argument('type')));
        $id = (int) $this->argument('id');

        if (! in_array($type, ['order', 'parcel'], true)) {
            $this->error('Type invalide. Utilisez "order" ou "parcel".');

            return self::FAILURE;
        }

        $course = $type === 'order' ? Order::find($id) : Parcel::find($id);
        if (! $course) {
            $this->error(ucfirst($type)." #{$id} introuvable.");

            return self::FAILURE;
        }

        $report = $settlementService->auditerLivraison($course);

        $this->info("Audit portefeuille : {$report['entity']} #{$report['id']} (statut: {$report['status']}, paiement: {$report['payment_method']})");

        $this->line('--- Écritures attendues ---');
        $this->table(
            ['Propriétaire', 'ID', 'Type', 'Montant (F CFA)'],
            array_map(static fn (array $r): array => [$r['owner_type'], $r['owner_id'], $r['type'], $r['amount']], $report['expected']),
        );

        $this->line('--- Écritures réelles ---');
        $this->table(
            ['Tx ID', 'Propriétaire', 'ID', 'Type', 'Montant (F CFA)'],
            array_map(static fn (array $r): array => [$r['id'], $r['owner_type'], $r['owner_id'], $r['type'], $r['amount']], $report['actual']),
        );

        if ($report['is_settled']) {
            $this->info('Résultat : CONFORME (0 écart).');

            return self::SUCCESS;
        }

        $this->error('Résultat : ÉCARTS DÉTECTÉS ('.count($report['discrepancies']).') :');
        foreach ($report['discrepancies'] as $gap) {
            $this->warn(" - {$gap}");
        }

        return self::FAILURE;
    }
}
