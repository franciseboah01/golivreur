<?php

namespace App\Models;

use App\Services\SettingsService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class City extends Model
{
    use HasFactory;

    public const RAYON_MIN_VILLE_KM = 1.0;

    public const RAYON_MAX_VILLE_KM = 45.0;

    public const RAYON_MAX_CONFIG_VILLE_KM = 60.0;

    public const RAYON_QUARTIER_VILLE_KM = 25.0;

    public static function distanceHaversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $terreKm = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $terreKm * asin(min(1.0, sqrt($a)));
    }

    public function estDansLaVille(float $lat, float $lng): bool
    {
        if ($this->center_lat === null || $this->center_lng === null) {
            return true;
        }

        // Coordonnées par défaut (0, 0) = centre non encore configuré par l'administration
        if (abs((float) $this->center_lat) < 0.0001 && abs((float) $this->center_lng) < 0.0001) {
            return true;
        }

        $rayonVilleKm = $this->radius_km;

        $distance = self::distanceHaversine(
            (float) $this->center_lat,
            (float) $this->center_lng,
            $lat,
            $lng
        );

        if ($distance <= $rayonVilleKm) {
            return true;
        }

        // Tolérance proportionnelle au rayon configuré autour d'un quartier actif géolocalisé :
        // une petite commune de 3 km garde une marge locale (~1.5 km) et ne déborde jamais sur la ville voisine.
        $rayonQuartierKm = min(self::RAYON_QUARTIER_VILLE_KM, max(1.5, $rayonVilleKm * 0.5));

        $neighborhoods = $this->relationLoaded('neighborhoods')
            ? $this->neighborhoods
            : $this->neighborhoods()
                ->where('is_active', true)
                ->whereNotNull('lat')
                ->whereNotNull('lng')
                ->get(['id', 'city_id', 'is_active', 'lat', 'lng']);

        foreach ($neighborhoods as $neighborhood) {
            if (! $neighborhood instanceof Neighborhood) {
                continue;
            }
            if (! $neighborhood->is_active || $neighborhood->lat === null || $neighborhood->lng === null) {
                continue;
            }
            if (abs((float) $neighborhood->lat) < 0.0001 && abs((float) $neighborhood->lng) < 0.0001) {
                continue;
            }

            $distanceQuartier = self::distanceHaversine(
                (float) $neighborhood->lat,
                (float) $neighborhood->lng,
                $lat,
                $lng
            );

            if ($distanceQuartier <= $rayonQuartierKm) {
                return true;
            }
        }

        return false;
    }

    protected $fillable = [
        'name',
        'is_active',
        'center_lat',
        'center_lng',
        'rain_mode_active',
        'rain_mode_activated_at',
        'emergency_mode_active',
        'emergency_mode_message',
        'emergency_mode_activated_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'rain_mode_active' => 'boolean',
        'rain_mode_activated_at' => 'datetime',
        'emergency_mode_active' => 'boolean',
        'emergency_mode_activated_at' => 'datetime',
        'center_lat' => 'float',
        'center_lng' => 'float',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function merchants(): HasMany
    {
        return $this->hasMany(Merchant::class);
    }

    public function couriers(): HasMany
    {
        return $this->hasMany(Courier::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function parcels(): HasMany
    {
        return $this->hasMany(Parcel::class);
    }

    public function neighborhoods(): HasMany
    {
        return $this->hasMany(Neighborhood::class);
    }

    /**
     * Numéro de service / support client officiel de cette ville (via SettingsService, sans migration).
     */
    public function getSupportPhoneAttribute(): string
    {
        return app(SettingsService::class)->getCitySupportPhone((int) $this->id);
    }

    /**
     * Rayon de couverture GPS personnalisé de cette ville en km (via SettingsService, sans migration).
     */
    public function getRadiusKmAttribute(): float
    {
        return app(SettingsService::class)->getCityRadiusKm($this->id !== null ? (int) $this->id : null);
    }
}
