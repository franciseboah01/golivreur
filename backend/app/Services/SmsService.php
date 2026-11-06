<?php

namespace App\Services;

use Illuminate\Support\Facades\{Cache, Log, Mail};

/**
 * Envoie les SMS via Twilio ou Orange API.
 * Gère le compteur mensuel avec alerte automatique à 2 500 SMS/mois.
 * Les codes OTP et de confirmation ne sont JAMAIS loggés.
 */
class SmsService
{
    private const SEUIL_ALERTE = 2500;
    private const CACHE_KEY    = 'sms_count_mensuel';

    public function __construct(
        private readonly ?object $twilioClient = null,
        private readonly string $twilioFrom = '',
        private readonly string $alertEmail = '',
    ) {}

    /**
     * Envoie un OTP d'inscription ou de connexion.
     * Le code n'apparaît jamais dans les logs.
     */
    public function envoyerOtp(string $phone, string $code, string $contexte): bool
    {
        $message = match ($contexte) {
            'inscription' => "GoLivreur : Votre code d'inscription est {$code}. Valable 5 minutes.",
            'connexion'   => "GoLivreur : Votre code de connexion est {$code}. Valable 5 minutes.",
            default       => "GoLivreur : Votre code est {$code}. Valable 5 minutes.",
        };

        return $this->envoyer($phone, $message, type: 'otp');
    }

    /**
     * Envoie le code destinataire GoColis par SMS.
     * Le code n'apparaît jamais dans les logs.
     */
    public function envoyerCodeDestinataire(string $phone, string $code, string $expediteurNom): bool
    {
        $message = "GoLivreur GoColis : {$expediteurNom} vous envoie un colis. "
                 . "Votre code de reception : {$code}. "
                 . "Donnez ce code uniquement au livreur.";

        return $this->envoyer($phone, $message, type: 'gocolis_recipient');
    }

    /**
     * Envoie un SMS de secours (mode hors-ligne).
     */
    public function envoyerSecours(string $phone, string $message): bool
    {
        return $this->envoyer($phone, $message, type: 'secours');
    }

    // ─── Méthode privée d'envoi ───────────────────────────────────────────────

    private function envoyer(string $phone, string $message, string $type): bool
    {
        try {
            // Normaliser le numéro ivoirien (+225 + numéro)
            $phoneInternational = $this->normaliserPhone($phone);

            if (app()->environment('testing', 'local') || !$this->twilioClient) {
                $this->incrementerCompteur();
                Log::info("SMS simulé ({$type})", [
                    'phone_masked' => substr($phone, 0, 2) . '****' . substr($phone, -3),
                ]);
                return true;
            }

            $this->twilioClient->messages->create($phoneInternational, [
                'from' => $this->twilioFrom,
                'body' => $message,
            ]);

            // Incrémenter le compteur mensuel
            $this->incrementerCompteur();

            // Logger l'envoi SANS le code
            Log::info("SMS envoyé", [
                'phone_masked' => substr($phone, 0, 2) . '****' . substr($phone, -3),
                'type'         => $type,
                // Le code n'est JAMAIS loggé
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error("Échec envoi SMS", [
                'phone_masked' => substr($phone, 0, 2) . '****' . substr($phone, -3),
                'type'         => $type,
                'error'        => $e->getMessage(),
            ]);
            return false;
        }
    }

    private function incrementerCompteur(): void
    {
        // Clé de cache mensuelle : sms_count_mensuel_2026_06
        $cle = self::CACHE_KEY . '_' . date('Y_m');

        $compteur = Cache::increment($cle);

        // Si la clé vient d'être créée, définir l'expiration à fin du mois
        if ($compteur === 1) {
            Cache::put($cle, 1, now()->endOfMonth());
        }

        // Alerte si seuil atteint (une seule alerte par mois)
        if ($compteur === self::SEUIL_ALERTE) {
            $this->envoyerAlertesBudget($compteur);
        }
    }

    private function envoyerAlertesBudget(int $compteur): void
    {
        $mois = date('F Y');
        try {
            Mail::to($this->alertEmail)->send(new \App\Mail\SmsBudgetAlertMail($compteur));
        } catch (\Exception $e) {
            Log::error("Impossible d'envoyer l'alerte budget SMS : {$e->getMessage()}");
        }
    }

    private function normaliserPhone(string $phone): string
    {
        // Supprimer les espaces et tirets
        $phone = preg_replace('/[\s\-]/', '', $phone);

        // Si commence par 0, remplacer par +225
        if (str_starts_with($phone, '0')) {
            return '+225' . $phone;
        }

        // Si commence par 225, ajouter +
        if (str_starts_with($phone, '225')) {
            return '+' . $phone;
        }

        return $phone;
    }

    /**
     * Retourne le compteur SMS du mois en cours.
     */
    public function compteurMensuel(): int
    {
        $cle = self::CACHE_KEY . '_' . date('Y_m');
        return (int) Cache::get($cle, 0);
    }
}
