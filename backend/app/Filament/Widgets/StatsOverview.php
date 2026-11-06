<?php

namespace App\Filament\Widgets;

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
        $today = now()->startOfDay();

        // 1. Volumes du jour
        $ordersCount = Order::where('created_at', '>=', $today)->count();
        $parcelsCount = Parcel::where('created_at', '>=', $today)->count();
        $totalCourses = $ordersCount + $parcelsCount;

        // 2. Chiffre d'Affaires (Commissions plateforme du jour)
        $caOrders = (float) Order::where('created_at', '>=', $today)
            ->where('status', Order::STATUS_DELIVERED)
            ->sum('platform_commission');

        $caParcels = (float) Parcel::where('created_at', '>=', $today)
            ->where('status', Parcel::STATUS_DELIVERED)
            ->sum('platform_commission');

        $totalCA = $caOrders + $caParcels;

        // 3. Volume d'Affaires Brut (GMV)
        $ordersGMV = (float) Order::where('created_at', '>=', $today)
            ->select(DB::raw('COALESCE(SUM(products_total + delivery_fee), 0) as total'))
            ->value('total');

        $parcelsGMV = (float) Parcel::where('created_at', '>=', $today)
            ->select(DB::raw('COALESCE(SUM(shipping_fee + COALESCE(insurance_amount, 0)), 0) as total'))
            ->value('total');

        $totalGMV = $ordersGMV + $parcelsGMV;

        // 4. Trésorerie Portefeuilles
        $totalWalletBalance = (float) Wallet::sum('balance');

        // 5. Flotte & Partenaires
        $totalCouriers = Courier::where('is_active', true)->count();
        $onlineCouriers = Courier::where('is_online', true)->where('is_active', true)->count();

        $totalMerchants = Merchant::where('is_active', true)->count();
        $openMerchants = Merchant::where('is_open', true)->where('is_active', true)->count();

        // 6. Mode Pluie & Zones d'opération
        $totalCities = City::count();
        $rainActiveCities = City::where('rain_mode_active', true)->count();

        // 7. Taux de succès
        $deliveredCount = Order::where('status', Order::STATUS_DELIVERED)->count()
            + Parcel::where('status', Parcel::STATUS_DELIVERED)->count();
        $cancelledCount = Order::where('status', Order::STATUS_CANCELLED)->count()
            + Parcel::where('status', Parcel::STATUS_CANCELLED)->count();
        $totalEnded = $deliveredCount + $cancelledCount;
        $successRate = $totalEnded > 0 ? round(($deliveredCount / $totalEnded) * 100, 1) : 100.0;

        // 8. Alertes de sécurité (codes OTP bloqués)
        $blockedOrders = Order::where('confirmation_locked', true)->count();
        $blockedParcels = Parcel::where('sender_code_locked', true)
            ->orWhere('recipient_code_locked', true)
            ->count();
        $totalBlocked = $blockedOrders + $blockedParcels;

        return [
            Stat::make('Courses du jour', $totalCourses)
                ->description("{$ordersCount} GoShop · {$parcelsCount} GoColis")
                ->descriptionIcon('heroicon-m-shopping-bag')
                ->color('primary'),

            Stat::make('Chiffre d\'Affaires Plateforme', number_format($totalCA, 0, ',', ' ') . ' F CFA')
                ->description('Commissions nettes encaissées')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('success'),

            Stat::make('Volume d\'Affaires Brut (GMV)', number_format($totalGMV, 0, ',', ' ') . ' F CFA')
                ->description('Paniers marchands + livraisons')
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('info'),

            Stat::make('Trésorerie Portefeuilles', number_format($totalWalletBalance, 0, ',', ' ') . ' F CFA')
                ->description('Solde total marchands & livreurs')
                ->descriptionIcon('heroicon-m-wallet')
                ->color('warning'),

            Stat::make('Livreurs en Ligne', "{$onlineCouriers} / {$totalCouriers}")
                ->description('Flotte active à Daloa')
                ->descriptionIcon('heroicon-m-truck')
                ->color('primary'),

            Stat::make('Commerces Ouverts', "{$openMerchants} / {$totalMerchants}")
                ->description('Prêts à recevoir des commandes')
                ->descriptionIcon('heroicon-m-building-storefront')
                ->color('info'),

            Stat::make('Mode Pluie', "{$rainActiveCities} / {$totalCities}")
                ->description($rainActiveCities > 0 ? "{$rainActiveCities} zone(s) sous majoration (+20%)" : 'Météo normale sur toutes les zones')
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
