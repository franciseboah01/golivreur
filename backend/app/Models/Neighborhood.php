<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Représente un quartier / zone rattaché à une ville.
 */
class Neighborhood extends Model
{
    use HasFactory;

    protected $fillable = [
        'city_id',
        'name',
        'is_active',
        'distance_km',
        'lat',
        'lng',
    ];

    protected $casts = [
        'is_active'   => 'boolean',
        'distance_km' => 'float',
        'lat'         => 'float',
        'lng'         => 'float',
    ];

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }
}

