<?php

namespace App\Filament\Resources\CourierResource\Pages;

use App\Filament\Resources\CourierResource;
use App\Filament\Support\FilamentCityScope;
use Filament\Resources\Pages\CreateRecord;

class CreateCourier extends CreateRecord
{
    protected static string $resource = CourierResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return FilamentCityScope::enforceCityOnCreate($data);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
