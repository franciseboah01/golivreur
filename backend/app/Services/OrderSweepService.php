<?php

namespace App\Services;

use App\Jobs\AnnulerCommandeTimeout;
use App\Models\Order;
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
     * @return array{pending_cancelled: int, assignment_retried: int}
     */
    public function balayer(): array
    {
        return [
            'pending_cancelled' => $this->balayerCommandesPendingExpirees(),
            'assignment_retried' => $this->balayerCommandesEnAttenteAttribution(),
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

                $this->assignmentService->attribuerLivreurGoShop($lockedOrder, tentative: $nextAttempt);
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
}

