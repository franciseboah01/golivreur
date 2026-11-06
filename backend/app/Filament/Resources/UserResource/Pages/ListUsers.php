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
            Actions\CreateAction::make()->label('Créer un utilisateur'),
        ];
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('Tous les Utilisateurs')
                ->badge(User::count()),
            'clients' => Tab::make('Clients')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('role', 'client'))
                ->badge(User::where('role', 'client')->count()),
            'merchants' => Tab::make('Commerçants')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('role', 'merchant'))
                ->badge(User::where('role', 'merchant')->count()),
            'couriers' => Tab::make('Livreurs')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('role', 'courier'))
                ->badge(User::where('role', 'courier')->count()),
            'admins' => Tab::make('Administrateurs')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('role', 'admin'))
                ->badge(User::where('role', 'admin')->count()),
        ];
    }
}
