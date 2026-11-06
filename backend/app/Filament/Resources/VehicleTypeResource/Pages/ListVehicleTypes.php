<?php

namespace App\Filament\Resources\VehicleTypeResource\Pages;

use App\Filament\Resources\VehicleTypeResource;
use App\Models\VehicleType;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListVehicleTypes extends ListRecords
{
    protected static string $resource = VehicleTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Nouveau Type de Véhicule'),
        ];
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('Tous les Véhicules')
                ->badge(VehicleType::count()),
            'active' => Tab::make('Actifs')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_active', true))
                ->badge(VehicleType::where('is_active', true)->count()),
            'inactive' => Tab::make('Inactifs')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_active', false))
                ->badge(VehicleType::where('is_active', false)->count()),
        ];
    }
}

