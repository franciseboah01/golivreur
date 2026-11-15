<?php

namespace App\Filament\Resources\CityResource\Pages;

use App\Filament\Resources\CityResource;
use App\Filament\Resources\NeighborhoodResource;
use App\Filament\Support\FilamentCityScope;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListCities extends ListRecords
{
    protected static string $resource = CityResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('neighborhoods')
                ->label('Quartiers & Zones')
                ->icon('heroicon-o-map')
                ->color('gray')
                ->url(NeighborhoodResource::getUrl('index')),

            Actions\CreateAction::make()
                ->label('Ajouter une Ville')
                ->visible(fn (): bool => ! FilamentCityScope::isLocal()),
        ];
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('Toutes les Villes')
                ->badge(fn (): int => FilamentCityScope::scopedTabCount(CityResource::class)),
            'active' => Tab::make('Opérationnelles & Actives')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_active', true))
                ->badge(fn (): int => FilamentCityScope::scopedTabCount(CityResource::class, fn (Builder $q) => $q->where('is_active', true))),
            'rain' => Tab::make('Mode Pluie Enclenché')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('rain_mode_active', true))
                ->badge(fn (): int => FilamentCityScope::scopedTabCount(CityResource::class, fn (Builder $q) => $q->where('rain_mode_active', true))),
            'emergency' => Tab::make('Mode Urgence Enclenché')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('emergency_mode_active', true))
                ->badge(fn (): int => FilamentCityScope::scopedTabCount(CityResource::class, fn (Builder $q) => $q->where('emergency_mode_active', true))),
            'inactive' => Tab::make('Inactives / Suspendues')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_active', false))
                ->badge(fn (): int => FilamentCityScope::scopedTabCount(CityResource::class, fn (Builder $q) => $q->where('is_active', false))),
        ];
    }
}
