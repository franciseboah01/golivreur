<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasHabilitationTab('administrators') || $user->hasHabilitationTab('clients');
    }

    public function view(User $user, User $model): bool
    {
        if ($user->role === 'super_admin' || $user->role === 'dg_national') {
            return true;
        }

        if ($user->role === 'city_manager') {
            return (int) $model->city_id === (int) $user->city_id;
        }

        return false;
    }

    public function create(User $user): bool
    {
        if ($user->role === 'super_admin' || $user->role === 'dg_national') {
            return true;
        }

        if ($user->role === 'city_manager') {
            return ! empty($user->city_id);
        }

        return false;
    }

    public function update(User $user, User $model): bool
    {
        if ($user->role === 'super_admin') {
            return true;
        }

        if ($user->role === 'dg_national') {
            return $model->role !== 'super_admin';
        }

        if ($user->role === 'city_manager') {
            return (int) $model->city_id === (int) $user->city_id
                && in_array($model->role, ['city_support', 'city_agent', 'city_finance'], true);
        }

        return false;
    }

    public function delete(User $user, User $model): bool
    {
        if ($user->id === $model->id) {
            return false;
        }

        if ($user->role === 'super_admin') {
            return true;
        }

        if ($user->role === 'dg_national') {
            return ! in_array($model->role, ['super_admin', 'dg_national'], true);
        }

        if ($user->role === 'city_manager') {
            return (int) $model->city_id === (int) $user->city_id
                && in_array($model->role, ['city_support', 'city_agent', 'city_finance'], true);
        }

        return false;
    }
}
