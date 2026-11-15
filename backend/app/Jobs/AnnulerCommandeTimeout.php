<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\NotificationService;
use App\Services\SettingsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AnnulerCommandeTimeout implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $orderId,
    ) {}

    public function handle(NotificationService $notificationService, SettingsService $settingsService): void
    {
        DB::transaction(function () use ($notificationService, $settingsService): void {
            /** @var Order|null $order */
            $order = Order::lockForUpdate()->find($this->orderId);
            if (! $order || $order->status !== Order::STATUS_PENDING || ! $order->created_at) {
                return;
            }

            // Pour un paiement en ligne, le délai d'acceptation commerce ne démarre qu'une fois le paiement confirmé.
            if ($order->payment_method !== 'cash' && $order->payment_status !== 'paid') {
                return;
            }

            $delaiSecondes = $settingsService->getDelaiRegle(
                'acceptation_commerce_secondes',
                $order->city_id ? (int) $order->city_id : null,
                45,
            );

            $reference = $order->paid_at ?? $order->created_at;
            if ($reference->diffInSeconds(now(), false) >= $delaiSecondes) {
                $order->update([
                    'status' => Order::STATUS_CANCELLED,
                    'cancelled_at' => now(),
                    'cancel_reason' => "Délai d'acceptation dépassé ({$delaiSecondes}s).",
                ]);
                $notificationService->notifierCommandeRefusee($order);
                Log::info("Commande #{$this->orderId} annulée pour timeout {$delaiSecondes}s.");
            }
        });
    }
}
