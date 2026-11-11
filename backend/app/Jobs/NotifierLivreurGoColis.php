<?php

namespace App\Jobs;

use App\Models\Courier;
use App\Models\Parcel;
use App\Modules\GoShop\Services\AssignmentService;
use App\Services\NotificationService;
use App\Services\SettingsService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

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

    public function handle(
        NotificationService $notificationService,
        AssignmentService $assignmentService,
        SettingsService $settingsService,
    ): void {
        $parcel = Parcel::find($this->parcelId);
        if (
            ! $parcel
            || $parcel->courier_id !== null
            || ! in_array($parcel->status, [Parcel::STATUS_PENDING, Parcel::STATUS_WAITING], true)
        ) {
            return;
        }

        $delais = $settingsService->get('delais_regles', $parcel->city_id);
        $delaiSecondes = max(1, (int) (is_array($delais) ? ($delais['acceptation_livreur_secondes'] ?? 30) : 30));

        if ($this->proposeeLe === null) {
            $courier = Courier::with('user')->find($this->courierId);
            if ($courier && $courier->is_active && $courier->is_online && (int) $courier->city_id === (int) $parcel->city_id) {
                $notificationService->proposerCourseGoColis($courier, $parcel);
                Log::info("Notification course GoColis #{$this->parcelId} envoyée au livreur #{$this->courierId} (tentative {$this->tentative}).");
            }

            return;
        }

        $secondesEcoulees = Carbon::parse($this->proposeeLe)->diffInSeconds(now(), false);
        if ($secondesEcoulees < $delaiSecondes) {
            Log::info("Vérification acceptation course GoColis #{$this->parcelId} tentative {$this->tentative}");

            return;
        }

        Log::info("Délai expiré ({$secondesEcoulees}s) pour course GoColis #{$this->parcelId} tentative {$this->tentative}, relance.");
        $assignmentService->attribuerLivreurGoColis($parcel, $this->tentative + 1, $this->exclusions);
    }
}
