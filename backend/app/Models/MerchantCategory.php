<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property-read string|null $logo_url
 */
class MerchantCategory extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'icon',
        'emoji',
        'logo_path',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected $appends = [
        'logo_url',
    ];

    /**
     * URL publique complète du logo de la catégorie (si uploadé).
     */
    public function getLogoUrlAttribute(): ?string
    {
        if (! empty($this->attributes['logo_path'])) {
            return url('storage/'.$this->attributes['logo_path']);
        }

        return null;
    }

    public function merchants(): HasMany
    {
        return $this->hasMany(Merchant::class, 'category', 'slug');
    }

    /**
     * Champs du dictionnaire cochés et ordonnés pour ce Type de Commerce.
     *
     * @return BelongsToMany<CatalogField, $this>
     */
    public function catalogFields(): BelongsToMany
    {
        return $this->belongsToMany(
            CatalogField::class,
            'merchant_category_catalog_field',
            'merchant_category_id',
            'catalog_field_id',
        )
            ->withPivot(['custom_label', 'is_required', 'sort_order'])
            ->withTimestamps()
            ->orderBy('merchant_category_catalog_field.sort_order')
            ->orderBy('catalog_fields.default_order')
            ->orderBy('catalog_fields.id');
    }
}
