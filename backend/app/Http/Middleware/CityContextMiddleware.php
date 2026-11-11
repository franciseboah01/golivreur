<?php

namespace App\Http\Middleware;

use App\Models\Scopes\CityScope;
use Closure;
use Illuminate\Http\Request;

/**
 * Filtre automatique par city_id pour tous les admins locaux.
 * Les admins nationaux (super_admin, national_*) voient toutes les villes.
 * Ce middleware est appliqué sur toutes les routes /api/v1/admin/*.
 *
 * Principe : toutes les requêtes Eloquent de l'admin local sont
 * automatiquement scopées à sa ville via ce middleware.
 * C'est la garantie centrale contre les fuites de données entre villes.
 */
class CityContextMiddleware
{
    private const ROLES_NATIONAUX = [
        'super_admin', 'national_dg', 'national_finance',
        'national_tech', 'national_support',
    ];

    public function handle(Request $request, Closure $next)
    {
        $user = auth()->user();

        if (! $user) {
            return response()->json(['succes' => false, 'message' => 'Non authentifié.'], 401);
        }

        // Les admins nationaux accèdent à tout — pas de filtre
        if (in_array($user->role, self::ROLES_NATIONAUX)) {
            return $next($request);
        }

        // Admin local — city_id obligatoire
        if (! $user->city_id) {
            return response()->json([
                'succes' => false,
                'message' => 'Aucune ville assignée à ce compte.',
            ], 403);
        }

        // Stocker le city_id dans le contexte de la requête
        // Les contrôleurs l'utiliseront via $request->get('city_context_id')
        $request->merge(['city_context_id' => $user->city_id]);

        // Injecter le scope Eloquent global pour cette requête
        CityScope::setCurrentCityId($user->city_id);

        return $next($request);
    }
}
