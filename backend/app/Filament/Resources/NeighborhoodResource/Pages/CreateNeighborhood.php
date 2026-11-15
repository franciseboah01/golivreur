<?php

namespace App\Filament\Resources\NeighborhoodResource\Pages;

use App\Filament\Resources\CityResource;
use App\Filament\Resources\NeighborhoodResource;
use App\Filament\Support\FilamentCityScope;
use Filament\Resources\Pages\CreateRecord;

class CreateNeighborhood extends CreateRecord
{
    protected static string $resource = NeighborhoodResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return FilamentCityScope::enforceCityOnCreate($data);
    }

    public function getBreadcrumbs(): array
    {
        return [
            CityResource::getUrl('index') => 'Villes & Zones d\'Opération',
            NeighborhoodResource::getUrl('index') => 'Quartiers & Zones',
            'Ajouter',
        ];
    }
}
