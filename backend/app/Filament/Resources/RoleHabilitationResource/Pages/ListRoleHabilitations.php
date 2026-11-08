<?php

namespace App\Filament\Resources\RoleHabilitationResource\Pages;

use App\Filament\Resources\AdministratorResource;
use App\Filament\Resources\RoleHabilitationResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListRoleHabilitations extends ListRecords
{
    protected static string $resource = RoleHabilitationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('back_to_admins')
                ->label('Retour aux Administrateurs')
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(AdministratorResource::getUrl('index')),

            Actions\CreateAction::make()
                ->label('Nouveau Niveau d\'Habilitation')
                ->icon('heroicon-o-plus-circle'),
        ];
    }
}
