<?php

namespace App\Filament\Widgets;

use App\Filament\Support\FilamentCityScope;
use App\Models\City;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Parcel;
use App\Models\Wallet;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

class StatsOverview extends BaseWidget
{
    protected static ?int $sort = 1;

    protected static ?string $pollingInterval = '30s';

    protected function getStats(): array
    {
        if (! FilamentCityScope::hasValidLocalCity()) {
            return [
                Stat::make('Courses du jour', 0)
                    ->description('0 GoShop · 0 GoColis')
                    ->descriptionIcon('heroicon-m-shopping-bag')
                    ->color('primary'),

                Stat::make('Chiffre d\'Affaires Plateforme', '0 F CFA')
                    ->description('Commissions nettes encaissées')
                    ->descriptionIcon('heroicon-m-banknotes')
                    ->color('success'),

                Stat::make('Volume d\'Affaires Brut (GMV)', '0 F CFA')
                    ->description('Paniers marchands + livraisons')
                    ->descriptionIcon('heroicon-m-arrow-trending-up')
                    ->color('info'),

                Stat::make('Trésorerie Portefeuilles', '0 F CFA')
                    ->description('Solde total marchands & livreurs')
                    ->descriptionIcon('heroicon-m-wallet')
                    ->color('warning'),

                Stat::make('Livreurs en Ligne', '0 / 0')
                    ->description('Aucune ville rattachée')
                    ->descriptionIcon('heroicon-m-truck')
                    ->color('primary'),

                Stat::make('Commerces Ouverts', '0 / 0')
                    ->description('Prêts à recevoir des commandes')
                    ->descriptionIcon('heroicon-m-building-storefront')
                    ->color('info'),

                Stat::make('Mode Pluie', '0 / 0')
                    ->description('Aucune ville rattachée')
                    ->descriptionIcon('heroicon-m-cloud')
                    ->color('gray'),

                Stat::make('Taux de Livraison Réussie', '0 %')
                    ->description('0 livrées · 0 annulations')
                    ->descriptionIcon('heroicon-m-check-badge')
                    ->color('success'),

                Stat::make('Alertes Codes Bloqués', 0)
                    ->description('Aucun code bloqué')
                    ->descriptionIcon('heroicon-m-shield-exclamation')
                    ->color('gray'),
            ];
        }

        $today = now()->startOfDay();
        $isLocal = FilamentCityScope::isLocal();
        $cityId = FilamentCityScope::localCityId();
        $cityName = $isLocal ? (FilamentCityScope::scopedCityName() ?? 'votre ville') : null;

        // 1. Volumes du jour
        $ordersCount = Order::where('created_at', '>=', $today)
            ->when($cityId, fn ($q) => $q->where('city_id', $cityId))
            ->count();
        $parcelsCount = Parcel::where('created_at', '>=', $today)
            ->when($cityId, fn ($q) => $q->where('city_id', $cityId))
            ->count();
        $totalCourses = $ordersCount + $parcelsCount;

        // 2. Chiffre d'Affaires (Commissions plateforme du jour)
        $caOrders = (float) Order::where('created_at', '>=', $today)
            ->when($cityId, fn ($q) => $q->where('city_id', $cityId))
            ->where('status', Order::STATUS_DELIVERED)
            ->sum('platform_commission');

        $caParcels = (float) Parcel::where('created_at', '>=', $today)
            ->when($cityId, fn ($q) => $q->where('city_id', $cityId))
            ->where('status', Parcel::STATUS_DELIVERED)
            ->sum('platform_commission');

        $totalCA = $caOrders + $caParcels;

        // 3. Volume d'Affaires Brut (GMV)
        $ordersGMV = (float) Order::where('created_at', '>=', $today)
            ->when($cityId, fn ($q) => $q->where('city_id', $cityId))
            ->select(DB::raw('COALESCE(SUM(products_total + delivery_fee), 0) as total'))
            ->value('total');

        $parcelsGMV = (float) Parcel::where('created_at', '>=', $today)
            ->when($cityId, fn ($q) => $q->where('city_id', $cityId))
            ->select(DB::raw('COALESCE(SUM(shipping_fee + COALESCE(insurance_amount, 0)), 0) as total'))
            ->value('total');

        $totalGMV = $ordersGMV + $parcelsGMV;

        // 4. Trésorerie Portefeuilles
        $walletQuery = Wallet::query();
        if ($cityId) {
            $walletQuery->where(function ($w) use ($cityId) {
                $w->where(function ($sub) use ($cityId) {
                    $sub->where('owner_type', 'merchant')
                        ->whereIn('owner_id', Merchant::where('city_id', $cityId)->select('id'));
                })->orWhere(function ($sub) use ($cityId) {
                    $sub->where('owner_type', 'courier')
                        ->whereIn('owner_id', Courier::where('city_id', $cityId)->select('id'));
                });
            });
        }
        $totalWalletBalance = (float) $walletQuery->sum('balance');

        // 5. Flotte & Partenaires
        $courierQuery = Courier::where('is_active', true)->when($cityId, fn ($q) => $q->where('city_id', $cityId));
        $totalCouriers = (clone $courierQuery)->count();
        $onlineCouriers = (clone $courierQuery)->where('is_online', true)->count();

        $merchantQuery = Merchant::where('is_active', true)->when($cityId, fn ($q) => $q->where('city_id', $cityId));
        $totalMerchants = (clone $merchantQuery)->count();
        $openMerchants = (clone $merchantQuery)->where('is_open', true)->count();

        // 6. Mode Pluie & Zones d'opération
        $cityQuery = City::query()->when($cityId, fn ($q) => $q->where('id', $cityId));
        $totalCities = (clone $cityQuery)->count();
        $rainActiveCities = (clone $cityQuery)->where('rain_mode_active', true)->count();

        // 7. Taux de succès
        $deliveredCount = Order::where('status', Order::STATUS_DELIVERED)
            ->when($cityId, fn ($q) => $q->where('city_id', $cityId))
            ->count()
            + Parcel::where('status', Parcel::STATUS_DELIVERED)
                ->when($cityId, fn ($q) => $q->where('city_id', $cityId))
                ->count();
        $cancelledCount = Order::where('status', Order::STATUS_CANCELLED)
            ->when($cityId, fn ($q) => $q->where('city_id', $cityId))
            ->count()
            + Parcel::where('status', Parcel::STATUS_CANCELLED)
                ->when($cityId, fn ($q) => $q->where('city_id', $cityId))
                ->count();
        $totalEnded = $deliveredCount + $cancelledCount;
        $successRate = $totalEnded > 0 ? round(($deliveredCount / $totalEnded) * 100, 1) : 100.0;

        // 8. Alertes de sécurité (codes OTP bloqués)
        $blockedOrders = Order::where('confirmation_locked', true)
            ->when($cityId, fn ($q) => $q->where('city_id', $cityId))
            ->count();
        $blockedParcels = Parcel::where(function ($q) {
            $q->where('sender_code_locked', true)
                ->orWhere('recipient_code_locked', true);
        })
            ->when($cityId, fn ($q) => $q->where('city_id', $cityId))
            ->count();
        $totalBlocked = $blockedOrders + $blockedParcels;

        return [
            Stat::make('Courses du jour', $totalCourses)
                ->description("{$ordersCount} GoShop · {$parcelsCount} GoColis")
                ->descriptionIcon('heroicon-m-shopping-bag')
                ->color('primary'),

            Stat::make('Chiffre d\'Affaires Plateforme', number_format($totalCA, 0, ',', ' ').' F CFA')
                ->description('Commissions nettes encaissées')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('success'),

            Stat::make('Volume d\'Affaires Brut (GMV)', number_format($totalGMV, 0, ',', ' ').' F CFA')
                ->description('Paniers marchands + livraisons')
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('info'),

            Stat::make('Trésorerie Portefeuilles', number_format($totalWalletBalance, 0, ',', ' ').' F CFA')
                ->description('Solde total marchands & livreurs')
                ->descriptionIcon('heroicon-m-wallet')
                ->color('warning'),

            Stat::make('Livreurs en Ligne', "{$onlineCouriers} / {$totalCouriers}")
                ->description($cityName ? "Flotte active à {$cityName}" : 'Flotte active (Toutes les villes)')
                ->descriptionIcon('heroicon-m-truck')
                ->color('primary'),

            Stat::make('Commerces Ouverts', "{$openMerchants} / {$totalMerchants}")
                ->description('Prêts à recevoir des commandes')
                ->descriptionIcon('heroicon-m-building-storefront')
                ->color('info'),

            Stat::make('Mode Pluie', "{$rainActiveCities} / {$totalCities}")
                ->description($rainActiveCities > 0 ? "{$rainActiveCities} zone(s) sous majoration (+20%)" : ($cityName ? "Météo normale à {$cityName}" : 'Météo normale sur toutes les zones'))
                ->descriptionIcon('heroicon-m-cloud')
                ->color($rainActiveCities > 0 ? 'warning' : 'gray'),

            Stat::make('Taux de Livraison Réussie', "{$successRate} %")
                ->description("{$deliveredCount} livrées · {$cancelledCount} annulations")
                ->descriptionIcon('heroicon-m-check-badge')
                ->color('success'),

            Stat::make('Alertes Codes Bloqués', $totalBlocked)
                ->description($totalBlocked > 0 ? 'Déblocage support requis' : 'Aucun code bloqué')
                ->descriptionIcon('heroicon-m-shield-exclamation')
                ->color($totalBlocked > 0 ? 'danger' : 'gray'),
        ];
    }
}
