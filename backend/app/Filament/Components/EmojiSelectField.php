<?php

namespace App\Filament\Components;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;

/**
 * Composant réutilisable de sélection d'emoji pour boutiques et catégories d'activité.
 * Offre une liste triée avec barre de recherche live et l'ajout d'un emoji personnalisé via le bouton +.
 */
class EmojiSelectField
{
    /**
     * Liste des emojis standards (triés alphabétiquement par intitulé métier).
     *
     * @return array<string, string>
     */
    public static function presetOptions(): array
    {
        return [
            '🏪' => '🏪 Autre Commerce / Supérette',
            '💅' => '💅 Beauté & Cosmétiques',
            '🥤' => '🥤 Boissons, Jus & Vins',
            '🥩' => '🥩 Boucherie & Viandes',
            '🥐' => '🥐 Boulangerie & Pâtisserie',
            '☕' => '☕ Café & Salon de thé',
            '👟' => '👟 Chaussures & Sport',
            '💇' => '💇 Coiffure & Esthétique',
            '📦' => '📦 Colis & Livraison Express',
            '🍔' => '🍔 Fast-Food & Burgers',
            '💐' => '💐 Fleuriste & Cadeaux',
            '🥦' => '🥦 Fruits, Légumes & Primeur',
            '📱' => '📱 High-Tech & Téléphonie',
            '💻' => '💻 Informatique & Bureautique',
            '📚' => '📚 Librairie & Papeterie',
            '🍖' => '🍖 Maquis & Grillades',
            '👗' => '👗 Mode, Prêt-à-porter & Tissus',
            '🍞' => '🍞 Pain & Viennoiseries',
            '🍰' => '🍰 Pâtisserie & Gâteaux',
            '💊' => '💊 Pharmacie & Parapharmacie',
            '🍕' => '🍕 Pizzeria',
            '🐟' => '🐟 Poissonnerie & Braise',
            '🍗' => '🍗 Poulet Braisé & Rôtisserie',
            '🍽️' => '🍽️ Restaurant & Cuisine Locale',
            '🥗' => '🥗 Salades & Restauration Saine',
            '🛒' => '🛒 Supermarché & Épicerie',
        ];
    }

    public static function make(string $name = 'emoji', string $label = 'Emoji représentatif'): Select
    {
        return Select::make($name)
            ->label($label)
            ->options(function (?string $state): array {
                $options = self::presetOptions();
                if (! empty($state) && ! isset($options[$state])) {
                    $options = [$state => "{$state} Emoji personnalisé"] + $options;
                }

                return $options;
            })
            ->searchable()
            ->preload()
            ->native(false)
            ->searchPrompt('Rechercher un emoji (ex: maquis, pharmacie, burger)...')
            ->placeholder('Choisir un emoji ou cliquez sur +')
            ->createOptionForm([
                TextInput::make('custom_emoji')
                    ->label('Saisir ou coller un emoji personnalisé')
                    ->placeholder('Ex: 🍲, 🍦, 🧃, 🧁')
                    ->required()
                    ->maxLength(16),
            ])
            ->createOptionUsing(fn (array $data): string => trim($data['custom_emoji']))
            ->helperText('Sélectionnez un emoji ou cliquez sur + pour en saisir un personnalisé. Prioritaire si aucun logo image n\'est uploadé.');
    }
}
