<?php

namespace App\Filament\Widgets;

use App\Models\Order;
use App\Models\Parcel;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

class WeeklyOrdersChart extends ChartWidget
{
    protected static ?string $heading = 'Volume des Courses sur 7 Jours (GoShop vs GoColis)';

    protected static ?int $sort = 2;

    protected static ?string $maxHeight = '280px';

    protected int|string|array $columnSpan = 1;

    protected function getData(): array
    {
        $days = collect(range(6, 0))->map(fn (int $i): Carbon => now()->subDays($i)->startOfDay());

        $labels = $days->map(fn (Carbon $date): string => $date->translatedFormat('D d/m'))->toArray();

        $ordersData = $days->map(fn (Carbon $date): int => Order::whereDate('created_at', $date)->count())->toArray();

        $parcelsData = $days->map(fn (Carbon $date): int => Parcel::whereDate('created_at', $date)->count())->toArray();

        return [
            'datasets' => [
                [
                    'label' => 'Commandes GoShop',
                    'data' => $ordersData,
                    'backgroundColor' => '#f59e0b',
                    'borderColor' => '#d97706',
                ],
                [
                    'label' => 'Expéditions GoColis',
                    'data' => $parcelsData,
                    'backgroundColor' => '#3b82f6',
                    'borderColor' => '#2563eb',
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
