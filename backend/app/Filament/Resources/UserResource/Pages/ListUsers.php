<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Filament\Support\FilamentCityScope;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Nouvel Utilisateur'),
        ];
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('Tous les Utilisateurs')
                ->badge(fn (): int => FilamentCityScope::scopedTabCount(UserResource::class)),
            'clients' => Tab::make('Clients')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('role', 'client'))
                ->badge(fn (): int => FilamentCityScope::scopedTabCount(UserResource::class, fn (Builder $q) => $q->where('role', 'client'))),
            'merchants' => Tab::make('Commerçants')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('role', 'merchant'))
                ->badge(fn (): int => FilamentCityScope::scopedTabCount(UserResource::class, fn (Builder $q) => $q->where('role', 'merchant'))),
            'couriers' => Tab::make('Livreurs')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('role', ['driver', 'courier']))
                ->badge(fn (): int => FilamentCityScope::scopedTabCount(UserResource::class, fn (Builder $q) => $q->whereIn('role', ['driver', 'courier']))),
        ];
    }
}
