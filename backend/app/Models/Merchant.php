<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property-read string|null $logo_url
 * @property-read string $resolved_emoji
 */
class Merchant extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'city_id',
        'name',
        'category',
        'emoji',
        'logo_path',
        'description',
        'address',
        'photo_path',
        'lat',
        'lng',
        'open_hours',
        'prep_time_minutes',
        'is_active',
        'is_open',
        'commission_rate',
        'photo_url',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_open' => 'boolean',
        'open_hours' => 'array',
        'prep_time_minutes' => 'integer',
        'commission_rate' => 'float',
        'lat' => 'float',
        'lng' => 'float',
    ];

    protected $appends = [
        'photo_url',
        'logo_url',
    ];

    /**
     * URL publique de la photo principale du commerce (vitrine / bannière).
     */
    public function getPhotoUrlAttribute(): ?string
    {
        if (! empty($this->attributes['photo_path'])) {
            return url('storage/'.$this->attributes['photo_path']);
        }

        if (! empty($this->attributes['photo_url'])) {
            return $this->attributes['photo_url'];
        }

        return $this->logo_url ?? url('images/placeholder-merchant.png');
    }

    /**
     * URL publique du logo de la boutique (si uploadé depuis l'admin).
     */
    public function getLogoUrlAttribute(): ?string
    {
        if (! empty($this->attributes['logo_path'])) {
            return url('storage/'.$this->attributes['logo_path']);
        }

        return null;
    }

    /**
     * Emoji effectif : celui de la boutique, sinon celui de sa catégorie, sinon un fallback.
     */
    public function getResolvedEmojiAttribute(): string
    {
        if (! empty($this->attributes['emoji'])) {
            return $this->attributes['emoji'];
        }

        return $this->categoryModel?->emoji ?: '🏪';
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

    /**
     * @return BelongsTo<MerchantCategory, $this>
     */
    public function categoryModel(): BelongsTo
    {
        return $this->belongsTo(MerchantCategory::class, 'category', 'slug');
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
