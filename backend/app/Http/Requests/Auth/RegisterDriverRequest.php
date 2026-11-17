<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Concerns\NormalizesIvorianPhone;
use Illuminate\Foundation\Http\FormRequest;

class RegisterDriverRequest extends FormRequest
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

        if ($this->has('vehicle_plate') && is_string($this->input('vehicle_plate'))) {
            $this->merge(['vehicle_plate' => strtoupper(trim($this->input('vehicle_plate')))]);
        }
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'phone' => ['required', 'string', 'regex:/^(07|05|01)[0-9]{8}$/', 'unique:users,phone'],
            'password' => ['required', 'string', 'min:6', 'max:100'],
            'city_id' => ['required', 'integer', 'exists:cities,id'],
            'vehicle_type' => ['required', 'string', 'max:50'],
            'vehicle_plate' => ['required', 'string', 'min:3', 'max:30'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Veuillez saisir votre nom et prénom.',
            'phone.required' => 'Veuillez saisir votre numéro de téléphone.',
            'phone.regex' => 'Numéro ivoirien invalide (10 chiffres commençant par 01, 05 ou 07).',
            'phone.unique' => 'Ce numéro de téléphone est déjà associé à un compte.',
            'password.required' => 'Veuillez choisir un mot de passe.',
            'password.min' => 'Le mot de passe doit contenir au moins 6 caractères.',
            'city_id.required' => 'Veuillez sélectionner votre ville.',
            'city_id.exists' => 'La ville sélectionnée est invalide.',
            'vehicle_type.required' => 'Veuillez sélectionner votre type de véhicule.',
            'vehicle_plate.required' => 'Veuillez saisir la plaque d\'immatriculation de votre véhicule.',
        ];
    }
}

