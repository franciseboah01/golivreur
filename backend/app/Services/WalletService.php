<?php

namespace App\Services;

use App\Filament\Support\FilamentCityScope;
use App\Models\ActivityLog;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\Notification as InAppNotification;
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
        $merchantUserId = $order->merchant()->value('user_id');
        $courierUserId = $order->courier()->value('user_id');
        if ($merchantUserId !== null && $courierUserId !== null && (int) $merchantUserId === (int) $courierUserId) {
            throw new AuthorizationException('Conflit financier : impossible de créditer une auto-livraison commerçant-livreur.');
        }

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
     * Crédite (dépôt espèces reçu en agence) ou débite (retrait/prélèvement espèces)
     * le portefeuille virtuel d'un Commerce ou d'un Livreur.
     *
     * - Vérifie strictement l'habilitation Finances et le périmètre ville du gestionnaire.
     * - Transaction atomique + garde-fou solde >= 0.
     * - Traçabilité immuable (wallet_transactions + activity_logs) + notification in-app immédiate.
     */
    public function effectuerOperationEspeces(
        string $ownerType,
        int $ownerId,
        string $direction,
        int $montant,
        string $motif,
        User $gestionnaire,
        ?string $ipAddress = null,
    ): WalletTransaction {
        $estAutorise = in_array($gestionnaire->role, [
            'super_admin',
            'dg_national',
            'national_finance',
            'city_manager',
            'city_finance',
        ], true) || (
            in_array($gestionnaire->role, User::ADMIN_ROLES, true)
            && $gestionnaire->hasHabilitationTab('finances')
        );

        if (! $estAutorise) {
            throw new AuthorizationException('Vous n\'êtes pas autorisé à effectuer une opération espèces sur un portefeuille.');
        }

        if (! in_array($ownerType, ['merchant', 'courier'], true)) {
            throw new \InvalidArgumentException('Type de bénéficiaire invalide (merchant ou courier attendu).');
        }

        if (! in_array($direction, ['credit', 'debit'], true)) {
            throw new \InvalidArgumentException('Sens d\'opération invalide (credit ou debit attendu).');
        }

        if ($montant <= 0) {
            throw new \InvalidArgumentException('Le montant de l\'opération espèces doit être strictement supérieur à 0 F CFA.');
        }

        $motifNettoye = trim($motif);
        if ($motifNettoye === '') {
            $motifNettoye = $direction === 'credit'
                ? 'Dépôt espèces au guichet'
                : 'Retrait espèces au guichet';
        }

        if ($ownerType === 'merchant') {
            $owner = Merchant::with(['user', 'city'])->findOrFail($ownerId);
            $ownerCityId = $owner->city_id !== null ? (int) $owner->city_id : null;
            $ownerLabel = $owner->name;
            $ownerUserId = (int) $owner->user_id;
            $ownerPhone = (string) ($owner->user->phone ?? '');
            $roleLabel = 'Commerce';
        } else {
            $owner = Courier::with(['user', 'city'])->findOrFail($ownerId);
            $ownerCityId = $owner->city_id !== null ? (int) $owner->city_id : null;
            $ownerLabel = (string) ($owner->user->name ?? "Livreur #{$owner->id}");
            $ownerUserId = (int) $owner->user_id;
            $ownerPhone = (string) ($owner->user->phone ?? '');
            $roleLabel = 'Livreur';
        }

        if (! FilamentCityScope::canAccessCityId($ownerCityId, $gestionnaire)) {
            throw new AuthorizationException('Action interdite : ce partenaire n\'appartient pas à votre ville d\'affectation.');
        }

        $wallet = $this->obtenirOuCreerWallet($ownerType, $ownerId);
        $type = $direction === 'credit' ? 'deposit' : 'withdrawal';
        $montantSigne = $direction === 'credit' ? $montant : -$montant;
        $prefix = $direction === 'credit' ? 'Dépôt espèces en agence' : 'Retrait espèces en agence';
        $description = "{$prefix} — {$motifNettoye} (par {$gestionnaire->name})";

        $transaction = $this->effectuerTransaction(
            wallet: $wallet,
            type: $type,
            montant: $montantSigne,
            description: $description,
            processedBy: $gestionnaire->id,
        );

        ActivityLog::create([
            'user_id' => $gestionnaire->id,
            'action' => $direction === 'credit' ? 'wallet_cash_deposit' : 'wallet_cash_withdrawal',
            'entity_type' => 'wallet',
            'entity_id' => $wallet->id,
            'properties' => [
                'wallet_id' => $wallet->id,
                'owner_type' => $ownerType,
                'owner_id' => $ownerId,
                'owner_name' => $ownerLabel,
                'owner_phone' => $ownerPhone,
                'city_id' => $ownerCityId,
                'transaction_id' => $transaction->id,
                'reference' => $transaction->reference,
                'direction' => $direction,
                'amount' => $montantSigne,
                'reason' => $motifNettoye,
                'balance_before' => (int) round((float) $transaction->balance_before),
                'balance_after' => (int) round((float) $transaction->balance_after),
                'processed_by_name' => $gestionnaire->name,
            ],
            'ip_address' => $ipAddress ?? request()?->ip(),
            'severity' => 'warning',
        ]);

        if ($ownerUserId > 0) {
            $montantFormate = number_format($montant, 0, ',', ' ');
            $soldeApresFormate = number_format((int) round((float) $transaction->balance_after), 0, ',', ' ');

            InAppNotification::create([
                'user_id' => $ownerUserId,
                'type' => $direction === 'credit' ? 'wallet_cash_deposit' : 'wallet_cash_withdrawal',
                'title' => $direction === 'credit'
                    ? "Dépôt espèces crédité (+{$montantFormate} F CFA)"
                    : "Retrait espèces débité (-{$montantFormate} F CFA)",
                'body' => $direction === 'credit'
                    ? "Votre portefeuille {$roleLabel} a été crédité de {$montantFormate} F CFA en espèces ({$motifNettoye}). Nouveau solde : {$soldeApresFormate} F CFA (Réf : {$transaction->reference})."
                    : "Votre portefeuille {$roleLabel} a été débité de {$montantFormate} F CFA en espèces ({$motifNettoye}). Nouveau solde : {$soldeApresFormate} F CFA (Réf : {$transaction->reference}).",
                'data' => [
                    'wallet_id' => $wallet->id,
                    'owner_type' => $ownerType,
                    'transaction_id' => $transaction->id,
                    'reference' => $transaction->reference,
                    'amount' => $montantSigne,
                    'balance_after' => (int) round((float) $transaction->balance_after),
                ],
                'is_read' => false,
            ]);
        }

        return $transaction;
    }

    /**
     * Recherche un Commerce et/ou un Livreur par numéro de téléphone ivoirien
     * pour le guichet Caisse Espèces (en respectant l'isolation ville).
     *
     * @return array{
     *     user: array{id: int, name: string, phone: string, city_name: string|null, is_active: bool},
     *     merchant: array<string, mixed>|null,
     *     courier: array<string, mixed>|null,
     *     out_of_scope_city: bool
     * }|null
     */
    public function rechercherPartenaireParTelephone(string $rawPhone, User $gestionnaire): ?array
    {
        $cleaned = preg_replace('/[\s\.\-\(\)]+/', '', trim($rawPhone)) ?? '';
        if (str_starts_with($cleaned, '+225')) {
            $phone = substr($cleaned, 4);
        } elseif (str_starts_with($cleaned, '00225')) {
            $phone = substr($cleaned, 5);
        } elseif (strlen($cleaned) === 13 && str_starts_with($cleaned, '225')) {
            $phone = substr($cleaned, 3);
        } else {
            $phone = $cleaned;
        }

        if ($phone === '') {
            return null;
        }

        $user = User::with(['city', 'merchant.city', 'courier.city'])
            ->whereNull('deleted_at')
            ->where(function ($q) use ($phone, $cleaned): void {
                $q->where('phone', $phone)
                    ->orWhere('phone', $cleaned)
                    ->orWhere('phone', '+225'.$phone);
            })
            ->first();

        if (! $user) {
            return null;
        }

        $merchantInfo = null;
        $courierInfo = null;
        $outOfScope = false;

        if ($user->merchant) {
            $mCityId = $user->merchant->city_id !== null ? (int) $user->merchant->city_id : null;
            if (FilamentCityScope::canAccessCityId($mCityId, $gestionnaire)) {
                $mWallet = Wallet::where('owner_type', 'merchant')
                    ->where('owner_id', $user->merchant->id)
                    ->first();
                $merchantInfo = [
                    'owner_type' => 'merchant',
                    'owner_id' => $user->merchant->id,
                    'name' => $user->merchant->name,
                    'city_name' => $user->merchant->city->name ?? $user->city->name ?? 'Non définie',
                    'is_active' => (bool) $user->merchant->is_active,
                    'balance' => $mWallet ? (int) round((float) $mWallet->balance) : 0,
                    'wallet_id' => $mWallet?->id,
                ];
            } else {
                $outOfScope = true;
            }
        }

        if ($user->courier) {
            $cCityId = $user->courier->city_id !== null ? (int) $user->courier->city_id : null;
            if (FilamentCityScope::canAccessCityId($cCityId, $gestionnaire)) {
                $cWallet = Wallet::where('owner_type', 'courier')
                    ->where('owner_id', $user->courier->id)
                    ->first();
                $courierInfo = [
                    'owner_type' => 'courier',
                    'owner_id' => $user->courier->id,
                    'name' => $user->name,
                    'vehicle_plate' => $user->courier->vehicle_plate ?: 'Sans plaque',
                    'vehicle_type' => $user->courier->vehicle_type ?: 'moto',
                    'city_name' => $user->courier->city->name ?? $user->city->name ?? 'Non définie',
                    'is_active' => (bool) $user->courier->is_active,
                    'balance' => $cWallet ? (int) round((float) $cWallet->balance) : 0,
                    'wallet_id' => $cWallet?->id,
                ];
            } else {
                $outOfScope = true;
            }
        }

        return [
            'user' => [
                'id' => $user->id,
                'name' => (string) $user->name,
                'phone' => (string) $user->phone,
                'city_name' => $user->city?->name,
                'is_active' => (bool) $user->is_active,
            ],
            'merchant' => $merchantInfo,
            'courier' => $courierInfo,
            'out_of_scope_city' => $outOfScope && $merchantInfo === null && $courierInfo === null,
        ];
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

                $estCreditRevenu = $montantEntier > 0 && ! in_array($type, ['withdrawal', 'debit_cash_remittance', 'deposit'], true);
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
