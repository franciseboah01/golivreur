<?php

namespace App\Support;

/**
 * Normalisation E.164 et masquage strict des numéros de téléphone ivoiriens.
 */
final class PhoneHelper
{
    /**
     * Convertit un numéro saisi en format E.164 (+225XXXXXXXXXX), ou retourne null s'il est invalide.
     */
    public static function toE164(string $phone): ?string
    {
        $cleaned = preg_replace('/[\s\.\-\(\)]+/', '', trim($phone)) ?? '';
        if ($cleaned === '') {
            return null;
        }

        if (str_starts_with($cleaned, '00')) {
            $cleaned = '+'.substr($cleaned, 2);
        }

        // Format local ivoirien : 10 chiffres commençant par 0 (ex: 0701020312)
        if (preg_match('/^0\d{9}$/', $cleaned) === 1) {
            return '+225'.$cleaned;
        }

        // Format national sans + : 225 suivi de 10 chiffres (13 chiffres)
        if (preg_match('/^225\d{10}$/', $cleaned) === 1) {
            return '+'.$cleaned;
        }

        // Format E.164 explicite Côte d'Ivoire : +225 suivi de 10 chiffres exacts
        if (str_starts_with($cleaned, '+225')) {
            return preg_match('/^\+225\d{10}$/', $cleaned) === 1 ? $cleaned : null;
        }

        // Autres indicatifs E.164 internationaux valides (8 à 15 chiffres)
        if (preg_match('/^\+[1-9]\d{7,14}$/', $cleaned) === 1) {
            return $cleaned;
        }

        return null;
    }

    /**
     * Convertit un numéro au format attendu par l'API Yellika (225XXXXXXXXXX sans '+').
     */
    public static function toYellikaRecipient(string $phone): ?string
    {
        $e164 = self::toE164($phone);

        if ($e164 === null) {
            return null;
        }

        return ltrim($e164, '+');
    }

    /**
     * Masque un numéro pour les journaux et sms_logs : `225 07 ** ** 12`.
     */
    public static function mask(string $phone): string
    {
        $e164 = self::toE164($phone);
        if ($e164 !== null) {
            $digits = ltrim($e164, '+');

            if (preg_match('/^225(\d{2})\d{6}(\d{2})$/', $digits, $matches) === 1) {
                return "225 {$matches[1]} ** ** {$matches[2]}";
            }

            if (strlen($digits) >= 4) {
                return substr($digits, 0, 2).' ** ** '.substr($digits, -2);
            }
        }

        $digitsOnly = preg_replace('/\D+/', '', $phone) ?? '';
        if (strlen($digitsOnly) >= 4) {
            return substr($digitsOnly, 0, 2).' ** '.substr($digitsOnly, -2);
        }

        return '***';
    }
}
