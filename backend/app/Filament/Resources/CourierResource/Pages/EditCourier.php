<?php

namespace App\Filament\Resources\CourierResource\Pages;

use App\Filament\Resources\CourierResource;
use App\Filament\Support\FilamentCityScope;
use App\Models\Courier;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditCourier extends EditRecord
{
    protected static string $resource = CourierResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var Courier $record */
        $record = $this->getRecord();

        $data = FilamentCityScope::enforceCityOnSave($data, $record);

        if (! empty($data['is_active']) && $record->activated_at === null) {
            $data['activated_at'] = now();
            $data['activated_by'] = auth()->id();
        }

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
