<?php

namespace App\Filament\Widgets;

use App\Filament\Support\FilamentCityScope;
use App\Models\Order;
use App\Models\Parcel;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

class WeeklyRevenueChart extends ChartWidget
{
    protected static ?string $heading = 'Évolution des Commissions Plateforme (F CFA sur 7 Jours)';

    protected static ?int $sort = 4;

    protected static ?string $maxHeight = '280px';

    protected int|string|array $columnSpan = 1;

    protected function getData(): array
    {
        $days = collect(range(6, 0))->map(fn (int $i): Carbon => now()->subDays($i)->startOfDay());

        $labels = $days->map(fn (Carbon $date): string => $date->translatedFormat('D d/m'))->toArray();

        if (! FilamentCityScope::hasValidLocalCity()) {
            return [
                'datasets' => [
                    [
                        'label' => 'Commissions Net Plateforme (F CFA)',
                        'data' => array_fill(0, count($labels), 0.0),
                        'fill' => 'start',
                        'backgroundColor' => 'rgba(16, 185, 129, 0.15)',
                        'borderColor' => '#10b981',
                        'tension' => 0.3,
                    ],
                ],
                'labels' => $labels,
            ];
        }

        $revenueData = $days->map(function (Carbon $date): float {
            $orderCA = (float) FilamentCityScope::scopeByCity(
                Order::whereDate('created_at', $date)->where('status', Order::STATUS_DELIVERED)
            )->sum('platform_commission');

            $parcelCA = (float) FilamentCityScope::scopeByCity(
                Parcel::whereDate('created_at', $date)->where('status', Parcel::STATUS_DELIVERED)
            )->sum('platform_commission');

            return $orderCA + $parcelCA;
        })->toArray();

        return [
            'datasets' => [
                [
                    'label' => 'Commissions Net Plateforme (F CFA)',
                    'data' => $revenueData,
                    'fill' => 'start',
                    'backgroundColor' => 'rgba(16, 185, 129, 0.15)',
                    'borderColor' => '#10b981',
                    'tension' => 0.3,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
