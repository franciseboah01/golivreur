<?php

namespace App\Filament\Resources\CityResource\Pages;

use App\Filament\Resources\CityResource;
use App\Filament\Support\FilamentCityScope;
use App\Models\City;
use App\Services\IvoryCoastGeocodingService;
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

        // Si l'admin n'a pas saisi ni pointé de GPS, tente un géocodage officiel automatique en Côte d'Ivoire.
        // En cas d'échec ou hors-ligne, laisse null (aucun utilisateur ne sera bloqué).
        $hasLat = isset($data['center_lat']) && $data['center_lat'] !== '';
        $hasLng = isset($data['center_lng']) && $data['center_lng'] !== '';
        if (! $hasLat && ! $hasLng && ! empty($data['name']) && is_string($data['name'])) {
            $found = app(IvoryCoastGeocodingService::class)->geocodeCity($data['name']);
            if ($found !== null) {
                $data['center_lat'] = $found['lat'];
                $data['center_lng'] = $found['lng'];
            }
        }

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
