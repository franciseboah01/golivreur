<?php

namespace App\Filament\Resources\CityResource\Pages;

use App\Filament\Resources\CityResource;
use App\Filament\Support\FilamentCityScope;
use Filament\Resources\Pages\CreateRecord;

class CreateCity extends CreateRecord
{
    protected static string $resource = CityResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (FilamentCityScope::isLocal()) {
            abort(403, 'La création d\'une ville est réservée à la direction nationale.');
        }

        return $data;
    }
}
