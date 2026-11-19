<?php

namespace App\Services;

use App\Mail\SmsBudgetAlertMail;
use App\Mail\SmsDailyAlertMail;
use App\Models\ActivityLog;
use App\Models\Parcel;
use App\Models\SmsLog;
use App\Services\Sms\Contracts\SmsGateway;
use App\Services\Sms\LogSmsGateway;
use App\Services\Sms\YellikaSmsGateway;
use App\Support\PhoneHelper;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Orchestration applicative des envois SMS (OTP, GoColis, secours).
 * Délègue exclusivement l'envoi à l'interface SmsGateway (aucun appel HTTP/fournisseur direct).
 * Journalise dans `sms_logs` et les logs applicatifs sans jamais exposer le message, le code ou le téléphone complet.
 */
class SmsService
{
    private const SEUIL_ALERTE = 2500;

    private const CACHE_KEY = 'sms_count_mensuel';

    public function __construct(
        private readonly ?SmsGateway $gateway = null,
        private readonly string $alertEmail = '',
        private readonly ?SettingsService $settingsService = null,
    ) {}

    /**
     * Envoie un OTP d'inscription ou de connexion.
     * Le code n'apparaît jamais dans les logs de production ni dans `sms_logs`.
     */
    public function envoyerOtp(string $phone, string $code, string $contexte): bool
    {
        $message = match ($contexte) {
            'inscription' => "GoLivreur : Votre code d'inscription est {$code}. Valable 5 minutes.",
            'connexion' => "GoLivreur : Votre code de connexion est {$code}. Valable 5 minutes.",
            default => "GoLivreur : Votre code est {$code}. Valable 5 minutes.",
        };

        return $this->envoyer($phone, $message, type: 'otp');
    }

    /**
     * Envoie le code destinataire GoColis par SMS.
     * Le code n'apparaît jamais dans les logs ni dans `sms_logs`.
     */
    public function envoyerCodeDestinataire(string $phone, string $code, string $expediteurNom): bool
    {
        $message = "GoLivreur GoColis : {$expediteurNom} vous envoie un colis. "
                 ."Votre code de reception : {$code}. "
                 .'Donnez ce code uniquement au livreur.';

        return $this->envoyer($phone, $message, type: 'gocolis_recipient');
    }

    /**
     * Notifie le destinataire par SMS que le colis a été ramassé et est en route.
     */
    public function envoyerNotificationColisRamasse(string $phone, Parcel $parcel): bool
    {
        $message = "GoLivreur GoColis : Votre colis #{$parcel->id} a ete pris en charge par le livreur et est en route vers vous.";

        return $this->envoyer($phone, $message, type: 'gocolis_pickup_notify');
    }

    /**
     * Notifie le destinataire par SMS que le colis a bien été livré.
     */
    public function envoyerNotificationColisLivre(string $phone, Parcel $parcel): bool
    {
        $message = "GoLivreur GoColis : Votre colis #{$parcel->id} a bien ete livre. Merci d'avoir utilise GoLivreur.";

        return $this->envoyer($phone, $message, type: 'gocolis_delivered_notify');
    }

    /**
     * Envoie un SMS de secours (mode hors-ligne).
     */
    public function envoyerSecours(string $phone, string $message): bool
    {
        return $this->envoyer($phone, $message, type: 'secours');
    }

    /**
     * Envoie un SMS de sécurité hors bande (réinitialisation mot de passe temporaire / PIN).
     * Le message n'est jamais enregistré dans les logs ni dans `sms_logs`.
     */
    public function envoyerNotificationSecurite(string $phone, string $message): bool
    {
        return $this->envoyer($phone, $message, type: 'security');
    }

    // ─── Envoi délégué à la passerelle ─────────────────────────────────────────

    private function envoyer(string $phone, string $message, string $type): bool
    {
        $gateway = $this->gateway ?? app(SmsGateway::class);
        $driverName = $this->resoudreNomPilote($gateway);
        $phoneMasked = PhoneHelper::mask($phone);
        $phoneE164 = PhoneHelper::toE164($phone);

        if ($phoneE164 === null) {
            $this->enregistrerSmsLog(
                phoneMasked: $phoneMasked,
                motif: $type,
                driver: $driverName,
                status: 'failed',
                providerMessageId: null,
                errorMessage: 'Numéro de téléphone invalide.',
            );

            Log::warning('SMS annulé : numéro de téléphone invalide', [
                'phone_masked' => $phoneMasked,
                'motif' => $type,
                'driver' => $driverName,
            ]);

            return false;
        }

        try {
            $result = $gateway->send($phoneE164, $message);

            $this->enregistrerSmsLog(
                phoneMasked: $phoneMasked,
                motif: $type,
                driver: $driverName,
                status: $result->succes ? 'sent' : 'failed',
                providerMessageId: $result->identifiantFournisseur,
                errorMessage: $result->erreur,
            );

            if (! $result->succes) {
                Log::error('Échec envoi SMS', [
                    'phone_masked' => $phoneMasked,
                    'motif' => $type,
                    'driver' => $driverName,
                    'error' => $result->erreur,
                ]);

                return false;
            }

            $this->incrementerCompteur();
            $this->verifierSeuilQuotidien();

            Log::info('SMS envoyé', [
                'phone_masked' => $phoneMasked,
                'motif' => $type,
                'driver' => $driverName,
                'provider_message_id' => $result->identifiantFournisseur,
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->enregistrerSmsLog(
                phoneMasked: $phoneMasked,
                motif: $type,
                driver: $driverName,
                status: 'failed',
                providerMessageId: null,
                errorMessage: substr($e->getMessage(), 0, 250),
            );

            Log::error('Échec envoi SMS (exception)', [
                'phone_masked' => $phoneMasked,
                'motif' => $type,
                'driver' => $driverName,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function resoudreNomPilote(SmsGateway $gateway): string
    {
        return match (true) {
            $gateway instanceof LogSmsGateway => 'log',
            $gateway instanceof YellikaSmsGateway => 'yellika',
            default => (string) config('sms.driver', 'custom'),
        };
    }

    private function enregistrerSmsLog(
        string $phoneMasked,
        string $motif,
        string $driver,
        string $status,
        ?string $providerMessageId,
        ?string $errorMessage,
    ): void {
        try {
            SmsLog::create([
                'phone_masked' => $phoneMasked,
                'motif' => $motif,
                'driver' => $driver,
                'status' => $status,
                'provider_message_id' => $providerMessageId,
                'error_message' => $errorMessage !== null ? mb_substr($errorMessage, 0, 255) : null,
                'sent_at' => $status === 'sent' ? now() : null,
            ]);
        } catch (\Throwable $e) {
            Log::warning("Impossible d'enregistrer dans sms_logs : {$e->getMessage()}");
        }
    }

    private function incrementerCompteur(): void
    {
        $cle = self::CACHE_KEY.'_'.date('Y_m');

        $compteur = Cache::increment($cle);

        if ($compteur === 1) {
            Cache::put($cle, 1, now()->endOfMonth());
        }

        $seuilMensuel = $this->resoudreSeuilMensuel();
        if ($compteur === $seuilMensuel) {
            $this->envoyerAlertesBudget($compteur);
        }
    }

    private function verifierSeuilQuotidien(): void
    {
        $seuilQuotidien = $this->resoudreSeuilQuotidien();
        if ($seuilQuotidien <= 0) {
            return;
        }

        $compteurJour = SmsLog::where('status', 'sent')
            ->whereDate('created_at', today())
            ->count();

        if ($compteurJour <= $seuilQuotidien) {
            return;
        }

        $cacheKey = 'sms_daily_alert_sent_'.now()->format('Y_m_d');
        if (! Cache::add($cacheKey, true, now()->endOfDay())) {
            return;
        }

        Log::warning('Seuil quotidien SMS dépassé', [
            'daily_count' => $compteurJour,
            'daily_threshold' => $seuilQuotidien,
        ]);

        try {
            ActivityLog::create([
                'user_id' => null,
                'action' => 'sms_daily_threshold_exceeded',
                'entity_type' => 'sms',
                'entity_id' => null,
                'properties' => [
                    'daily_count' => $compteurJour,
                    'daily_threshold' => $seuilQuotidien,
                ],
                'severity' => 'warning',
            ]);
        } catch (\Throwable $e) {
            Log::warning("Impossible de journaliser l'alerte SMS quotidienne : {$e->getMessage()}");
        }

        $destinataire = $this->resoudreEmailAlerte();
        if ($destinataire === '') {
            return;
        }

        try {
            Mail::to($destinataire)->send(new SmsDailyAlertMail($compteurJour, $seuilQuotidien));
        } catch (\Throwable $e) {
            Log::error("Impossible d'envoyer l'alerte SMS quotidienne : {$e->getMessage()}");
        }
    }

    private function resoudreSeuilMensuel(): int
    {
        $settingsService = $this->settingsService ?? app(SettingsService::class);
        $sms = $settingsService->get('sms');

        return max(1, (int) ($sms['seuil_alerte_mensuel'] ?? self::SEUIL_ALERTE));
    }

    private function resoudreSeuilQuotidien(): int
    {
        $settingsService = $this->settingsService ?? app(SettingsService::class);
        $sms = $settingsService->get('sms');

        return (int) ($sms['seuil_alerte_quotidien'] ?? SettingsService::DEFAULTS['sms']['seuil_alerte_quotidien']);
    }

    private function resoudreEmailAlerte(): string
    {
        if (trim($this->alertEmail) !== '') {
            return trim($this->alertEmail);
        }

        $settingsService = $this->settingsService ?? app(SettingsService::class);
        $sms = $settingsService->get('sms');
        $email = is_array($sms) ? ($sms['alert_email'] ?? null) : null;

        if (is_string($email) && trim($email) !== '') {
            return trim($email);
        }

        return (string) config('services.admin_alert_email', config('mail.from.address', 'admin@golivreur.ci'));
    }

    private function envoyerAlertesBudget(int $compteur): void
    {
        $destinataire = $this->resoudreEmailAlerte();
        if ($destinataire === '') {
            return;
        }

        try {
            Mail::to($destinataire)->send(new SmsBudgetAlertMail($compteur));
        } catch (\Exception $e) {
            Log::error("Impossible d'envoyer l'alerte budget SMS : {$e->getMessage()}");
        }
    }

    /**
     * Retourne le compteur SMS du mois en cours.
     */
    public function compteurMensuel(): int
    {
        $cle = self::CACHE_KEY.'_'.date('Y_m');

        return (int) Cache::get($cle, 0);
    }
}
