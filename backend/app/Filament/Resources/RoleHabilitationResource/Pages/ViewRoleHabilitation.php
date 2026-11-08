<?php

namespace App\Filament\Resources\RoleHabilitationResource\Pages;

use App\Filament\Resources\RoleHabilitationResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewRoleHabilitation extends ViewRecord
{
    protected static string $resource = RoleHabilitationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make()->label('Modifier'),
            Actions\Action::make('back')
                ->label('Retour à la liste')
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(RoleHabilitationResource::getUrl('index')),
        ];
    }
}

