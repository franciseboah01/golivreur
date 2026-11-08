<?php

namespace App\Filament\Resources\RoleHabilitationResource\Pages;

use App\Filament\Resources\RoleHabilitationResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditRoleHabilitation extends EditRecord
{
    protected static string $resource = RoleHabilitationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make()->label('Voir'),
            Actions\DeleteAction::make()
                ->label('Supprimer')
                ->hidden(fn ($record) => $record->is_system || $record->slug === 'super_admin' || $record->users()->count() > 0),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}

