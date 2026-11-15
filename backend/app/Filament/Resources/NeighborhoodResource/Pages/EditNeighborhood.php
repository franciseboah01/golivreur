<?php

namespace App\Filament\Resources\NeighborhoodResource\Pages;

use App\Filament\Resources\CityResource;
use App\Filament\Resources\NeighborhoodResource;
use App\Filament\Support\FilamentCityScope;
use App\Models\Neighborhood;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditNeighborhood extends EditRecord
{
    protected static string $resource = NeighborhoodResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var Neighborhood $record */
        $record = $this->getRecord();

        return FilamentCityScope::enforceCityOnSave($data, $record);
    }

    public function getBreadcrumbs(): array
    {
        return [
            CityResource::getUrl('index') => 'Villes & Zones d\'Opération',
            NeighborhoodResource::getUrl('index') => 'Quartiers & Zones',
            'Modifier',
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()->label('Supprimer'),
        ];
    }
}
