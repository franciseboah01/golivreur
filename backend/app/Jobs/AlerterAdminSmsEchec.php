<?php

namespace App\Jobs;

use App\Models\Parcel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class AlerterAdminSmsEchec implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public Parcel $parcel,
    ) {}

    public function handle(): void
    {
        Log::error("Alerte admin : échec d'envoi du SMS destinataire pour le colis #{$this->parcel->id}", [
            'parcel_id' => $this->parcel->id,
            'city_id' => $this->parcel->city_id,
        ]);
    }
}
