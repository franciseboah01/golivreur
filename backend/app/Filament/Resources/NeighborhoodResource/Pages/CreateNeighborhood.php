<?php

namespace App\Filament\Resources\NeighborhoodResource\Pages;

use App\Filament\Resources\CityResource;
use App\Filament\Resources\NeighborhoodResource;
use Filament\Resources\Pages\CreateRecord;

class CreateNeighborhood extends CreateRecord
{
    protected static string $resource = NeighborhoodResource::class;

    public function getBreadcrumbs(): array
    {
        return [
            CityResource::getUrl('index') => 'Villes & Zones d\'Opération',
            NeighborhoodResource::getUrl('index') => 'Quartiers & Zones',
            'Ajouter',
        ];
    }
}
