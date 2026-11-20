<?php

namespace App\Filament\Resources\MerchantCategoryResource\Pages;

use App\Filament\Resources\MerchantCategoryResource;
use App\Models\MerchantCategory;
use App\Services\CatalogSchemaService;
use Filament\Resources\Pages\CreateRecord;

class CreateMerchantCategory extends CreateRecord
{
    protected static string $resource = MerchantCategoryResource::class;

    /** @var array<int|string, array<string, mixed>> */
    private array $pendingFieldConfig = [];

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->pendingFieldConfig = is_array($data['catalog_field_config'] ?? null)
            ? $data['catalog_field_config']
            : [];
        unset($data['catalog_field_config']);

        return $data;
    }

    protected function afterCreate(): void
    {
        /** @var MerchantCategory $category */
        $category = $this->getRecord();
        $schemaService = app(CatalogSchemaService::class);

        if ($this->pendingFieldConfig !== []) {
            $schemaService->syncCategoryFieldConfig($category, $this->pendingFieldConfig);
        } else {
            $schemaService->attachUniversalDefaults($category);
        }
    }
}
