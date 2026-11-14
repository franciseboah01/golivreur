<?php

namespace App\Enums;

use App\Services\SettingsService;

enum PaymentMethod: string
{
    case CASH = 'cash';
    case ORANGE_MONEY = 'orange_money';
    case WAVE = 'wave';
    case MTN_MOMO = 'mtn_momo';
    case MOOV = 'moov';
    case WALLET = 'wallet';

    /**
     * Modes sélectionnables par un client au checkout et activables via Settings.
     *
     * @return list<self>
     */
    public static function clientCases(): array
    {
        return [
            self::CASH,
            self::ORANGE_MONEY,
            self::WAVE,
            self::MTN_MOMO,
            self::MOOV,
        ];
    }

    public function isClientSelectable(): bool
    {
        return $this !== self::WALLET;
    }

    public function label(): string
    {
        return match ($this) {
            self::CASH => 'Espèces à la livraison',
            self::WALLET => 'Portefeuille GoLivreur',
            self::WAVE => 'Wave Mobile Money',
            self::ORANGE_MONEY => 'Orange Money',
            self::MTN_MOMO => 'MTN MoMo',
            self::MOOV => 'Moov Money',
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::CASH => 'Espèces',
            self::WALLET => 'Portefeuille',
            self::WAVE => 'Wave',
            self::ORANGE_MONEY => 'Orange',
            self::MTN_MOMO => 'MTN',
            self::MOOV => 'Moov',
        };
    }

    public function settingsLabel(): string
    {
        return match ($this) {
            self::CASH => 'Espèces à la livraison (Cash)',
            self::ORANGE_MONEY => 'Orange Money Côte d\'Ivoire',
            self::WAVE => 'Wave Côte d\'Ivoire',
            self::MTN_MOMO => 'MTN Mobile Money',
            self::MOOV => 'Moov Money (Flooz)',
            self::WALLET => 'Portefeuille GoLivreur',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::CASH => '#10b981',
            self::WALLET => '#6366f1',
            self::WAVE => '#06b6d4',
            self::ORANGE_MONEY => '#f97316',
            self::MTN_MOMO => '#eab308',
            self::MOOV => '#3b82f6',
        };
    }

    /**
     * Inclut les anciens alias historiques pour les requêtes d'agrégation en base.
     *
     * @return list<string>
     */
    public function valuesWithLegacyAliases(): array
    {
        return match ($this) {
            self::MTN_MOMO => [self::MTN_MOMO->value, 'mtn'],
            self::ORANGE_MONEY => [self::ORANGE_MONEY->value, 'orange'],
            default => [$this->value],
        };
    }

    /**
     * Résout un identifiant canonique ou un ancien alias (mtn → mtn_momo, orange → orange_money).
     */
    public static function tryFromInput(?string $value): ?self
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($value) {
            'mtn' => self::MTN_MOMO,
            'orange' => self::ORANGE_MONEY,
            default => self::tryFrom($value),
        };
    }

    /**
     * Options pour SettingsPage (modes activables pour les clients).
     *
     * @return array<string, string>
     */
    public static function settingsOptions(): array
    {
        $options = [];
        foreach (self::clientCases() as $case) {
            $options[$case->value] = $case->settingsLabel();
        }

        return $options;
    }

    /**
     * Options complètes pour les ressources Filament (formulaires et filtres).
     *
     * @return array<string, string>
     */
    public static function resourceOptions(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    /**
     * Ordre d'affichage des graphiques (compatibilité labels CDC / PaymentMethodsChart).
     *
     * @return list<self>
     */
    public static function chartOrder(): array
    {
        return [
            self::CASH,
            self::WALLET,
            self::WAVE,
            self::ORANGE_MONEY,
            self::MTN_MOMO,
            self::MOOV,
        ];
    }

    /**
     * Liste canonique des modes actifs selon SettingsService (défaut D-PAIEMENT-1 : ['cash']).
     *
     * @return list<string>
     */
    public static function activeValues(?int $cityId = null, ?SettingsService $settingsService = null): array
    {
        $service = $settingsService ?? app(SettingsService::class);
        $config = $service->get('paiements', $cityId);
        $raw = is_array($config) ? ($config['modes_actifs'] ?? null) : null;

        if (! is_array($raw)) {
            return [self::CASH->value];
        }

        $active = [];
        foreach ($raw as $item) {
            if (! is_string($item)) {
                continue;
            }
            $enum = self::tryFromInput($item);
            if ($enum !== null && $enum->isClientSelectable() && ! in_array($enum->value, $active, true)) {
                $active[] = $enum->value;
            }
        }

        return $active !== [] ? $active : [self::CASH->value];
    }

    /**
     * Retourne les objets PaymentMethod actifs.
     *
     * @return list<self>
     */
    public static function activeCases(?int $cityId = null, ?SettingsService $settingsService = null): array
    {
        $cases = [];
        foreach (self::activeValues($cityId, $settingsService) as $value) {
            $case = self::tryFrom($value);
            if ($case !== null) {
                $cases[] = $case;
            }
        }

        return $cases;
    }

    public static function defaultActive(?int $cityId = null, ?SettingsService $settingsService = null): string
    {
        $active = self::activeValues($cityId, $settingsService);

        return in_array(self::CASH->value, $active, true)
            ? self::CASH->value
            : $active[0];
    }
}
