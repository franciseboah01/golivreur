<?php

namespace App\Http\Requests\Profile;

class UpdateBusinessProfileRequest extends BaseProfileRequest
{
    public function rules(): array
    {
        return array_merge($this->commonUserRules(), [
            'business_name' => ['sometimes', 'required', 'string', 'min:2', 'max:100'],
            'merchant_name' => ['sometimes', 'required', 'string', 'min:2', 'max:100'],
            'category' => ['sometimes', 'required', 'string', 'max:100'],
            'emoji' => ['sometimes', 'nullable', 'string', 'max:32'],
            'address' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'open_hours' => ['sometimes', 'nullable'],
            'prep_time_minutes' => ['sometimes', 'integer', 'min:1', 'max:240'],
            'logo' => ['sometimes', 'nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:2048'],
            'merchant_photo' => ['sometimes', 'nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:2048'],
        ]);
    }
}
