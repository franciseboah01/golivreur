<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\NotificationService;
use App\Services\SettingsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Annule automatiquement une commande GoShop en ligne restée en attente de paiement
 * au-delà du délai configurable (10 minutes par défaut dans SettingsService).
 */
class AnnulerCommandePaiementTimeout implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $orderId,
    ) {}

    public function handle(
        NotificationService $notificationService,
        SettingsService $settingsService,
        ActivityLogService $activityLogService,
    ): void {
        DB::transaction(function () use ($notificationService, $settingsService, $activityLogService): void {
            /** @var Order|null $order */
            $order = Order::lockForUpdate()->find($this->orderId);
            if (
                ! $order
                || $order->status !== Order::STATUS_PENDING
                || $order->payment_method === 'cash'
                || $order->payment_status !== 'pending'
                || ! $order->created_at
            ) {
                return;
            }

            $delaiMinutes = $settingsService->getDelaiRegle(
                'paiement_attente_minutes',
                $order->city_id ? (int) $order->city_id : null,
                10,
            );

            if ($order->created_at->diffInSeconds(now(), false) < ($delaiMinutes * 60)) {
                return;
            }

            $order->update([
                'status' => Order::STATUS_CANCELLED,
                'payment_status' => 'failed',
                'cancelled_at' => now(),
                'cancel_reason' => "Délai de paiement en ligne dépassé ({$delaiMinutes} min).",
            ]);

            if ($order->client instanceof User) {
                $notificationService->notifierPaiementEchoue($order->client, $order);
            }

            $activityLogService->log(
                userId: null,
                action: 'order_payment_timeout_cancelled',
                entityType: 'order',
                entityId: $order->id,
                properties: [
                    'city_id' => $order->city_id,
                    'delai_minutes' => $delaiMinutes,
                ],
            );

            Log::info("Commande #{$this->orderId} annulée pour non-paiement en ligne après {$delaiMinutes} min.");
        });
    }
}
