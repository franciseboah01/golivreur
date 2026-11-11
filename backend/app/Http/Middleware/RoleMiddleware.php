<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RoleMiddleware
{
    private const ADMIN_ROLES = [
        'super_admin',
        'dg_national',
        'national_finance',
        'national_tech',
        'national_support',
        'city_manager',
        'city_finance',
        'city_support',
        'city_agent',
    ];

    public function handle(Request $request, Closure $next, ...$roles)
    {
        $user = auth()->user();

        if (! $user) {
            return response()->json([
                'succes' => false,
                'message' => 'Non authentifié.',
            ], 401);
        }

        $userRole = $user->role;

        foreach ($roles as $role) {
            if ($role === 'admin' && in_array($userRole, self::ADMIN_ROLES, true)) {
                return $next($request);
            }

            if ($userRole === $role) {
                return $next($request);
            }
        }

        return response()->json([
            'succes' => false,
            'message' => 'Accès non autorisé pour ce profil.',
        ], 403);
    }
}
