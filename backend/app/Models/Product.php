<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Produit / Plat du catalogue commerçant GoShop (avec attributs dynamiques contrôlés par l'Admin).
 *
 * @property int $id
 * @property int $merchant_id
 * @property string $name
 * @property string|null $description
 * @property int $price
 * @property string|null $photo_url
 * @property bool $is_available
 * @property int|null $quantity
 * @property string|null $category
 * @property int $sort_order
 * @property array<string, mixed>|null $custom_attributes
 */
class Product extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'merchant_id',
        'name',
        'description',
        'price',
        'photo_url',
        'is_available',
        'quantity',
        'category',
        'sort_order',
        'custom_attributes',
    ];

    protected $casts = [
        'price' => 'integer',
        'is_available' => 'boolean',
        'quantity' => 'integer',
        'sort_order' => 'integer',
        'custom_attributes' => 'array',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $product): void {
            if ($product->quantity !== null && (int) $product->quantity <= 0) {
                $product->quantity = 0;
                $product->is_available = false;
            }
        });
    }

    /**
     * Résout une URL publique exploitable pour la photo du produit
     * (compatible avec les anciens liens complets et les uploads locaux sur le disque `public`).
     */
    public function getPhotoUrlAttribute(?string $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        $trimmed = trim($value);
        if (str_starts_with($trimmed, 'http://') || str_starts_with($trimmed, 'https://') || str_starts_with($trimmed, '/')) {
            return $trimmed;
        }

        return url('storage/'.ltrim($trimmed, '/'));
    }

    /**
     * Alias conservé pour les appels explicites.
     */
    public function getResolvedPhotoUrlAttribute(): ?string
    {
        return $this->photo_url;
    }

    /**
     * @return BelongsTo<Merchant, $this>
     */
    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }
}
