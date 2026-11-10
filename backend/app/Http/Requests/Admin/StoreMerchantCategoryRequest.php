<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreMerchantCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100', 'unique:merchant_categories,name'],
            'slug' => ['required', 'string', 'max:100', 'unique:merchant_categories,slug'],
            'description' => ['nullable', 'string', 'max:500'],
            'emoji' => ['nullable', 'string', 'max:32'],
            'logo' => [
                'nullable',
                'image',
                'mimes:jpeg,jpg,png,webp',
                'max:2048', // 2 Mo max
            ],
            'is_active' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Le nom de la catégorie est obligatoire.',
            'name.unique' => 'Une catégorie portant ce nom existe déjà.',
            'slug.required' => 'L\'identifiant système (slug) est obligatoire.',
            'slug.unique' => 'Cet identifiant (slug) est déjà utilisé.',
            'emoji.max' => 'L\'emoji sélectionné est trop long.',
            'logo.image' => 'Le logo de la catégorie doit être une image valide.',
            'logo.mimes' => 'Seuls les formats JPG, PNG et WEBP sont acceptés pour le logo.',
            'logo.max' => 'Le logo de la catégorie ne doit pas dépasser 2 Mo.',
        ];
    }
}

