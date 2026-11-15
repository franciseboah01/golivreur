<?php

namespace App\Filament\Resources\MerchantResource\Pages;

use App\Filament\Resources\MerchantCategoryResource;
use App\Filament\Resources\MerchantResource;
use App\Filament\Support\FilamentCityScope;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListMerchants extends ListRecords
{
    protected static string $resource = MerchantResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('categories')
                ->label('Catégories d\'Activité')
                ->icon('heroicon-o-tag')
                ->color('gray')
                ->url(MerchantCategoryResource::getUrl('index')),

            Actions\CreateAction::make()->label('Nouveau Commerce'),
        ];
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('Tous les Commerces')
                ->badge(fn (): int => FilamentCityScope::scopedTabCount(MerchantResource::class)),
            'open' => Tab::make('Ouverts')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_open', true))
                ->badge(fn (): int => FilamentCityScope::scopedTabCount(MerchantResource::class, fn (Builder $q) => $q->where('is_open', true))),
            'active' => Tab::make('Validés & Actifs')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_active', true))
                ->badge(fn (): int => FilamentCityScope::scopedTabCount(MerchantResource::class, fn (Builder $q) => $q->where('is_active', true))),
            'pending' => Tab::make('En Attente de Validation')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_active', false))
                ->badge(fn (): int => FilamentCityScope::scopedTabCount(MerchantResource::class, fn (Builder $q) => $q->where('is_active', false))),
        ];
    }
}
