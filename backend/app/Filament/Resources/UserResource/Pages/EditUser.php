<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Filament\Support\FilamentCityScope;
use App\Models\User;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var User $record */
        $record = $this->getRecord();

        if (FilamentCityScope::isLocal()) {
            if (! in_array($record->role, ['client', 'merchant', 'driver'], true)) {
                abort(403, 'Action non autorisée sur un compte administrateur depuis Utilisateurs.');
            }

            $role = $data['role'] ?? $record->role;
            if (! in_array($role, ['client', 'merchant', 'driver'], true)) {
                abort(403, 'Un administrateur local ne peut pas attribuer un rôle administratif.');
            }
        }

        return FilamentCityScope::enforceCityOnSave($data, $record);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
