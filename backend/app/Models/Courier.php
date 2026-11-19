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

    protected static function booted(): void
    {
        static::saved(function (Courier $courier): void {
            if ($courier->wasChanged('is_active') && $courier->user_id !== null) {
                User::whereKey($courier->user_id)->update([
                    'is_active' => (bool) $courier->is_active,
                ]);
            }
        });
    }

    protected $fillable = [
        'user_id',
        'city_id',
        'vehicle_type',
        'vehicle_plate',
        'id_card_number',
        'id_card_photo_url',
        'driver_license_number',
        'photo_path',
        'photo_url',
        'is_active',
        'activated_at',
        'activated_by',
        'is_online',
        'current_lat',
        'current_lng',
        'location_updated_at',
        'consecutive_refusals',
        'penalty_until',
        'average_rating',
        'total_ratings',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_online' => 'boolean',
        'activated_at' => 'datetime',
        'location_updated_at' => 'datetime',
        'penalty_until' => 'datetime',
        'current_lat' => 'float',
        'current_lng' => 'float',
        'consecutive_refusals' => 'integer',
        'average_rating' => 'float',
        'total_ratings' => 'integer',
    ];

    protected $appends = [
        'photo_url',
    ];

    public function getPhotoUrlAttribute(): ?string
    {
        if (! empty($this->attributes['photo_path'])) {
            return url('storage/'.$this->attributes['photo_path']);
        }

        if (! empty($this->attributes['photo_url'])) {
            return $this->attributes['photo_url'];
        }

        return $this->relationLoaded('user') && $this->user?->photo_url
            ? $this->user->photo_url
            : url('images/placeholder-driver.png');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<City, $this>
     */
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
