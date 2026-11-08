<?php

namespace App\Filament\Resources\MerchantResource\Pages;

use App\Filament\Resources\MerchantCategoryResource;
use App\Filament\Resources\MerchantResource;
use App\Models\Merchant;
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
                ->badge(Merchant::count()),
            'open' => Tab::make('Ouverts')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_open', true))
                ->badge(Merchant::where('is_open', true)->count()),
            'active' => Tab::make('Validés & Actifs')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_active', true))
                ->badge(Merchant::where('is_active', true)->count()),
            'pending' => Tab::make('En Attente de Validation')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_active', false))
                ->badge(Merchant::where('is_active', false)->count()),
        ];
    }
}
