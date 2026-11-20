<?php

namespace App\Filament\Resources\MerchantCategoryResource\Pages;

use App\Filament\Resources\MerchantCategoryResource;
use App\Models\MerchantCategory;
use App\Services\CatalogSchemaService;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditMerchantCategory extends EditRecord
{
    protected static string $resource = MerchantCategoryResource::class;

    /** @var array<int|string, array<string, mixed>> */
    private array $pendingFieldConfig = [];

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()->label('Supprimer'),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var MerchantCategory $record */
        $record = $this->getRecord();
        $data['catalog_field_config'] = app(CatalogSchemaService::class)->buildConfigRowsForCategory($record);

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->pendingFieldConfig = is_array($data['catalog_field_config'] ?? null)
            ? $data['catalog_field_config']
            : [];
        unset($data['catalog_field_config']);

        return $data;
    }

    protected function afterSave(): void
    {
        /** @var MerchantCategory $category */
        $category = $this->getRecord();
        app(CatalogSchemaService::class)->syncCategoryFieldConfig($category, $this->pendingFieldConfig);
    }
}
