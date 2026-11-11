<?php

namespace App\Http\Requests\Profile;

class UpdateDriverProfileRequest extends BaseProfileRequest
{
    public function rules(): array
    {
        return array_merge($this->commonUserRules(), [
            'vehicle_type' => ['sometimes', 'required', 'string', 'max:50'],
            'vehicle_plate' => ['sometimes', 'nullable', 'string', 'max:30'],
            'driver_license_number' => ['sometimes', 'nullable', 'string', 'max:50'],
            'id_card_number' => ['sometimes', 'nullable', 'string', 'max:50'],
        ]);
    }
}
