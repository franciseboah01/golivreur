<?php

namespace App\Services\Sms;

use App\Services\Sms\Contracts\SmsGateway;

/**
 * Repli fail-closed lorsqu'un pilote SMS inconnu est configuré.
 */
class InvalidDriverSmsGateway implements SmsGateway
{
    public function __construct(
        private readonly string $driver,
    ) {}

    public function send(string $telephoneE164, string $message): SmsResult
    {
        return new SmsResult(
            succes: false,
            identifiantFournisseur: null,
            erreur: "Pilote SMS inconnu ou non supporté : {$this->driver}.",
        );
    }
}
