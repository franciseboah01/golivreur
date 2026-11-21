<?php

namespace App\Http\Requests\Profile;

class UpdateBusinessProfileRequest extends BaseProfileRequest
{
    protected function adminOnlyFields(): array
    {
        return [
            'name',
            'business_name',
            'merchant_name',
            'category',
            'neighborhood',
            'neighborhood_id',
            'quartier',
            'address',
            'lat',
            'lng',
            'latitude',
            'longitude',
            'city_id',
            'documents',
            'id_card_number',
            'rccm',
            'phone',
            'email',
            'photo',
            'role',
            'is_active',
            'status',
            'commission_rate',
            'password',
        ];
    }

    public function rules(): array
    {
        return [
            'emoji' => ['sometimes', 'nullable', 'string', 'max:32'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'open_hours' => ['sometimes', 'nullable'],
            'prep_time_minutes' => ['sometimes', 'integer', 'min:1', 'max:240'],
            'is_open' => ['sometimes', 'boolean'],
            'logo' => ['sometimes', 'nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:2048'],
            'merchant_photo' => ['sometimes', 'nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:2048'],
            'language' => ['sometimes', 'string', 'in:fr,en'],
            'notifications_enabled' => ['sometimes', 'boolean'],
        ];
    }
}
