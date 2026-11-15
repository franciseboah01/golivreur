<?php

namespace App\Filament\Resources\OrderResource\Pages;

use App\Filament\Resources\OrderResource;
use App\Filament\Support\FilamentCityScope;
use App\Models\Order;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Nouvelle Commande'),
        ];
    }

    public function getTabs(): array
    {
        $inProgressStatuses = [
            Order::STATUS_PENDING,
            Order::STATUS_ACCEPTED,
            Order::STATUS_WAITING,
            'preparing',
            Order::STATUS_READY,
            Order::STATUS_PICKED_UP,
            Order::STATUS_IN_TRANSIT,
            'delivering',
        ];

        return [
            'all' => Tab::make('Toutes les Commandes')
                ->badge(fn (): int => FilamentCityScope::scopedTabCount(OrderResource::class)),
            'in_progress' => Tab::make('En Cours')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', $inProgressStatuses))
                ->badge(fn (): int => FilamentCityScope::scopedTabCount(OrderResource::class, fn (Builder $q) => $q->whereIn('status', $inProgressStatuses))),
            'delivered' => Tab::make('Livrées')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', Order::STATUS_DELIVERED))
                ->badge(fn (): int => FilamentCityScope::scopedTabCount(OrderResource::class, fn (Builder $q) => $q->where('status', Order::STATUS_DELIVERED))),
            'locked' => Tab::make('Codes Bloqués')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('confirmation_locked', true))
                ->badge(fn (): int => FilamentCityScope::scopedTabCount(OrderResource::class, fn (Builder $q) => $q->where('confirmation_locked', true))),
            'cancelled' => Tab::make('Annulées')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', Order::STATUS_CANCELLED))
                ->badge(fn (): int => FilamentCityScope::scopedTabCount(OrderResource::class, fn (Builder $q) => $q->where('status', Order::STATUS_CANCELLED))),
        ];
    }
}
