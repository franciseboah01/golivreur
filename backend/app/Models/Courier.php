<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Courier extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'city_id',
        'vehicle_type',
        'license_plate',
        'photo_path',
        'is_active',
        'is_online',
        'is_available',
        'current_lat',
        'current_lng',
        'last_location_at',
        'average_rating',
        'total_ratings',
        'completed_deliveries',
    ];

    protected $casts = [
        'is_active'            => 'boolean',
        'is_online'            => 'boolean',
        'is_available'         => 'boolean',
        'current_lat'          => 'float',
        'current_lng'          => 'float',
        'last_location_at'     => 'datetime',
        'average_rating'       => 'float',
        'total_ratings'        => 'integer',
        'completed_deliveries' => 'integer',
    ];

    public function getPhotoUrlAttribute(): ?string
    {
        if (!empty($this->attributes['photo_path'])) {
            return url('storage/' . $this->attributes['photo_path']);
        }
        return $this->attributes['photo_url'] ?? url('images/placeholder-driver.png');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function parcels(): HasMany
    {
        return $this->hasMany(Parcel::class);
    }
}

