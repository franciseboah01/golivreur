<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API Resource représentant le profil complet d'un Commerce Partenaire (User + Merchant).
 */
class BusinessProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $merchant = $this->merchant;
        $openHours = $merchant?->open_hours;

        $openHoursText = null;
        if (is_array($openHours)) {
            $openHoursText = $openHours['summary'] ?? implode(' | ', array_map(
                fn ($k, $v) => is_string($k) ? "{$k}: {$v}" : (string) $v,
                array_keys($openHours),
                array_values($openHours)
            ));
        } elseif (is_string($openHours)) {
            $openHoursText = $openHours;
        }

        return [
            'id'                    => $this->id,
            'user_id'               => $this->id,
            'merchant_id'           => $merchant?->id,
            'name'                  => $this->name,
            'phone'                 => $this->phone,
            'email'                 => $this->email,
            'role'                  => $this->role,
            'city_id'               => $merchant?->city_id ?? $this->city_id,
            'city_name'             => ($merchant?->city ?? $this->city)?->name,
            'language'              => $this->language ?? 'fr',
            'notifications_enabled' => (bool) ($this->notifications_enabled ?? true),
            'photo_url'             => $this->photo_url,
            'business_name'         => $merchant?->name,
            'category'              => $merchant?->category,
            'category_name'         => $merchant?->categoryModel?->name ?? ucfirst((string) ($merchant?->category ?? '')),
            'emoji'                 => $merchant?->resolved_emoji ?? '🏪',
            'logo_url'              => $merchant?->logo_url ?? $merchant?->categoryModel?->logo_url,
            'merchant_photo_url'    => $merchant?->photo_url,
            'address'               => $merchant?->address,
            'description'           => $merchant?->description,
            'open_hours'            => $openHours,
            'open_hours_text'       => $openHoursText,
            'prep_time_minutes'     => (int) ($merchant?->prep_time_minutes ?? 20),
            'is_open'               => (bool) ($merchant?->is_open ?? false),
            'is_active'             => (bool) ($merchant?->is_active ?? $this->is_active),
            'average_rating'        => (float) ($merchant?->average_rating ?? 0),
            'total_ratings'         => (int) ($merchant?->total_ratings ?? 0),
        ];
    }
}
