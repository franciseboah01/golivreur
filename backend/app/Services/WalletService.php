<?php

namespace App\Services;

use App\Models\{Courier, Merchant, Order, Parcel, Wallet, WalletTransaction};
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
     * Crédite le livreur après une livraison GoShop réussie.
     * Montant = 80% des frais de livraison.
     */
    public function crediterLivreur(Order $order): WalletTransaction
    {
        $wallet = $this->obtenirOuCreerWallet('courier', $order->courier_id);

        return $this->effectuerTransaction(
            wallet: $wallet,
            type: 'credit_delivery',
            montant: $order->courier_amount,
            description: "GoShop — Commande #{$order->id} — Livraison créditée",
            orderId: $order->id,
        );
    }

    /**
     * Crédite le merchant après une livraison GoShop réussie.
     * Montant = prix produits - 15% commission.
     */
    public function crediterMerchant(Order $order): WalletTransaction
    {
        $wallet = $this->obtenirOuCreerWallet('merchant', $order->merchant_id);

        return $this->effectuerTransaction(
            wallet: $wallet,
            type: 'credit_delivery',
            montant: $order->merchant_amount,
            description: "GoShop — Commande #{$order->id} — Vente créditée",
            orderId: $order->id,
        );
    }

    /**
     * Crédite le livreur après une livraison GoColis réussie.
     * Montant = 75% des frais d'expédition.
     */
    public function crediterLivreurGoColis(Parcel $parcel): WalletTransaction
    {
        $wallet = $this->obtenirOuCreerWallet('courier', $parcel->courier_id);

        return $this->effectuerTransaction(
            wallet: $wallet,
            type: 'credit_shipping',
            montant: $parcel->courier_amount,
            description: "GoColis — Expédition #{$parcel->id} — Livraison créditée",
            parcelId: $parcel->id,
        );
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
            montant: $montant,
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
     * Effectue la transaction de façon atomique.
     * Toute erreur provoque un rollback complet.
     */
    private function effectuerTransaction(
        Wallet $wallet,
        string $type,
        float $montant,
        string $description,
        ?int $orderId = null,
        ?int $parcelId = null,
        ?int $processedBy = null,
    ): WalletTransaction {
        return DB::transaction(function () use (
            $wallet, $type, $montant, $description,
            $orderId, $parcelId, $processedBy
        ) {
            // Verrouillage pessimiste pour éviter les race conditions
            $wallet = Wallet::lockForUpdate()->find($wallet->id);

            $balanceAvant = $wallet->balance;
            $balanceApres = $balanceAvant + $montant;

            // Mise à jour du wallet
            $wallet->update([
                'balance'       => $balanceApres,
                'total_earned'  => $type !== 'withdrawal'
                    ? $wallet->total_earned + $montant
                    : $wallet->total_earned,
                'total_withdrawn' => $type === 'withdrawal'
                    ? $wallet->total_withdrawn + $montant
                    : $wallet->total_withdrawn,
            ]);

            // Ligne de transaction immuable
            return WalletTransaction::create([
                'wallet_id'      => $wallet->id,
                'type'           => $type,
                'amount'         => $montant,
                'balance_before' => $balanceAvant,
                'balance_after'  => $balanceApres,
                'reference'      => 'TXN-' . strtoupper(Str::random(12)),
                'description'    => $description,
                'status'         => 'completed',
                'order_id'       => $orderId,
                'parcel_id'      => $parcelId,
                'processed_by'   => $processedBy,
            ]);
        });
    }
}
