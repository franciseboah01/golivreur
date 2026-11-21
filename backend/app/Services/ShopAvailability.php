<?php

namespace App\Services;

use App\Models\Merchant;
use Carbon\Carbon;

/**
 * Service métier centralisé des horaires d'ouverture des boutiques GoShop (fuseau Africa/Abidjan).
 *
 * Format structuré supporté dans `merchants.open_hours` :
 *   [
 *     'always_open' => false,
 *     'days' => [
 *       'mon' => [['open' => '08:00', 'close' => '14:00'], ['open' => '16:00', 'close' => '20:00']],
 *       'tue' => [['open' => '22:00', 'close' => '02:00']], // intervalle passant minuit
 *       'wed' => [],
 *       ...
 *     ],
 *   ]
 *
 * Défaut pour les commerces existants sans `open_hours` configuré : 24h/24 7j/7.
 * Note : aucun horaire d'ouverture au niveau `cities` n'existe aujourd'hui (seules les heures de pointe
 * tarifaires existent dans `SettingsService`).
 */
class ShopAvailability
{
    public const TIMEZONE = 'Africa/Abidjan';

    /** @var array<int, string> */
    private const DAY_KEYS = [
        1 => 'mon',
        2 => 'tue',
        3 => 'wed',
        4 => 'thu',
        5 => 'fri',
        6 => 'sat',
        7 => 'sun',
    ];

    public static function isOpen(Merchant $commerce, ?Carbon $instant = null): bool
    {
        if (! $commerce->is_active || ! $commerce->is_open) {
            return false;
        }

        $now = ($instant ? $instant->copy() : now())->setTimezone(self::TIMEZONE);
        $raw = $commerce->open_hours;

        // Défaut 24h/24 7j/7 si aucun horaire configuré
        if ($raw === null || $raw === '' || (is_array($raw) && empty($raw))) {
            return true;
        }

        if (is_array($raw)) {
            if (($raw['always_open'] ?? false) === true) {
                return true;
            }

            if (isset($raw['days']) && is_array($raw['days'])) {
                return self::isInstantInStructuredDays($raw['days'], $now);
            }
        }

        // Compatibilité ascendante avec l'ancien format (`summary`, `open`/`close`, `monday => '08:00-20:00'`)
        return self::isInstantInLegacyFormat($raw, $now);
    }

    /**
     * Retourne la prochaine date/heure d'ouverture (en Africa/Abidjan) lorsque la boutique est fermée.
     */
    public static function nextOpening(Merchant $commerce, ?Carbon $instant = null): ?Carbon
    {
        if (! $commerce->is_active || ! $commerce->is_open) {
            return null;
        }

        $now = ($instant ? $instant->copy() : now())->setTimezone(self::TIMEZONE);
        if (self::isOpen($commerce, $now)) {
            return null;
        }

        $raw = $commerce->open_hours;
        if (! is_array($raw) || ! isset($raw['days']) || ! is_array($raw['days'])) {
            return null;
        }

        $days = $raw['days'];
        $currentMinutes = ($now->hour * 60) + $now->minute;

        for ($offset = 0; $offset < 8; $offset++) {
            $candidateDate = $now->copy()->startOfDay()->addDays($offset);
            $dayKey = self::DAY_KEYS[$candidateDate->dayOfWeekIso];
            $intervals = self::normalizeDayIntervals($days[$dayKey] ?? []);

            // Trier les intervalles par heure d'ouverture croissante
            usort($intervals, static fn (array $a, array $b): int => $a['open_min'] <=> $b['open_min']);

            foreach ($intervals as $interval) {
                if ($offset === 0 && $interval['open_min'] <= $currentMinutes) {
                    continue;
                }

                return $candidateDate->copy()->addMinutes($interval['open_min']);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $days
     */
    private static function isInstantInStructuredDays(array $days, Carbon $now): bool
    {
        $todayKey = self::DAY_KEYS[$now->dayOfWeekIso];
        $yesterday = $now->copy()->subDay();
        $yesterdayKey = self::DAY_KEYS[$yesterday->dayOfWeekIso];
        $nowMinutes = ($now->hour * 60) + $now->minute;

        // 1. Intervalles du jour courant
        foreach (self::normalizeDayIntervals($days[$todayKey] ?? []) as $interval) {
            $open = $interval['open_min'];
            $close = $interval['close_min'];

            if ($open === $close) {
                return true; // 24h sur cette journée
            }

            if ($close > $open) {
                if ($nowMinutes >= $open && $nowMinutes < $close) {
                    return true;
                }
            } else {
                // Intervalle passant minuit (ex. 22:00 -> 02:00) : ouvert de 22:00 à 23:59 aujourd'hui
                if ($nowMinutes >= $open) {
                    return true;
                }
            }
        }

        // 2. Débordement après minuit d'un intervalle d'hier (ex. hier 22:00 -> 02:00, aujourd'hui 01:30)
        foreach (self::normalizeDayIntervals($days[$yesterdayKey] ?? []) as $interval) {
            $open = $interval['open_min'];
            $close = $interval['close_min'];

            if ($close < $open && $nowMinutes < $close) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{open_min: int, close_min: int}>
     */
    private static function normalizeDayIntervals(mixed $rawIntervals): array
    {
        if (! is_array($rawIntervals)) {
            return [];
        }

        $result = [];
        foreach (array_slice($rawIntervals, 0, 2) as $slot) {
            if (! is_array($slot)) {
                continue;
            }
            $open = self::parseHmToMinutes((string) ($slot['open'] ?? ''));
            $close = self::parseHmToMinutes((string) ($slot['close'] ?? ''));
            if ($open !== null && $close !== null) {
                $result[] = ['open_min' => $open, 'close_min' => $close];
            }
        }

        return $result;
    }

    private static function parseHmToMinutes(string $value): ?int
    {
        if (! preg_match('/^([01]?\d|2[0-3])[:hH]([0-5]\d)$/', trim($value), $m)) {
            return null;
        }

        return ((int) $m[1] * 60) + (int) $m[2];
    }

    private static function isInstantInLegacyFormat(mixed $raw, Carbon $now): bool
    {
        if (is_string($raw)) {
            return self::matchesLegacyRangeString($raw, $now);
        }

        if (! is_array($raw)) {
            return true;
        }

        if (isset($raw['open'], $raw['close'])) {
            return self::matchesLegacyRangeString("{$raw['open']}-{$raw['close']}", $now);
        }

        if (isset($raw['summary']) && is_string($raw['summary'])) {
            return self::matchesLegacyRangeString($raw['summary'], $now);
        }

        return true;
    }

    private static function matchesLegacyRangeString(string $range, Carbon $now): bool
    {
        if (! preg_match('/([01]?\d|2[0-3])[:hH]([0-5]\d)\s*[-–to]+\s*([01]?\d|2[0-3])[:hH]([0-5]\d)/', $range, $m)) {
            return true;
        }

        $open = ((int) $m[1] * 60) + (int) $m[2];
        $close = ((int) $m[3] * 60) + (int) $m[4];
        $cur = ($now->hour * 60) + $now->minute;

        if ($open === $close) {
            return true;
        }

        return $close > $open
            ? ($cur >= $open && $cur < $close)
            : ($cur >= $open || $cur < $close);
    }
}
