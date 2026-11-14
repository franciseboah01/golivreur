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

        $user = auth()->user();
        $isLocal = $user && ($user->is_local_admin || str_starts_with((string) $user->role, 'city_')) && $user->city_id;
        $cityId = $isLocal ? (int) $user->city_id : null;

        $ordersData = $days->map(function (Carbon $date) use ($cityId): int {
            $q = Order::whereDate('created_at', $date);
            if ($cityId) {
                $q->where('city_id', $cityId);
            }

            return $q->count();
        })->toArray();

        $parcelsData = $days->map(function (Carbon $date) use ($cityId): int {
            $q = Parcel::whereDate('created_at', $date);
            if ($cityId) {
                $q->where('city_id', $cityId);
            }

            return $q->count();
        })->toArray();

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
