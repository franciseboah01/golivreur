<?php

namespace App\Jobs;

use App\Models\Order;
use App\Modules\GoShop\Services\AssignmentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class NotifierLivreurGoShop implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  array<int, int>  $exclusions
     */
    public function __construct(
        public int $orderId,
        public int $courierId,
        public int $tentative = 1,
        public array $exclusions = [],
        public ?string $proposeeLe = null,
    ) {}

    public function handle(AssignmentService $assignmentService): void
    {
        $order = Order::find($this->orderId);
        if (
            ! $order
            || $order->courier_id !== null
            || ! in_array($order->status, [Order::STATUS_ACCEPTED, Order::STATUS_READY, Order::STATUS_WAITING], true)
        ) {
            return;
        }

        $assignmentService->traiterVerificationTimeout(
            course: $order,
            courierId: $this->courierId,
            tentative: $this->tentative,
            exclusions: $this->exclusions,
            proposeeLe: $this->proposeeLe,
            type: 'goshop',
        );
    }
}
