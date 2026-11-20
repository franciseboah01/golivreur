<?php

namespace App\Services\Payment\Strategies;

use App\Enums\PaymentMethod;
use App\Models\Parcel;
use App\Services\Payment\Contracts\ParcelPaymentStrategy;
use App\Services\Payment\ParcelPaymentResult;

/**
 * Stratégie de paiement en espèces au ramassage / livraison.
 */
class CashParcelPaymentStrategy implements ParcelPaymentStrategy
{
    public function supports(string $paymentMethod): bool
    {
        return $paymentMethod === PaymentMethod::CASH->value;
    }

    public function process(Parcel $parcel): ParcelPaymentResult
    {
        $reference = sprintf('CASH-PARCEL-%d', $parcel->id);

        $parcel->update([
            'payment_status' => 'pending_cash',
            'payment_reference' => $reference,
        ]);

        return new ParcelPaymentResult(
            paymentStatus: 'pending_cash',
            paymentReference: $reference,
            isSimulated: false,
            message: 'Paiement en espèces au ramassage enregistré.',
        );
    }
}
