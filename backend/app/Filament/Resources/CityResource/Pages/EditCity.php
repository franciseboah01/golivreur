<?php

namespace App\Filament\Resources\CityResource\Pages;

use App\Filament\Resources\CityResource;
use App\Filament\Support\FilamentCityScope;
use App\Models\City;
use App\Services\SettingsService;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditCity extends EditRecord
{
    protected static string $resource = CityResource::class;

    private ?string $pendingSupportPhone = null;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->visible(fn (): bool => ! FilamentCityScope::isLocal()),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var City $record */
        $record = $this->getRecord();
        $data['support_phone'] = app(SettingsService::class)->getOwnCitySupportPhone((int) $record->id)
            ?? app(SettingsService::class)->getCitySupportPhone((int) $record->id);

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var City $record */
        $record = $this->getRecord();

        if (FilamentCityScope::isLocal()) {
            FilamentCityScope::authorizeCityId((int) $record->id);
        }

        $this->pendingSupportPhone = isset($data['support_phone']) && is_string($data['support_phone'])
            ? trim($data['support_phone'])
            : '';
        unset($data['support_phone']);

        return $data;
    }

    protected function afterSave(): void
    {
        /** @var City $city */
        $city = $this->getRecord();

        if ($this->pendingSupportPhone !== null) {
            app(SettingsService::class)->setCitySupportPhone(
                cityId: (int) $city->id,
                rawPhone: $this->pendingSupportPhone,
                userId: auth()->id() ? (int) auth()->id() : null,
            );
        }
    }
}
