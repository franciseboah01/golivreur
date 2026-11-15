<?php

namespace App\Modules\GoShop\Services;

use App\Jobs\AlerterAdminPasDelivreur;
use App\Jobs\NotifierLivreurGoColis;
use App\Jobs\NotifierLivreurGoShop;
use App\Models\Courier;
use App\Models\Order;
use App\Models\Parcel;
use App\Services\AsyncJobDispatcher;
use App\Services\CourierCapacityService;
use App\Services\NotificationService;
use App\Services\SettingsService;
use App\Services\WalletService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Attribue les courses aux livreurs disponibles par proximité géographique.
 * Gère jusqu'à 3 tentatives avant de passer en statut 'waiting' et d'alerter l'admin.
 */
class AssignmentService
{
    public const RAYON_INITIAL_KM = 5;

    private const RAYON_ETENDU_KM = 10;

    private const MAX_TENTATIVES = 3;

    private readonly WalletService $walletService;

    private readonly SettingsService $settingsService;

    private readonly CourierCapacityService $capacityService;

    public function __construct(
        private readonly NotificationService $notificationService,
        ?WalletService $walletService = null,
        ?SettingsService $settingsService = null,
        ?CourierCapacityService $capacityService = null,
    ) {
        $this->walletService = $walletService ?? app(WalletService::class);
        $this->settingsService = $settingsService ?? app(SettingsService::class);
        $this->capacityService = $capacityService ?? new CourierCapacityService($this->settingsService);
    }

    /**
     * Trouve le livreur le plus proche et lui propose la course GoShop.
     *
     * @param  array<int, int>  $exclusions
     */
    public function attribuerLivreurGoShop(Order $order, int $tentative = 1, array $exclusions = []): void
    {
        $statutsEligibles = $tentative === 1
            ? [Order::STATUS_PENDING, Order::STATUS_ACCEPTED, Order::STATUS_READY, Order::STATUS_WAITING]
            : [Order::STATUS_ACCEPTED, Order::STATUS_READY, Order::STATUS_WAITING];

        if (
            $order->courier_id !== null
            || ! in_array($order->status, $statutsEligibles, true)
        ) {
            return;
        }

        $montantRequisCash = $order->payment_method === 'cash'
            ? $this->walletService->calculerMontantAReverserOrder($order)
            : null;

        $this->attribuerCourse(
            course: $order,
            type: 'goshop',
            waitingStatus: Order::STATUS_WAITING,
            pickupLat: $order->pickup_lat !== null ? (float) $order->pickup_lat : null,
            pickupLng: $order->pickup_lng !== null ? (float) $order->pickup_lng : null,
            cityId: (int) $order->city_id,
            tentative: $tentative,
            exclusions: $exclusions,
            montantRequisCash: $montantRequisCash,
            onOffer: function (Courier $livreur, int $currentAttempt, array $updatedExclusions, int $delaySeconds) use ($order): void {
                $this->notificationService->proposerCourseGoShop($livreur, $order);

                if (class_exists(NotifierLivreurGoShop::class)) {
                    $orderId = $order->id;
                    $courierId = $livreur->id;
                    $proposeeLe = now()->toIso8601String();
                    AsyncJobDispatcher::dispatchAfterCommit(
                        static fn () => NotifierLivreurGoShop::dispatch(
                            $orderId,
                            $courierId,
                            $currentAttempt,
                            $updatedExclusions,
                            $proposeeLe,
                        )
                            ->delay(now()->addSeconds($delaySeconds))
                            ->onQueue('default'),
                        operation: 'notifier_livreur_goshop',
                        contexte: ['order_id' => $orderId, 'courier_id' => $courierId, 'tentative' => $currentAttempt],
                    );
                }
            },
            onWaiting: function (bool $wasAlreadyWaiting) use ($order): void {
                if (! $wasAlreadyWaiting) {
                    $this->notificationService->notifierClientAttenteGoShop($order);
                }
            },
        );
    }

    /**
     * Trouve le livreur le plus proche et lui propose la course GoColis.
     * La taille du colis est visible avant acceptation (v5).
     *
     * @param  array<int, int>  $exclusions
     */
    public function attribuerLivreurGoColis(Parcel $parcel, int $tentative = 1, array $exclusions = []): void
    {
        if (
            $parcel->courier_id !== null
            || ! in_array($parcel->status, [Parcel::STATUS_PENDING, Parcel::STATUS_WAITING], true)
        ) {
            return;
        }

        $montantRequisCash = $parcel->payment_method === 'cash'
            ? $this->walletService->calculerMontantAReverserParcel($parcel)
            : null;

        $this->attribuerCourse(
            course: $parcel,
            type: 'gocolis',
            waitingStatus: Parcel::STATUS_WAITING,
            pickupLat: $parcel->pickup_lat !== null ? (float) $parcel->pickup_lat : null,
            pickupLng: $parcel->pickup_lng !== null ? (float) $parcel->pickup_lng : null,
            cityId: (int) $parcel->city_id,
            tentative: $tentative,
            exclusions: $exclusions,
            montantRequisCash: $montantRequisCash,
            onOffer: function (Courier $livreur, int $currentAttempt, array $updatedExclusions, int $delaySeconds) use ($parcel): void {
                $this->notificationService->proposerCourseGoColis($livreur, $parcel);

                if (class_exists(NotifierLivreurGoColis::class)) {
                    $parcelId = $parcel->id;
                    $courierId = $livreur->id;
                    $proposeeLe = now()->toIso8601String();
                    AsyncJobDispatcher::dispatchAfterCommit(
                        static fn () => NotifierLivreurGoColis::dispatch(
                            $parcelId,
                            $courierId,
                            $currentAttempt,
                            $updatedExclusions,
                            $proposeeLe,
                        )
                            ->delay(now()->addSeconds($delaySeconds))
                            ->onQueue('default'),
                        operation: 'notifier_livreur_gocolis',
                        contexte: ['parcel_id' => $parcelId, 'courier_id' => $courierId, 'tentative' => $currentAttempt],
                    );
                }
            },
            onWaiting: function (bool $notifyClient) use ($parcel): void {
                if ($notifyClient) {
                    $this->notificationService->notifierClientAttenteGoColis($parcel);
                }
            },
        );
    }

    /**
     * Logique factorisée d'attribution et d'escalade GoShop / GoColis après timeout.
     */
    public function traiterVerificationTimeout(
        Order|Parcel $course,
        int $courierId,
        int $tentative,
        array $exclusions,
        ?string $proposeeLe,
        string $type,
    ): void {
        if ($proposeeLe === null) {
            $courier = Courier::with('user')->find($courierId);
            if ($courier && $courier->is_active && $courier->is_online && (int) $courier->city_id === (int) $course->city_id) {
                if ($course instanceof Order) {
                    $this->notificationService->proposerCourseGoShop($courier, $course);
                } else {
                    $this->notificationService->proposerCourseGoColis($courier, $course);
                }

                $label = $type === 'goshop' ? 'GoShop' : 'GoColis';
                Log::info("Notification course {$label} #{$course->id} envoyée au livreur #{$courierId} (tentative {$tentative}).");
            }

            return;
        }

        $delaiSecondes = $this->settingsService->getDelaiRegle('acceptation_livreur_secondes', (int) $course->city_id, 30);

        $secondesEcoulees = Carbon::parse($proposeeLe)->diffInSeconds(now(), false);
        $label = $type === 'goshop' ? 'GoShop' : 'GoColis';

        if ($secondesEcoulees < $delaiSecondes) {
            Log::info("Vérification acceptation course {$label} #{$course->id} tentative {$tentative}");

            return;
        }

        Log::info("Délai expiré ({$secondesEcoulees}s) pour course {$label} #{$course->id} tentative {$tentative}, relance.");

        if ($course instanceof Order) {
            $this->attribuerLivreurGoShop($course, $tentative + 1, $exclusions);
        } else {
            $this->attribuerLivreurGoColis($course, $tentative + 1, $exclusions);
        }
    }

    /**
     * @param  array<int, int>  $exclusions
     * @param  callable(Courier, int, array<int, int>, int): void  $onOffer
     * @param  callable(bool): void  $onWaiting
     */
    private function attribuerCourse(
        Order|Parcel $course,
        string $type,
        string $waitingStatus,
        ?float $pickupLat,
        ?float $pickupLng,
        int $cityId,
        int $tentative,
        array $exclusions,
        ?int $montantRequisCash,
        callable $onOffer,
        callable $onWaiting,
    ): void {
        if ($tentative > self::MAX_TENTATIVES) {
            $wasAlreadyWaiting = $course->status === $waitingStatus;
            $course->update([
                'status' => $waitingStatus,
                'assignment_attempts' => max((int) $course->assignment_attempts, self::MAX_TENTATIVES),
            ]);

            if (class_exists(AlerterAdminPasDelivreur::class)) {
                AsyncJobDispatcher::dispatchAfterCommit(
                    static fn () => AlerterAdminPasDelivreur::dispatch($course, $type)
                        ->onQueue('high'),
                    operation: 'alerter_admin_pas_de_livreur',
                    contexte: ['course_id' => $course->id, 'type' => $type],
                );
            }

            $onWaiting($type === 'gocolis' ? true : $wasAlreadyWaiting);

            return;
        }

        $livreur = $this->trouverLivreurProche(
            lat: $pickupLat,
            lng: $pickupLng,
            cityId: $cityId,
            excludeIds: $exclusions,
            montantRequisCash: $montantRequisCash,
        );

        if (! $livreur) {
            $wasAlreadyWaiting = $course->status === $waitingStatus;

            if ($type === 'gocolis' || $tentative > 1) {
                $course->update([
                    'status' => $waitingStatus,
                    'assignment_attempts' => max((int) $course->assignment_attempts, $tentative - 1),
                ]);
            }

            if (class_exists(AlerterAdminPasDelivreur::class)) {
                AsyncJobDispatcher::dispatchAfterCommit(
                    static fn () => AlerterAdminPasDelivreur::dispatch($course, $type)
                        ->onQueue('high'),
                    operation: 'alerter_admin_pas_de_livreur',
                    contexte: ['course_id' => $course->id, 'type' => $type],
                );
            }

            if ($type === 'goshop' && $tentative > 1) {
                $onWaiting($wasAlreadyWaiting);
            }

            return;
        }

        $course->update(['assignment_attempts' => $tentative]);

        $delaiSecondes = $this->settingsService->getDelaiRegle('acceptation_livreur_secondes', $cityId, 30);

        $updatedExclusions = array_values(array_unique([...$exclusions, $livreur->id]));
        $onOffer($livreur, $tentative, $updatedExclusions, $delaiSecondes);
    }

    /**
     * Trouve le livreur en ligne le plus proche d'un point GPS dans une ville.
     * Pour les courses en espèces, exclut tout livreur dont le solde disponible est insuffisant.
     */
    private function trouverLivreurProche(
        ?float $lat,
        ?float $lng,
        int $cityId,
        array $excludeIds = [],
        ?int $montantRequisCash = null,
    ): ?Courier {
        $fraicheurMinutes = $this->settingsService->getDelaiRegle(
            'gps_fraicheur_minutes',
            $cityId,
            2,
        );

        $baseQuery = Courier::where('city_id', $cityId)
            ->where('is_online', true)
            ->where('is_active', true)
            ->whereNotNull('current_lat')
            ->whereNotNull('current_lng')
            ->whereNotNull('location_updated_at')
            ->where('location_updated_at', '>=', now()->subMinutes($fraicheurMinutes))
            ->where(function ($q) {
                $q->whereNull('penalty_until')
                    ->orWhere('penalty_until', '<', now());
            })
            ->whereNotIn('id', $excludeIds);

        if ($lat && $lng) {
            $haversine = '(6371 * acos(least(1.0, greatest(-1.0, cos(radians(?)) * cos(radians(current_lat)) * cos(radians(current_lng) - radians(?)) + sin(radians(?)) * sin(radians(current_lat))))))';

            $baseQuery = $baseQuery
                ->selectRaw("*, {$haversine} AS distance", [$lat, $lng, $lat])
                ->whereRaw("{$haversine} < ?", [$lat, $lng, $lat, self::RAYON_ETENDU_KM])
                ->orderBy('distance');
        }

        /** @var Courier|null $eligible */
        $eligible = $baseQuery->get()->first(function (Courier $candidat) use ($cityId, $montantRequisCash): bool {
            if (! $this->capacityService->peutAccepterNouvelleCourse($candidat->id, $cityId)) {
                return false;
            }

            if ($montantRequisCash === null) {
                return true;
            }

            return $this->walletService->verifierSoldeCashSuffisant(
                courier: $candidat,
                montantAReverser: $montantRequisCash,
                settingsService: $this->settingsService,
                lockWallet: false,
            )['ok'];
        });

        return $eligible;
    }
}
