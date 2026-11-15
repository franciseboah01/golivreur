<?php

namespace App\Filament\Resources\ParcelResource\Pages;

use App\Filament\Resources\ParcelResource;
use App\Filament\Support\FilamentCityScope;
use App\Models\Parcel;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditParcel extends EditRecord
{
    protected static string $resource = ParcelResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var Parcel $record */
        $record = $this->getRecord();
        $data['declared_value'] = (int) ($data['declared_value'] ?? 0);
        $data['insurance_amount'] = (int) ($data['insurance_amount'] ?? 0);

        return FilamentCityScope::enforceCityOnSave($data, $record);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
