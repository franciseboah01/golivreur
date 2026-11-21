<?php

namespace App\Jobs;

use App\Models\Order;
use App\Modules\GoShop\Services\OrderService;
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
        self::annulerSiExpiree($this->orderId, $notificationService, $settingsService);
    }

    public static function annulerSiExpiree(
        int $orderId,
        NotificationService $notificationService,
        SettingsService $settingsService,
        int $margeSecondes = 0,
    ): bool {
        return DB::transaction(function () use ($orderId, $notificationService, $settingsService, $margeSecondes): bool {
            /** @var Order|null $order */
            $order = Order::lockForUpdate()->find($orderId);
            if (! $order || $order->status !== Order::STATUS_PENDING || ! $order->created_at) {
                return false;
            }

            // Pour un paiement en ligne, le délai d'acceptation commerce ne démarre qu'une fois le paiement confirmé.
            if ($order->payment_method !== 'cash' && $order->payment_status !== 'paid') {
                return false;
            }

            $cityId = $order->city_id ? (int) $order->city_id : null;
            $delaiSecondes = $settingsService->getDelaiRegle(
                'acceptation_commerce_secondes',
                $cityId,
            );
            $seuilTotalSecondes = $delaiSecondes + max(0, $margeSecondes);

            $reference = $order->paid_at ?? $order->created_at;
            if ($reference->diffInSeconds(now(), false) >= $seuilTotalSecondes) {
                $order->update([
                    'status' => Order::STATUS_CANCELLED,
                    'cancelled_at' => now(),
                    'cancel_reason' => "Délai d'acceptation dépassé ({$delaiSecondes}s).",
                ]);
                app(OrderService::class)->restaurerStockCommande($order);
                $notificationService->notifierCommandeRefusee($order);
                Log::info("Commande #{$orderId} annulée pour timeout {$delaiSecondes}s.");

                return true;
            }

            return false;
        });
    }
}
