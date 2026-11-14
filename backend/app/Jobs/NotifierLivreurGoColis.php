<?php

namespace App\Jobs;

use App\Models\Parcel;
use App\Modules\GoShop\Services\AssignmentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class NotifierLivreurGoColis implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  array<int, int>  $exclusions
     */
    public function __construct(
        public int $parcelId,
        public int $courierId,
        public int $tentative = 1,
        public array $exclusions = [],
        public ?string $proposeeLe = null,
    ) {}

    public function handle(AssignmentService $assignmentService): void
    {
        $parcel = Parcel::find($this->parcelId);
        if (
            ! $parcel
            || $parcel->courier_id !== null
            || ! in_array($parcel->status, [Parcel::STATUS_PENDING, Parcel::STATUS_WAITING], true)
        ) {
            return;
        }

        $assignmentService->traiterVerificationTimeout(
            course: $parcel,
            courierId: $this->courierId,
            tentative: $this->tentative,
            exclusions: $this->exclusions,
            proposeeLe: $this->proposeeLe,
            type: 'gocolis',
        );
    }
}
