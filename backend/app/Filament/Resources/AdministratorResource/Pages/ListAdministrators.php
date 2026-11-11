<?php

namespace App\Filament\Resources\AdministratorResource\Pages;

use App\Filament\Resources\AdministratorResource;
use App\Filament\Resources\RoleHabilitationResource;
use App\Models\User;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListAdministrators extends ListRecords
{
    protected static string $resource = AdministratorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('roles_habilitation')
                ->label('Niveaux d\'Habilitation & Rôles')
                ->icon('heroicon-o-key')
                ->color('warning')
                ->url(RoleHabilitationResource::getUrl('index'))
                ->visible(fn () => auth()->user()?->role === 'super_admin'),

            Actions\CreateAction::make()->label('Nouvel Administrateur'),
        ];
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('Tous les Administrateurs')
                ->badge(User::whereIn('role', User::ADMIN_ROLES)->count()),
            'direction' => Tab::make('Direction & Super Admin')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('role', ['super_admin', 'dg_national']))
                ->badge(User::whereIn('role', ['super_admin', 'dg_national'])->count()),
            'city_managers' => Tab::make('City Managers')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('role', 'city_manager'))
                ->badge(User::where('role', 'city_manager')->count()),
            'operations' => Tab::make('Support & Opérations')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('role', ['city_support', 'national_support', 'city_agent']))
                ->badge(User::whereIn('role', ['city_support', 'national_support', 'city_agent'])->count()),
            'finance' => Tab::make('Finances')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('role', ['city_finance', 'national_finance']))
                ->badge(User::whereIn('role', ['city_finance', 'national_finance'])->count()),
        ];
    }
}
