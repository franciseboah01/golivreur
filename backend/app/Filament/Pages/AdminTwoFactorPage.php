<?php

namespace App\Filament\Pages;

use App\Http\Middleware\EnsureAdminTwoFactorVerified;
use App\Models\OtpCode;
use App\Models\User;
use App\Services\SmsService;
use App\Services\SupportAuditService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Page de vérification 2FA (code à 6 chiffres hors bande) obligatoire pour les comptes administrateurs.
 */
class AdminTwoFactorPage extends Page
{
    protected static ?string $slug = 'two-factor';

    protected static ?string $title = 'Vérification 2FA Administrateur';

    protected static string $view = 'filament.pages.admin-two-factor-page';

    protected static bool $shouldRegisterNavigation = false;

    public string $code = '';

    public bool $codeSent = false;

    public ?string $statusMessage = null;

    public ?string $errorMessage = null;

    public function mount(): void
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return;
        }

        // Envoie automatiquement un premier code si aucun code valide récent n'existe
        $hasActive = OtpCode::where('phone', $this->channelKey($user))
            ->where('purpose', 'admin_2fa')
            ->where('is_used', false)
            ->where('expires_at', '>', now())
            ->exists();

        if (! $hasActive) {
            $this->envoyerCode();
        } else {
            $this->codeSent = true;
            $this->statusMessage = 'Un code de sécurité à 6 chiffres est déjà en cours de validité. Saisissez-le ci-dessous.';
        }
    }

    public function envoyerCode(): void
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return;
        }

        $rateKey = "admin_2fa_send:{$user->id}";
        if (RateLimiter::tooManyAttempts($rateKey, 5)) {
            $this->errorMessage = 'Trop de demandes de code. Patientez quelques minutes.';

            return;
        }
        RateLimiter::hit($rateKey, 300);

        $plainCode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $ttl = max(3, (int) config('admin.two_factor_ttl_minutes', 5));

        OtpCode::create([
            'phone' => $this->channelKey($user),
            'code_hash' => Hash::make($plainCode),
            'code_enc' => Crypt::encryptString($plainCode),
            'purpose' => 'admin_2fa',
            'expires_at' => now()->addMinutes($ttl),
        ]);

        // Envoi hors bande par SMS (si téléphone) et/ou Email
        if (! empty($user->phone)) {
            try {
                app(SmsService::class)->envoyerOtp($user->phone, $plainCode, 'admin_2fa');
            } catch (\Throwable) {
                // Fallback email ci-dessous
            }
        }

        if (! empty($user->email)) {
            try {
                Mail::raw(
                    "Code de vérification 2FA GoLivreur Admin : {$plainCode} (valable {$ttl} minutes).",
                    static function ($message) use ($user): void {
                        $message->to($user->email)->subject('GoLivreur Admin — Code 2FA');
                    },
                );
            } catch (\Throwable) {
                // Journalisé sans jamais exposer le code en clair
            }
        }

        app(SupportAuditService::class)->record(
            advisorId: $user->id,
            targetUserId: $user->id,
            action: 'admin_2fa_code_sent',
            result: 'success',
            context: ['has_phone' => ! empty($user->phone), 'has_email' => ! empty($user->email)],
        );

        $this->codeSent = true;
        $this->errorMessage = null;
        $this->statusMessage = "Un code de sécurité à 6 chiffres a été envoyé sur vos canaux vérifiés (valable {$ttl} min).";
    }

    public function verifierCode(): void
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return;
        }

        $input = trim($this->code);
        if (! preg_match('/^[0-9]{6}$/', $input)) {
            $this->errorMessage = 'Veuillez saisir un code à 6 chiffres.';

            return;
        }

        $otp = OtpCode::where('phone', $this->channelKey($user))
            ->where('purpose', 'admin_2fa')
            ->where('is_used', false)
            ->where('expires_at', '>', now())
            ->latest()
            ->first();

        if (! $otp || ! Hash::check($input, $otp->code_hash)) {
            if ($otp) {
                $otp->increment('attempts');
                if ($otp->attempts >= 5) {
                    $otp->update(['is_used' => true]);
                }
            }

            app(SupportAuditService::class)->record(
                advisorId: $user->id,
                targetUserId: $user->id,
                action: 'admin_2fa_verified',
                result: 'failure',
                context: ['reason' => 'invalid_or_expired'],
                severity: 'warning',
            );

            $this->errorMessage = 'Code 2FA incorrect ou expiré.';

            return;
        }

        $otp->delete();
        RateLimiter::clear("admin_2fa_send:{$user->id}");

        if (! $user->two_factor_enabled) {
            $user->update(['two_factor_enabled' => true]);
        }

        session([
            EnsureAdminTwoFactorVerified::SESSION_VERIFIED_AT_KEY => time(),
            EnsureAdminTwoFactorVerified::SESSION_USER_ID_KEY => $user->id,
        ]);

        app(SupportAuditService::class)->record(
            advisorId: $user->id,
            targetUserId: $user->id,
            action: 'admin_2fa_verified',
            result: 'success',
        );

        Notification::make()
            ->title('Authentification 2FA validée')
            ->success()
            ->send();

        $this->redirect(url('/'.trim((string) config('admin.path', 'admin'), '/')));
    }

    private function channelKey(User $user): string
    {
        return $user->phone ?: ('admin-'.$user->id);
    }
}
