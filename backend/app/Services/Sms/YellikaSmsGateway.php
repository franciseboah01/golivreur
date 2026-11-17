<?php

namespace App\Services\Sms;

use App\Services\Sms\Contracts\SmsGateway;
use App\Support\PhoneHelper;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Passerelle HTTP Yellika SMS API v3.
 * Endpoint : POST https://panel.yellikasms.com/api/v3/sms/send
 */
class YellikaSmsGateway implements SmsGateway
{
    public function send(string $telephoneE164, string $message): SmsResult
    {
        $apiToken = trim((string) config('sms.yellika.api_token', ''));
        if ($apiToken === '') {
            Log::error('Passerelle Yellika non configurée (YELLIKA_API_TOKEN absent).');

            return new SmsResult(
                succes: false,
                identifiantFournisseur: null,
                erreur: 'Jeton API Yellika manquant.',
            );
        }

        $recipient = PhoneHelper::toYellikaRecipient($telephoneE164);
        if ($recipient === null) {
            return new SmsResult(
                succes: false,
                identifiantFournisseur: null,
                erreur: 'Numéro de téléphone invalide.',
            );
        }

        $endpoint = trim((string) config('sms.yellika.base_url', 'https://panel.yellikasms.com/api/v3/sms/send'));
        $senderId = trim((string) config('sms.yellika.sender_id', 'GoLivreur')) ?: 'GoLivreur';
        $type = trim((string) config('sms.yellika.type', 'plain')) ?: 'plain';
        $timeout = max(1, (int) config('sms.yellika.timeout', 10));
        $maxAttempts = max(1, min(2, (int) config('sms.yellika.max_attempts', 2)));

        $lastError = 'Erreur inconnue lors de l\'appel Yellika.';

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $response = Http::withToken($apiToken)
                    ->acceptJson()
                    ->asJson()
                    ->timeout($timeout)
                    ->post($endpoint, [
                        'recipient' => $recipient,
                        'sender_id' => $senderId,
                        'type' => $type,
                        'message' => $message,
                    ]);

                $statusCode = $response->status();
                $json = $response->json();

                if ($response->successful()) {
                    if (is_array($json) && (($json['status'] ?? null) === 'error' || ($json['success'] ?? null) === false)) {
                        $apiError = is_string($json['message'] ?? null)
                            ? $json['message']
                            : 'Réponse d\'erreur Yellika.';

                        return new SmsResult(
                            succes: false,
                            identifiantFournisseur: null,
                            erreur: $apiError,
                        );
                    }

                    $providerId = $this->extraireIdentifiantFournisseur($json);

                    return new SmsResult(
                        succes: true,
                        identifiantFournisseur: $providerId,
                        erreur: null,
                    );
                }

                $apiMessage = is_array($json) && is_string($json['message'] ?? null)
                    ? $json['message']
                    : "HTTP {$statusCode}";
                $lastError = "Erreur Yellika ({$statusCode}) : {$apiMessage}";

                // Erreur client 4xx (hors 429 Too Many Requests) : échec immédiat sans réessai
                if ($statusCode >= 400 && $statusCode < 500 && $statusCode !== 429) {
                    return new SmsResult(
                        succes: false,
                        identifiantFournisseur: null,
                        erreur: $lastError,
                    );
                }
            } catch (ConnectionException $e) {
                $lastError = 'Délai dépassé ou erreur réseau Yellika.';
                Log::warning('Timeout/réseau Yellika SMS', [
                    'attempt' => $attempt,
                    'max_attempts' => $maxAttempts,
                    'phone_masked' => PhoneHelper::mask($telephoneE164),
                ]);
            } catch (\Throwable $e) {
                $lastError = 'Erreur technique Yellika.';
                Log::warning('Exception Yellika SMS', [
                    'attempt' => $attempt,
                    'max_attempts' => $maxAttempts,
                    'phone_masked' => PhoneHelper::mask($telephoneE164),
                    'exception' => $e::class,
                ]);
            }
        }

        return new SmsResult(
            succes: false,
            identifiantFournisseur: null,
            erreur: $lastError,
        );
    }

    private function extraireIdentifiantFournisseur(mixed $json): ?string
    {
        if (! is_array($json)) {
            return null;
        }

        $candidates = [
            $json['uid'] ?? null,
            $json['id'] ?? null,
            $json['message_id'] ?? null,
            is_array($json['data'] ?? null) ? ($json['data']['uid'] ?? null) : null,
            is_array($json['data'] ?? null) ? ($json['data']['id'] ?? null) : null,
            is_array($json['data'] ?? null) ? ($json['data']['message_id'] ?? null) : null,
        ];

        foreach ($candidates as $candidate) {
            if (is_scalar($candidate) && trim((string) $candidate) !== '') {
                return trim((string) $candidate);
            }
        }

        return null;
    }
}
