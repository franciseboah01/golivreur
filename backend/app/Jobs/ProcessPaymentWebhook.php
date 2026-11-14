<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\Parcel;
use App\Models\User;
use App\Models\WebhookLog;
use App\Services\ActivityLogService;
use App\Services\NotificationService;
use App\Services\WalletService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Job de traitement des webhooks de paiement Hub2/CinetPay.
 * Queue : high (priorité maximale — confirmation paiement Mobile Money).
 * Un paiement confirmé non traité = client qui attend indéfiniment.
 */
class ProcessPaymentWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Nombre maximum de tentatives avant abandon
    public int $tries = 3;

    // Délai entre les tentatives : 30s, 60s, 120s
    public array $backoff = [30, 60, 120];

    // Timeout maximum du job
    public int $timeout = 30;

    public function __construct(
        private readonly int $webhookLogId,
        private readonly array $payload,
    ) {
        $this->onQueue('high');
    }

    public function handle(
        WalletService $walletService,
        NotificationService $notificationService,
        ActivityLogService $activityLogService,
    ): void {
        $webhookLog = WebhookLog::find($this->webhookLogId);

        if (! $webhookLog || $webhookLog->status === 'processed') {
            return; // Déjà traité (idempotence)
        }

        $txId = $webhookLog->transaction_id
            ?? ($this->payload['transaction_id'] ?? $this->payload['data']['transaction_id'] ?? $this->payload['cpm_trans_id'] ?? null);

        if ($txId !== null && WebhookLog::query()
            ->where('id', '!=', $webhookLog->id)
            ->where('transaction_id', (string) $txId)
            ->where('signature_valid', true)
            ->where('status', 'processed')
            ->exists()) {
            $webhookLog->update([
                'status' => 'rejected',
                'error_message' => "Rejeu bloqué : transaction {$txId} déjà traitée.",
                'processed_at' => now(),
            ]);

            return;
        }

        try {
            DB::transaction(function () use ($webhookLog, $notificationService, $activityLogService) {
                $payload = $this->payload;

                // Extraire la référence de transaction et le statut
                $reference = $payload['transaction_id']
                    ?? $payload['data']['transaction_id']
                    ?? $payload['cpm_trans_id']
                    ?? null;
                $statut = $payload['status'] ?? $payload['data']['status'] ?? null;

                if (! $reference) {
                    throw new \RuntimeException('Référence de transaction manquante dans le payload.');
                }

                // Chercher la commande ou l'expédition associée
                $order = Order::where('payment_reference', $reference)->first();
                $parcel = ! $order ? Parcel::where('payment_reference', $reference)->first() : null;

                if (! $order && ! $parcel) {
                    // Référence inconnue — logguer mais ne pas échouer
                    Log::warning("Webhook : référence inconnue {$reference}");
                    $webhookLog->update(['status' => 'processed', 'error_message' => 'Référence inconnue.']);

                    return;
                }

                if ($statut === 'SUCCESS' || $statut === 'ACCEPTED') {
                    $receivedAmount = $this->extractAmount($payload);

                    // Paiement confirmé (uniquement si le montant concorde strictement avec le total dû)
                    if ($order) {
                        $expectedAmount = (int) round((float) $order->products_total + (float) $order->delivery_fee);
                        if ($receivedAmount === null || $receivedAmount !== $expectedAmount) {
                            $this->handleAmountMismatch(
                                entity: $order,
                                entityType: 'order',
                                reference: (string) $reference,
                                expectedAmount: $expectedAmount,
                                receivedAmount: $receivedAmount,
                                webhookLog: $webhookLog,
                                activityLogService: $activityLogService,
                            );

                            return;
                        }

                        if ($order->payment_status !== 'paid') {
                            $order->update(['payment_status' => 'paid']);
                            if ($order->client instanceof User) {
                                $notificationService->notifierPaiementConfirme($order->client, $order);
                            }
                            $activityLogService->log(
                                userId: null,
                                action: 'payment_confirmed',
                                entityType: 'order',
                                entityId: $order->id,
                                properties: ['reference' => $reference, 'amount' => $expectedAmount],
                            );
                        }
                    } elseif ($parcel) {
                        $expectedAmount = (int) round((float) $parcel->shipping_fee + ((bool) $parcel->has_insurance ? (float) $parcel->insurance_amount : 0.0));
                        if ($receivedAmount === null || $receivedAmount !== $expectedAmount) {
                            $this->handleAmountMismatch(
                                entity: $parcel,
                                entityType: 'parcel',
                                reference: (string) $reference,
                                expectedAmount: $expectedAmount,
                                receivedAmount: $receivedAmount,
                                webhookLog: $webhookLog,
                                activityLogService: $activityLogService,
                            );

                            return;
                        }

                        if ($parcel->payment_status !== 'paid') {
                            $parcel->update(['payment_status' => 'paid']);
                            if ($parcel->sender instanceof User) {
                                $notificationService->notifierPaiementConfirme($parcel->sender, null, $parcel);
                            }
                            $activityLogService->log(
                                userId: null,
                                action: 'payment_confirmed',
                                entityType: 'parcel',
                                entityId: $parcel->id,
                                properties: ['reference' => $reference, 'amount' => $expectedAmount],
                            );
                        }
                    }
                } elseif ($statut === 'FAILED' || $statut === 'CANCELLED') {
                    // Paiement échoué
                    if ($order) {
                        $order->update([
                            'payment_status' => 'failed',
                            'status' => Order::STATUS_CANCELLED,
                            'cancel_reason' => 'Paiement Mobile Money échoué.',
                            'cancelled_at' => now(),
                        ]);
                        if ($order->client instanceof User) {
                            $notificationService->notifierPaiementEchoue($order->client, $order);
                        }
                    } elseif ($parcel) {
                        $parcel->update([
                            'payment_status' => 'failed',
                            'status' => Parcel::STATUS_CANCELLED,
                            'cancelled_at' => now(),
                        ]);
                    }
                }

                $webhookLog->update([
                    'transaction_id' => $webhookLog->transaction_id ?? (string) $reference,
                    'status' => 'processed',
                    'processed_at' => now(),
                    'horizon_job_id' => $this->job?->getJobId(),
                ]);
            });
        } catch (\Exception $e) {
            $webhookLog->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            Log::error('ProcessPaymentWebhook échoué', [
                'webhook_log_id' => $this->webhookLogId,
                'error' => $e->getMessage(),
                'attempt' => $this->attempts(),
            ]);

            // Relancer l'exception pour déclencher le backoff
            throw $e;
        }
    }

    /**
     * Gestion de l'échec définitif (après $tries tentatives).
     */
    public function failed(\Throwable $exception): void
    {
        WebhookLog::find($this->webhookLogId)?->update([
            'status' => 'failed',
            'error_message' => "Échec définitif après {$this->tries} tentatives : {$exception->getMessage()}",
        ]);

        // Alerte critique au Fondateur
        ActivityLogService::logStatic(
            action: 'webhook_job_failed_permanently',
            entityType: 'webhook_log',
            entityId: $this->webhookLogId,
            severity: 'critical',
            properties: ['error' => $exception->getMessage()],
        );

        Log::critical('Webhook paiement échoué définitivement', [
            'webhook_log_id' => $this->webhookLogId,
            'error' => $exception->getMessage(),
        ]);
    }

    private function extractAmount(array $payload): ?int
    {
        $raw = $payload['amount'] ?? $payload['data']['amount'] ?? $payload['cpm_amount'] ?? null;

        if ($raw === null || ! is_numeric($raw)) {
            return null;
        }

        return (int) round((float) $raw);
    }

    private function handleAmountMismatch(
        Order|Parcel $entity,
        string $entityType,
        string $reference,
        int $expectedAmount,
        ?int $receivedAmount,
        WebhookLog $webhookLog,
        ActivityLogService $activityLogService,
    ): void {
        $entity->update(['payment_status' => 'amount_mismatch']);

        $receivedLabel = $receivedAmount !== null ? (string) $receivedAmount : 'absent';
        $message = "Écart de montant webhook : reçu {$receivedLabel} F CFA, attendu {$expectedAmount} F CFA.";

        Log::critical('Alerte critique : écart de montant sur webhook paiement', [
            'entity_type' => $entityType,
            'entity_id' => $entity->id,
            'reference' => $reference,
            'expected_amount' => $expectedAmount,
            'received_amount' => $receivedAmount,
            'webhook_log_id' => $webhookLog->id,
        ]);

        $activityLogService->log(
            userId: null,
            action: 'webhook_amount_mismatch',
            entityType: $entityType,
            entityId: $entity->id,
            properties: [
                'reference' => $reference,
                'source' => $webhookLog->source,
                'webhook_log_id' => $webhookLog->id,
                'expected_amount' => $expectedAmount,
                'received_amount' => $receivedAmount,
            ],
            severity: 'critical',
        );

        $webhookLog->update([
            'status' => 'rejected',
            'error_message' => $message,
            'processed_at' => now(),
            'horizon_job_id' => $this->job?->getJobId(),
        ]);
    }
}
