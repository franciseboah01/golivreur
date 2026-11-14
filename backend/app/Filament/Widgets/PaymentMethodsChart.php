<?php

namespace App\Filament\Widgets;

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
        $methods = [
            'cash' => ['label' => 'Espèces à la livraison', 'color' => '#10b981'],
            'wallet' => ['label' => 'Portefeuille GoLivreur', 'color' => '#6366f1'],
            'wave' => ['label' => 'Wave Mobile Money', 'color' => '#06b6d4'],
            'orange_money' => ['label' => 'Orange Money', 'color' => '#f97316'],
            'mtn' => ['label' => 'MTN MoMo', 'color' => '#eab308'],
            'moov' => ['label' => 'Moov Money', 'color' => '#3b82f6'],
        ];

        $user = auth()->user();
        $isLocal = $user && ($user->is_local_admin || str_starts_with((string) $user->role, 'city_')) && $user->city_id;
        $cityId = $isLocal ? (int) $user->city_id : null;

        $labels = [];
        $data = [];
        $backgroundColors = [];

        foreach ($methods as $key => $meta) {
            $ordersQ = Order::where('payment_method', $key);
            $parcelsQ = Parcel::where('payment_method', $key);
            if ($cityId) {
                $ordersQ->where('city_id', $cityId);
                $parcelsQ->where('city_id', $cityId);
            }
            $countOrders = $ordersQ->count();
            $countParcels = $parcelsQ->count();
            $total = $countOrders + $countParcels;

            $labels[] = $meta['label'];
            $data[] = $total;
            $backgroundColors[] = $meta['color'];
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
