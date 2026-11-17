<?php

namespace App\Services\Sms;

use App\Services\Sms\Contracts\SmsGateway;
use App\Support\PhoneHelper;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Pilote SMS local : journalise le message en local/testing/staging uniquement.
 * Strictement interdit en production.
 */
class LogSmsGateway implements SmsGateway
{
    public const ALLOWED_ENVIRONMENTS = ['local', 'testing', 'staging'];

    public function send(string $telephoneE164, string $message): SmsResult
    {
        if (! self::estAutoriseDansEnvironnementCourant()) {
            Log::error('Pilote SMS log refusé : interdit en environnement de production.');

            return new SmsResult(
                succes: false,
                identifiantFournisseur: null,
                erreur: 'Le pilote SMS log est strictement refusé en environnement de production.',
            );
        }

        $normalized = PhoneHelper::toE164($telephoneE164);
        if ($normalized === null) {
            return new SmsResult(
                succes: false,
                identifiantFournisseur: null,
                erreur: 'Numéro de téléphone invalide.',
            );
        }

        $providerId = 'log-'.Str::uuid()->toString();

        Log::info('SMS simulé (pilote log)', [
            'phone_masked' => PhoneHelper::mask($normalized),
            'provider_message_id' => $providerId,
            'message' => $message,
        ]);

        return new SmsResult(
            succes: true,
            identifiantFournisseur: $providerId,
            erreur: null,
        );
    }

    public static function estAutoriseDansEnvironnementCourant(): bool
    {
        return app()->environment(self::ALLOWED_ENVIRONMENTS);
    }
}
