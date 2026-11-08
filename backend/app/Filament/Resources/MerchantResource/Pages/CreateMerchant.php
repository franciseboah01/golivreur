<?php

namespace App\Filament\Resources\MerchantResource\Pages;

use App\Filament\Resources\MerchantResource;
use App\Models\City;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateMerchant extends CreateRecord
{
    protected static string $resource = MerchantResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (empty($data['lat']) || empty($data['lng'])) {
            $city = City::find($data['city_id'] ?? null);
            $data['lat'] = $city?->center_lat ?? 6.8770;
            $data['lng'] = $city?->center_lng ?? -6.4502;
        }

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
