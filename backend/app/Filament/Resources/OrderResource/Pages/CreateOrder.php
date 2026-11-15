<?php

namespace App\Filament\Resources\OrderResource\Pages;

use App\Filament\Resources\OrderResource;
use App\Filament\Support\FilamentCityScope;
use App\Services\ConfirmationCodeService;
use Filament\Resources\Pages\CreateRecord;

class CreateOrder extends CreateRecord
{
    protected static string $resource = OrderResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data = FilamentCityScope::enforceCityOnCreate($data);

        if (empty($data['confirmation_code'])) {
            $paire = app(ConfirmationCodeService::class)->genererPaire();
            $data['confirmation_code'] = $paire['hash'];
            $data['confirmation_code_enc'] = $paire['enc'];
        }

        return $data;
    }
}
