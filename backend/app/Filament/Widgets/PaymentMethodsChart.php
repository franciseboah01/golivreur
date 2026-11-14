<?php

namespace App\Filament\Widgets;

use App\Enums\PaymentMethod;
use App\Models\Order;
use App\Models\Parcel;
use Filament\Widgets\ChartWidget;

class PaymentMethodsChart extends ChartWidget
{
    protected static ?string $heading = 'Répartition des Moyens de Paiement';

    protected static ?int $sort = 3;

    protected static ?string $maxHeight = '280px';

    protected int|string|array $columnSpan = 1;

    protected function getData(): array
    {
        $user = auth()->user();
        $isLocal = $user && ($user->is_local_admin || str_starts_with((string) $user->role, 'city_')) && $user->city_id;
        $cityId = $isLocal ? (int) $user->city_id : null;

        $labels = [];
        $data = [];
        $backgroundColors = [];

        foreach (PaymentMethod::chartOrder() as $method) {
            $values = $method->valuesWithLegacyAliases();
            $ordersQ = Order::whereIn('payment_method', $values);
            $parcelsQ = Parcel::whereIn('payment_method', $values);
            if ($cityId) {
                $ordersQ->where('city_id', $cityId);
                $parcelsQ->where('city_id', $cityId);
            }
            $total = $ordersQ->count() + $parcelsQ->count();

            $labels[] = $method->label();
            $data[] = $total;
            $backgroundColors[] = $method->color();
        }

        return [
            'datasets' => [
                [
                    'label' => 'Transactions',
                    'data' => $data,
                    'backgroundColor' => $backgroundColors,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'doughnut';
    }
}
