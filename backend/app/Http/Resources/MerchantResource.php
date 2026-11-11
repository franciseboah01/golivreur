<?php

namespace App\Http\Resources;

use App\Models\Merchant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API Resource représentant un Commerce Partenaire pour l'application mobile.
 *
 * Champs ajoutés pour la cohérence Admin <-> Mobile :
 * - name           (string)      : Nom de l'enseigne en base de données
 * - category       (string)      : Slug de la catégorie (ex: "maquis")
 * - category_name  (string|null) : Libellé officiel de la catégorie depuis `merchant_categories`
 * - category_emoji (string|null) : Emoji de la catégorie associée
 * - emoji          (string)      : Emoji du commerce (ou celui de sa catégorie en fallback, sinon "🏪")
 * - logo_url       (string|null) : URL publique du logo boutique (`storage/commerces/logos/...`)
 * - photo_url      (string|null) : URL publique de la photo vitrine (`storage/commerces/...`)
 *
 * @mixin Merchant
 */
class MerchantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'city_id' => $this->city_id,
            'name' => $this->name,
            'category' => $this->category,
            'category_name' => $this->categoryModel->name ?? ucfirst((string) $this->category),
            'category_emoji' => $this->categoryModel?->emoji,
            'emoji' => $this->resolved_emoji,
            'logo_url' => $this->logo_url ?? $this->categoryModel?->logo_url,
            'photo_url' => $this->photo_url,
            'description' => $this->description,
            'address' => $this->address,
            'lat' => (float) $this->lat,
            'lng' => (float) $this->lng,
            'prep_time_minutes' => (int) $this->prep_time_minutes,
            'is_open' => (bool) $this->is_open,
            'is_active' => (bool) $this->is_active,
            'average_rating' => (float) ($this->average_rating ?? 0),
            'total_ratings' => (int) ($this->total_ratings ?? 0),
            'products_count' => $this->whenCounted('products'),
            'products' => $this->whenLoaded('products'),
        ];
    }
}
