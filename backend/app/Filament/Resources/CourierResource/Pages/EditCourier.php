<?php

namespace App\Filament\Resources\CourierResource\Pages;

use App\Filament\Resources\CourierResource;
use App\Filament\Support\FilamentCityScope;
use App\Models\Courier;
use App\Services\CityGeolocationService;
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

    protected function afterSave(): void
    {
        /** @var Courier $record */
        $record = $this->getRecord();
        $adminId = auth()->id();

        if ($adminId !== null) {
            app(CityGeolocationService::class)->clearCourierReidentification(
                courier: $record,
                adminUserId: (int) $adminId,
                newCityId: $record->city_id ? (int) $record->city_id : null,
                ipAddress: request()->ip(),
            );
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
