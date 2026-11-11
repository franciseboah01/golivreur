<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientAddress extends Model
{
    protected $fillable = [
        'user_id',
        'city_id',
        'label',
        'neighborhood',
        'address_line',
        'lat',
        'lng',
        'is_default',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'lat'        => 'float',
        'lng'        => 'float',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    /**
     * Retourne une adresse formatée cohérente avec le quartier de livraison.
     */
    public function formattedFullAddress(): string
    {
        $cityName = $this->city?->name ?? 'Daloa';
        $details = trim((string) $this->address_line);

        if ($details === '') {
            return "{$cityName}, {$this->neighborhood}";
        }

        return "{$cityName}, {$this->neighborhood} — {$details}";
    }
}

