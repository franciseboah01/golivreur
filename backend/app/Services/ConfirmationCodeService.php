<?php

namespace App\Services;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;

/**
 * Génère, chiffre (D-CODE-1) et déchiffre les codes de confirmation à 4 chiffres.
 * La vérification métier continue exclusivement via Hash::check().
 */
class ConfirmationCodeService
{
    public function genererCode(): string
    {
        return (string) random_int(1000, 9999);
    }

    /**
     * @return array{code: string, hash: string, enc: string}
     */
    public function genererPaire(): array
    {
        $code = $this->genererCode();

        return [
            'code' => $code,
            'hash' => Hash::make($code),
            'enc' => Crypt::encryptString($code),
        ];
    }

    public function chiffrer(string $codeClair): string
    {
        return Crypt::encryptString($codeClair);
    }

    public function dechiffrer(?string $valeurChiffree): ?string
    {
        if (! is_string($valeurChiffree) || $valeurChiffree === '') {
            return null;
        }

        try {
            $code = Crypt::decryptString($valeurChiffree);

            return $code !== '' ? $code : null;
        } catch (DecryptException) {
            return null;
        }
    }
}
