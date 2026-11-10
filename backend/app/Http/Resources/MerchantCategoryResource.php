<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API Resource représentant une Catégorie d'Activité pour l'application mobile.
 *
 * Champs exposés :
 * - id          (int)         : Identifiant unique en base
 * - name        (string)      : Nom lisible de la catégorie (ex: "Maquis & Grillades")
 * - slug        (string)      : Identifiant technique (ex: "maquis")
 * - description (string|null) : Description courte des produits
 * - icon        (string|null) : Nom d'icône Material fallback éventuel
 * - emoji       (string)      : Emoji configuré dans l'admin (fallback "🏪")
 * - logo_url    (string|null) : URL publique du logo uploadé, ou null si absent
 * - is_active   (bool)        : Statut d'activation
 */
class MerchantCategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'name'        => $this->name,
            'slug'        => $this->slug,
            'description' => $this->description,
            'icon'        => $this->icon,
            'emoji'       => $this->emoji ?: '🏪',
            'logo_url'    => $this->logo_url,
            'is_active'   => (bool) $this->is_active,
        ];
    }
}

