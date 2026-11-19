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
