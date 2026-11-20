<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Concerns\NormalizesIvorianPhone;
use Illuminate\Foundation\Http\FormRequest;

class ConfirmPasswordResetRequest extends FormRequest
{
    use NormalizesIvorianPhone;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $updates = [];

        if ($this->has('phone') && is_string($this->input('phone'))) {
            $updates['phone'] = $this->normalizePhoneValue($this->input('phone'));
        }

        $code = $this->input('code') ?? $this->input('otp');
        if ($code !== null) {
            $updates['code'] = trim((string) $code);
        }

        if (! empty($updates)) {
            $this->merge($updates);
        }
    }

    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'regex:/^(01|05|07|21|25|27)[0-9]{8}$/'],
            'code' => ['required_without:firebase_id_token', 'nullable', 'string'],
            'firebase_id_token' => ['nullable', 'string'],
            'password' => ['required', 'string', 'min:6', 'max:100', 'confirmed'],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.required' => 'Veuillez saisir votre numéro de téléphone.',
            'phone.regex' => 'Format ivoirien invalide : 10 chiffres attendus.',
            'code.required_without' => 'Veuillez saisir le code de réinitialisation reçu par SMS.',
            'password.required' => 'Veuillez saisir un nouveau mot de passe.',
            'password.min' => 'Le nouveau mot de passe doit contenir au moins 6 caractères.',
            'password.confirmed' => 'La confirmation du mot de passe ne correspond pas.',
        ];
    }
}
