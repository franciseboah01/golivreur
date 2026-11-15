<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Filament\Support\FilamentCityScope;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (FilamentCityScope::isLocal()) {
            $role = $data['role'] ?? 'client';
            if (! in_array($role, ['client', 'merchant', 'driver'], true)) {
                abort(403, 'Un administrateur local ne peut créer que des comptes client, commerce ou livreur.');
            }
        }

        return FilamentCityScope::enforceCityOnCreate($data);
    }
}
