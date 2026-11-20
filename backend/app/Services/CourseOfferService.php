<?php

namespace App\Services;

use App\Models\City;
use App\Models\Courier;
use App\Models\Neighborhood;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Parcel;
use App\Modules\GoShop\Services\AssignmentService;
use Illuminate\Support\Carbon;

/**
 * Gère la fenêtre d'exclusivité des propositions de courses GoShop et GoColis
 * en s'appuyant sur la table `notifications` existante (zéro nouvelle table SQL).
 *
 * - Garantit que seul le livreur actuellement ciblé voit la proposition pendant son délai (30s par défaut).
 * - Empêche un autre livreur d'accepter la course tant que la fenêtre exclusive du livreur ciblé est active.
 * - Conserve l'historique des livreurs déjà sollicités (`exclusions`) pour éviter de reproposer au même livreur.
 * - Rattrape à la volée les propositions expirées lorsque le worker queue ou le cron n'a pas encore tourné.
 */
class CourseOfferService
{
    public const TYPE_OFFER_GOSHOP = 'course_offer_goshop';

    public const TYPE_OFFER_GOCOLIS = 'course_offer_gocolis';

    private readonly SettingsService $settingsService;

    public function __construct(?SettingsService $settingsService = null)
    {
        $this->settingsService = $settingsService ?? app(SettingsService::class);
    }

    /**
     * Retourne la dernière offre enregistrée pour une course (Order ou Parcel).
     *
     * @return array{
     *     notification: Notification,
     *     courier_id: int,
     *     user_id: int,
     *     tentative: int,
     *     exclusions: list<int>,
     *     proposed_at: Carbon,
     *     expires_at: Carbon,
     *     remaining_seconds: int,
     *     is_expired: bool
     * }|null
     */
    public function obtenirDerniereOffrePourCourse(Order|Parcel $course): ?array
    {
        $isOrder = $course instanceof Order;
        $type = $isOrder ? self::TYPE_OFFER_GOSHOP : self::TYPE_OFFER_GOCOLIS;
        $idKey = $isOrder ? 'order_id' : 'parcel_id';
        $targetId = (int) $course->id;

        $candidates = Notification::query()
            ->where('type', $type)
            ->where('created_at', '>=', now()->subHours(2))
            ->orderByDesc('id')
            ->limit(30)
            ->get();

        /** @var Notification|null $latest */
        $latest = $candidates->first(function (Notification $n) use ($idKey, $targetId): bool {
            $data = is_array($n->data) ? $n->data : [];

            return isset($data[$idKey]) && (int) $data[$idKey] === $targetId;
        });

        if (! $latest) {
            return null;
        }

        return $this->hydraterOffre($latest, (int) $course->city_id);
    }

    /**
     * Liste tous les IDs de livreurs (`couriers.id`) ayant déjà reçu une proposition pour cette course.
     *
     * @return list<int>
     */
    public function obtenirExclusionsPourCourse(Order|Parcel $course): array
    {
        $isOrder = $course instanceof Order;
        $type = $isOrder ? self::TYPE_OFFER_GOSHOP : self::TYPE_OFFER_GOCOLIS;
        $idKey = $isOrder ? 'order_id' : 'parcel_id';
        $targetId = (int) $course->id;

        $notifications = Notification::query()
            ->where('type', $type)
            ->where('created_at', '>=', now()->subHours(6))
            ->orderBy('id')
            ->limit(50)
            ->get();

        $courierIds = [];
        $userIdsToResolve = [];

        foreach ($notifications as $n) {
            $data = is_array($n->data) ? $n->data : [];
            if (! isset($data[$idKey]) || (int) $data[$idKey] !== $targetId) {
                continue;
            }

            if (isset($data['courier_id']) && is_numeric($data['courier_id'])) {
                $cid = (int) $data['courier_id'];
                if ($cid > 0) {
                    $courierIds[$cid] = $cid;
                }
            } elseif ($n->user_id) {
                $userIdsToResolve[] = (int) $n->user_id;
            }

            if (isset($data['exclusions']) && is_array($data['exclusions'])) {
                foreach ($data['exclusions'] as $ex) {
                    if (is_numeric($ex) && (int) $ex > 0) {
                        $courierIds[(int) $ex] = (int) $ex;
                    }
                }
            }
        }

        if ($userIdsToResolve !== []) {
            $resolved = Courier::query()
                ->whereIn('user_id', array_values(array_unique($userIdsToResolve)))
                ->pluck('id');
            foreach ($resolved as $cid) {
                $courierIds[(int) $cid] = (int) $cid;
            }
        }

        return array_values($courierIds);
    }

    /**
     * Vérifie si la course fait actuellement l'objet d'une proposition exclusive non expirée
     * destinée à un AUTRE livreur que celui qui tente l'action.
     */
    public function estReserveeAutreLivreur(Order|Parcel $course, Courier $courier): bool
    {
        if ($course->courier_id !== null) {
            return (int) $course->courier_id !== (int) $courier->id;
        }

        if ($course->status === Order::STATUS_WAITING) {
            return false;
        }

        $offre = $this->obtenirDerniereOffrePourCourse($course);
        if ($offre === null || $offre['is_expired']) {
            return false;
        }

        if ($offre['courier_id'] > 0 && $offre['courier_id'] !== (int) $courier->id) {
            return true;
        }

        if ($offre['user_id'] > 0 && $offre['user_id'] !== (int) $courier->user_id) {
            return true;
        }

        return false;
    }

    /**
     * Marque comme lues/clôturées les notifications d'offre associées à une course.
     */
    public function cloturerOffresPourCourse(Order|Parcel $course, ?int $onlyUserId = null): void
    {
        $isOrder = $course instanceof Order;
        $type = $isOrder ? self::TYPE_OFFER_GOSHOP : self::TYPE_OFFER_GOCOLIS;
        $idKey = $isOrder ? 'order_id' : 'parcel_id';
        $targetId = (int) $course->id;

        $query = Notification::query()
            ->where('type', $type)
            ->where('is_read', false)
            ->where('created_at', '>=', now()->subHours(6));

        if ($onlyUserId !== null) {
            $query->where('user_id', $onlyUserId);
        }

        $idsToClose = $query->get()
            ->filter(function (Notification $n) use ($idKey, $targetId): bool {
                $data = is_array($n->data) ? $n->data : [];

                return isset($data[$idKey]) && (int) $data[$idKey] === $targetId;
            })
            ->pluck('id')
            ->all();

        if ($idsToClose !== []) {
            Notification::whereIn('id', $idsToClose)->update([
                'is_read' => true,
                'read_at' => now(),
            ]);
        }
    }

    /**
     * Retourne les propositions actives destinées EXCLUSIVEMENT à ce livreur.
     *
     * @return array{
     *     orders: list<array<string, mixed>>,
     *     parcels: list<array<string, mixed>>
     * }
     */
    public function obtenirOffresActivesPourLivreur(Courier $courier): array
    {
        if (! $courier->is_online || ! $courier->is_active || ! $courier->city_id) {
            return ['orders' => [], 'parcels' => []];
        }

        $capacityService = new CourierCapacityService($this->settingsService);
        if (! $capacityService->peutAccepterNouvelleCourse($courier->id, (int) $courier->city_id)) {
            return ['orders' => [], 'parcels' => []];
        }

        $recentForCourier = Notification::query()
            ->where('user_id', $courier->user_id)
            ->whereIn('type', [self::TYPE_OFFER_GOSHOP, self::TYPE_OFFER_GOCOLIS])
            ->where('is_read', false)
            ->where('created_at', '>=', now()->subMinutes(10))
            ->orderByDesc('id')
            ->limit(15)
            ->get();

        $offeredOrders = [];
        $offeredParcels = [];
        $seenOrderIds = [];
        $seenParcelIds = [];

        foreach ($recentForCourier as $notif) {
            $data = is_array($notif->data) ? $notif->data : [];

            if ($notif->type === self::TYPE_OFFER_GOSHOP && isset($data['order_id'])) {
                $orderId = (int) $data['order_id'];
                if ($orderId <= 0 || isset($seenOrderIds[$orderId])) {
                    continue;
                }
                $seenOrderIds[$orderId] = true;

                $order = Order::with(['merchant', 'client', 'items.product'])->find($orderId);
                if (
                    ! $order
                    || $order->courier_id !== null
                    || (int) $order->city_id !== (int) $courier->city_id
                    || ! in_array($order->status, [Order::STATUS_ACCEPTED, Order::STATUS_READY, Order::STATUS_WAITING], true)
                ) {
                    continue;
                }

                $latestOffer = $this->obtenirDerniereOffrePourCourse($order);
                if (
                    $latestOffer === null
                    || $latestOffer['is_expired']
                    || $latestOffer['notification']->id !== $notif->id
                ) {
                    continue;
                }

                $serialized = $order->toArray();
                $serialized['offer_expires_at'] = $latestOffer['expires_at']->toIso8601String();
                $serialized['offer_remaining_seconds'] = $latestOffer['remaining_seconds'];
                $serialized['offer_tentative'] = $latestOffer['tentative'];
                $offeredOrders[] = $serialized;
            } elseif ($notif->type === self::TYPE_OFFER_GOCOLIS && isset($data['parcel_id'])) {
                $parcelId = (int) $data['parcel_id'];
                if ($parcelId <= 0 || isset($seenParcelIds[$parcelId])) {
                    continue;
                }
                $seenParcelIds[$parcelId] = true;

                $parcel = Parcel::find($parcelId);
                if (
                    ! $parcel
                    || $parcel->courier_id !== null
                    || (int) $parcel->city_id !== (int) $courier->city_id
                    || ! in_array($parcel->status, [Parcel::STATUS_PENDING, Parcel::STATUS_WAITING], true)
                ) {
                    continue;
                }

                $latestOffer = $this->obtenirDerniereOffrePourCourse($parcel);
                if (
                    $latestOffer === null
                    || $latestOffer['is_expired']
                    || $latestOffer['notification']->id !== $notif->id
                ) {
                    continue;
                }

                $offeredParcels[] = [
                    'id' => $parcel->id,
                    'size' => $parcel->size,
                    'size_label' => $parcel->size_label,
                    'status' => $parcel->status,
                    'shipping_fee' => (float) $parcel->shipping_fee,
                    'courier_amount' => (float) $parcel->courier_amount,
                    'distance_km' => $parcel->distance_km !== null ? (float) $parcel->distance_km : 0.0,
                    'pickup_address' => $parcel->pickup_address ?: $this->resoudreZoneApproximative(
                        $parcel,
                        $parcel->pickup_lat !== null ? (float) $parcel->pickup_lat : null,
                        $parcel->pickup_lng !== null ? (float) $parcel->pickup_lng : null,
                    ),
                    'delivery_address' => $parcel->delivery_address ?: $this->resoudreZoneApproximative(
                        $parcel,
                        $parcel->delivery_lat !== null ? (float) $parcel->delivery_lat : null,
                        $parcel->delivery_lng !== null ? (float) $parcel->delivery_lng : null,
                    ),
                    'pickup_lat' => $parcel->pickup_lat !== null ? (float) $parcel->pickup_lat : null,
                    'pickup_lng' => $parcel->pickup_lng !== null ? (float) $parcel->pickup_lng : null,
                    'delivery_lat' => $parcel->delivery_lat !== null ? (float) $parcel->delivery_lat : null,
                    'delivery_lng' => $parcel->delivery_lng !== null ? (float) $parcel->delivery_lng : null,
                    'payment_method' => $parcel->payment_method,
                    'offer_expires_at' => $latestOffer['expires_at']->toIso8601String(),
                    'offer_remaining_seconds' => $latestOffer['remaining_seconds'],
                    'offer_tentative' => $latestOffer['tentative'],
                    'created_at' => $parcel->created_at?->toIso8601String(),
                ];
            }
        }

        return [
            'orders' => $offeredOrders,
            'parcels' => $offeredParcels,
        ];
    }

    /**
     * Rattrape à la volée les propositions expirées dans une ville donnée
     * (filet temps réel lorsque aucun worker queue ne tourne en tâche de fond).
     */
    public function rattraperOffresExpireesVille(int $cityId, AssignmentService $assignmentService): void
    {
        $delaiLivreur = $this->settingsService->getDelaiRegle('acceptation_livreur_secondes', $cityId, 30);

        // 1. Commandes GoShop acceptées/prêtes sans livreur dont la proposition actuelle a expiré
        $candidateOrders = Order::query()
            ->where('city_id', $cityId)
            ->whereNull('courier_id')
            ->whereIn('status', [Order::STATUS_ACCEPTED, Order::STATUS_READY])
            ->where('created_at', '>=', now()->subHours(3))
            ->orderBy('id')
            ->limit(10)
            ->get();

        foreach ($candidateOrders as $order) {
            $latestOffer = $this->obtenirDerniereOffrePourCourse($order);
            if ($latestOffer !== null) {
                if (! $latestOffer['is_expired']) {
                    continue;
                }
                $this->cloturerOffresPourCourse($order);
                $exclusions = $this->obtenirExclusionsPourCourse($order);
                $nextAttempt = max((int) $order->assignment_attempts, $latestOffer['tentative']) + 1;
                $assignmentService->attribuerLivreurGoShop($order, tentative: $nextAttempt, exclusions: $exclusions);
            } elseif (
                (int) $order->assignment_attempts === 0
                || (
                    $order->last_assignment_attempt_at !== null
                    && $order->last_assignment_attempt_at->diffInSeconds(now(), false) >= $delaiLivreur
                )
            ) {
                $exclusions = $this->obtenirExclusionsPourCourse($order);
                $nextAttempt = (int) $order->assignment_attempts + 1;
                $assignmentService->attribuerLivreurGoShop($order, tentative: $nextAttempt, exclusions: $exclusions);
            }
        }

        // 2. Expéditions GoColis pending sans livreur dont la proposition actuelle a expiré
        $candidateParcels = Parcel::query()
            ->where('city_id', $cityId)
            ->whereNull('courier_id')
            ->where('status', Parcel::STATUS_PENDING)
            ->where('created_at', '>=', now()->subHours(3))
            ->orderBy('id')
            ->limit(10)
            ->get();

        foreach ($candidateParcels as $parcel) {
            $latestOffer = $this->obtenirDerniereOffrePourCourse($parcel);
            if ($latestOffer !== null) {
                if (! $latestOffer['is_expired']) {
                    continue;
                }
                $this->cloturerOffresPourCourse($parcel);
                $exclusions = $this->obtenirExclusionsPourCourse($parcel);
                $nextAttempt = max((int) $parcel->assignment_attempts, $latestOffer['tentative']) + 1;
                $assignmentService->attribuerLivreurGoColis($parcel, tentative: $nextAttempt, exclusions: $exclusions);
            } elseif ((int) $parcel->assignment_attempts === 0) {
                $assignmentService->attribuerLivreurGoColis($parcel, tentative: 1);
            }
        }
    }

    /**
     * @return array{
     *     notification: Notification,
     *     courier_id: int,
     *     user_id: int,
     *     tentative: int,
     *     exclusions: list<int>,
     *     proposed_at: Carbon,
     *     expires_at: Carbon,
     *     remaining_seconds: int,
     *     is_expired: bool
     * }
     */
    private function hydraterOffre(Notification $notif, int $cityId): array
    {
        $data = is_array($notif->data) ? $notif->data : [];
        $defaultDelay = $this->settingsService->getDelaiRegle('acceptation_livreur_secondes', $cityId, 30);

        $proposedAt = ! empty($data['proposed_at']) && is_string($data['proposed_at'])
            ? Carbon::parse($data['proposed_at'])
            : ($notif->created_at ? Carbon::instance($notif->created_at) : now());

        $expiresAt = ! empty($data['expires_at']) && is_string($data['expires_at'])
            ? Carbon::parse($data['expires_at'])
            : $proposedAt->copy()->addSeconds($defaultDelay);

        $courierId = isset($data['courier_id']) && is_numeric($data['courier_id'])
            ? (int) $data['courier_id']
            : (int) (Courier::where('user_id', $notif->user_id)->value('id') ?? 0);

        $exclusions = [];
        if (isset($data['exclusions']) && is_array($data['exclusions'])) {
            foreach ($data['exclusions'] as $ex) {
                if (is_numeric($ex) && (int) $ex > 0) {
                    $exclusions[] = (int) $ex;
                }
            }
        }
        if ($courierId > 0 && ! in_array($courierId, $exclusions, true)) {
            $exclusions[] = $courierId;
        }

        $diff = (int) round(now()->diffInSeconds($expiresAt, false));
        $isExpired = (bool) $notif->is_read || $diff <= 0;

        return [
            'notification' => $notif,
            'courier_id' => $courierId,
            'user_id' => (int) $notif->user_id,
            'tentative' => isset($data['tentative']) && is_numeric($data['tentative'])
                ? max(1, (int) $data['tentative'])
                : 1,
            'exclusions' => array_values(array_unique($exclusions)),
            'proposed_at' => $proposedAt,
            'expires_at' => $expiresAt,
            'remaining_seconds' => max(0, $diff),
            'is_expired' => $isExpired,
        ];
    }

    private function resoudreZoneApproximative(Parcel $parcel, ?float $lat, ?float $lng): string
    {
        if ($lat !== null && $lng !== null) {
            $quartier = Neighborhood::query()
                ->where('city_id', $parcel->city_id)
                ->where('is_active', true)
                ->whereNotNull('lat')
                ->whereNotNull('lng')
                ->get()
                ->sortBy(fn (Neighborhood $q): float => City::distanceHaversine($lat, $lng, (float) $q->lat, (float) $q->lng))
                ->first();

            if ($quartier !== null) {
                return $quartier->name;
            }
        }

        $cityName = City::whereKey($parcel->city_id)->value('name');

        return is_string($cityName) && $cityName !== '' ? "Secteur {$cityName}" : 'Zone urbaine';
    }
}
