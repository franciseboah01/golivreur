<?php

namespace App\Http\Requests\Profile;

class UpdateClientProfileRequest extends BaseProfileRequest
{
    public function rules(): array
    {
        return array_merge($this->commonUserRules(), [
            'default_address' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);
    }
}
