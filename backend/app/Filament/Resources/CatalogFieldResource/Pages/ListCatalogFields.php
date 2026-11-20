<?php

namespace App\Filament\Resources\CatalogFieldResource\Pages;

use App\Filament\Resources\CatalogFieldResource;
use App\Filament\Resources\MerchantCategoryResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListCatalogFields extends ListRecords
{
    protected static string $resource = CatalogFieldResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('merchant_categories')
                ->label('Configurer les Types de Commerce')
                ->icon('heroicon-o-tag')
                ->color('gray')
                ->url(MerchantCategoryResource::getUrl('index')),

            Actions\CreateAction::make()->label('Nouveau Champ'),
        ];
    }
}
