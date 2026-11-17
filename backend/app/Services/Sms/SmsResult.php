<?php

namespace App\Services\Sms;

final class SmsResult
{
    public function __construct(
        public readonly bool $succes,
        public readonly ?string $identifiantFournisseur = null,
        public readonly ?string $erreur = null,
    ) {}
}
