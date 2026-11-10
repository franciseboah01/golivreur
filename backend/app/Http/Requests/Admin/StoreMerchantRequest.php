<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreMerchantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'user_id' => ['required', 'exists:users,id'],
            'city_id' => ['required', 'exists:cities,id'],
            'category' => ['required', 'string', 'max:100'],
            'emoji' => ['nullable', 'string', 'max:32'],
            'address' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'commission_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'prep_time_minutes' => ['nullable', 'integer', 'min:1', 'max:240'],
            'photo' => [
                'nullable',
                'image',
                'mimes:jpeg,jpg,png,webp',
                'max:2048', // 2 Mo max
            ],
            'logo' => [
                'nullable',
                'image',
                'mimes:jpeg,jpg,png,webp',
                'max:2048', // 2 Mo max
            ],
            // Sous-champs si création dynamique d'un nouveau gestionnaire
            'manager_name' => ['nullable', 'string', 'max:255'],
            'manager_phone' => ['nullable', 'string', 'max:20', 'unique:users,phone'],
            'manager_email' => ['nullable', 'email', 'max:255', 'unique:users,email'],
            'manager_password' => ['nullable', 'string', 'min:8'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Le nom de l\'enseigne est obligatoire.',
            'user_id.required' => 'Veuillez associer un compte gestionnaire au commerce.',
            'user_id.exists' => 'Le compte gestionnaire sélectionné est invalide.',
            'city_id.required' => 'La ville d\'implantation est obligatoire.',
            'category.required' => 'Veuillez sélectionner ou créer une catégorie d\'activité.',
            'photo.image' => 'Le fichier doit être une image valide.',
            'photo.mimes' => 'Seuls les formats JPG, PNG et WEBP sont acceptés pour la photo du commerce.',
            'photo.max' => 'La photo du commerce ne doit pas dépasser 2 Mo.',
            'logo.image' => 'Le logo de la boutique doit être une image valide.',
            'logo.mimes' => 'Seuls les formats JPG, PNG et WEBP sont acceptés pour le logo.',
            'logo.max' => 'Le logo de la boutique ne doit pas dépasser 2 Mo.',
            'emoji.max' => 'L\'emoji sélectionné est trop long.',
            'manager_phone.unique' => 'Ce numéro de téléphone est déjà utilisé par un autre utilisateur.',
            'manager_email.unique' => 'Cette adresse email est déjà utilisée par un autre utilisateur.',
        ];
    }
}

