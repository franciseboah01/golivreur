<?php

namespace App\Services\Payment;

/**
 * Résultat immuable d'un traitement de paiement d'expédition GoColis.
 */
final readonly class ParcelPaymentResult
{
    public function __construct(
        public string $paymentStatus,
        public ?string $paymentReference,
        public bool $isSimulated,
        public string $message,
    ) {}
}
