<?php

namespace App\Filament\Resources\CityResource\Pages;

use App\Filament\Resources\CityResource;
use App\Filament\Support\FilamentCityScope;
use App\Models\City;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditCity extends EditRecord
{
    protected static string $resource = CityResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->visible(fn (): bool => ! FilamentCityScope::isLocal()),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var City $record */
        $record = $this->getRecord();

        if (FilamentCityScope::isLocal()) {
            FilamentCityScope::authorizeCityId((int) $record->id);
        }

        return $data;
    }
}
