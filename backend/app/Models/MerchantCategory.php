<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        if (!empty($this->attributes['logo_path'])) {
            return url('storage/' . $this->attributes['logo_path']);
        }

        return null;
    }

    public function merchants(): HasMany
    {
        return $this->hasMany(Merchant::class, 'category', 'slug');
    }
}
