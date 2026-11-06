<?php

namespace App\Filament\Resources\MerchantCategoryResource\Pages;

use App\Filament\Resources\MerchantCategoryResource;
use App\Models\MerchantCategory;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListMerchantCategories extends ListRecords
{
    protected static string $resource = MerchantCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Nouvelle Catégorie'),
        ];
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('Toutes les Catégories')
                ->badge(MerchantCategory::count()),
            'active' => Tab::make('Actives')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_active', true))
                ->badge(MerchantCategory::where('is_active', true)->count()),
            'inactive' => Tab::make('Inactives')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_active', false))
                ->badge(MerchantCategory::where('is_active', false)->count()),
        ];
    }
}

