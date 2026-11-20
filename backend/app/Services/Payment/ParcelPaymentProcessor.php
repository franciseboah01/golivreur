<?php

namespace App\Services\Payment;

use App\Models\Parcel;
use App\Services\Payment\Contracts\ParcelPaymentStrategy;
use App\Services\Payment\Strategies\CashParcelPaymentStrategy;
use App\Services\Payment\Strategies\SimulatedMobileMoneyPaymentStrategy;

/**
 * Orchestrateur de stratégies de paiement GoColis (Pattern Strategy).
 *
 * Sélectionne dynamiquement la stratégie correspondant au mode de paiement choisi
 * (`cash`, `wave`, `orange_money`, `mtn_momo`, `moov`).
 */
class ParcelPaymentProcessor
{
    /**
     * @var list<ParcelPaymentStrategy>
     */
    private readonly array $strategies;

    public function __construct(
        CashParcelPaymentStrategy $cashStrategy,
        SimulatedMobileMoneyPaymentStrategy $simulatedMobileMoneyStrategy,
    ) {
        $this->strategies = [
            $cashStrategy,
            $simulatedMobileMoneyStrategy,
        ];
    }

    public function process(Parcel $parcel): ParcelPaymentResult
    {
        $method = (string) $parcel->payment_method;

        foreach ($this->strategies as $strategy) {
            if ($strategy->supports($method)) {
                return $strategy->process($parcel);
            }
        }

        return new ParcelPaymentResult(
            paymentStatus: (string) $parcel->payment_status,
            paymentReference: $parcel->payment_reference,
            isSimulated: false,
            message: 'Paiement enregistré.',
        );
    }
}
