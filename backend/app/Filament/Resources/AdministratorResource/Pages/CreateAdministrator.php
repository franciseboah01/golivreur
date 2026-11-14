<?php

namespace App\Filament\Resources\AdministratorResource\Pages;

use App\Filament\Resources\AdministratorResource;
use App\Models\RoleHabilitation;
use Filament\Resources\Pages\CreateRecord;

class CreateAdministrator extends CreateRecord
{
    protected static string $resource = AdministratorResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $user = auth()->user();
        $allowedRoles = array_keys(AdministratorResource::getAllowedRolesForUser($user));

        if (! isset($data['role']) || ! in_array($data['role'], $allowedRoles, true)) {
            abort(403, "Vous n'avez pas l'autorisation de créer un compte avec le rôle [".($data['role'] ?? 'non spécifié').'].');
        }

        if ($user && $user->role === 'city_manager') {
            $data['city_id'] = $user->city_id;
        }

        if (empty($data['role_habilitation_id']) && ! empty($data['role'])) {
            $data['role_habilitation_id'] = RoleHabilitation::where('slug', $data['role'])->value('id');
        }

        return $data;
    }
}
