<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Merchant extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'city_id',
        'name',
        'category',
        'description',
        'address',
        'lat',
        'lng',
        'open_hours',
        'prep_time_minutes',
        'is_active',
        'is_open',
        'commission_rate',
        'logo_url',
        'banner_url',
    ];

    protected $casts = [
        'is_active'         => 'boolean',
        'is_open'           => 'boolean',
        'open_hours'        => 'array',
        'prep_time_minutes' => 'integer',
        'commission_rate'   => 'float',
        'lat'               => 'float',
        'lng'               => 'float',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
