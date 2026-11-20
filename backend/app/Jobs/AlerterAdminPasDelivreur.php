<?php

namespace App\Jobs;

use App\Models\Notification;
use App\Models\Order;
use App\Models\Parcel;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class AlerterAdminPasDelivreur implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public mixed $model,
        public string $type,
    ) {}

    public function handle(): void
    {
        $courseId = is_object($this->model) && isset($this->model->id) ? (int) $this->model->id : 0;
        $cityId = is_object($this->model) && isset($this->model->city_id) ? (int) $this->model->city_id : null;

        Log::warning("Alerte admin : aucun livreur disponible pour {$this->type} #{$courseId}");

        if ($courseId <= 0) {
            return;
        }

        $label = $this->type === 'gocolis' || $this->model instanceof Parcel
            ? "Expédition GoColis #{$courseId}"
            : "Commande GoShop #{$courseId}";

        $admins = User::query()
            ->where('is_active', true)
            ->where(function ($q) use ($cityId): void {
                $q->whereIn('role', ['super_admin', 'dg_national', 'national_support']);
                if ($cityId !== null) {
                    $q->orWhere(function ($sub) use ($cityId): void {
                        $sub->whereIn('role', ['city_manager', 'city_support'])
                            ->where('city_id', $cityId);
                    });
                }
            })
            ->pluck('id');

        foreach ($admins as $adminId) {
            Notification::create([
                'user_id' => (int) $adminId,
                'type' => 'admin_no_driver_alert',
                'title' => "Aucun livreur disponible — {$label}",
                'body' => "{$label} est en attente d'affectation manuelle dans Filament.",
                'data' => [
                    'course_type' => $this->type,
                    'order_id' => $this->model instanceof Order ? $courseId : null,
                    'parcel_id' => $this->model instanceof Parcel ? $courseId : null,
                    'city_id' => $cityId,
                ],
                'is_read' => false,
            ]);
        }
    }
}
