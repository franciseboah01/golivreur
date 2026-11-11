<?php

namespace App\Filament\Resources\CourierResource\Pages;

use App\Filament\Resources\CourierResource;
use App\Models\City;
use Filament\Resources\Pages\CreateRecord;

class CreateCourier extends CreateRecord
{
    protected static string $resource = CourierResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (empty($data['current_lat']) || empty($data['current_lng'])) {
            $city = City::find($data['city_id'] ?? null);
            $data['current_lat'] = $city->center_lat ?? 6.8770;
            $data['current_lng'] = $city->center_lng ?? -6.4502;
        }

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
