<?php

namespace App\Filament\Resources\MerchantCategoryResource\Pages;

use App\Filament\Resources\CatalogFieldResource;
use App\Filament\Resources\MerchantCategoryResource;
use App\Filament\Resources\MerchantResource;
use App\Models\MerchantCategory;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListMerchantCategories extends ListRecords
{
    protected static string $resource = MerchantCategoryResource::class;

    public function getBreadcrumbs(): array
    {
        return [
            MerchantResource::getUrl('index') => 'Commerces Partenaires',
            'Types de Commerce',
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('catalog_fields')
                ->label('Dictionnaire de Champs')
                ->icon('heroicon-o-adjustments-horizontal')
                ->color('warning')
                ->url(CatalogFieldResource::getUrl('index')),

            Actions\Action::make('back_to_merchants')
                ->label('Retour aux Commerces')
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(MerchantResource::getUrl('index')),

            Actions\CreateAction::make()->label('Nouveau Type de Commerce'),
        ];
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('Tous les Types')
                ->badge(MerchantCategory::count()),
            'active' => Tab::make('Actifs')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_active', true))
                ->badge(MerchantCategory::where('is_active', true)->count()),
            'inactive' => Tab::make('Inactifs')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_active', false))
                ->badge(MerchantCategory::where('is_active', false)->count()),
        ];
    }
}
