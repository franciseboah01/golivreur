<?php

namespace App\Filament\Resources\CatalogFieldResource\Pages;

use App\Filament\Resources\CatalogFieldResource;
use App\Models\CatalogField;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditCatalogField extends EditRecord
{
    protected static string $resource = CatalogFieldResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->label('Supprimer')
                ->visible(fn (): bool => $this->getRecord() instanceof CatalogField && ! $this->getRecord()->isUsedByAnyProduct()),
        ];
    }
}
