<?php

namespace App\Services\Auth;

use App\Models\OtpCode;
use App\Services\Auth\Contracts\PasswordResetOtpChannel;
use App\Services\FirebasePhoneAuthService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Canal OTP de réinitialisation via Firebase Phone Authentication.
 *
 * Le SDK mobile déclenche l'envoi du SMS (ou utilise les numéros de test gratuits
 * de la Console Firebase), puis envoie le Firebase ID Token au backend pour vérification
 * cryptographique. Un hash sentinelle non devinable est stocké dans `otp_codes` afin de
 * conserver le suivi serveur des tentatives (max 2) et de la fenêtre de 5 minutes.
 */
class FirebasePasswordResetOtpChannel implements PasswordResetOtpChannel
{
    public function __construct(
        private readonly FirebasePhoneAuthService $firebasePhoneAuthService,
    ) {}

    public function name(): string
    {
        return 'firebase';
    }

    public function dispatchResetOtp(string $phone, int $ttlMinutes): array
    {
        return [
            'code_hash' => Hash::make('firebase_reset_'.Str::random(40)),
            'sent' => true,
        ];
    }

    public function verifyResetOtp(
        string $phone,
        ?string $code,
        ?string $firebaseIdToken,
        OtpCode $otpRecord,
    ): bool {
        $cleanToken = trim((string) $firebaseIdToken);
        if ($cleanToken === '') {
            return false;
        }

        try {
            $verifiedPhone = $this->firebasePhoneAuthService->verifyIdToken($cleanToken);
        } catch (\Throwable) {
            return false;
        }

        return $verifiedPhone === $phone;
    }
}
