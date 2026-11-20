<?php

namespace App\Services;

use App\Models\City;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Géocodage gratuit (OpenStreetMap Nominatim) et garde-fous géographiques
 * dédiés à la Côte d'Ivoire (zéro clé API, zéro migration SQL).
 */
class IvoryCoastGeocodingService
{
    public const MIN_LAT = 4.20;

    public const MAX_LAT = 10.85;

    public const MIN_LNG = -8.70;

    public const MAX_LNG = -2.40;

    private const NOMINATIM_ENDPOINT = 'https://nominatim.openstreetmap.org/search';

    /**
     * Vérifie si un couple de coordonnées se trouve bien en Côte d'Ivoire.
     */
    public function isWithinIvoryCoast(float $lat, float $lng): bool
    {
        return $lat >= self::MIN_LAT
            && $lat <= self::MAX_LAT
            && $lng >= self::MIN_LNG
            && $lng <= self::MAX_LNG;
    }

    /**
     * Valide un couple (lat, lng) pour la Côte d'Ivoire.
     * Retourne null si valide (ou si les deux sont vides), sinon un message d'erreur explicite.
     */
    public function validateCoordinates(?float $lat, ?float $lng): ?string
    {
        if ($lat === null && $lng === null) {
            return null;
        }

        if ($lat === null || $lng === null) {
            return 'Renseignez à la fois la latitude et la longitude, ou laissez les deux champs vides.';
        }

        if ($lng > 0 && $this->isWithinIvoryCoast($lat, -$lng)) {
            $suggested = number_format(-$lng, 6, '.', '');

            return "En Côte d'Ivoire, la longitude est toujours négative : utilisez {$suggested} au lieu de {$lng} (ou cliquez sur la carte).";
        }

        if (! $this->isWithinIvoryCoast($lat, $lng)) {
            return 'Ces coordonnées sont en dehors de la Côte d\'Ivoire (latitude attendue entre 4.2 et 10.85 ; longitude entre -8.7 et -2.4). Utilisez la recherche ou cliquez sur la carte.';
        }

        return null;
    }

    /**
     * Valide les coordonnées d'un quartier : bornes Côte d'Ivoire + périmètre de la ville parente.
     */
    public function validateNeighborhoodWithinCity(City $city, ?float $lat, ?float $lng): ?string
    {
        $baseError = $this->validateCoordinates($lat, $lng);
        if ($baseError !== null) {
            return $baseError;
        }

        if ($lat === null || $lng === null) {
            return null;
        }

        if (
            $city->center_lat !== null
            && $city->center_lng !== null
            && ! (abs((float) $city->center_lat) < 0.0001 && abs((float) $city->center_lng) < 0.0001)
        ) {
            $maxRadiusKm = $city->radius_km;
            $distanceKm = City::distanceHaversine(
                (float) $city->center_lat,
                (float) $city->center_lng,
                $lat,
                $lng,
            );

            if ($distanceKm > $maxRadiusKm) {
                $rounded = round($distanceKm, 1);

                return "Ce point est à {$rounded} km du centre de {$city->name} (rayon configuré : {$maxRadiusKm} km). Placez le repère dans le périmètre ou ajustez le rayon de la ville.";
            }
        }

        return null;
    }

    /**
     * Recherche le centre géographique officiel d'une ville de Côte d'Ivoire via OpenStreetMap Nominatim.
     *
     * @return array{lat: float, lng: float, display_name: string}|null
     */
    public function geocodeCity(string $cityName): ?array
    {
        $cleanName = trim($cityName);
        if ($cleanName === '') {
            return null;
        }

        $cacheKey = 'ci_geocode:city:'.md5(mb_strtolower($cleanName));

        /** @var array{lat: float, lng: float, display_name: string}|null $cached */
        $cached = Cache::get($cacheKey);
        if (is_array($cached) && isset($cached['lat'], $cached['lng'])) {
            return $cached;
        }

        $candidates = $this->queryNominatim([
            'q' => "{$cleanName}, Côte d'Ivoire",
            'countrycodes' => 'ci',
            'format' => 'jsonv2',
            'addressdetails' => 1,
            'limit' => 5,
        ]);

        $preferredTypes = ['city', 'town', 'village', 'municipality', 'administrative', 'suburb', 'county'];
        $best = null;

        foreach ($candidates as $item) {
            $lat = isset($item['lat']) ? (float) $item['lat'] : null;
            $lng = isset($item['lon']) ? (float) $item['lon'] : null;
            if ($lat === null || $lng === null || ! $this->isWithinIvoryCoast($lat, $lng)) {
                continue;
            }

            $entry = [
                'lat' => round($lat, 6),
                'lng' => round($lng, 6),
                'display_name' => (string) ($item['display_name'] ?? $cleanName),
            ];

            $type = strtolower((string) ($item['type'] ?? ''));
            $category = strtolower((string) ($item['category'] ?? $item['class'] ?? ''));
            if (in_array($type, $preferredTypes, true) || $category === 'place' || $category === 'boundary') {
                $best = $entry;
                break;
            }

            $best ??= $entry;
        }

        if ($best !== null) {
            Cache::put($cacheKey, $best, now()->addHours(24));
        }

        return $best;
    }

    /**
     * Recherche un quartier dans une ville de Côte d'Ivoire via OpenStreetMap Nominatim.
     * Ne retourne un résultat que s'il se situe bien dans la ville cible (évite les homonymes).
     *
     * @return array{lat: float, lng: float, display_name: string}|null
     */
    public function geocodeNeighborhood(
        string $neighborhoodName,
        string $cityName,
        ?float $cityCenterLat = null,
        ?float $cityCenterLng = null,
        float $maxRadiusKm = City::RAYON_MAX_VILLE_KM,
    ): ?array {
        $cleanNb = trim($neighborhoodName);
        $cleanCity = trim($cityName);
        if ($cleanNb === '' || $cleanCity === '') {
            return null;
        }

        $hasCityCenter = $cityCenterLat !== null
            && $cityCenterLng !== null
            && ! (abs($cityCenterLat) < 0.0001 && abs($cityCenterLng) < 0.0001);

        $cacheKey = 'ci_geocode:nb:'.md5(mb_strtolower("{$cleanNb}|{$cleanCity}|{$maxRadiusKm}|".($hasCityCenter ? "{$cityCenterLat},{$cityCenterLng}" : '')));

        /** @var array{lat: float, lng: float, display_name: string}|null $cached */
        $cached = Cache::get($cacheKey);
        if (is_array($cached) && isset($cached['lat'], $cached['lng'])) {
            return $cached;
        }

        $params = [
            'q' => "{$cleanNb}, {$cleanCity}, Côte d'Ivoire",
            'countrycodes' => 'ci',
            'format' => 'jsonv2',
            'addressdetails' => 1,
            'limit' => 5,
        ];

        if ($hasCityCenter) {
            // Biais géographique adapté au rayon configuré de la ville
            $delta = max(0.05, ($maxRadiusKm / 111.0) * 1.3);
            $params['viewbox'] = sprintf(
                '%.5f,%.5f,%.5f,%.5f',
                $cityCenterLng - $delta,
                $cityCenterLat + $delta,
                $cityCenterLng + $delta,
                $cityCenterLat - $delta,
            );
            $params['bounded'] = 1;
        }

        $candidates = $this->queryNominatim($params);

        foreach ($candidates as $item) {
            $lat = isset($item['lat']) ? (float) $item['lat'] : null;
            $lng = isset($item['lon']) ? (float) $item['lon'] : null;
            if ($lat === null || $lng === null || ! $this->isWithinIvoryCoast($lat, $lng)) {
                continue;
            }

            if ($hasCityCenter) {
                $distanceKm = City::distanceHaversine($cityCenterLat, $cityCenterLng, $lat, $lng);
                if ($distanceKm > $maxRadiusKm) {
                    continue;
                }
            }

            $result = [
                'lat' => round($lat, 6),
                'lng' => round($lng, 6),
                'display_name' => (string) ($item['display_name'] ?? "{$cleanNb}, {$cleanCity}"),
            ];

            Cache::put($cacheKey, $result, now()->addHours(24));

            return $result;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return list<array<string, mixed>>
     */
    private function queryNominatim(array $params): array
    {
        try {
            $response = Http::timeout(6)
                ->connectTimeout(4)
                ->withHeaders([
                    'User-Agent' => 'GoLivreurAdmin/1.0 (+https://golivreur.ci)',
                    'Accept-Language' => 'fr,en;q=0.5',
                    'Accept' => 'application/json',
                ])
                ->get(self::NOMINATIM_ENDPOINT, $params);

            if (! $response->successful()) {
                return [];
            }

            $decoded = $response->json();

            return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
        } catch (\Throwable) {
            return [];
        }
    }
}
