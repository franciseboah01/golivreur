<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();
    }

    /**
     * Exige systématiquement la gate viewHorizon (y compris en environnement local).
     */
    protected function authorization(): void
    {
        $this->gate();

        Horizon::auth(function ($request): bool {
            return Gate::check('viewHorizon', [$request->user()]);
        });
    }

    /**
     * Register the Horizon gate.
     *
     * Seuls les administrateurs super_admin et national_tech actifs sont autorisés.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function (?User $user = null): bool {
            if ($user === null || ! $user->is_active) {
                return false;
            }

            return in_array($user->role, ['super_admin', 'national_tech'], true);
        });
    }
}
