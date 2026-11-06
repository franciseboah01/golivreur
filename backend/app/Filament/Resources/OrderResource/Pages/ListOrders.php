<?php

namespace App\Filament\Resources\OrderResource\Pages;

use App\Filament\Resources\OrderResource;
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
        return [
            'all' => Tab::make('Toutes les Commandes')
                ->badge(Order::count()),
            'in_progress' => Tab::make('En Cours')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', [
                    Order::STATUS_PENDING,
                    Order::STATUS_ACCEPTED,
                    'preparing',
                    Order::STATUS_READY,
                    Order::STATUS_PICKED_UP,
                    Order::STATUS_IN_TRANSIT,
                    'delivering',
                ]))
                ->badge(Order::whereIn('status', [
                    Order::STATUS_PENDING,
                    Order::STATUS_ACCEPTED,
                    'preparing',
                    Order::STATUS_READY,
                    Order::STATUS_PICKED_UP,
                    Order::STATUS_IN_TRANSIT,
                    'delivering',
                ])->count()),
            'delivered' => Tab::make('Livrées')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', Order::STATUS_DELIVERED))
                ->badge(Order::where('status', Order::STATUS_DELIVERED)->count()),
            'locked' => Tab::make('Codes Bloqués')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('confirmation_locked', true))
                ->badge(Order::where('confirmation_locked', true)->count()),
            'cancelled' => Tab::make('Annulées')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', Order::STATUS_CANCELLED))
                ->badge(Order::where('status', Order::STATUS_CANCELLED)->count()),
        ];
    }
}
