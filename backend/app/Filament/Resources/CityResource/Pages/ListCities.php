<?php

namespace App\Filament\Resources\CityResource\Pages;

use App\Filament\Resources\CityResource;
use App\Filament\Resources\NeighborhoodResource;
use App\Models\City;
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

            Actions\CreateAction::make()->label('Ajouter une Ville'),
        ];
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('Toutes les Villes')
                ->badge(City::count()),
            'active' => Tab::make('Opérationnelles & Actives')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_active', true))
                ->badge(City::where('is_active', true)->count()),
            'rain' => Tab::make('Mode Pluie Enclenché')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('rain_mode_active', true))
                ->badge(City::where('rain_mode_active', true)->count()),
            'emergency' => Tab::make('Mode Urgence Enclenché')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('emergency_mode_active', true))
                ->badge(City::where('emergency_mode_active', true)->count()),
            'inactive' => Tab::make('Inactives / Suspendues')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_active', false))
                ->badge(City::where('is_active', false)->count()),
        ];
    }
}
