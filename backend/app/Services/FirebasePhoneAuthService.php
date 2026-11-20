<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Contract\Auth as FirebaseAuthContract;

/**
 * Vérifie les jetons Firebase Phone Authentication (ID Tokens JWT)
 * émis côté mobile après validation du code SMS OTP à 6 chiffres.
 *
 * Utilise les mêmes identifiants de compte de service Firebase
 * (`config/firebase.php` / `FIREBASE_CREDENTIALS`) déjà configurés pour FCM.
 */
class FirebasePhoneAuthService
{
    /**
     * Vérifie la signature cryptographique, l'émetteur, l'audience et l'expiration
     * d'un Firebase ID Token, puis retourne le numéro ivoirien local normalisé (10 chiffres).
     *
     * @throws \InvalidArgumentException si le jeton est absent, invalide, expiré ou sans numéro vérifié.
     */
    public function verifyIdToken(string $idToken): string
    {
        $cleanToken = trim($idToken);
        if ($cleanToken === '') {
            throw new \InvalidArgumentException('Jeton de vérification SMS Firebase manquant.');
        }

        try {
            /** @var FirebaseAuthContract $firebaseAuth */
            $firebaseAuth = app(FirebaseAuthContract::class);
            $verifiedToken = $firebaseAuth->verifyIdToken($cleanToken, false, 60);
        } catch (\Throwable $e) {
            Log::warning('Firebase Phone Auth: échec de vérification du jeton ID', [
                'reason' => $e->getMessage(),
            ]);

            throw new \InvalidArgumentException(
                'Vérification SMS Firebase invalide ou expirée. Veuillez redemander un code.',
                0,
                $e,
            );
        }

        $rawPhone = $verifiedToken->claims()->get('phone_number');
        if (! is_string($rawPhone) || trim($rawPhone) === '') {
            throw new \InvalidArgumentException(
                'Le jeton Firebase ne contient aucun numéro de téléphone vérifié.',
            );
        }

        $localPhone = self::normalizeIvorianPhone($rawPhone);
        if ($localPhone === '') {
            throw new \InvalidArgumentException(
                'Numéro de téléphone vérifié par Firebase invalide.',
            );
        }

        return $localPhone;
    }

    /**
     * Indique si le fichier de credentials Firebase Admin est configuré côté serveur.
     */
    public function isConfigured(): bool
    {
        $credentials = config('firebase.projects.app.credentials');

        if (is_array($credentials)) {
            return ! empty($credentials);
        }

        if (! is_string($credentials) || trim($credentials) === '') {
            return false;
        }

        return is_file($credentials) || is_file(base_path($credentials));
    }

    /**
     * Convertit un numéro E.164 (+2250701020304) ou local au format ivoirien local (10 chiffres).
     */
    public static function normalizeIvorianPhone(string $raw): string
    {
        $cleaned = preg_replace('/[\s\.\-\(\)]+/', '', trim($raw)) ?? '';

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
