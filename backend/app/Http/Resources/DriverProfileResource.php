<?php

namespace App\Http\Resources;

use App\Models\VehicleType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API Resource représentant le profil complet d'un Livreur Partenaire (User + Courier).
 */
class DriverProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $courier = $this->courier;
        $vehicleType = $courier?->vehicle_type;
        $vehicleTypeName = $vehicleType
            ? (VehicleType::where('slug', $vehicleType)->value('name') ?? ucfirst($vehicleType))
            : null;

        return [
            'id'                    => $this->id,
            'user_id'               => $this->id,
            'courier_id'            => $courier?->id,
            'name'                  => $this->name,
            'phone'                 => $this->phone,
            'email'                 => $this->email,
            'role'                  => $this->role,
            'city_id'               => $courier?->city_id ?? $this->city_id,
            'city_name'             => ($courier?->city ?? $this->city)?->name,
            'language'              => $this->language ?? 'fr',
            'notifications_enabled' => (bool) ($this->notifications_enabled ?? true),
            'photo_url'             => $courier?->photo_url ?? $this->photo_url ?? url('images/placeholder-driver.png'),
            'user_photo_url'        => $this->photo_url,
            'vehicle_type'          => $vehicleType,
            'vehicle_type_name'     => $vehicleTypeName,
            'vehicle_plate'         => $courier?->vehicle_plate,
            'id_card_number'        => $courier?->id_card_number,
            'driver_license_number' => $courier?->driver_license_number,
            'is_online'             => (bool) ($courier?->is_online ?? false),
            'is_active'             => (bool) ($courier?->is_active ?? $this->is_active),
            'average_rating'        => (float) ($courier?->average_rating ?? 0),
            'total_ratings'         => (int) ($courier?->total_ratings ?? 0),
        ];
    }
}
