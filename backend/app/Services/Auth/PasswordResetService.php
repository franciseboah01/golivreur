<?php

namespace App\Services\Auth;

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Models\OtpCode;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\Auth\Contracts\PasswordResetOtpChannel;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Orchestration sécurisée de la réinitialisation de mot de passe par OTP SMS.
 *
 * Règles métier strictes :
 *  - Maximum 2 tentatives pour saisir le bon code OTP.
 *  - Fenêtre stricte de 5 minutes à partir de l'envoi du premier code (un renvoi ne prolonge pas le délai).
 *  - Après 2 échecs ou expiration des 5 minutes : invalidation immédiate + blocage temporaire (rate limit)
 *    avec redirection vers le service client.
 *  - Canal SMS interchangeable (Firebase Phone Auth en test / API SMS locale en prod).
 */
class PasswordResetService
{
    public const MAX_ATTEMPTS = 2;

    public const TTL_MINUTES = 5;

    public const LOCKOUT_SECONDS = 1800; // 30 minutes

    public const MAX_DISPATCHES_PER_WINDOW = 3;

    public const LOCKED_MESSAGE = 'Trop de tentatives ou délai expiré. Veuillez contacter le service client pour réinitialiser votre mot de passe.';

    public function __construct(
        private readonly FirebasePasswordResetOtpChannel $firebaseChannel,
        private readonly SmsPasswordResetOtpChannel $smsChannel,
        private readonly ActivityLogService $activityLogService,
        private readonly SettingsService $settingsService,
    ) {}

    /**
     * Étape 1 : Demande (ou renvoi) d'un code OTP de réinitialisation.
     *
     * @return array{status: int, payload: array<string, mixed>}
     */
    public function requestReset(string $phone, bool $useFirebaseOtp, ?string $ip = null): array
    {
        $supportPhone = $this->getSupportPhone();

        if ($this->isLocked($phone)) {
            return $this->lockedResponse($supportPhone);
        }

        $rateKey = $this->dispatchRateKey($phone);
        if (RateLimiter::tooManyAttempts($rateKey, self::MAX_DISPATCHES_PER_WINDOW)) {
            $this->lockPhone($phone);

            return $this->lockedResponse($supportPhone);
        }

        $user = User::where('phone', $phone)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->first();

        if (! $user) {
            return [
                'status' => 404,
                'payload' => [
                    'succes' => false,
                    'message' => 'Aucun compte actif trouvé avec ce numéro de téléphone.',
                ],
            ];
        }

        $channel = $this->resolveChannel($useFirebaseOtp);
        $existing = $this->latestUnconsumedOtp($phone);

        // Si un OTP non consommé existait mais a dépassé les 5 min ou les 2 tentatives -> blocage immédiat
        if ($existing !== null && ($existing->expires_at->isPast() || $existing->attempts >= self::MAX_ATTEMPTS)) {
            $existing->update(['is_used' => true]);
            $this->lockPhone($phone);

            return $this->lockedResponse($supportPhone);
        }

        $dispatch = $channel->dispatchResetOtp($phone, self::TTL_MINUTES);
        if (! $dispatch['sent']) {
            return [
                'status' => 503,
                'payload' => [
                    'succes' => false,
                    'message' => 'Impossible d\'envoyer le code SMS pour le moment. Veuillez réessayer.',
                ],
            ];
        }

        RateLimiter::hit($rateKey, self::LOCKOUT_SECONDS);

        if ($existing !== null) {
            // Renvoi : conserve strictement la date d'expiration initiale (5 min depuis le 1er envoi) et le compteur d'essais
            $existing->update(['code_hash' => $dispatch['code_hash']]);
            $otpRecord = $existing->fresh() ?? $existing;
        } else {
            $otpRecord = OtpCode::create([
                'phone' => $phone,
                'code_hash' => $dispatch['code_hash'],
                'purpose' => 'password_reset',
                'attempts' => 0,
                'is_used' => false,
                'expires_at' => now()->addMinutes(self::TTL_MINUTES),
            ]);
        }

        $remainingSeconds = max(1, (int) now()->diffInSeconds($otpRecord->expires_at, false));
        $remainingAttempts = max(0, self::MAX_ATTEMPTS - (int) $otpRecord->attempts);

        $this->activityLogService->log(
            userId: $user->id,
            action: 'password_reset_otp_requested',
            entityType: 'user',
            entityId: $user->id,
            properties: [
                'channel' => $channel->name(),
                'ip' => $ip,
                'is_resend' => $existing !== null,
            ],
        );

        return [
            'status' => 200,
            'payload' => [
                'succes' => true,
                'message' => 'Un code de réinitialisation a été envoyé par SMS.',
                'donnees' => [
                    'channel' => $channel->name(),
                    'phone_e164' => "+225{$phone}",
                    'expires_at' => $otpRecord->expires_at->toIso8601String(),
                    'expires_in_seconds' => $remainingSeconds,
                    'max_attempts' => self::MAX_ATTEMPTS,
                    'remaining_attempts' => $remainingAttempts,
                    'support_phone' => $supportPhone,
                ],
            ],
        ];
    }

    /**
     * Enregistre une tentative OTP échouée signalée par le client (ex: code SMS rejeté par le SDK Firebase).
     * Garantit l'application serveur stricte des 2 tentatives maximum même en mode Firebase.
     *
     * @return array{status: int, payload: array<string, mixed>}
     */
    public function recordFailedAttempt(string $phone, ?string $ip = null): array
    {
        $supportPhone = $this->getSupportPhone();

        if ($this->isLocked($phone)) {
            return $this->lockedResponse($supportPhone);
        }

        $otpRecord = $this->latestUnconsumedOtp($phone);
        if ($otpRecord === null || $otpRecord->expires_at->isPast()) {
            if ($otpRecord !== null) {
                $otpRecord->update(['is_used' => true]);
            }
            $this->lockPhone($phone);

            return $this->lockedResponse($supportPhone);
        }

        return $this->handleFailedVerification($phone, $otpRecord, $supportPhone, $ip);
    }

    /**
     * Étape 2 : Vérifie l'OTP (max 2 tentatives, fenêtre 5 min) et met à jour le mot de passe.
     *
     * @return array{status: int, payload: array<string, mixed>}
     */
    public function confirmReset(
        string $phone,
        string $newPassword,
        ?string $code,
        ?string $firebaseIdToken,
        ?string $ip = null,
    ): array {
        $supportPhone = $this->getSupportPhone();

        if ($this->isLocked($phone)) {
            return $this->lockedResponse($supportPhone);
        }

        $otpRecord = $this->latestUnconsumedOtp($phone);
        if ($otpRecord === null || $otpRecord->expires_at->isPast()) {
            if ($otpRecord !== null) {
                $otpRecord->update(['is_used' => true]);
            }
            $this->lockPhone($phone);

            return $this->lockedResponse($supportPhone);
        }

        if ($otpRecord->attempts >= self::MAX_ATTEMPTS) {
            $otpRecord->update(['is_used' => true]);
            $this->lockPhone($phone);

            return $this->lockedResponse($supportPhone);
        }

        $useFirebase = trim((string) $firebaseIdToken) !== '';
        $channel = $this->resolveChannel($useFirebase);
        $verified = $channel->verifyResetOtp($phone, $code, $firebaseIdToken, $otpRecord);

        if (! $verified) {
            return $this->handleFailedVerification($phone, $otpRecord, $supportPhone, $ip);
        }

        $user = User::where('phone', $phone)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->first();

        if (! $user) {
            return [
                'status' => 404,
                'payload' => [
                    'succes' => false,
                    'message' => 'Aucun compte actif trouvé avec ce numéro de téléphone.',
                ],
            ];
        }

        DB::transaction(function () use ($user, $newPassword, $otpRecord, $channel, $ip): void {
            $user->update([
                'password' => Hash::make($newPassword),
                'session_token' => null,
            ]);

            if ($user->mustChangePassword()) {
                $user->setMustChangePassword(false, $user->id);
            }

            $otpRecord->delete();
            OtpCode::where('phone', $user->phone)
                ->where('purpose', 'password_reset')
                ->delete();

            AuthController::revokeUserRefreshTokens($user->id);

            $this->activityLogService->log(
                userId: $user->id,
                action: 'password_reset_completed',
                entityType: 'user',
                entityId: $user->id,
                properties: [
                    'channel' => $channel->name(),
                    'ip' => $ip,
                ],
                severity: 'warning',
            );
        });

        RateLimiter::clear($this->dispatchRateKey($phone));
        Cache::forget($this->lockKey($phone));

        return [
            'status' => 200,
            'payload' => [
                'succes' => true,
                'message' => 'Votre mot de passe a été réinitialisé avec succès. Vous pouvez maintenant vous connecter.',
            ],
        ];
    }

    public function getSupportPhone(): string
    {
        $contenus = $this->settingsService->get('contenus');
        $raw = is_array($contenus) ? ($contenus['support_whatsapp'] ?? null) : null;

        if (is_string($raw) && trim($raw) !== '') {
            return trim($raw);
        }

        return (string) SettingsService::DEFAULTS['contenus']['support_whatsapp'];
    }

    /**
     * @return array{status: int, payload: array<string, mixed>}
     */
    private function handleFailedVerification(
        string $phone,
        OtpCode $otpRecord,
        string $supportPhone,
        ?string $ip,
    ): array {
        $otpRecord->increment('attempts');
        $otpRecord->refresh();

        $attempts = (int) $otpRecord->attempts;
        $remaining = max(0, self::MAX_ATTEMPTS - $attempts);

        if ($attempts >= self::MAX_ATTEMPTS) {
            $otpRecord->update(['is_used' => true]);
            $this->lockPhone($phone);

            $user = User::where('phone', $phone)->first();
            $this->activityLogService->log(
                userId: $user?->id,
                action: 'password_reset_locked',
                entityType: 'user',
                entityId: $user?->id,
                properties: [
                    'attempts' => $attempts,
                    'ip' => $ip,
                ],
                severity: 'warning',
            );

            return $this->lockedResponse($supportPhone);
        }

        return [
            'status' => 422,
            'payload' => [
                'succes' => false,
                'code' => 'PASSWORD_RESET_INVALID_OTP',
                'message' => "Code de réinitialisation incorrect. Il vous reste {$remaining} tentative.",
                'donnees' => [
                    'remaining_attempts' => $remaining,
                    'max_attempts' => self::MAX_ATTEMPTS,
                    'locked' => false,
                    'support_phone' => $supportPhone,
                ],
            ],
        ];
    }

    /**
     * @return array{status: int, payload: array<string, mixed>}
     */
    private function lockedResponse(string $supportPhone): array
    {
        return [
            'status' => 429,
            'payload' => [
                'succes' => false,
                'code' => 'PASSWORD_RESET_LOCKED',
                'message' => self::LOCKED_MESSAGE,
                'donnees' => [
                    'remaining_attempts' => 0,
                    'max_attempts' => self::MAX_ATTEMPTS,
                    'locked' => true,
                    'support_phone' => $supportPhone,
                ],
            ],
        ];
    }

    private function resolveChannel(bool $useFirebaseOtp): PasswordResetOtpChannel
    {
        return $useFirebaseOtp ? $this->firebaseChannel : $this->smsChannel;
    }

    private function latestUnconsumedOtp(string $phone): ?OtpCode
    {
        return OtpCode::where('phone', $phone)
            ->where('purpose', 'password_reset')
            ->where('is_used', false)
            ->latest('id')
            ->first();
    }

    private function isLocked(string $phone): bool
    {
        return (bool) Cache::get($this->lockKey($phone), false);
    }

    private function lockPhone(string $phone): void
    {
        Cache::put($this->lockKey($phone), true, now()->addSeconds(self::LOCKOUT_SECONDS));
    }

    private function lockKey(string $phone): string
    {
        return "pwd_reset_locked:{$phone}";
    }

    private function dispatchRateKey(string $phone): string
    {
        return "pwd_reset_req:{$phone}";
    }
}
