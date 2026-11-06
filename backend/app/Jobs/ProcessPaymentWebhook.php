<?php

namespace App\Jobs;

use App\Models\{Order, Parcel, WebhookLog};
use App\Services\{ActivityLogService, NotificationService, WalletService};
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue, SerializesModels};
use Illuminate\Support\Facades\{DB, Log};

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
        private readonly int   $webhookLogId,
        private readonly array $payload,
    ) {
        $this->onQueue('high');
    }

    public function handle(
        WalletService       $walletService,
        NotificationService $notificationService,
        ActivityLogService  $activityLogService,
    ): void {
        $webhookLog = WebhookLog::find($this->webhookLogId);

        if (!$webhookLog || $webhookLog->status === 'processed') {
            return; // Déjà traité (idempotence)
        }

        try {
            DB::transaction(function () use ($webhookLog, $notificationService, $activityLogService) {
                $payload = $this->payload;

                // Extraire la référence de transaction et le statut
                $reference = $payload['transaction_id'] ?? $payload['data']['transaction_id'] ?? null;
                $statut    = $payload['status'] ?? $payload['data']['status'] ?? null;

                if (!$reference) {
                    throw new \RuntimeException('Référence de transaction manquante dans le payload.');
                }

                // Chercher la commande ou l'expédition associée
                $order  = Order::where('payment_reference', $reference)->first();
                $parcel = !$order ? Parcel::where('payment_reference', $reference)->first() : null;

                if (!$order && !$parcel) {
                    // Référence inconnue — logguer mais ne pas échouer
                    Log::warning("Webhook : référence inconnue {$reference}");
                    $webhookLog->update(['status' => 'processed', 'error_message' => 'Référence inconnue.']);
                    return;
                }

                if ($statut === 'SUCCESS' || $statut === 'ACCEPTED') {
                    // Paiement confirmé
                    if ($order) {
                        if ($order->payment_status !== 'paid') {
                            $order->update(['payment_status' => 'paid']);
                            if ($order->client instanceof \App\Models\User) {
                                $notificationService->notifierPaiementConfirme($order->client, $order);
                            }
                            $activityLogService->log(
                                userId: null,
                                action: 'payment_confirmed',
                                entityType: 'order',
                                entityId: $order->id,
                                properties: ['reference' => $reference, 'amount' => $order->products_total + $order->delivery_fee],
                            );
                        }
                    } elseif ($parcel) {
                        if ($parcel->payment_status !== 'paid') {
                            $parcel->update(['payment_status' => 'paid']);
                            if ($parcel->sender instanceof \App\Models\User) {
                                $notificationService->notifierPaiementConfirme($parcel->sender, null, $parcel);
                            }
                        }
                    }
                } elseif ($statut === 'FAILED' || $statut === 'CANCELLED') {
                    // Paiement échoué
                    if ($order) {
                        $order->update([
                            'payment_status' => 'failed',
                            'status'         => Order::STATUS_CANCELLED,
                            'cancel_reason'  => 'Paiement Mobile Money échoué.',
                            'cancelled_at'   => now(),
                        ]);
                        if ($order->client instanceof \App\Models\User) {
                            $notificationService->notifierPaiementEchoue($order->client, $order);
                        }
                    } elseif ($parcel) {
                        $parcel->update([
                            'payment_status' => 'failed',
                            'status'         => Parcel::STATUS_CANCELLED,
                            'cancelled_at'   => now(),
                        ]);
                    }
                }

                $webhookLog->update([
                    'status'       => 'processed',
                    'processed_at' => now(),
                    'horizon_job_id' => $this->job?->getJobId(),
                ]);
            });
        } catch (\Exception $e) {
            $webhookLog->update([
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            Log::error('ProcessPaymentWebhook échoué', [
                'webhook_log_id' => $this->webhookLogId,
                'error'          => $e->getMessage(),
                'attempt'        => $this->attempts(),
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
            'status'        => 'failed',
            'error_message' => "Échec définitif après {$this->tries} tentatives : {$exception->getMessage()}",
        ]);

        // Alerte critique au Fondateur
        \App\Services\ActivityLogService::logStatic(
            action: 'webhook_job_failed_permanently',
            entityType: 'webhook_log',
            entityId: $this->webhookLogId,
            severity: 'critical',
            properties: ['error' => $exception->getMessage()],
        );

        Log::critical('Webhook paiement échoué définitivement', [
            'webhook_log_id' => $this->webhookLogId,
            'error'          => $exception->getMessage(),
        ]);
    }
}
