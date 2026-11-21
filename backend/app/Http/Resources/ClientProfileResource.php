<?php

namespace App\Http\Resources;

use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API Resource représentant le profil complet d'un Client GoLivreur.
 *
 * @mixin User
 * @mixin User
 */
class ClientProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            'role' => $this->role,
            'roles' => $this->effectiveRoles(),
            'city_id' => $this->city_id,
            'city_name' => $this->city?->name,
            'support_phone' => app(SettingsService::class)->getCitySupportPhone($this->city_id ? (int) $this->city_id : null),
            'default_address' => $this->default_address,
            'language' => $this->language ?? 'fr',
            'notifications_enabled' => (bool) ($this->notifications_enabled ?? true),
            'photo_url' => $this->photo_url,
            'avatar_url' => $this->photo_url,
            'is_active' => (bool) $this->is_active,
            'password_set' => ! empty($this->password),
            'must_change_password' => $this->mustChangePassword(),
            'pin_reset_required_at' => $this->pinResetRequiredAt(),
        ];
    }
}
