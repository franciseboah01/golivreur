<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;

class RoleMiddleware
{
    public function handle(Request $request, Closure $next, string ...$roles)
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return response()->json([
                'succes' => false,
                'message' => 'Non authentifié.',
            ], 401);
        }

        if ($user->hasAnyRole($roles)) {
            return $next($request);
        }

        return response()->json([
            'succes' => false,
            'code' => 'ROLE_MISSING',
            'required_roles' => $roles,
            'user_roles' => $user->effectiveRoles(),
            'message' => 'Accès non autorisé pour ce profil.',
        ], 403);
    }
}
