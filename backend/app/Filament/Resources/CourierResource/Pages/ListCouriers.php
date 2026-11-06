<?php

namespace App\Filament\Resources\CourierResource\Pages;

use App\Filament\Resources\CourierResource;
use App\Models\Courier;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListCouriers extends ListRecords
{
    protected static string $resource = CourierResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Nouveau Livreur'),
        ];
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('Tous les Livreurs')
                ->badge(Courier::count()),
            'online' => Tab::make('En Ligne')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_online', true))
                ->badge(Courier::where('is_online', true)->count()),
            'active' => Tab::make('Validés & Actifs')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_active', true))
                ->badge(Courier::where('is_active', true)->count()),
            'pending' => Tab::make('En Attente de Validation')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_active', false))
                ->badge(Courier::where('is_active', false)->count()),
        ];
    }
}
