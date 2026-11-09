<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Widgets\PendingCouriersWidget;
use App\Filament\Pages\Widgets\PendingMerchantsWidget;
use App\Models\Courier;
use App\Models\Merchant;
use Filament\Pages\Page;

/**
 * Page regroupant les commerces et livreurs en attente de validation / activation.
 * Permet aux Super Admins, City Agents, Support et Managers de valider les dossiers d'un clic.
 */
class PendingValidationsPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationGroup = 'Partenaires';

    protected static ?string $navigationLabel = 'Validations en attente';

    protected static ?string $title = 'Dossiers Partenaires en Attente de Validation';

    protected static string $view = 'filament.pages.pending-validations';

    protected static ?int $navigationSort = 0;

    public string $activeTab = 'all';

    public function mount(): void
    {
        $pendingCouriers = Courier::where('is_active', false)->count();
        $pendingMerchants = Merchant::where('is_active', false)->count();

        if ($pendingCouriers > 0 && $pendingMerchants === 0) {
            $this->activeTab = 'couriers';
        } elseif ($pendingMerchants > 0 && $pendingCouriers === 0) {
            $this->activeTab = 'merchants';
        } else {
            $this->activeTab = 'all';
        }
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();
        return $user !== null && $user->hasHabilitationTab('pending_validations');
    }

    public static function getNavigationBadge(): ?string
    {
        $count = Merchant::where('is_active', false)->count() + Courier::where('is_active', false)->count();
        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public function getPendingCouriersCountProperty(): int
    {
        return Courier::where('is_active', false)->count();
    }

    public function getPendingMerchantsCountProperty(): int
    {
        return Merchant::where('is_active', false)->count();
    }

    public function setTab(string $tab): void
    {
        $this->activeTab = $tab;
    }
}
