<?php

namespace App\Filament\Resources\MerchantResource\Pages;

use App\Filament\Resources\MerchantResource;
use App\Filament\Support\FilamentCityScope;
use App\Models\Merchant;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditMerchant extends EditRecord
{
    protected static string $resource = MerchantResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var Merchant $record */
        $record = $this->getRecord();

        return FilamentCityScope::enforceCityOnSave($data, $record);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
