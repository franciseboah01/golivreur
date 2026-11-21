<?php

namespace App\Http\Requests\Profile;

class UpdateDriverProfileRequest extends BaseProfileRequest
{
    protected function adminOnlyFields(): array
    {
        return [
            'name',
            'email',
            'phone',
            'photo',
            'avatar',
            'city_id',
            'role',
            'is_active',
            'status',
            'password',
            'vehicle_type',
            'vehicle_plate',
            'driver_license_number',
            'id_card_number',
            'documents',
            'commission_rate',
            'penalty_until',
        ];
    }

    public function rules(): array
    {
        return [
            'language' => ['sometimes', 'string', 'in:fr,en'],
            'notifications_enabled' => ['sometimes', 'boolean'],
        ];
    }
}
