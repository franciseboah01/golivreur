<?php

namespace App\Services\Auth\Contracts;

use App\Models\OtpCode;

/**
 * Contrat modulaire (Pattern Strategy) pour l'envoi et la vérification des OTP
 * de réinitialisation de mot de passe.
 *
 * Permet d'interchanger librement le fournisseur (Firebase Phone Auth en test/dev,
 * passerelle SMS locale Côte d'Ivoire en production) sans modifier PasswordResetService.
 */
interface PasswordResetOtpChannel
{
    /**
     * Identifiant canonique du canal ('firebase' | 'sms').
     */
    public function name(): string;

    /**
     * Prépare ou déclenche l'envoi du code OTP et retourne le hash à persister dans `otp_codes`.
     *
     * @return array{code_hash: string, sent: bool}
     */
    public function dispatchResetOtp(string $phone, int $ttlMinutes): array;

    /**
     * Vérifie la preuve OTP (Firebase ID Token ou code 6 chiffres SMS).
     */
    public function verifyResetOtp(
        string $phone,
        ?string $code,
        ?string $firebaseIdToken,
        OtpCode $otpRecord,
    ): bool;
}
