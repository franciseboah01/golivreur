<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Models\User;
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
                ->badge(User::whereNotIn('role', User::ADMIN_ROLES)->count()),
            'clients' => Tab::make('Clients')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('role', 'client'))
                ->badge(User::where('role', 'client')->count()),
            'merchants' => Tab::make('Commerçants')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('role', 'merchant'))
                ->badge(User::where('role', 'merchant')->count()),
            'couriers' => Tab::make('Livreurs')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('role', ['driver', 'courier']))
                ->badge(User::whereIn('role', ['driver', 'courier'])->count()),
        ];
    }
}
