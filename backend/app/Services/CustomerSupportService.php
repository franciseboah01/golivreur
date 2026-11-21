<?php

namespace App\Services;

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Models\ActivityLog;
use App\Models\Notification;
use App\Models\Order;
use App\Models\OtpCode;
use App\Models\Parcel;
use App\Models\Refund;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Service métier dédié au Module Service Client (Screen Pop + Vérification Hors Bande + Reset Sécurisé).
 *
 * Règles de sécurité strictes :
 *  - Aucune question de sécurité (interdiction formelle).
 *  - Vérification d'identité hors bande (OTP SMS/Email) OBLIGATOIRE avant tout reset mot de passe ou PIN.
 *  - Le conseiller ne voit JAMAIS le mot de passe temporaire en clair (envoyé directement à l'utilisateur).
 *  - Changement obligatoire de mot de passe forcé à la prochaine connexion (`must_change_password`).
 *  - Toutes les actions sont tracées dans le journal d'audit immuable via SupportAuditService.
 */
class CustomerSupportService
{
    private const VERIFICATION_CACHE_MINUTES = 10;

    private const OTP_TTL_MINUTES = 5;

    public function __construct(
        private readonly SmsService $smsService,
        private readonly SupportAuditService $auditService,
    ) {}

    /**
     * Normalise un numéro de téléphone ivoirien pour la recherche Screen Pop.
     */
    public function normaliserTelephone(string $rawPhone): string
    {
        $clean = preg_replace('/[\s\.\-\(\)]+/', '', trim($rawPhone)) ?? '';
        if (str_starts_with($clean, '+225')) {
            return substr($clean, 4);
        }
        if (str_starts_with($clean, '00225')) {
            return substr($clean, 5);
        }
        if (strlen($clean) === 13 && str_starts_with($clean, '225')) {
            return substr($clean, 3);
        }

        return $clean;
    }

    /**
     * Screen Pop : charge instantanément le profil complet, rôles, portefeuilles,
     * historique des commandes/colis et tickets/incidents ouverts pour l'appelant.
     *
     * @return array<string, mixed>|null
     */
    public function rechercherFicheAppelant(string $rawPhone, User $advisor, ?string $ip = null): ?array
    {
        $phone = $this->normaliserTelephone($rawPhone);
        if ($phone === '') {
            return null;
        }

        $candidates = array_values(array_unique([$phone, "+225{$phone}", trim($rawPhone)]));

        $user = User::with(['city', 'merchant.city', 'courier.city'])
            ->whereIn('phone', $candidates)
            ->whereNull('deleted_at')
            ->first();

        if (! $user) {
            $this->auditService->record(
                advisorId: $advisor->id,
                targetUserId: null,
                action: 'support_screen_pop_lookup',
                result: 'failure',
                context: ['phone_suffix' => substr($phone, -4), 'reason' => 'not_found'],
                ipAddress: $ip,
            );

            return null;
        }

        $merchantWallet = $user->merchant
            ? Wallet::where('owner_type', 'merchant')->where('owner_id', $user->merchant->id)->first()
            : null;
        $courierWallet = $user->courier
            ? Wallet::where('owner_type', 'courier')->where('owner_id', $user->courier->id)->first()
            : null;

        $orders = Order::with(['merchant:id,name', 'courier.user:id,name'])
            ->where(function ($q) use ($user): void {
                $q->where('client_id', $user->id);
                if ($user->merchant) {
                    $q->orWhere('merchant_id', $user->merchant->id);
                }
                if ($user->courier) {
                    $q->orWhere('courier_id', $user->courier->id);
                }
            })
            ->latest()
            ->limit(15)
            ->get();

        $parcels = Parcel::with(['courier.user:id,name'])
            ->where(function ($q) use ($user): void {
                $q->where('sender_id', $user->id);
                if ($user->courier) {
                    $q->orWhere('courier_id', $user->courier->id);
                }
            })
            ->latest()
            ->limit(15)
            ->get();

        // Tickets / Incidents ouverts dérivés des tables existantes (aucune nouvelle table)
        $openTickets = [];

        foreach ($orders as $order) {
            if ($order->status === Order::STATUS_DISPUTED || $order->confirmation_locked) {
                $openTickets[] = [
                    'type' => 'order_incident',
                    'reference' => "CMD-#{$order->id}",
                    'label' => $order->confirmation_locked
                        ? 'Code de confirmation GoShop verrouillé'
                        : 'Litige commande GoShop ouvert',
                    'status' => $order->status,
                    'created_at' => $order->updated_at?->toIso8601String(),
                ];
            }
        }

        foreach ($parcels as $parcel) {
            if (
                in_array($parcel->status, [Parcel::STATUS_DISPUTED, Parcel::STATUS_LOST], true)
                || $parcel->sender_code_locked
                || $parcel->recipient_code_locked
            ) {
                $openTickets[] = [
                    'type' => 'parcel_incident',
                    'reference' => "COLIS-#{$parcel->id}",
                    'label' => ($parcel->sender_code_locked || $parcel->recipient_code_locked)
                        ? 'Code de sécurité GoColis verrouillé'
                        : 'Litige ou perte GoColis ouvert',
                    'status' => $parcel->status,
                    'created_at' => $parcel->updated_at?->toIso8601String(),
                ];
            }
        }

        $pendingRefunds = Refund::where('requested_by', $user->id)
            ->orWhereHas('order', fn ($q) => $q->where('client_id', $user->id))
            ->whereIn('status', ['pending', 'approved'])
            ->latest()
            ->limit(10)
            ->get();

        foreach ($pendingRefunds as $refund) {
            $openTickets[] = [
                'type' => 'refund',
                'reference' => "REMB-#{$refund->id}",
                'label' => "Demande de remboursement ({$refund->amount} F CFA)",
                'status' => $refund->status,
                'created_at' => $refund->created_at?->toIso8601String(),
            ];
        }

        $supportNotifications = Notification::where('user_id', $user->id)
            ->where('type', 'support_ticket')
            ->where('is_read', false)
            ->latest()
            ->limit(10)
            ->get();

        foreach ($supportNotifications as $notif) {
            $openTickets[] = [
                'type' => 'support_ticket',
                'reference' => "TKT-#{$notif->id}",
                'label' => $notif->title,
                'status' => 'open',
                'created_at' => $notif->created_at?->toIso8601String(),
            ];
        }

        $auditTrail = ActivityLog::where('entity_type', 'user')
            ->where('entity_id', $user->id)
            ->latest()
            ->limit(15)
            ->get();

        $recentOtps = OtpCode::whereIn('phone', $candidates)
            ->orWhere('purpose', "phone_chg_{$user->id}")
            ->orWhere('purpose', "email_chg_{$user->id}")
            ->latest('id')
            ->limit(10)
            ->get();

        $this->auditService->record(
            advisorId: $advisor->id,
            targetUserId: $user->id,
            action: 'support_screen_pop_lookup',
            result: 'success',
            context: ['roles' => $user->effectiveRoles(), 'open_tickets_count' => count($openTickets)],
            ipAddress: $ip,
        );

        return [
            'user' => $user,
            'roles' => $user->effectiveRoles(),
            'must_change_password' => $user->mustChangePassword(),
            'pin_reset_required_at' => $user->pinResetRequiredAt(),
            'merchant_wallet_balance' => (int) ($merchantWallet->balance ?? 0),
            'courier_wallet_balance' => (int) ($courierWallet->balance ?? 0),
            'orders' => $orders,
            'parcels' => $parcels,
            'recent_otps' => $recentOtps,
            'open_tickets' => $openTickets,
            'audit_trail' => $auditTrail,
            'identity_verified' => $this->estIdentiteVerifiee($advisor->id, $user->id),
        ];
    }

    /**
     * Déclenche une vérification d'identité hors bande (OTP 6 chiffres SMS/Email) au numéro enregistré.
     * Aucune question de sécurité autorisée.
     *
     * @return array{sent: bool, channels: list<string>, expires_in_minutes: int}
     */
    public function envoyerVerificationIdentiteHorsBande(User $target, User $advisor, ?string $ip = null): array
    {
        $plainOtp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        OtpCode::create([
            'phone' => $target->phone,
            'code_hash' => Hash::make($plainOtp),
            'code_enc' => Crypt::encryptString($plainOtp),
            'purpose' => 'support_identity_verify',
            'expires_at' => now()->addMinutes(self::OTP_TTL_MINUTES),
        ]);

        $channels = [];

        if (! empty($target->phone)) {
            $smsSent = $this->smsService->envoyerOtp($target->phone, $plainOtp, 'verification_service_client');
            if ($smsSent) {
                $channels[] = 'sms';
            }
        }

        if (! empty($target->email)) {
            try {
                Mail::raw(
                    "Code de vérification d'identité Service Client GoLivreur : {$plainOtp} (valable ".self::OTP_TTL_MINUTES.' minutes). Ne le partagez qu\'avec le conseiller qui vous a appelé.',
                    static function ($message) use ($target): void {
                        $message->to($target->email)->subject('GoLivreur — Vérification d\'identité Service Client');
                    },
                );
                $channels[] = 'email';
            } catch (\Throwable) {
                // Non bloquant si SMS a réussi
            }
        }

        $sent = ! empty($channels);

        $this->auditService->record(
            advisorId: $advisor->id,
            targetUserId: $target->id,
            action: 'support_identity_otp_sent',
            result: $sent ? 'success' : 'failure',
            context: ['channels' => $channels],
            ipAddress: $ip,
        );

        return [
            'sent' => $sent,
            'channels' => $channels,
            'expires_in_minutes' => self::OTP_TTL_MINUTES,
        ];
    }

    /**
     * Valide le code OTP communiqué par l'appelant et ouvre une fenêtre vérifiée de 10 minutes
     * liée strictement au couple (conseiller, utilisateur ciblé).
     */
    public function verifierIdentiteHorsBande(User $target, string $code, User $advisor, ?string $ip = null): bool
    {
        $cleanCode = trim($code);
        $otp = OtpCode::where('phone', $target->phone)
            ->where('purpose', 'support_identity_verify')
            ->where('is_used', false)
            ->where('expires_at', '>', now())
            ->latest()
            ->first();

        if (! $otp || ! Hash::check($cleanCode, $otp->code_hash)) {
            if ($otp) {
                $otp->increment('attempts');
                if ($otp->attempts >= 3) {
                    $otp->update(['is_used' => true]);
                }
            }

            $this->auditService->record(
                advisorId: $advisor->id,
                targetUserId: $target->id,
                action: 'support_identity_verified',
                result: 'failure',
                context: ['reason' => 'invalid_or_expired_otp'],
                ipAddress: $ip,
                severity: 'warning',
            );

            return false;
        }

        $otp->delete();

        Cache::put(
            $this->verificationCacheKey($advisor->id, $target->id),
            ['verified_at' => now()->toIso8601String()],
            now()->addMinutes(self::VERIFICATION_CACHE_MINUTES),
        );

        $this->auditService->record(
            advisorId: $advisor->id,
            targetUserId: $target->id,
            action: 'support_identity_verified',
            result: 'success',
            context: ['valid_minutes' => self::VERIFICATION_CACHE_MINUTES],
            ipAddress: $ip,
        );

        return true;
    }

    public function estIdentiteVerifiee(int $advisorId, int $targetUserId): bool
    {
        return Cache::has($this->verificationCacheKey($advisorId, $targetUserId));
    }

    /**
     * Réinitialisation sécurisée du mot de passe :
     *  - Exige la vérification d'identité hors bande préalable.
     *  - Génère un mot de passe temporaire aléatoire JAMAIS affiché au conseiller ni loggé.
     *  - Envoie le mot de passe temporaire directement à l'utilisateur hors bande (SMS/Email).
     *  - Force `must_change_password = true` à la prochaine connexion et révoque les sessions actives.
     *
     * @return array{dispatched: bool, channels: list<string>}
     */
    public function reinitialiserMotDePasseSecurise(User $target, User $advisor, ?string $ip = null): array
    {
        if (! $this->estIdentiteVerifiee($advisor->id, $target->id)) {
            $this->auditService->record(
                advisorId: $advisor->id,
                targetUserId: $target->id,
                action: 'support_password_reset',
                result: 'failure',
                context: ['reason' => 'identity_not_verified'],
                ipAddress: $ip,
                severity: 'warning',
            );

            throw new AuthorizationException('Vérification d\'identité hors bande (OTP) obligatoire avant toute réinitialisation.');
        }

        $temporaryPassword = Str::password(10, letters: true, numbers: true, symbols: false);

        $target->update([
            'password' => Hash::make($temporaryPassword),
            'session_token' => null,
        ]);

        $target->setMustChangePassword(true, $advisor->id);
        AuthController::revokeUserRefreshTokens($target->id);

        $channels = [];
        if (! empty($target->phone)) {
            $smsSent = $this->smsService->envoyerNotificationSecurite(
                $target->phone,
                "GoLivreur : votre mot de passe temporaire est {$temporaryPassword}. Vous devrez le changer dès votre prochaine connexion.",
            );
            if ($smsSent) {
                $channels[] = 'sms';
            }
        }

        if (! empty($target->email)) {
            try {
                Mail::raw(
                    "Votre mot de passe temporaire GoLivreur est : {$temporaryPassword}\nPar sécurité, vous devrez définir un nouveau mot de passe dès votre prochaine connexion.",
                    static function ($message) use ($target): void {
                        $message->to($target->email)->subject('GoLivreur — Mot de passe temporaire');
                    },
                );
                $channels[] = 'email';
            } catch (\Throwable) {
                // Non bloquant si SMS a réussi
            }
        }

        // Consomme la preuve d'identité (usage unique par opération sensible)
        Cache::forget($this->verificationCacheKey($advisor->id, $target->id));

        $this->auditService->record(
            advisorId: $advisor->id,
            targetUserId: $target->id,
            action: 'support_password_reset',
            result: 'success',
            context: [
                'must_change_password' => true,
                'sessions_revoked' => true,
                'delivery_channels' => $channels,
            ],
            ipAddress: $ip,
            severity: 'warning',
        );

        // Retourne UNIQUEMENT le statut d'envoi — JAMAIS le mot de passe temporaire !
        return [
            'dispatched' => ! empty($channels),
            'channels' => $channels,
        ];
    }

    /**
     * Réinitialisation sécurisée du PIN rapide (verrouillage local) :
     *  - Exige la vérification d'identité hors bande préalable.
     *  - Révoque les refresh tokens et force la réinitialisation du PIN sur les appareils mobiles.
     */
    public function reinitialiserPinSecurise(User $target, User $advisor, ?string $ip = null): void
    {
        if (! $this->estIdentiteVerifiee($advisor->id, $target->id)) {
            $this->auditService->record(
                advisorId: $advisor->id,
                targetUserId: $target->id,
                action: 'support_pin_reset',
                result: 'failure',
                context: ['reason' => 'identity_not_verified'],
                ipAddress: $ip,
                severity: 'warning',
            );

            throw new AuthorizationException('Vérification d\'identité hors bande (OTP) obligatoire avant toute réinitialisation de PIN.');
        }

        $target->update(['session_token' => null]);
        $target->markPinResetRequired($advisor->id);
        AuthController::revokeUserRefreshTokens($target->id);

        if (! empty($target->phone)) {
            $this->smsService->envoyerNotificationSecurite(
                $target->phone,
                'GoLivreur : votre code PIN rapide a été réinitialisé par le Service Client. Reconnectez-vous avec votre mot de passe pour en définir un nouveau.',
            );
        }

        Cache::forget($this->verificationCacheKey($advisor->id, $target->id));

        $this->auditService->record(
            advisorId: $advisor->id,
            targetUserId: $target->id,
            action: 'support_pin_reset',
            result: 'success',
            context: ['sessions_revoked' => true],
            ipAddress: $ip,
            severity: 'warning',
        );
    }

    private function verificationCacheKey(int $advisorId, int $targetUserId): string
    {
        return "support_identity_verified:{$advisorId}:{$targetUserId}";
    }
}
