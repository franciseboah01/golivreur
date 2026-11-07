<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class VerifyOtpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $code = $this->input('code') ?? $this->input('otp');
        if ($code !== null) {
            $this->merge([
                'otp'  => (string) $code,
                'code' => (string) $code,
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'phone'        => ['required', 'string'],
            'otp'          => ['nullable', 'string'],
            'code'         => ['nullable', 'string'],
            'name'         => ['nullable', 'string'],
            'device_token' => ['nullable', 'string'],
        ];
    }
}
