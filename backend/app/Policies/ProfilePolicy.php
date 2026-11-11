<?php

namespace App\Policies;

use App\Models\User;

/**
 * Policy garantissant qu'un utilisateur mobile ne peut consulter ni modifier
 * le profil d'un autre utilisateur (retourne 403 Forbidden).
 * Les administrateurs habilités conservent l'accès via le panneau Filament.
 */
class ProfilePolicy
{
    public function viewAny(User $authUser): bool
    {
        return in_array($authUser->role, User::ADMIN_ROLES, true);
    }

    public function view(User $authUser, User $targetUser): bool
    {
        return $authUser->id === $targetUser->id
            || in_array($authUser->role, User::ADMIN_ROLES, true);
    }

    public function create(User $authUser): bool
    {
        return in_array($authUser->role, User::ADMIN_ROLES, true);
    }

    public function update(User $authUser, User $targetUser): bool
    {
        return $authUser->id === $targetUser->id
            || in_array($authUser->role, User::ADMIN_ROLES, true);
    }

    public function delete(User $authUser, User $targetUser): bool
    {
        return $authUser->role === 'super_admin' || in_array($authUser->role, User::ADMIN_ROLES, true);
    }

    /**
     * Action API stricte : un utilisateur ne modifie QUE son propre profil.
     */
    public function viewSelfProfile(User $authUser, User $targetUser): bool
    {
        return $authUser->id === $targetUser->id;
    }

    /**
     * Action API stricte : modification autorisée uniquement sur son propre compte.
     */
    public function updateSelfProfile(User $authUser, User $targetUser): bool
    {
        return $authUser->id === $targetUser->id;
    }
}

