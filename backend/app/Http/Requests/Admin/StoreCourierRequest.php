<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreCourierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'user_id' => ['required', 'exists:users,id'],
            'city_id' => ['required', 'exists:cities,id'],
            'vehicle_type' => ['required', 'string', 'max:50'],
            'vehicle_plate' => ['nullable', 'string', 'max:30'],
            'id_card_number' => ['nullable', 'string', 'max:50'],
            'photo' => [
                'nullable',
                'image',
                'mimes:jpeg,jpg,png,webp',
                'max:2048', // 2 Mo max
            ],
            // Sous-champs si création dynamique d'un nouveau livreur
            'driver_name' => ['nullable', 'string', 'max:255'],
            'driver_phone' => ['nullable', 'string', 'max:20', 'unique:users,phone'],
            'driver_email' => ['nullable', 'email', 'max:255', 'unique:users,email'],
            'driver_password' => ['nullable', 'string', 'min:8'],
        ];
    }

    public function messages(): array
    {
        return [
            'user_id.required' => 'Veuillez associer un compte utilisateur au profil livreur.',
            'user_id.exists' => 'Le compte utilisateur sélectionné est invalide.',
            'city_id.required' => 'La ville d\'affectation est obligatoire.',
            'vehicle_type.required' => 'Veuillez sélectionner un type de véhicule.',
            'photo.image' => 'Le fichier doit être une image valide.',
            'photo.mimes' => 'Seuls les formats JPG, PNG et WEBP sont acceptés pour la photo du livreur.',
            'photo.max' => 'La photo du livreur ne doit pas dépasser 2 Mo.',
            'driver_phone.unique' => 'Ce numéro de téléphone est déjà utilisé par un autre utilisateur.',
            'driver_email.unique' => 'Cette adresse email est déjà utilisée par un autre utilisateur.',
        ];
    }
}

