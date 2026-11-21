<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Parcel;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;

/**
 * Point d'entrée UNIQUE et IDEMPOTENT de règlement financier en fin de livraison
 * (GoShop & GoColis), appelé par tous les chemins (API livreur, Filament, rattrapage).
 */
class DeliverySettlementService
{
    public function __construct(
        private readonly WalletService $walletService,
    ) {}

    /**
     * Exécute le règlement financier idempotent pour une commande GoShop ou un colis GoColis livré.
     *
     * @return array{settled: bool, created_transactions: int, skipped: bool}
     */
    public function reglerLivraison(Order|Parcel $course): array
    {
        if ($course instanceof Order) {
            return $this->reglerCommandeGoShop($course);
        }

        return $this->reglerColisGoColis($course);
    }

    /**
     * Audite les écritures attendues vs réelles pour une commande ou un colis.
     *
     * @return array{
     *     entity: string,
     *     id: int,
     *     status: string,
     *     payment_method: string,
     *     is_settled: bool,
     *     expected: list<array{owner_type: string, owner_id: int, type: string, amount: int}>,
     *     actual: list<array{id: int, owner_type: string, owner_id: int, type: string, amount: int}>,
     *     discrepancies: list<string>
     * }
     */
    public function auditerLivraison(Order|Parcel $course): array
    {
        $isOrder = $course instanceof Order;
        $entity = $isOrder ? 'order' : 'parcel';
        $isCash = $course->payment_method === 'cash';

        /** @var list<array{owner_type: string, owner_id: int, type: string, amount: int}> $expected */
        $expected = [];

        if ($isOrder) {
            if ($course->courier_id !== null) {
                $expected[] = [
                    'owner_type' => 'courier',
                    'owner_id' => (int) $course->courier_id,
                    'type' => $isCash ? 'debit_cash_remittance' : 'credit_delivery',
                    'amount' => $isCash
                        ? -$this->walletService->calculerMontantAReverserOrder($course)
                        : (int) round((float) $course->courier_amount),
                ];
            }

            if ($course->merchant_id !== null) {
                $expected[] = [
                    'owner_type' => 'merchant',
                    'owner_id' => (int) $course->merchant_id,
                    'type' => 'credit_delivery',
                    'amount' => (int) round((float) $course->merchant_amount),
                ];
            }

            $rows = WalletTransaction::with('wallet')
                ->where('order_id', $course->id)
                ->orderBy('id')
                ->get();
        } else {
            if ($course->courier_id !== null) {
                $expected[] = [
                    'owner_type' => 'courier',
                    'owner_id' => (int) $course->courier_id,
                    'type' => $isCash ? 'debit_cash_remittance' : 'credit_shipping',
                    'amount' => $isCash
                        ? -$this->walletService->calculerMontantAReverserParcel($course)
                        : (int) round((float) $course->courier_amount),
                ];
            }

            $rows = WalletTransaction::with('wallet')
                ->where('parcel_id', $course->id)
                ->orderBy('id')
                ->get();
        }

        /** @var list<array{id: int, owner_type: string, owner_id: int, type: string, amount: int}> $actual */
        $actual = $rows->map(static fn (WalletTransaction $tx): array => [
            'id' => (int) $tx->id,
            'owner_type' => $tx->wallet !== null ? (string) $tx->wallet->owner_type : 'unknown',
            'owner_id' => $tx->wallet !== null ? (int) $tx->wallet->owner_id : 0,
            'type' => (string) $tx->type,
            'amount' => (int) $tx->amount,
        ])->values()->all();

        $discrepancies = [];
        foreach ($expected as $exp) {
            $matching = array_values(array_filter(
                $actual,
                static fn (array $act): bool => $act['owner_type'] === $exp['owner_type']
                    && $act['owner_id'] === $exp['owner_id']
                    && $act['type'] === $exp['type'],
            ));

            if (count($matching) === 0) {
                $discrepancies[] = "Écriture manquante : {$exp['owner_type']} #{$exp['owner_id']} ({$exp['type']}, attendu {$exp['amount']} F CFA)";
            } elseif (count($matching) > 1) {
                $discrepancies[] = "Écriture dupliquée : {$exp['owner_type']} #{$exp['owner_id']} ({$exp['type']}, ".count($matching).' occurrences)';
            } elseif ($matching[0]['amount'] !== $exp['amount']) {
                $discrepancies[] = "Écart de montant : {$exp['owner_type']} #{$exp['owner_id']} ({$exp['type']}, attendu {$exp['amount']} F, réel {$matching[0]['amount']} F)";
            }
        }

        return [
            'entity' => $entity,
            'id' => (int) $course->id,
            'status' => (string) $course->status,
            'payment_method' => (string) $course->payment_method,
            'is_settled' => empty($discrepancies),
            'expected' => $expected,
            'actual' => $actual,
            'discrepancies' => $discrepancies,
        ];
    }

    /**
     * @return array{settled: bool, created_transactions: int, skipped: bool}
     */
    private function reglerCommandeGoShop(Order $order): array
    {
        return DB::transaction(function () use ($order): array {
            /** @var Order $locked */
            $locked = Order::lockForUpdate()->findOrFail($order->id);

            if ($locked->status !== Order::STATUS_DELIVERED) {
                return ['settled' => false, 'created_transactions' => 0, 'skipped' => true];
            }

            $isCash = $locked->payment_method === 'cash';
            $updates = [];
            if ($locked->delivered_at === null) {
                $updates['delivered_at'] = now();
            }
            if ($isCash && $locked->payment_status !== 'paid_cash') {
                $updates['payment_status'] = 'paid_cash';
            }
            if (! empty($updates)) {
                $locked->update($updates);
            }

            $created = 0;

            if ($locked->courier_id !== null) {
                $courierType = $isCash ? 'debit_cash_remittance' : 'credit_delivery';
                if (! $this->transactionExiste('order', (int) $locked->id, 'courier', (int) $locked->courier_id, $courierType)) {
                    if ($isCash) {
                        $this->walletService->debiterReversementCashOrder($locked);
                    } else {
                        $this->walletService->crediterLivreur($locked);
                    }
                    $created++;
                }
            }

            if ($locked->merchant_id !== null) {
                if (! $this->transactionExiste('order', (int) $locked->id, 'merchant', (int) $locked->merchant_id, 'credit_delivery')) {
                    $this->walletService->crediterMerchant($locked);
                    $created++;
                }
            }

            return [
                'settled' => true,
                'created_transactions' => $created,
                'skipped' => $created === 0,
            ];
        });
    }

    /**
     * @return array{settled: bool, created_transactions: int, skipped: bool}
     */
    private function reglerColisGoColis(Parcel $parcel): array
    {
        return DB::transaction(function () use ($parcel): array {
            /** @var Parcel $locked */
            $locked = Parcel::lockForUpdate()->findOrFail($parcel->id);

            if ($locked->status !== Parcel::STATUS_DELIVERED) {
                return ['settled' => false, 'created_transactions' => 0, 'skipped' => true];
            }

            $isCash = $locked->payment_method === 'cash';
            $updates = [];
            if ($locked->delivered_at === null) {
                $updates['delivered_at'] = now();
            }
            if ($isCash && $locked->payment_status !== 'paid_cash') {
                $updates['payment_status'] = 'paid_cash';
            }
            if (! empty($updates)) {
                $locked->update($updates);
            }

            $created = 0;

            if ($locked->courier_id !== null) {
                $courierType = $isCash ? 'debit_cash_remittance' : 'credit_shipping';
                if (! $this->transactionExiste('parcel', (int) $locked->id, 'courier', (int) $locked->courier_id, $courierType)) {
                    if ($isCash) {
                        $this->walletService->debiterReversementCashParcel($locked);
                    } else {
                        $this->walletService->crediterLivreurGoColis($locked);
                    }
                    $created++;
                }
            }

            return [
                'settled' => true,
                'created_transactions' => $created,
                'skipped' => $created === 0,
            ];
        });
    }

    private function transactionExiste(
        string $entity,
        int $entityId,
        string $ownerType,
        int $ownerId,
        string $txType,
    ): bool {
        $walletId = Wallet::where('owner_type', $ownerType)
            ->where('owner_id', $ownerId)
            ->value('id');

        if ($walletId === null) {
            return false;
        }

        $column = $entity === 'order' ? 'order_id' : 'parcel_id';

        return WalletTransaction::where('wallet_id', $walletId)
            ->where($column, $entityId)
            ->where('type', $txType)
            ->exists();
    }
}
