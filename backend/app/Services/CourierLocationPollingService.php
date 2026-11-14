<?php

namespace App\Services;

use App\Models\Courier;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Partage la logique de polling bêta (D-BETA-1) de la position du livreur (1 requête / 5 s).
 */
class CourierLocationPollingService
{
    public function repondrePosition(
        int $ownerId,
        ?int $authenticatedUserId,
        string $scopeKey,
        bool $estEnCours,
        ?Courier $courier,
        string $forbiddenMessage,
        string $inactiveMessage,
    ): JsonResponse {
        if ($authenticatedUserId === null || $ownerId !== $authenticatedUserId) {
            return response()->json(['succes' => false, 'message' => $forbiddenMessage], 403);
        }

        if (! $estEnCours || $courier === null) {
            return response()->json(['succes' => false, 'message' => $inactiveMessage], 422);
        }

        $rateKey = "client_courier_location:{$authenticatedUserId}:{$scopeKey}";
        if (RateLimiter::tooManyAttempts($rateKey, 1)) {
            return response()->json([
                'succes' => false,
                'message' => 'Suivi interrogé trop fréquemment (maximum 1 requête toutes les 5 secondes).',
            ], 429);
        }
        RateLimiter::hit($rateKey, 5);

        return response()->json([
            'succes' => true,
            'donnees' => [
                'lat' => $courier->current_lat !== null ? (float) $courier->current_lat : null,
                'lng' => $courier->current_lng !== null ? (float) $courier->current_lng : null,
                'updated_at' => $courier->location_updated_at?->toIso8601String(),
            ],
        ]);
    }
}
