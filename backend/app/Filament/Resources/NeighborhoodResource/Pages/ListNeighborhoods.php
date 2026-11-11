<?php

namespace App\Filament\Resources\NeighborhoodResource\Pages;

use App\Filament\Resources\CityResource;
use App\Filament\Resources\NeighborhoodResource;
use App\Models\Neighborhood;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListNeighborhoods extends ListRecords
{
    protected static string $resource = NeighborhoodResource::class;

    public function getBreadcrumbs(): array
    {
        return [
            CityResource::getUrl('index') => 'Villes & Zones d\'Opération',
            'Quartiers & Zones',
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('back_to_cities')
                ->label('Retour aux Villes')
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(CityResource::getUrl('index')),

            Actions\CreateAction::make()->label('Ajouter un Quartier'),
        ];
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('Tous les Quartiers')
                ->badge(Neighborhood::count()),
            'active' => Tab::make('Actifs')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_active', true))
                ->badge(Neighborhood::where('is_active', true)->count()),
            'inactive' => Tab::make('Inactifs')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_active', false))
                ->badge(Neighborhood::where('is_active', false)->count()),
        ];
    }
}
