<?php

namespace App\Filament\Widgets;

use App\Models\{Courier, Merchant, Order, Parcel};
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StatsOverview extends BaseWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $today = now()->startOfDay();

        $ordersCount = Order::where('created_at', '>=', $today)->count();
        $parcelsCount = Parcel::where('created_at', '>=', $today)->count();
        $totalCourses = $ordersCount + $parcelsCount;

        $caOrders = (float) Order::where('created_at', '>=', $today)
            ->where('status', Order::STATUS_DELIVERED)
            ->sum('platform_commission');

        $caParcels = (float) Parcel::where('created_at', '>=', $today)
            ->where('status', Parcel::STATUS_DELIVERED)
            ->sum('platform_commission');

        $totalCA = $caOrders + $caParcels;

        $onlineCouriers = Courier::where('is_online', true)->where('is_active', true)->count();
        $openMerchants = Merchant::where('is_open', true)->where('is_active', true)->count();

        return [
            Stat::make('Courses du jour', $totalCourses)
                ->description("{$ordersCount} GoShop · {$parcelsCount} GoColis")
                ->descriptionIcon('heroicon-m-shopping-bag')
                ->color('primary'),

            Stat::make('Chiffre d\'Affaires Plateforme', number_format($totalCA, 0, ',', ' ') . ' F CFA')
                ->description('Commissions du jour')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('success'),

            Stat::make('Livreurs en ligne', $onlineCouriers)
                ->description('Disponibles à Daloa')
                ->descriptionIcon('heroicon-m-truck')
                ->color('warning'),

            Stat::make('Commerces ouverts', $openMerchants)
                ->description('Prêts à recevoir des commandes')
                ->descriptionIcon('heroicon-m-building-storefront')
                ->color('info'),
        ];
    }
}
