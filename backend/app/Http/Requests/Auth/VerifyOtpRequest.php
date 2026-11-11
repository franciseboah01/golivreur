<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Concerns\NormalizesIvorianPhone;
use Illuminate\Foundation\Http\FormRequest;

class VerifyOtpRequest extends FormRequest
{
    use NormalizesIvorianPhone;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $code = $this->input('code') ?? $this->input('otp');
        $updates = [];

        if ($code !== null) {
            $updates['otp'] = (string) $code;
            $updates['code'] = (string) $code;
        }

        if ($this->has('phone') && is_string($this->input('phone'))) {
            $updates['phone'] = $this->normalizePhoneValue($this->input('phone'));
        }

        if (! empty($updates)) {
            $this->merge($updates);
        }
    }

    public function rules(): array
    {
        return [
            'phone' => ['required', 'string'],
            'otp' => ['nullable', 'string'],
            'code' => ['nullable', 'string'],
            'name' => ['nullable', 'string', 'max:255'],
            'city_id' => ['nullable', 'integer'],
            'device_token' => ['nullable', 'string'],
        ];
    }
}
