<?php

namespace App\Services\Payment\Strategies;

use App\Enums\PaymentMethod;
use App\Models\Parcel;
use App\Services\ActivityLogService;
use App\Services\Payment\Contracts\ParcelPaymentStrategy;
use App\Services\Payment\ParcelPaymentResult;
use Illuminate\Support\Str;

/**
 * Stratégie temporaire de simulation des paiements Mobile Money (Wave, Orange Money, MTN MoMo, Moov)
 * pour la phase de tests terrain.
 *
 * N'effectue aucun appel HTTP vers les passerelles externes : enregistre l'expédition
 * avec un statut configurable (`paid` ou `pending`) et une référence `SIM-...` tracée en audit.
 * Pour brancher les vraies API plus tard, il suffira d'enregistrer des stratégies dédiées
 * (WavePaymentStrategy, OrangeMoneyPaymentStrategy, etc.) dans ParcelPaymentProcessor.
 */
class SimulatedMobileMoneyPaymentStrategy implements ParcelPaymentStrategy
{
    private const SUPPORTED_METHODS = [
        PaymentMethod::WAVE->value,
        PaymentMethod::ORANGE_MONEY->value,
        PaymentMethod::MTN_MOMO->value,
        PaymentMethod::MOOV->value,
    ];

    public function __construct(
        private readonly ActivityLogService $activityLogService,
    ) {}

    public function supports(string $paymentMethod): bool
    {
        return in_array($paymentMethod, self::SUPPORTED_METHODS, true);
    }

    public function process(Parcel $parcel): ParcelPaymentResult
    {
        $simulateEnabled = (bool) config('payments.simulate_mobile_money', true);
        $configuredStatus = (string) config('payments.simulated_status', 'paid');
        $status = $simulateEnabled && in_array($configuredStatus, ['paid', 'pending'], true)
            ? $configuredStatus
            : 'pending';

        $prefix = strtoupper(str_replace('_', '', (string) $parcel->payment_method));
        $reference = sprintf('SIM-%s-%d-%s', $prefix, $parcel->id, strtoupper(Str::random(6)));

        $parcel->update([
            'payment_status' => $status,
            'payment_reference' => $reference,
        ]);

        if ($simulateEnabled) {
            $this->activityLogService->log(
                userId: (int) $parcel->sender_id,
                action: 'parcel_payment_simulated',
                entityType: 'parcel',
                entityId: $parcel->id,
                properties: [
                    'payment_method' => $parcel->payment_method,
                    'payment_status' => $status,
                    'payment_reference' => $reference,
                    'amount' => (int) $parcel->shipping_fee + (int) $parcel->insurance_amount,
                ],
            );
        }

        return new ParcelPaymentResult(
            paymentStatus: $status,
            paymentReference: $reference,
            isSimulated: $simulateEnabled,
            message: $status === 'paid'
                ? 'Paiement simulé validé (mode test).'
                : 'Expédition enregistrée en attente de paiement.',
        );
    }
}
