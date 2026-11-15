<?php

namespace App\Filament\Widgets;

use App\Filament\Support\FilamentCityScope;
use App\Models\City;
use App\Models\Order;
use App\Models\Parcel;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class EmergencyStatsWidget extends BaseWidget
{
    protected static ?string $pollingInterval = '15s';

    protected function getStats(): array
    {
        if (! FilamentCityScope::hasValidLocalCity()) {
            return [
                Stat::make('Mode Pluie Météo (+20%)', '0 / 0 villes')
                    ->description('Aucune ville rattachée')
                    ->descriptionIcon('heroicon-m-cloud')
                    ->color('gray'),

                Stat::make('Mode Urgence & Crises', '0 zone(s) suspendue(s)')
                    ->description('Aucune ville rattachée')
                    ->descriptionIcon('heroicon-m-exclamation-triangle')
                    ->color('gray'),

                Stat::make('Codes OTP Bloqués', '0 course(s)')
                    ->description('0 GoShop · 0 GoColis')
                    ->descriptionIcon('heroicon-m-lock-closed')
                    ->color('gray'),
            ];
        }

        $cityQuery = FilamentCityScope::scopeByCity(City::query(), 'id');
        $totalCities = (clone $cityQuery)->count();
        $rainActive = (clone $cityQuery)->where('rain_mode_active', true)->count();
        $emergencyActive = (clone $cityQuery)->where('emergency_mode_active', true)->count();

        $lockedOrders = FilamentCityScope::scopeByCity(Order::where('confirmation_locked', true))->count();
        $lockedParcels = FilamentCityScope::scopeByCity(
            Parcel::where(fn ($q) => $q->where('sender_code_locked', true)->orWhere('recipient_code_locked', true))
        )->count();
        $totalLocked = $lockedOrders + $lockedParcels;

        return [
            Stat::make('Mode Pluie Météo (+20%)', "{$rainActive} / {$totalCities} villes")
                ->description($rainActive > 0 ? "Majoration tarifaire en cours sur {$rainActive} zone(s)" : 'Conditions météo standard sur tout le réseau')
                ->descriptionIcon('heroicon-m-cloud')
                ->color($rainActive > 0 ? 'warning' : 'gray'),

            Stat::make('Mode Urgence & Crises', "{$emergencyActive} zone(s) suspendue(s)")
                ->description($emergencyActive > 0 ? 'Arrêt d\'urgence en cours sur certaines villes' : 'Opérations normales sur toutes les zones')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($emergencyActive > 0 ? 'danger' : 'success'),

            Stat::make('Codes OTP Bloqués', "{$totalLocked} course(s)")
                ->description("{$lockedOrders} GoShop · {$lockedParcels} GoColis")
                ->descriptionIcon('heroicon-m-lock-closed')
                ->color($totalLocked > 0 ? 'danger' : 'gray'),
        ];
    }
}
