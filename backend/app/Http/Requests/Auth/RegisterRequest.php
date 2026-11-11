<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Concerns\NormalizesIvorianPhone;
use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    use NormalizesIvorianPhone;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('phone') && is_string($this->input('phone'))) {
            $this->merge(['phone' => $this->normalizePhoneValue($this->input('phone'))]);
        }
    }

    public function rules(): array
    {
        return [
            'phone' => ['required', 'string'],
            'name' => ['nullable', 'string', 'max:255'],
            'city_id' => ['nullable', 'integer'],
        ];
    }
}
