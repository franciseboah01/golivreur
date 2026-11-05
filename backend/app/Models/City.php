<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class City extends Model
{
    use HasFactory;

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
        'is_active'                   => 'boolean',
        'rain_mode_active'            => 'boolean',
        'rain_mode_activated_at'      => 'datetime',
        'emergency_mode_active'       => 'boolean',
        'emergency_mode_activated_at' => 'datetime',
        'center_lat'                  => 'float',
        'center_lng'                  => 'float',
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
}

