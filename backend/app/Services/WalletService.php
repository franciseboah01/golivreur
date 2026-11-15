<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Courier;
use App\Models\Order;
use App\Models\Parcel;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Gère tous les mouvements de fonds sur les portefeuilles virtuels.
 * Chaque opération est atomique (DB::transaction) et génère
 * une wallet_transaction immuable avec balance_before/after.
 * Modules critiques — couverture de test : 90% minimum.
 */
class WalletService
{
    /**
     * Crédite le livreur après une livraison GoShop non-espèces réussie.
     * Montant = 80% des frais de livraison.
     */
    public function crediterLivreur(Order $order): WalletTransaction
    {
        $wallet = $this->obtenirOuCreerWallet('courier', (int) $order->courier_id);

        return $this->effectuerTransaction(
            wallet: $wallet,
            type: 'credit_delivery',
            montant: (int) round((float) $order->courier_amount),
            description: "GoShop — Commande #{$order->id} — Livraison créditée",
            orderId: $order->id,
        );
    }

    /**
     * Crédite le merchant après une livraison GoShop réussie.
     * Montant = prix produits - commission commerce.
     */
    public function crediterMerchant(Order $order): WalletTransaction
    {
        $wallet = $this->obtenirOuCreerWallet('merchant', (int) $order->merchant_id);

        return $this->effectuerTransaction(
            wallet: $wallet,
            type: 'credit_delivery',
            montant: (int) round((float) $order->merchant_amount),
            description: "GoShop — Commande #{$order->id} — Vente créditée",
            orderId: $order->id,
        );
    }

    /**
     * Crédite le livreur après une livraison GoColis non-espèces réussie.
     * Montant = 75% des frais d'expédition.
     */
    public function crediterLivreurGoColis(Parcel $parcel): WalletTransaction
    {
        $wallet = $this->obtenirOuCreerWallet('courier', (int) $parcel->courier_id);

        return $this->effectuerTransaction(
            wallet: $wallet,
            type: 'credit_shipping',
            montant: (int) round((float) $parcel->courier_amount),
            description: "GoColis — Expédition #{$parcel->id} — Livraison créditée",
            parcelId: $parcel->id,
        );
    }

    /**
     * Effectue un ajustement comptable manuel sur un portefeuille.
     * Rôles autorisés : super_admin, national_finance.
     * Motif obligatoire, traçabilité dans activity_logs.
     */
    public function ajusterSolde(Wallet $wallet, int $montant, string $motif, User $admin): WalletTransaction
    {
        if (! in_array($admin->role, ['super_admin', 'national_finance'], true)) {
            throw new AuthorizationException('Seul un Super Admin ou National Finance peut effectuer un ajustement comptable.');
        }

        $motifNettoye = trim($motif);
        if ($motifNettoye === '') {
            throw new \InvalidArgumentException('Le motif de l\'ajustement est obligatoire.');
        }

        if ($montant === 0) {
            throw new \InvalidArgumentException('Le montant de l\'ajustement ne peut pas être nul.');
        }

        $transaction = $this->effectuerTransaction(
            wallet: $wallet,
            type: 'adjustment',
            montant: $montant,
            description: "Ajustement comptable : {$motifNettoye}",
            processedBy: $admin->id,
        );

        ActivityLog::create([
            'user_id' => $admin->id,
            'action' => 'wallet_balance_adjusted',
            'entity_type' => 'wallet',
            'entity_id' => $wallet->id,
            'properties' => [
                'wallet_id' => $wallet->id,
                'transaction_id' => $transaction->id,
                'amount' => $montant,
                'reason' => $motifNettoye,
                'balance_before' => $transaction->balance_before,
                'balance_after' => $transaction->balance_after,
            ],
            'ip_address' => request()?->ip(),
            'severity' => 'warning',
        ]);

        return $transaction;
    }

    /**
     * Débite le wallet du livreur du reversement dû (total encaissé - part livreur)
     * pour une commande GoShop payée en espèces.
     */
    public function debiterReversementCashOrder(Order $order): WalletTransaction
    {
        $wallet = $this->obtenirOuCreerWallet('courier', (int) $order->courier_id);
        $montantAReverser = $this->calculerMontantAReverserOrder($order);

        return $this->effectuerTransaction(
            wallet: $wallet,
            type: 'debit_cash_remittance',
            montant: -$montantAReverser,
            description: "GoShop — Commande #{$order->id} — Reversement espèces encaissées",
            orderId: $order->id,
        );
    }

    /**
     * Débite le wallet du livreur du reversement dû (part plateforme + assurance)
     * pour une expédition GoColis payée en espèces.
     */
    public function debiterReversementCashParcel(Parcel $parcel): WalletTransaction
    {
        $wallet = $this->obtenirOuCreerWallet('courier', (int) $parcel->courier_id);
        $montantAReverser = $this->calculerMontantAReverserParcel($parcel);

        return $this->effectuerTransaction(
            wallet: $wallet,
            type: 'debit_cash_remittance',
            montant: -$montantAReverser,
            description: "GoColis — Expédition #{$parcel->id} — Reversement espèces encaissées",
            parcelId: $parcel->id,
        );
    }

    public function calculerMontantAReverserOrder(Order $order): int
    {
        $totalClient = (int) round((float) $order->products_total) + (int) round((float) $order->delivery_fee);
        $partLivreur = (int) round((float) $order->courier_amount);

        return max(0, $totalClient - $partLivreur);
    }

    public function calculerMontantAReverserParcel(Parcel $parcel): int
    {
        $totalClient = (int) round((float) $parcel->shipping_fee) + (int) round((float) $parcel->insurance_amount);
        $partLivreur = (int) round((float) $parcel->courier_amount);

        return max(0, $totalClient - $partLivreur);
    }

    public function calculerEngagementsCashEnCours(int $courierId): int
    {
        $ordersTotal = Order::where('courier_id', $courierId)
            ->where('payment_method', 'cash')
            ->whereIn('status', [
                Order::STATUS_ACCEPTED,
                Order::STATUS_READY,
                Order::STATUS_PICKED_UP,
                Order::STATUS_IN_TRANSIT,
            ])
            ->get(['products_total', 'delivery_fee', 'courier_amount'])
            ->sum(fn (Order $order): int => $this->calculerMontantAReverserOrder($order));

        $parcelsTotal = Parcel::where('courier_id', $courierId)
            ->where('payment_method', 'cash')
            ->whereIn('status', [
                Parcel::STATUS_ACCEPTED,
                Parcel::STATUS_AT_SENDER,
                Parcel::STATUS_PICKED_UP,
                Parcel::STATUS_IN_TRANSIT,
                Parcel::STATUS_AT_RECIPIENT,
            ])
            ->get(['shipping_fee', 'insurance_amount', 'courier_amount'])
            ->sum(fn (Parcel $parcel): int => $this->calculerMontantAReverserParcel($parcel));

        return (int) $ordersTotal + (int) $parcelsTotal;
    }

    /**
     * Vérifie le solde minimum paramétré (CDC 7.4.1) et le solde disponible après engagements cash.
     *
     * @return array{ok: bool, message: string|null, solde: int, disponible: int}
     */
    public function verifierSoldeCashSuffisant(
        Courier $courier,
        int $montantAReverser,
        ?SettingsService $settingsService = null,
        bool $lockWallet = true,
    ): array {
        $settingsService ??= app(SettingsService::class);
        $rawMinimum = $settingsService->get('wallet.solde_minimum_cash', $courier->city_id ? (int) $courier->city_id : null);
        $soldeMinimum = (int) round(is_numeric($rawMinimum) ? (float) $rawMinimum : 10000.0);

        if ($lockWallet) {
            $wallet = $this->obtenirOuCreerWallet('courier', $courier->id);
            $wallet = Wallet::lockForUpdate()->findOrFail($wallet->id);
        } else {
            $wallet = Wallet::where('owner_type', 'courier')
                ->where('owner_id', $courier->id)
                ->first();
        }

        $solde = $wallet ? (int) round((float) $wallet->balance) : 0;
        $engagements = $this->calculerEngagementsCashEnCours($courier->id);
        $soldeRequis = $soldeMinimum + $engagements;
        $disponible = $solde - $engagements;

        if ($solde < $soldeRequis) {
            $detailMinimum = $engagements > 0
                ? "minimum {$soldeMinimum} F CFA + engagements en cours {$engagements} F CFA"
                : "minimum requis de {$soldeMinimum} F CFA";

            return [
                'ok' => false,
                'message' => "Solde portefeuille insuffisant pour une course en espèces ({$solde} F CFA < {$detailMinimum}).",
                'solde' => $solde,
                'disponible' => $disponible,
            ];
        }

        if ($disponible < $montantAReverser) {
            return [
                'ok' => false,
                'message' => "Solde disponible insuffisant pour couvrir le reversement espèces ({$montantAReverser} F CFA requis, {$disponible} F CFA disponible).",
                'solde' => $solde,
                'disponible' => $disponible,
            ];
        }

        return [
            'ok' => true,
            'message' => null,
            'solde' => $solde,
            'disponible' => $disponible,
        ];
    }

    /**
     * Effectue un remboursement validé par un admin (selon plafond autorisé).
     */
    public function effectuerRemboursement(
        string $ownerType,
        int $ownerId,
        float $montant,
        string $description,
        int $adminId,
        ?int $orderId = null,
        ?int $parcelId = null,
    ): WalletTransaction {
        $wallet = $this->obtenirOuCreerWallet($ownerType, $ownerId);

        return $this->effectuerTransaction(
            wallet: $wallet,
            type: 'refund',
            montant: (int) round($montant),
            description: $description,
            orderId: $orderId,
            parcelId: $parcelId,
            processedBy: $adminId,
        );
    }

    /**
     * Retourne le solde actuel d'un wallet.
     */
    public function solde(string $ownerType, int $ownerId): float
    {
        $wallet = Wallet::where('owner_type', $ownerType)
            ->where('owner_id', $ownerId)
            ->first();

        return $wallet ? (float) $wallet->balance : 0.0;
    }

    // ─── Méthodes privées ─────────────────────────────────────────────────────

    private function obtenirOuCreerWallet(string $ownerType, int $ownerId): Wallet
    {
        return Wallet::firstOrCreate(
            ['owner_type' => $ownerType, 'owner_id' => $ownerId],
            ['balance' => 0, 'total_earned' => 0, 'total_withdrawn' => 0],
        );
    }

    /**
     * Effectue la transaction de façon atomique avec SAVEPOINT pour l'idempotence en base.
     * Toute erreur provoque un rollback complet.
     */
    private function effectuerTransaction(
        Wallet $wallet,
        string $type,
        float|int $montant,
        string $description,
        ?int $orderId = null,
        ?int $parcelId = null,
        ?int $processedBy = null,
    ): WalletTransaction {
        $montantEntier = (int) round((float) $montant);

        try {
            return DB::transaction(function () use (
                $wallet, $type, $montantEntier, $description,
                $orderId, $parcelId, $processedBy
            ): WalletTransaction {
                // Verrouillage pessimiste pour éviter les race conditions
                $lockedWallet = Wallet::lockForUpdate()->findOrFail($wallet->id);

                if ($orderId !== null) {
                    $existante = WalletTransaction::where('wallet_id', $lockedWallet->id)
                        ->where('type', $type)
                        ->where('order_id', $orderId)
                        ->first();
                    if ($existante !== null) {
                        return $existante;
                    }
                }

                if ($parcelId !== null) {
                    $existante = WalletTransaction::where('wallet_id', $lockedWallet->id)
                        ->where('type', $type)
                        ->where('parcel_id', $parcelId)
                        ->first();
                    if ($existante !== null) {
                        return $existante;
                    }
                }

                $balanceAvant = (int) round((float) $lockedWallet->balance);
                $balanceApres = $balanceAvant + $montantEntier;

                if (in_array($type, ['withdrawal', 'adjustment'], true) && $balanceApres < 0) {
                    throw new \InvalidArgumentException('Solde insuffisant pour effectuer cette opération : le solde ne peut pas être négatif.');
                }

                if ($type === 'debit_cash_remittance' && $balanceApres < 0) {
                    throw new \InvalidArgumentException('Solde portefeuille livreur insuffisant pour effectuer le reversement en espèces.');
                }

                $estCreditRevenu = $montantEntier > 0 && ! in_array($type, ['withdrawal', 'debit_cash_remittance'], true);
                $estRetrait = $type === 'withdrawal';

                // Ligne de transaction immuable créée dans le même SAVEPOINT (contrainte unique DB en filet)
                $transaction = WalletTransaction::create([
                    'wallet_id' => $lockedWallet->id,
                    'type' => $type,
                    'amount' => $montantEntier,
                    'balance_before' => $balanceAvant,
                    'balance_after' => $balanceApres,
                    'reference' => 'TXN-'.strtoupper(Str::random(12)),
                    'description' => $description,
                    'status' => 'completed',
                    'order_id' => $orderId,
                    'parcel_id' => $parcelId,
                    'processed_by' => $processedBy,
                ]);

                // Mise à jour du wallet
                $lockedWallet->update([
                    'balance' => $balanceApres,
                    'total_earned' => $estCreditRevenu
                        ? (int) $lockedWallet->total_earned + $montantEntier
                        : (int) $lockedWallet->total_earned,
                    'total_withdrawn' => $estRetrait
                        ? (int) $lockedWallet->total_withdrawn + abs($montantEntier)
                        : (int) $lockedWallet->total_withdrawn,
                ]);

                return $transaction;
            });
        } catch (UniqueConstraintViolationException $e) {
            $existante = WalletTransaction::where('wallet_id', $wallet->id)
                ->where('type', $type)
                ->when($orderId !== null, fn ($q) => $q->where('order_id', $orderId))
                ->when($parcelId !== null, fn ($q) => $q->where('parcel_id', $parcelId))
                ->first();

            if ($existante !== null) {
                return $existante;
            }

            throw $e;
        }
    }
}
