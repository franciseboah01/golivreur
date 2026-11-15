<?php

namespace App\Filament\Resources\CourierResource\Pages;

use App\Filament\Resources\CourierResource;
use App\Filament\Resources\VehicleTypeResource;
use App\Filament\Support\FilamentCityScope;
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
            Actions\Action::make('vehicle_types')
                ->label('Types de Véhicules')
                ->icon('heroicon-o-truck')
                ->color('gray')
                ->url(VehicleTypeResource::getUrl('index')),

            Actions\CreateAction::make()->label('Nouveau Livreur'),
        ];
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('Tous les Livreurs')
                ->badge(fn (): int => FilamentCityScope::scopedTabCount(CourierResource::class)),
            'online' => Tab::make('En Ligne')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_online', true))
                ->badge(fn (): int => FilamentCityScope::scopedTabCount(CourierResource::class, fn (Builder $q) => $q->where('is_online', true))),
            'active' => Tab::make('Validés & Actifs')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_active', true))
                ->badge(fn (): int => FilamentCityScope::scopedTabCount(CourierResource::class, fn (Builder $q) => $q->where('is_active', true))),
            'pending' => Tab::make('En Attente de Validation')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_active', false))
                ->badge(fn (): int => FilamentCityScope::scopedTabCount(CourierResource::class, fn (Builder $q) => $q->where('is_active', false))),
        ];
    }
}
