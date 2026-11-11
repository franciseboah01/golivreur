<?php

namespace App\Http\Requests\Concerns;

trait NormalizesIvorianPhone
{
    /**
     * Normalise un numéro ivoirien (supprime espaces, ponctuation et indicatif +225 / 00225).
     */
    protected function normalizePhoneValue(string $phone): string
    {
        $cleaned = preg_replace('/[\s\.\-\(\)]+/', '', trim($phone)) ?? '';

        if (str_starts_with($cleaned, '+225')) {
            return substr($cleaned, 4);
        }

        if (str_starts_with($cleaned, '00225')) {
            return substr($cleaned, 5);
        }

        if (strlen($cleaned) === 13 && str_starts_with($cleaned, '225')) {
            return substr($cleaned, 3);
        }

        return $cleaned;
    }
}

