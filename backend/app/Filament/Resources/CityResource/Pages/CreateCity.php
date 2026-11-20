<?php

namespace App\Filament\Resources\CityResource\Pages;

use App\Filament\Resources\CityResource;
use App\Filament\Support\FilamentCityScope;
use App\Models\City;
use App\Services\SettingsService;
use Filament\Resources\Pages\CreateRecord;

class CreateCity extends CreateRecord
{
    protected static string $resource = CityResource::class;

    private ?string $pendingSupportPhone = null;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (FilamentCityScope::isLocal()) {
            abort(403, 'La création d\'une ville est réservée à la direction nationale.');
        }

        $this->pendingSupportPhone = isset($data['support_phone']) && is_string($data['support_phone'])
            ? trim($data['support_phone'])
            : null;
        unset($data['support_phone']);

        return $data;
    }

    protected function afterCreate(): void
    {
        /** @var City $city */
        $city = $this->getRecord();

        if ($this->pendingSupportPhone !== null && $this->pendingSupportPhone !== '') {
            app(SettingsService::class)->setCitySupportPhone(
                cityId: (int) $city->id,
                rawPhone: $this->pendingSupportPhone,
                userId: auth()->id() ? (int) auth()->id() : null,
            );
        }
    }
}
