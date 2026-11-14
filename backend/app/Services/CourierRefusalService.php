<?php

namespace App\Services;

use App\Models\Courier;
use App\Models\Order;
use App\Models\Parcel;
use Illuminate\Http\JsonResponse;

/**
 * Centralise la vérification d'éligibilité, l'incrément des refus consécutifs
 * et l'application des pénalités de mise hors ligne des livreurs (GoShop & GoColis).
 */
class CourierRefusalService
{
    public function __construct(private readonly SettingsService $settingsService) {}

    /**
     * @return array{0: int, 1: int}
     */
    public function obtenirReglesPenaliteRefus(?int $cityId): array
    {
        return [
            $this->settingsService->getDelaiRegle('refus_avant_penalite', $cityId, 3),
            $this->settingsService->getDelaiRegle('duree_penalite_minutes', $cityId, 15),
        ];
    }

    public function verifierEligibilite(Courier $courier): ?JsonResponse
    {
        if (! $courier->is_active || ($courier->user && ! $courier->user->is_active)) {
            return response()->json([
                'succes' => false,
                'message' => 'Votre compte livreur est suspendu ou inactif.',
            ], 403);
        }

        if ($courier->penalty_until !== null) {
            if ($courier->penalty_until->isFuture()) {
                if ($courier->is_online) {
                    $courier->update(['is_online' => false]);
                }

                return response()->json([
                    'succes' => false,
                    'message' => 'Votre compte est temporairement suspendu suite à des refus répétés.',
                ], 403);
            }

            $courier->update([
                'penalty_until' => null,
                'consecutive_refusals' => 0,
            ]);

            return null;
        }

        [$seuilRefus, $dureePenalite] = $this->obtenirReglesPenaliteRefus($courier->city_id ? (int) $courier->city_id : null);

        if ($courier->consecutive_refusals >= $seuilRefus) {
            $courier->update([
                'penalty_until' => now()->addMinutes($dureePenalite),
                'is_online' => false,
            ]);

            return response()->json([
                'succes' => false,
                'message' => "Suite à {$seuilRefus} refus consécutifs, vous êtes hors ligne pour {$dureePenalite} minutes.",
            ], 403);
        }

        return null;
    }

    /**
     * Enregistre le refus d'une proposition non encore assignée, applique la pénalité
     * si le seuil est atteint et prépare la tentative de réattribution suivante.
     *
     * @return array{response: JsonResponse, reassign_tentative: int, exclude_courier_id: int}
     */
    public function enregistrerRefusProposition(
        Courier $lockedCourier,
        Order|Parcel $lockedCourse,
        string $courseLabel,
    ): array {
        [$seuilRefus, $dureePenalite] = $this->obtenirReglesPenaliteRefus(
            $lockedCourier->city_id ? (int) $lockedCourier->city_id : null,
        );

        $nouveauxRefus = (int) $lockedCourier->consecutive_refusals + 1;

        if ($nouveauxRefus >= $seuilRefus) {
            $lockedCourier->update([
                'consecutive_refusals' => $nouveauxRefus,
                'penalty_until' => now()->addMinutes($dureePenalite),
                'is_online' => false,
            ]);
        } else {
            $lockedCourier->update([
                'consecutive_refusals' => $nouveauxRefus,
            ]);
        }

        $tentativeCourante = max(1, (int) $lockedCourse->assignment_attempts);
        $lockedCourse->update(['assignment_attempts' => $tentativeCourante]);

        return [
            'response' => response()->json([
                'succes' => true,
                'donnees' => $lockedCourse->fresh(),
                'message' => $nouveauxRefus >= $seuilRefus
                    ? "{$courseLabel} refusée. Suite à {$seuilRefus} refus consécutifs, vous êtes hors ligne pour {$dureePenalite} minutes."
                    : "{$courseLabel} refusée et transmise au livreur suivant.",
            ]),
            'reassign_tentative' => $tentativeCourante + 1,
            'exclude_courier_id' => $lockedCourier->id,
        ];
    }
}
