<?php

namespace App\Http\Requests\Profile;

use App\Http\Requests\Concerns\NormalizesIvorianPhone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

abstract class BaseProfileRequest extends FormRequest
{
    use NormalizesIvorianPhone;

    /**
     * Empêche la modification d'un autre utilisateur via un paramètre user_id usurpé.
     */
    public function authorize(): bool
    {
        if (! auth()->check()) {
            return false;
        }

        if ($this->filled('user_id') && (int) $this->input('user_id') !== (int) auth()->id()) {
            return false;
        }

        return true;
    }

    /**
     * Normalise le numéro de téléphone ivoirien avant validation (supprime espaces, tirets et +225).
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('phone') && is_string($this->input('phone'))) {
            $this->merge(['phone' => $this->normalizePhoneValue($this->input('phone'))]);
        }

        if ($this->has('email') && $this->input('email') === '') {
            $this->merge(['email' => null]);
        }

        if ($this->has('notifications_enabled')) {
            $val = $this->input('notifications_enabled');
            if (is_string($val)) {
                $this->merge([
                    'notifications_enabled' => filter_var($val, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true,
                ]);
            }
        }
    }

    /**
     * Règles communes partagées entre Client, Driver et Business.
     */
    protected function commonUserRules(): array
    {
        $userId = auth()->id();

        return [
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:100'],
            'phone' => [
                'sometimes',
                'required',
                'string',
                'regex:/^(01|05|07|21|25|27)[0-9]{8}$/',
                Rule::unique('users', 'phone')->ignore($userId),
            ],
            'email' => [
                'sometimes',
                'nullable',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($userId),
            ],
            'city_id' => ['prohibited'],
            'language' => ['sometimes', 'string', Rule::in(['fr', 'en'])],
            'notifications_enabled' => ['sometimes', 'boolean'],
            'photo' => ['sometimes', 'nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:2048'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Le nom complet est obligatoire.',
            'name.min' => 'Le nom doit contenir au moins 2 caractères.',
            'phone.required' => 'Le numéro de téléphone est obligatoire.',
            'phone.regex' => 'Format ivoirien invalide : 10 chiffres attendus (ex: 0701000001).',
            'phone.unique' => 'Ce numéro de téléphone est déjà utilisé par un autre compte.',
            'email.email' => 'Veuillez saisir une adresse email valide.',
            'email.unique' => 'Cette adresse email est déjà utilisée par un autre compte.',
            'city_id.prohibited' => 'La modification de la ville via le profil est réservée à l\'administration.',
            'photo.image' => 'La photo doit être un fichier image valide.',
            'photo.mimes' => 'Formats d\'image acceptés : JPG, PNG, WEBP.',
            'photo.max' => 'La photo de profil ne doit pas dépasser 2 Mo.',
            'logo.image' => 'Le logo doit être une image valide.',
            'logo.mimes' => 'Formats acceptés pour le logo : JPG, PNG, WEBP.',
            'logo.max' => 'Le logo ne doit pas dépasser 2 Mo.',
            'merchant_photo.image' => 'La photo du commerce doit être une image valide.',
            'merchant_photo.mimes' => 'Formats acceptés pour la photo du commerce : JPG, PNG, WEBP.',
            'merchant_photo.max' => 'La photo du commerce ne doit pas dépasser 2 Mo.',
            'language.in' => 'La langue doit être "fr" ou "en".',
            'category.exists' => 'La catégorie sélectionnée est invalide.',
        ];
    }
}
