<?php

namespace App\Filament\Resources\AdministratorResource\Pages;

use App\Filament\Resources\AdministratorResource;
use App\Models\RoleHabilitation;
use App\Models\User;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditAdministrator extends EditRecord
{
    protected static string $resource = AdministratorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()->label('Supprimer'),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $user = auth()->user();
        $allowedRoles = array_keys(AdministratorResource::getAllowedRolesForUser($user));

        /** @var User $record */
        $record = $this->getRecord();

        $targetRole = $data['role'] ?? $record->role;
        if (! in_array($targetRole, $allowedRoles, true)) {
            abort(403, "Vous n'avez pas l'autorisation d'assigner le rôle [{$targetRole}].");
        }

        if ($user && $user->role === 'city_manager') {
            if ($user->city_id === null || $record->city_id === null || (int) $record->city_id !== (int) $user->city_id) {
                abort(403, 'Action non autorisée sur un administrateur hors de votre ville.');
            }
            $data['city_id'] = $user->city_id;
        }

        if (empty($data['role_habilitation_id']) && ! empty($data['role'])) {
            $data['role_habilitation_id'] = RoleHabilitation::where('slug', $data['role'])->value('id');
        }

        return $data;
    }
}
