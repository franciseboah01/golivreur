<?php

namespace App\Filament\Pages;

use App\Filament\Resources\WalletTransactionResource;
use App\Filament\Support\FilamentCityScope;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\WalletService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Collection;

/**
 * Guichet Caisse Espèces (Finances) :
 * Permet au gestionnaire de rechercher un Commerce ou un Livreur par numéro de téléphone,
 * de consulter son solde en temps réel et de créditer (dépôt espèces) ou débiter (retrait espèces)
 * son portefeuille virtuel avec visibilité immédiate dans GL Business et GL Driver.
 */
class CashWalletOperationsPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-currency-dollar';

    protected static ?string $navigationGroup = 'Finances';

    protected static ?string $navigationLabel = 'Caisse Espèces (Crédit / Débit)';

    protected static ?string $title = 'Caisse Espèces — Créditer / Débiter un Commerce ou un Livreur';

    protected static string $view = 'filament.pages.cash-wallet-operations-page';

    protected static ?int $navigationSort = 8;

    public string $searchPhone = '';

    public ?string $selectedOwnerType = null; // 'merchant' | 'courier'

    public ?int $selectedOwnerId = null;

    public string $direction = 'credit'; // 'credit' | 'debit'

    public string $amount = '';

    public string $reason = '';

    public ?string $lookupNotice = null;

    /** @var array<string, mixed>|null */
    public ?array $partnerData = null;

    /** @var array<string, mixed>|null */
    public ?array $lastReceipt = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return false;
        }

        if (! FilamentCityScope::hasValidLocalCity($user)) {
            return false;
        }

        return in_array($user->role, [
            'super_admin',
            'dg_national',
            'national_finance',
            'city_manager',
            'city_finance',
        ], true) || (
            in_array($user->role, User::ADMIN_ROLES, true)
            && $user->hasHabilitationTab('finances')
        );
    }

    public function updatedSearchPhone(string $value): void
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if (strlen($digits) >= 10) {
            $this->rechercherPartenaire();
        }
    }

    public function rechercherPartenaire(): void
    {
        $manager = auth()->user();
        if (! $manager instanceof User) {
            return;
        }

        $this->lastReceipt = null;

        $result = app(WalletService::class)->rechercherPartenaireParTelephone($this->searchPhone, $manager);

        if ($result === null) {
            $this->partnerData = null;
            $this->selectedOwnerType = null;
            $this->selectedOwnerId = null;
            $this->lookupNotice = 'Aucun compte trouvé pour ce numéro de téléphone.';

            return;
        }

        if ($result['out_of_scope_city']) {
            $this->partnerData = null;
            $this->selectedOwnerType = null;
            $this->selectedOwnerId = null;
            $this->lookupNotice = 'Ce partenaire n\'appartient pas à votre ville d\'affectation.';

            return;
        }

        if ($result['merchant'] === null && $result['courier'] === null) {
            $this->partnerData = null;
            $this->selectedOwnerType = null;
            $this->selectedOwnerId = null;
            $this->lookupNotice = 'Ce numéro appartient uniquement à un compte Client. Seuls les Commerces et Livreurs possèdent un portefeuille virtuel.';

            return;
        }

        $this->partnerData = $result;
        $this->lookupNotice = null;

        // Sélection automatique si un seul portefeuille existe (Commerce ou Livreur)
        if ($result['merchant'] !== null && $result['courier'] === null) {
            $this->selectedOwnerType = 'merchant';
            $this->selectedOwnerId = (int) $result['merchant']['owner_id'];
        } elseif ($result['courier'] !== null && $result['merchant'] === null) {
            $this->selectedOwnerType = 'courier';
            $this->selectedOwnerId = (int) $result['courier']['owner_id'];
        } else {
            // Multi-rôle : préserver le choix courant s'il appartient à ce compte, sinon sélectionner commerce par défaut
            if ($this->selectedOwnerType === 'courier' && $result['courier'] !== null) {
                $this->selectedOwnerId = (int) $result['courier']['owner_id'];
            } else {
                $this->selectedOwnerType = 'merchant';
                $this->selectedOwnerId = (int) $result['merchant']['owner_id'];
            }
        }
    }

    public function selectionnerPortefeuille(string $ownerType): void
    {
        if (! is_array($this->partnerData)) {
            return;
        }

        if ($ownerType === 'merchant' && is_array($this->partnerData['merchant'] ?? null)) {
            $this->selectedOwnerType = 'merchant';
            $this->selectedOwnerId = (int) $this->partnerData['merchant']['owner_id'];
        } elseif ($ownerType === 'courier' && is_array($this->partnerData['courier'] ?? null)) {
            $this->selectedOwnerType = 'courier';
            $this->selectedOwnerId = (int) $this->partnerData['courier']['owner_id'];
        }
    }

    public function choisirMontantRapide(int $value): void
    {
        $this->amount = (string) $value;
    }

    public function executerOperationEspeces(): void
    {
        $manager = auth()->user();
        if (! $manager instanceof User) {
            return;
        }

        if (! in_array($this->selectedOwnerType, ['merchant', 'courier'], true) || ! $this->selectedOwnerId) {
            Notification::make()
                ->title('Aucun portefeuille sélectionné')
                ->body('Recherchez d\'abord un Commerce ou un Livreur via son numéro de téléphone.')
                ->warning()
                ->send();

            return;
        }

        $montant = (int) preg_replace('/\D+/', '', $this->amount);
        if ($montant <= 0) {
            Notification::make()
                ->title('Montant invalide')
                ->body('Veuillez saisir un montant strictement supérieur à 0 F CFA.')
                ->danger()
                ->send();

            return;
        }

        $motif = trim($this->reason);
        if ($motif === '') {
            $motif = $this->direction === 'credit'
                ? 'Dépôt espèces reçu au guichet'
                : 'Retrait espèces remis au guichet';
        }

        try {
            $tx = app(WalletService::class)->effectuerOperationEspeces(
                ownerType: $this->selectedOwnerType,
                ownerId: $this->selectedOwnerId,
                direction: $this->direction,
                montant: $montant,
                motif: $motif,
                gestionnaire: $manager,
                ipAddress: request()->ip(),
            );

            $targetInfo = $this->selectedOwnerType === 'merchant'
                ? ($this->partnerData['merchant'] ?? [])
                : ($this->partnerData['courier'] ?? []);

            $partnerUser = is_array($this->partnerData['user'] ?? null)
                ? $this->partnerData['user']
                : [];
            $partnerPhone = (string) ($partnerUser['phone'] ?? $this->searchPhone);

            $this->lastReceipt = [
                'reference' => $tx->reference,
                'direction' => $this->direction,
                'owner_type' => $this->selectedOwnerType,
                'owner_name' => (string) ($targetInfo['name'] ?? ''),
                'owner_phone' => $partnerPhone,
                'amount' => $montant,
                'balance_before' => (int) round((float) $tx->balance_before),
                'balance_after' => (int) round((float) $tx->balance_after),
                'reason' => $motif,
                'operator' => $manager->name,
                'date' => $tx->created_at?->format('d/m/Y H:i:s') ?? now()->format('d/m/Y H:i:s'),
            ];

            $labelOp = $this->direction === 'credit' ? 'Crédit espèces validé' : 'Débit espèces validé';
            $montantFmt = number_format($montant, 0, ',', ' ');
            $soldeFmt = number_format((int) round((float) $tx->balance_after), 0, ',', ' ');

            Notification::make()
                ->title("{$labelOp} ({$montantFmt} F CFA)")
                ->body("Nouveau solde : {$soldeFmt} F CFA • Réf : {$tx->reference}. Visible immédiatement dans l'application mobile.")
                ->success()
                ->send();

            // Réinitialiser les champs de saisie et rafraîchir le solde en temps réel
            $this->amount = '';
            $this->reason = '';

            $refreshed = app(WalletService::class)->rechercherPartenaireParTelephone(
                $partnerPhone,
                $manager,
            );
            if ($refreshed !== null) {
                $this->partnerData = $refreshed;
            }
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Opération refusée')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * Retourne les 15 dernières opérations espèces (dépôts / retraits) dans le périmètre du gestionnaire.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getRecentCashOperationsProperty(): array
    {
        /** @var Collection<int, WalletTransaction> $rows */
        $rows = WalletTransactionResource::getEloquentQuery()
            ->with('wallet')
            ->whereIn('type', ['deposit', 'withdrawal'])
            ->latest('id')
            ->limit(15)
            ->get();

        $merchantIds = $rows
            ->filter(fn (WalletTransaction $t): bool => $t->wallet?->owner_type === 'merchant')
            ->map(fn (WalletTransaction $t): int => (int) ($t->wallet->owner_id ?? 0))
            ->unique()
            ->values();

        $courierIds = $rows
            ->filter(fn (WalletTransaction $t): bool => $t->wallet?->owner_type === 'courier')
            ->map(fn (WalletTransaction $t): int => (int) ($t->wallet->owner_id ?? 0))
            ->unique()
            ->values();

        $merchants = $merchantIds->isNotEmpty()
            ? Merchant::with('user:id,name,phone')->whereIn('id', $merchantIds)->get()->keyBy('id')
            : collect();
        $couriers = $courierIds->isNotEmpty()
            ? Courier::with('user:id,name,phone')->whereIn('id', $courierIds)->get()->keyBy('id')
            : collect();

        $operatorIds = $rows->pluck('processed_by')->filter()->unique()->values();
        $operators = $operatorIds->isNotEmpty()
            ? User::whereIn('id', $operatorIds)->pluck('name', 'id')
            : collect();

        return $rows->map(function (WalletTransaction $tx) use ($merchants, $couriers, $operators): array {
            $ownerType = (string) ($tx->wallet->owner_type ?? '');
            $ownerId = (int) ($tx->wallet->owner_id ?? 0);

            if ($ownerType === 'merchant') {
                /** @var Merchant|null $m */
                $m = $merchants->get($ownerId);
                $partnerLabel = $m ? "🏪 {$m->name}" : "🏪 Commerce #{$ownerId}";
                $partnerPhone = $m?->user->phone ?? '-';
            } elseif ($ownerType === 'courier') {
                /** @var Courier|null $c */
                $c = $couriers->get($ownerId);
                $partnerLabel = $c ? '🏍️ '.($c->user->name ?? "Livreur #{$ownerId}") : "🏍️ Livreur #{$ownerId}";
                $partnerPhone = $c?->user->phone ?? '-';
            } else {
                $partnerLabel = "Portefeuille #{$tx->wallet_id}";
                $partnerPhone = '-';
            }

            return [
                'reference' => $tx->reference,
                'partner_label' => $partnerLabel,
                'partner_phone' => $partnerPhone,
                'type' => $tx->type,
                'amount' => (int) round((float) $tx->amount),
                'balance_after' => (int) round((float) $tx->balance_after),
                'description' => (string) ($tx->description ?? ''),
                'operator' => $tx->processed_by ? (string) ($operators->get($tx->processed_by) ?? "#{$tx->processed_by}") : 'Système',
                'date' => $tx->created_at?->format('d/m/Y H:i') ?? '',
            ];
        })->all();
    }

    public function getSelectedBalanceProperty(): int
    {
        if (! is_array($this->partnerData) || $this->selectedOwnerType === null) {
            return 0;
        }

        $slot = $this->selectedOwnerType === 'merchant'
            ? ($this->partnerData['merchant'] ?? null)
            : ($this->partnerData['courier'] ?? null);

        return is_array($slot) ? (int) ($slot['balance'] ?? 0) : 0;
    }

    public function getParsedAmountProperty(): int
    {
        return (int) preg_replace('/\D+/', '', $this->amount);
    }

    public function getProjectedBalanceProperty(): int
    {
        $parsedAmount = $this->getParsedAmountProperty();
        $delta = $this->direction === 'credit' ? $parsedAmount : -$parsedAmount;

        return $this->getSelectedBalanceProperty() + $delta;
    }

    public function getTransactionsListUrlProperty(): string
    {
        return WalletTransactionResource::getUrl('index');
    }
}
