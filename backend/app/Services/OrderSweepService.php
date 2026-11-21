<?php

namespace App\Services;

use App\Jobs\AnnulerCommandeTimeout;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Parcel;
use App\Models\User;
use App\Modules\GoShop\Services\AssignmentService;
use Illuminate\Support\Facades\DB;

/**
 * Balayage périodique de filet de secours pour récupérer les commandes GoShop
 * dont un job différé Redis (timeout commerce ou relance livreur) aurait été perdu.
 */
class OrderSweepService
{
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly NotificationService $notificationService,
        private readonly AssignmentService $assignmentService,
        private readonly ActivityLogService $activityLogService,
    ) {}

    /**
     * @return array{pending_cancelled: int, assignment_retried: int, unsettled_alerts: int}
     */
    public function balayer(): array
    {
        return [
            'pending_cancelled' => $this->balayerCommandesPendingExpirees(),
            'assignment_retried' => $this->balayerCommandesEnAttenteAttribution(),
            'unsettled_alerts' => $this->signalerLivraisonsSansReglement(),
        ];
    }

    private function balayerCommandesPendingExpirees(): int
    {
        $cancelledCount = 0;

        $candidateIds = Order::where('status', Order::STATUS_PENDING)
            ->orderBy('id')
            ->pluck('id');

        foreach ($candidateIds as $orderId) {
            $orderCityId = Order::whereKey((int) $orderId)->value('city_id');
            $cityId = $orderCityId !== null ? (int) $orderCityId : null;
            $delaySeconds = $this->settingsService->getDelaiRegle('acceptation_commerce_secondes', $cityId);
            $marginSeconds = $this->settingsService->getDelaiRegle('marge_balayage_commerce_secondes', $cityId, minimum: 0);

            $cancelled = AnnulerCommandeTimeout::annulerSiExpiree(
                orderId: (int) $orderId,
                notificationService: $this->notificationService,
                settingsService: $this->settingsService,
                margeSecondes: $marginSeconds,
            );

            if (! $cancelled) {
                continue;
            }

            $cancelledCount++;

            $this->activityLogService->log(
                userId: null,
                action: 'order_sweep_pending_timeout_cancelled',
                entityType: 'order',
                entityId: (int) $orderId,
                properties: [
                    'city_id' => $cityId,
                    'delay_seconds' => $delaySeconds,
                    'margin_seconds' => $marginSeconds,
                ],
                severity: 'warning',
            );
        }

        return $cancelledCount;
    }

    private function balayerCommandesEnAttenteAttribution(): int
    {
        $retriedCount = 0;

        $candidateIds = Order::whereIn('status', [Order::STATUS_ACCEPTED, Order::STATUS_READY])
            ->whereNull('courier_id')
            ->where('assignment_attempts', '<', AssignmentService::MAX_TENTATIVES)
            ->orderBy('id')
            ->pluck('id');

        foreach ($candidateIds as $orderId) {
            $retried = DB::transaction(function () use ($orderId, &$retriedCount): bool {
                /** @var Order|null $lockedOrder */
                $lockedOrder = Order::lockForUpdate()->find((int) $orderId);

                if (
                    ! $lockedOrder
                    || $lockedOrder->courier_id !== null
                    || ! in_array($lockedOrder->status, [Order::STATUS_ACCEPTED, Order::STATUS_READY], true)
                    || (int) $lockedOrder->assignment_attempts >= AssignmentService::MAX_TENTATIVES
                ) {
                    return false;
                }

                $cityId = (int) $lockedOrder->city_id;
                $delaySeconds = $this->settingsService->getDelaiRegle('acceptation_livreur_secondes', $cityId);
                $marginSeconds = $this->settingsService->getDelaiRegle('marge_balayage_livreur_secondes', $cityId, minimum: 0);
                $thresholdSeconds = $delaySeconds + $marginSeconds;

                $reference = $lockedOrder->last_assignment_attempt_at
                    ?? $lockedOrder->accepted_at
                    ?? $lockedOrder->ready_at
                    ?? $lockedOrder->updated_at
                    ?? $lockedOrder->created_at;

                if (! $reference || $reference->diffInSeconds(now(), false) < $thresholdSeconds) {
                    return false;
                }

                $previousAttempts = (int) $lockedOrder->assignment_attempts;
                $nextAttempt = $previousAttempts + 1;
                $exclusions = app(CourseOfferService::class)->obtenirExclusionsPourCourse($lockedOrder);
                app(CourseOfferService::class)->cloturerOffresPourCourse($lockedOrder);

                $this->assignmentService->attribuerLivreurGoShop(
                    order: $lockedOrder,
                    tentative: $nextAttempt,
                    exclusions: $exclusions,
                );
                $lockedOrder->refresh();

                $this->activityLogService->log(
                    userId: null,
                    action: 'order_sweep_assignment_retried',
                    entityType: 'order',
                    entityId: $lockedOrder->id,
                    properties: [
                        'city_id' => $cityId,
                        'previous_attempts' => $previousAttempts,
                        'new_attempts' => (int) $lockedOrder->assignment_attempts,
                        'new_status' => $lockedOrder->status,
                        'delay_seconds' => $delaySeconds,
                        'margin_seconds' => $marginSeconds,
                    ],
                    severity: 'warning',
                );

                $retriedCount++;

                return true;
            });

            unset($retried);
        }

        return $retriedCount;
    }

    /**
     * Signale à l'administration toute livraison (GoShop ou GoColis) terminée depuis > 5 min
     * sans règlement portefeuille complet (Point 4d).
     */
    private function signalerLivraisonsSansReglement(): int
    {
        $cutoff = now()->subMinutes(5);
        $settlementService = app(DeliverySettlementService::class);
        $alertCount = 0;

        $orders = Order::where('status', Order::STATUS_DELIVERED)
            ->where(function ($q) use ($cutoff): void {
                $q->where('delivered_at', '<=', $cutoff)
                    ->orWhere(function ($q2) use ($cutoff): void {
                        $q2->whereNull('delivered_at')->where('updated_at', '<=', $cutoff);
                    });
            })
            ->orderBy('id')
            ->limit(100)
            ->get();

        foreach ($orders as $order) {
            $audit = $settlementService->auditerLivraison($order);
            if ($audit['is_settled']) {
                continue;
            }

            if ($this->notifierAdminsLivraisonNonReglee('order', (int) $order->id, (int) $order->city_id, $audit['discrepancies'])) {
                $alertCount++;
            }
        }

        $parcels = Parcel::where('status', Parcel::STATUS_DELIVERED)
            ->where(function ($q) use ($cutoff): void {
                $q->where('delivered_at', '<=', $cutoff)
                    ->orWhere(function ($q2) use ($cutoff): void {
                        $q2->whereNull('delivered_at')->where('updated_at', '<=', $cutoff);
                    });
            })
            ->orderBy('id')
            ->limit(100)
            ->get();

        foreach ($parcels as $parcel) {
            $audit = $settlementService->auditerLivraison($parcel);
            if ($audit['is_settled']) {
                continue;
            }

            if ($this->notifierAdminsLivraisonNonReglee('parcel', (int) $parcel->id, (int) $parcel->city_id, $audit['discrepancies'])) {
                $alertCount++;
            }
        }

        return $alertCount;
    }

    /**
     * @param  list<string>  $discrepancies
     */
    private function notifierAdminsLivraisonNonReglee(string $entity, int $entityId, int $cityId, array $discrepancies): bool
    {
        $marker = "{$entity}:{$entityId}";
        $alreadyAlerted = Notification::where('type', 'admin_unsettled_delivery_alert')
            ->where('body', 'like', "%[{$marker}]%")
            ->exists();

        if ($alreadyAlerted) {
            return false;
        }

        $admins = User::where('is_active', true)
            ->where(function ($q) use ($cityId): void {
                $q->whereIn('role', ['super_admin', 'dg_national', 'national_finance', 'national_support'])
                    ->orWhere(function ($q2) use ($cityId): void {
                        $q2->whereIn('role', ['city_manager', 'city_finance', 'city_support'])
                            ->where('city_id', $cityId);
                    });
            })
            ->get();

        $label = $entity === 'order' ? "Commande GoShop #{$entityId}" : "Colis GoColis #{$entityId}";
        $body = "[{$marker}] {$label} livré depuis plus de 5 min sans règlement portefeuille complet : ".implode(' ; ', $discrepancies);

        foreach ($admins as $admin) {
            Notification::create([
                'user_id' => $admin->id,
                'type' => 'admin_unsettled_delivery_alert',
                'title' => "Règlement manquant — {$label}",
                'body' => $body,
                'data' => [
                    'entity' => $entity,
                    'entity_id' => $entityId,
                    'city_id' => $cityId,
                    'discrepancies' => $discrepancies,
                ],
                'is_read' => false,
            ]);
        }

        $this->activityLogService->log(
            userId: null,
            action: 'unsettled_delivery_detected',
            entityType: $entity,
            entityId: $entityId,
            properties: [
                'city_id' => $cityId,
                'discrepancies' => $discrepancies,
            ],
            severity: 'critical',
        );

        return true;
    }
}
