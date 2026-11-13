<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class City extends Model
{
    use HasFactory;

    public const RAYON_MAX_VILLE_KM = 25.0;

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

        $distance = self::distanceHaversine(
            (float) $this->center_lat,
            (float) $this->center_lng,
            $lat,
            $lng
        );

        return $distance <= self::RAYON_MAX_VILLE_KM;
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
}
