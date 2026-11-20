<?php

namespace App\Services\Auth;

use App\Models\OtpCode;
use App\Services\Auth\Contracts\PasswordResetOtpChannel;
use App\Services\SmsService;
use Illuminate\Support\Facades\Hash;

/**
 * Canal OTP de réinitialisation via la passerelle SMS modulaire (`SmsGateway` — Yellika / API locale CI / Log).
 *
 * Prêt pour la production dès l'activation d'un fournisseur SMS local sans aucune modification
 * de la logique métier de réinitialisation.
 */
class SmsPasswordResetOtpChannel implements PasswordResetOtpChannel
{
    public function __construct(
        private readonly SmsService $smsService,
    ) {}

    public function name(): string
    {
        return 'sms';
    }

    public function dispatchResetOtp(string $phone, int $ttlMinutes): array
    {
        $plainCode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $sent = $this->smsService->envoyerOtp($phone, $plainCode, 'password_reset');

        return [
            'code_hash' => Hash::make($plainCode),
            'sent' => $sent,
        ];
    }

    public function verifyResetOtp(
        string $phone,
        ?string $code,
        ?string $firebaseIdToken,
        OtpCode $otpRecord,
    ): bool {
        $cleanCode = trim((string) $code);
        if ($cleanCode === '') {
            return false;
        }

        return Hash::check($cleanCode, $otpRecord->code_hash);
    }
}
