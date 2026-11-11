<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class AnnulerCommandeTimeout implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $orderId,
    ) {}

    public function handle(NotificationService $notificationService): void
    {
        $order = Order::find($this->orderId);
        if ($order && $order->status === Order::STATUS_PENDING) {
            if ($order->created_at && $order->created_at->diffInSeconds(now()) >= 40) {
                $order->update([
                    'status' => 'cancelled',
                    'cancelled_at' => now(),
                    'cancel_reason' => 'Délai d\'acceptation dépassé (45s).',
                ]);
                $notificationService->notifierCommandeRefusee($order);
                Log::info("Commande #{$this->orderId} annulée pour timeout 45s.");
            }
        }
    }
}
