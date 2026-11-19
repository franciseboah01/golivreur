<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Concerns\NormalizesIvorianPhone;
use Illuminate\Foundation\Http\FormRequest;

class RegisterMerchantRequest extends FormRequest
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

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:150'],
            'manager_name' => ['nullable', 'string', 'max:150'],
            'phone' => ['required', 'string', 'regex:/^(07|05|01)[0-9]{8}$/'],
            'password' => ['required', 'string', 'min:6', 'max:100'],
            'city_id' => ['required', 'integer', 'exists:cities,id'],
            'category' => ['required', 'string', 'max:60'],
            'address' => ['required', 'string', 'min:2', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Veuillez saisir le nom de votre commerce.',
            'phone.required' => 'Veuillez saisir le numéro de téléphone du gérant.',
            'phone.regex' => 'Numéro ivoirien invalide (10 chiffres commençant par 01, 05 ou 07).',
            'phone.unique' => 'Ce numéro de téléphone est déjà associé à un compte.',
            'password.required' => 'Veuillez choisir un mot de passe.',
            'password.min' => 'Le mot de passe doit contenir au moins 6 caractères.',
            'city_id.required' => 'Veuillez sélectionner la ville d\'implantation.',
            'city_id.exists' => 'La ville sélectionnée est invalide.',
            'category.required' => 'Veuillez sélectionner la catégorie de votre commerce.',
            'address.required' => 'Veuillez saisir l\'adresse ou le quartier de votre commerce.',
        ];
    }
}
