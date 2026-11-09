<?php

namespace App\Filament\Components;

use Filament\Forms\Components\Select;

/**
 * Composant réutilisable pour liste déroulante harmonisée.
 * Applique systématiquement la recherche live client, le préchargement
 * des options et le rendu moderne non natif avec barre de recherche.
 */
class SearchableSelect
{
    public static function make(string $name): Select
    {
        return Select::make($name)
            ->searchable()
            ->preload()
            ->native(false);
    }
}

