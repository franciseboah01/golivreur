<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\City;
use App\Models\Courier;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;

/**
 * Service de géolocalisation et règles de mobilité inter-villes (sans migration SQL) :
 * - Client : détection GPS automatique de la ville active la plus proche.
 * - Commerce : ville fixe (interdiction de déplacer sa boutique dans une autre ville).
 * - Livreur : détection de changement de ville -> blocage prise de service jusqu'à réidentification Admin.
 */
class CityGeolocationService
{
    public const CLIENT_DETECT_MAX_RADIUS_KM = 45.0;

    public const DRIVER_OUTSIDE_CITY_RADIUS_KM = 45.0;

    public const COURIER_REID_SETTING_PREFIX = 'courier_city_reid:';

    /**
     * Trouve la ville active la plus proche des coordonnées GPS dans un rayon maximal.
     *
     * @return array{city: City, distance_km: float}|null
     */
    public function findNearestActiveCity(
        float $lat,
        float $lng,
        float $maxRadiusKm = self::CLIENT_DETECT_MAX_RADIUS_KM,
    ): ?array {
        $cities = City::query()
            ->where('is_active', true)
            ->whereNotNull('center_lat')
            ->whereNotNull('center_lng')
            ->get();

        $nearestCity = null;
        $minDistance = INF;

        foreach ($cities as $city) {
            if (abs((float) $city->center_lat) < 0.0001 && abs((float) $city->center_lng) < 0.0001) {
                continue;
            }

            $distance = City::distanceHaversine(
                (float) $city->center_lat,
                (float) $city->center_lng,
                $lat,
                $lng,
            );

            if ($distance < $minDistance) {
                $minDistance = $distance;
                $nearestCity = $city;
            }
        }

        if ($nearestCity === null || $minDistance > $maxRadiusKm) {
            return null;
        }

        return [
            'city' => $nearestCity,
            'distance_km' => round($minDistance, 2),
        ];
    }

    /**
     * Vérifie si une position GPS se situe hors de la ville d'affectation enregistrée.
     * Retourne null si la position est valide dans la ville enregistrée (ou si la ville n'a pas de centre).
     *
     * @return array{
     *     outside_registered_city: bool,
     *     detected_city: City|null,
     *     distance_from_registered_km: float,
     *     distance_to_detected_km: float|null
     * }|null
     */
    public function detectCityChange(?City $registeredCity, float $lat, float $lng, float $outsideThresholdKm = self::DRIVER_OUTSIDE_CITY_RADIUS_KM): ?array
    {
        if (
            $registeredCity === null
            || $registeredCity->center_lat === null
            || $registeredCity->center_lng === null
            || (abs((float) $registeredCity->center_lat) < 0.0001 && abs((float) $registeredCity->center_lng) < 0.0001)
        ) {
            return null;
        }

        $distanceFromRegistered = City::distanceHaversine(
            (float) $registeredCity->center_lat,
            (float) $registeredCity->center_lng,
            $lat,
            $lng,
        );

        // Position toujours dans le périmètre légal de la ville d'affectation
        if ($distanceFromRegistered <= City::RAYON_MAX_VILLE_KM) {
            return null;
        }

        $nearest = $this->findNearestActiveCity($lat, $lng, 60.0);
        if (
            $nearest !== null
            && (int) $nearest['city']->id !== (int) $registeredCity->id
            && $nearest['distance_km'] < $distanceFromRegistered
        ) {
            return [
                'outside_registered_city' => true,
                'detected_city' => $nearest['city'],
                'distance_from_registered_km' => round($distanceFromRegistered, 2),
                'distance_to_detected_km' => $nearest['distance_km'],
            ];
        }

        if ($distanceFromRegistered > $outsideThresholdKm) {
            return [
                'outside_registered_city' => true,
                'detected_city' => null,
                'distance_from_registered_km' => round($distanceFromRegistered, 2),
                'distance_to_detected_km' => null,
            ];
        }

        return null;
    }

    public function courierReidentificationSettingKey(int $courierId): string
    {
        return self::COURIER_REID_SETTING_PREFIX.$courierId;
    }

    /**
     * Retourne les informations de réidentification en attente pour un livreur, ou null s'il est en règle.
     *
     * @return array<string, mixed>|null
     */
    public function getCourierReidentificationFlag(Courier $courier): ?array
    {
        $value = Setting::query()
            ->where('key', $this->courierReidentificationSettingKey($courier->id))
            ->whereNull('city_id')
            ->value('value');

        if (! is_array($value) || ($value['required'] ?? false) !== true) {
            return null;
        }

        return $value;
    }

    /**
     * Bloque immédiatement la prise de service d'un livreur qui a changé de ville
     * jusqu'à réidentification et réaffectation par un administrateur.
     *
     * @return array<string, mixed>
     */
    public function flagCourierReidentificationRequired(
        Courier $courier,
        ?City $detectedCity,
        float $lat,
        float $lng,
        float $distanceFromRegisteredKm,
        ?string $ipAddress = null,
    ): array {
        $courier->loadMissing('city');

        if ($courier->is_online) {
            $courier->update(['is_online' => false]);
        }

        $payload = [
            'required' => true,
            'registered_city_id' => $courier->city_id ? (int) $courier->city_id : null,
            'registered_city_name' => $courier->city?->name,
            'detected_city_id' => $detectedCity ? (int) $detectedCity->id : null,
            'detected_city_name' => $detectedCity?->name,
            'detected_lat' => round($lat, 6),
            'detected_lng' => round($lng, 6),
            'distance_km' => round($distanceFromRegisteredKm, 1),
            'detected_at' => now()->toIso8601String(),
        ];

        Setting::updateOrCreate(
            [
                'key' => $this->courierReidentificationSettingKey($courier->id),
                'city_id' => null,
            ],
            [
                'value' => $payload,
                'description' => "Réidentification obligatoire requise pour le livreur #{$courier->id} suite à un changement de ville GPS.",
            ],
        );

        ActivityLog::create([
            'user_id' => $courier->user_id,
            'action' => 'driver_city_reidentification_required',
            'entity_type' => 'courier',
            'entity_id' => $courier->id,
            'properties' => $payload,
            'ip_address' => $ipAddress,
            'severity' => 'warning',
        ]);

        return $payload;
    }

    /**
     * Lève le verrou de réidentification d'un livreur après vérification physique par un Admin
     * et met à jour sa ville d'affectation (courier + user) si modifiée.
     */
    public function clearCourierReidentification(
        Courier $courier,
        int $adminUserId,
        ?int $newCityId = null,
        ?string $ipAddress = null,
    ): void {
        $previousCityId = $courier->city_id ? (int) $courier->city_id : null;

        if ($newCityId !== null && $newCityId !== $previousCityId) {
            $courier->update(['city_id' => $newCityId]);
        }

        $effectiveCityId = $newCityId ?? $previousCityId;
        if ($effectiveCityId !== null && $courier->user) {
            $courier->user->update(['city_id' => $effectiveCityId]);
        }

        $deleted = Setting::query()
            ->where('key', $this->courierReidentificationSettingKey($courier->id))
            ->whereNull('city_id')
            ->delete();

        if ($deleted > 0 || ($newCityId !== null && $newCityId !== $previousCityId)) {
            ActivityLog::create([
                'user_id' => $adminUserId,
                'action' => 'driver_city_reidentified_by_admin',
                'entity_type' => 'courier',
                'entity_id' => $courier->id,
                'properties' => [
                    'previous_city_id' => $previousCityId,
                    'new_city_id' => $effectiveCityId,
                    'revalidated_by' => $adminUserId,
                ],
                'ip_address' => $ipAddress,
                'severity' => 'warning',
            ]);
        }
    }

    /**
     * Réponse JSON 403 standardisée lorsqu'un livreur doit se faire réidentifier par un Admin.
     *
     * @param  array<string, mixed>  $flag
     */
    public function buildReidentificationDeniedResponse(array $flag): JsonResponse
    {
        $detectedName = is_string($flag['detected_city_name'] ?? null) && $flag['detected_city_name'] !== ''
            ? $flag['detected_city_name']
            : 'une autre localité';
        $registeredName = is_string($flag['registered_city_name'] ?? null) && $flag['registered_city_name'] !== ''
            ? $flag['registered_city_name']
            : 'votre ville d\'affectation';

        return response()->json([
            'succes' => false,
            'code' => 'CITY_REIDENTIFICATION_REQUIRED',
            'message' => "Changement de ville détecté ({$detectedName} au lieu de {$registeredName}). Vous devez vous faire réidentifier par un administrateur GoLivreur avant de prendre service dans cette ville.",
            'donnees' => [
                'reidentification_required' => true,
                'registered_city_id' => $flag['registered_city_id'] ?? null,
                'registered_city_name' => $flag['registered_city_name'] ?? null,
                'detected_city_id' => $flag['detected_city_id'] ?? null,
                'detected_city_name' => $flag['detected_city_name'] ?? null,
                'detected_at' => $flag['detected_at'] ?? null,
            ],
        ], 403);
    }
}
