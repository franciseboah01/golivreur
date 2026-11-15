<?php

namespace App\Filament\Resources\ParcelResource\Pages;

use App\Filament\Resources\ParcelResource;
use App\Filament\Support\FilamentCityScope;
use App\Services\ConfirmationCodeService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Hash;

class CreateParcel extends CreateRecord
{
    protected static string $resource = ParcelResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data = FilamentCityScope::enforceCityOnCreate($data);
        $codeService = app(ConfirmationCodeService::class);

        if (empty($data['sender_code'])) {
            $senderPaire = $codeService->genererPaire();
            $data['sender_code'] = $senderPaire['hash'];
            $data['sender_code_enc'] = $senderPaire['enc'];
        }

        if (empty($data['recipient_code'])) {
            $data['recipient_code'] = Hash::make($codeService->genererCode());
        }

        $data['declared_value'] = (int) ($data['declared_value'] ?? 0);
        $data['insurance_amount'] = (int) ($data['insurance_amount'] ?? 0);

        return $data;
    }
}
