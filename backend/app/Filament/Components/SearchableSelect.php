<?php

namespace App\Filament\Components;

use Filament\Forms\Components\Select;

/**
 * Composant réutilisable pour liste déroulante harmonisée.
 * Applique systématiquement la recherche live client, le préchargement
 * des options, le rendu non natif avec barre de recherche et le tri alphabétique.
 */
class SearchableSelect
{
    public static function make(string $name): Select
    {
        return Select::make($name)
            ->searchable()
            ->preload()
            ->native(false)
            ->searchPrompt('Rechercher dans la liste...')
            ->noSearchResultsMessage('Aucun résultat trouvé.');
    }

    /**
     * Trie un tableau associatif [clé => libellé] par ordre alphabétique sur le libellé.
     *
     * @param  array<int|string, string>  $options
     * @return array<int|string, string>
     */
    public static function sortedOptions(array $options): array
    {
        uasort($options, function (string $a, string $b): int {
            if (class_exists(\Collator::class)) {
                $collator = new \Collator('fr_FR');

                return (int) $collator->compare($a, $b);
            }

            return strnatcasecmp($a, $b);
        });

        return $options;
    }
}
