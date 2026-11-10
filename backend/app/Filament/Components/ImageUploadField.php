<?php

namespace App\Filament\Components;

use Filament\Forms\Components\FileUpload;

/**
 * Composant réutilisable d'upload d'image (photos commerces, logos, photos livreurs).
 * Inclut l'aperçu instantané, le stockage public, la validation max 2 Mo et les formats JPG/PNG/WEBP.
 */
class ImageUploadField
{
    public static function make(string $name, string $directory, string $label): FileUpload
    {
        return FileUpload::make($name)
            ->label($label)
            ->image()
            ->disk('public')
            ->directory($directory)
            ->maxSize(2048)
            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
            ->imageEditor()
            ->previewable()
            ->helperText('Formats acceptés : JPG, PNG, WEBP (Max : 2 Mo). Aperçu instantané avant validation.');
    }
}

