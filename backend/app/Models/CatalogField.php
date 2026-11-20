<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Champ de la bibliothèque centrale du Catalogue Dynamique (contrôlée par l'Admin).
 *
 * @property int $id
 * @property string $code
 * @property string $label
 * @property string $field_type
 * @property string|null $placeholder
 * @property array<int, string>|null $select_options
 * @property bool $is_core
 * @property bool $is_universal_default
 * @property bool $is_required_default
 * @property int $default_order
 * @property bool $is_active
 */
class CatalogField extends Model
{
    use HasFactory;

    public const TYPE_TEXT = 'text';

    public const TYPE_TEXTAREA = 'textarea';

    public const TYPE_NUMBER = 'number';

    public const TYPE_CURRENCY = 'currency';

    public const TYPE_IMAGE = 'image';

    public const TYPE_SELECT = 'select';

    public const TYPE_BOOLEAN = 'boolean';

    /** @var list<string> */
    public const CORE_CODES = [
        'name',
        'description',
        'price',
        'photo',
        'quantity',
        'category',
    ];

    protected $fillable = [
        'code',
        'label',
        'field_type',
        'placeholder',
        'select_options',
        'is_core',
        'is_universal_default',
        'is_required_default',
        'default_order',
        'is_active',
    ];

    protected $casts = [
        'select_options' => 'array',
        'is_core' => 'boolean',
        'is_universal_default' => 'boolean',
        'is_required_default' => 'boolean',
        'default_order' => 'integer',
        'is_active' => 'boolean',
    ];

    /**
     * @return array<string, string>
     */
    public static function fieldTypeLabels(): array
    {
        return [
            self::TYPE_TEXT => 'Texte court',
            self::TYPE_TEXTAREA => 'Texte long',
            self::TYPE_CURRENCY => 'Prix (F CFA)',
            self::TYPE_NUMBER => 'Nombre entier',
            self::TYPE_IMAGE => 'Photo / Image',
            self::TYPE_SELECT => 'Liste de choix',
            self::TYPE_BOOLEAN => 'Oui / Non',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (CatalogField $field): void {
            if ($field->isUsedByAnyProduct()) {
                throw new DomainException(
                    "Impossible de supprimer le champ « {$field->label} » : il est déjà associé à au moins un produit existant.",
                );
            }

            $field->merchantCategories()->detach();
        });
    }

    /**
     * @return BelongsToMany<MerchantCategory, $this>
     */
    public function merchantCategories(): BelongsToMany
    {
        return $this->belongsToMany(
            MerchantCategory::class,
            'merchant_category_catalog_field',
            'catalog_field_id',
            'merchant_category_id',
        )
            ->withPivot(['custom_label', 'is_required', 'sort_order'])
            ->withTimestamps();
    }

    /**
     * Compte le nombre de produits (actifs ou archivés) possédant une valeur pour ce champ.
     */
    public function countProductsUsingField(): int
    {
        return match ($this->code) {
            'name' => Product::withTrashed()->whereNotNull('name')->where('name', '!=', '')->count(),
            'description' => Product::withTrashed()->whereNotNull('description')->where('description', '!=', '')->count(),
            'price' => Product::withTrashed()->whereNotNull('price')->count(),
            'photo' => Product::withTrashed()->whereNotNull('photo_url')->where('photo_url', '!=', '')->count(),
            'quantity' => Product::withTrashed()->whereNotNull('quantity')->count(),
            'category' => Product::withTrashed()->whereNotNull('category')->where('category', '!=', '')->count(),
            default => $this->countProductsWithDynamicAttribute($this->code),
        };
    }

    /**
     * Indique si ce champ est déjà utilisé par au moins un produit existant (interdit la suppression).
     */
    public function isUsedByAnyProduct(): bool
    {
        return $this->countProductsUsingField() > 0;
    }

    private function countProductsWithDynamicAttribute(string $code): int
    {
        $count = 0;
        $rows = Product::withTrashed()
            ->whereNotNull('custom_attributes')
            ->get(['id', 'custom_attributes']);

        foreach ($rows as $product) {
            $attrs = $product->custom_attributes;
            if (! is_array($attrs) || ! array_key_exists($code, $attrs)) {
                continue;
            }
            $val = $attrs[$code];
            if ($val !== null && $val !== '' && $val !== []) {
                $count++;
            }
        }

        return $count;
    }
}
