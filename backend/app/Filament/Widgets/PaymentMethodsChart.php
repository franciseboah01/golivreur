<?php

namespace App\Filament\Widgets;

use App\Enums\PaymentMethod;
use App\Filament\Support\FilamentCityScope;
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
        $hasValidCity = FilamentCityScope::hasValidLocalCity();

        $labels = [];
        $data = [];
        $backgroundColors = [];

        foreach (PaymentMethod::chartOrder() as $method) {
            $values = $method->valuesWithLegacyAliases();
            $total = $hasValidCity
                ? FilamentCityScope::scopeByCity(Order::whereIn('payment_method', $values))->count()
                    + FilamentCityScope::scopeByCity(Parcel::whereIn('payment_method', $values))->count()
                : 0;

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
