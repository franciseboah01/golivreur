<?php

namespace App\Http\Controllers\Api\V1\Driver;

use App\Http\Controllers\Controller;
use App\Http\Resources\DriverCourseResource;
use App\Models\Courier;
use App\Models\Order;
use App\Models\Parcel;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Modules\GoShop\Services\AssignmentService;
use App\Modules\GoShop\Services\OrderService;
use App\Services\CityGeolocationService;
use App\Services\CourierCapacityService;
use App\Services\CourierRefusalService;
use App\Services\CourseOfferService;
use App\Services\NotificationService;
use App\Services\SettingsService;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

class DriverController extends Controller
{
    private function getCourier(): Courier
    {
        $courier = Courier::where('user_id', auth()->id())->first();
        if (! $courier) {
            abort(404, 'Profil livreur introuvable.');
        }

        return $courier;
    }

    private function verifierEligibiliteLivreur(Courier $courier, ?SettingsService $settingsService = null): ?JsonResponse
    {
        $service = $settingsService !== null
            ? new CourierRefusalService($settingsService)
            : app(CourierRefusalService::class);

        return $service->verifierEligibilite($courier);
    }

    public function availability(Request $request, CityGeolocationService $geoService): JsonResponse
    {
        $validated = $request->validate([
            'is_online' => 'sometimes|boolean',
            'lat' => 'nullable|numeric|between:-90,90',
            'lng' => 'nullable|numeric|between:-180,180',
        ]);

        $courier = $this->getCourier();

        return DB::transaction(function () use ($request, $validated, $courier, $geoService): JsonResponse {
            /** @var Courier $lockedCourier */
            $lockedCourier = Courier::with(['user', 'city'])->lockForUpdate()->findOrFail($courier->id);
            $isOnline = $request->boolean('is_online', ! $lockedCourier->is_online);

            if ($isOnline && ($refusal = $this->verifierEligibiliteLivreur($lockedCourier)) !== null) {
                return $refusal;
            }

            $hasGps = isset($validated['lat'], $validated['lng']);
            if ($isOnline && $hasGps) {
                $lat = (float) $validated['lat'];
                $lng = (float) $validated['lng'];
                $cityChange = $geoService->detectCityChange($lockedCourier->city, $lat, $lng);

                if ($cityChange !== null) {
                    $flag = $geoService->flagCourierReidentificationRequired(
                        courier: $lockedCourier,
                        detectedCity: $cityChange['detected_city'],
                        lat: $lat,
                        lng: $lng,
                        distanceFromRegisteredKm: $cityChange['distance_from_registered_km'],
                        ipAddress: $request->ip(),
                    );

                    return $geoService->buildReidentificationDeniedResponse($flag);
                }

                $lockedCourier->update([
                    'is_online' => true,
                    'current_lat' => $lat,
                    'current_lng' => $lng,
                    'location_updated_at' => now(),
                ]);
            } else {
                $lockedCourier->update(['is_online' => $isOnline]);
            }

            return response()->json([
                'succes' => true,
                'donnees' => $lockedCourier->fresh(),
                'message' => $isOnline ? 'Vous êtes en ligne.' : 'Vous êtes hors ligne.',
            ]);
        });
    }

    public function updateLocation(Request $request, CityGeolocationService $geoService): JsonResponse
    {
        $validated = $request->validate([
            'lat' => 'required|numeric|between:-90,90',
            'lng' => 'required|numeric|between:-180,180',
        ]);

        $courier = $this->getCourier()->load(['user', 'city']);

        if (! $courier->is_active || ($courier->user && ! $courier->user->is_active)) {
            return response()->json([
                'succes' => false,
                'message' => 'Votre compte livreur est suspendu ou inactif.',
            ], 403);
        }

        // Throttle : 1 requête par 5 secondes par livreur (CDC 11.4)
        $rateKey = "driver_location:{$courier->id}";
        if (RateLimiter::tooManyAttempts($rateKey, 1)) {
            return response()->json([
                'succes' => false,
                'message' => 'Position mise à jour trop fréquemment (maximum 1 requête toutes les 5 secondes).',
            ], 429);
        }
        RateLimiter::hit($rateKey, 5);

        $lat = (float) $validated['lat'];
        $lng = (float) $validated['lng'];

        $courier->update([
            'current_lat' => $lat,
            'current_lng' => $lng,
            'location_updated_at' => now(),
        ]);

        $existingFlag = $geoService->getCourierReidentificationFlag($courier);
        if ($existingFlag !== null) {
            if ($courier->is_online) {
                $courier->update(['is_online' => false]);
            }

            return $geoService->buildReidentificationDeniedResponse($existingFlag);
        }

        $cityChange = $geoService->detectCityChange($courier->city, $lat, $lng);
        if ($cityChange !== null) {
            $flag = $geoService->flagCourierReidentificationRequired(
                courier: $courier,
                detectedCity: $cityChange['detected_city'],
                lat: $lat,
                lng: $lng,
                distanceFromRegisteredKm: $cityChange['distance_from_registered_km'],
                ipAddress: $request->ip(),
            );

            return $geoService->buildReidentificationDeniedResponse($flag);
        }

        return response()->json([
            'succes' => true,
            'donnees' => [
                'lat' => $courier->current_lat,
                'lng' => $courier->current_lng,
                'updated_at' => $courier->location_updated_at,
            ],
        ]);
    }

    public function courses(CourseOfferService $offerService, AssignmentService $assignmentService): JsonResponse
    {
        $courier = $this->getCourier();

        if ($courier->is_online && $courier->is_active && $courier->city_id) {
            $offerService->rattraperOffresExpireesVille((int) $courier->city_id, $assignmentService);
        }

        $orders = Order::where('courier_id', $courier->id)
            ->whereIn('status', [
                Order::STATUS_ACCEPTED,
                'ready',
                'picked_up',
                'in_transit',
            ])
            ->with(['merchant.user', 'client', 'items.product'])
            ->get();

        $parcels = Parcel::where('courier_id', $courier->id)
            ->whereIn('status', [
                Parcel::STATUS_ACCEPTED,
                Parcel::STATUS_AT_SENDER,
                Parcel::STATUS_PICKED_UP,
                Parcel::STATUS_IN_TRANSIT,
                Parcel::STATUS_AT_RECIPIENT,
            ])
            ->with(['sender', 'photos'])
            ->get();

        $offers = $offerService->obtenirOffresActivesPourLivreur($courier);

        return response()->json([
            'succes' => true,
            'donnees' => [
                'orders' => DriverCourseResource::collection($orders)->resolve(),
                'parcels' => DriverCourseResource::collection($parcels)->resolve(),
                'offers' => $offers,
            ],
        ]);
    }

    public function acceptOrder(
        Order $order,
        SettingsService $settingsService,
        WalletService $walletService,
        NotificationService $notificationService,
    ): JsonResponse {
        $courier = $this->getCourier();

        return DB::transaction(function () use ($order, $courier, $settingsService, $walletService, $notificationService) {
            /** @var Order $lockedOrder */
            $lockedOrder = Order::with('merchant:id,user_id')->lockForUpdate()->findOrFail($order->id);
            /** @var Courier $lockedCourier */
            $lockedCourier = Courier::with('user')->lockForUpdate()->findOrFail($courier->id);

            // Interdiction stricte d'auto-livraison pour un commerçant-livreur
            if ($lockedOrder->merchant && (int) $lockedOrder->merchant->user_id === (int) $lockedCourier->user_id) {
                return response()->json([
                    'succes' => false,
                    'code' => 'SELF_ASSIGNMENT_FORBIDDEN',
                    'message' => 'Auto-assignation interdite : un commerçant-livreur ne peut pas livrer les commandes de sa propre boutique.',
                ], 403);
            }

            if ($denied = $this->verifierLivreurEnLigneEtEligible(
                lockedCourier: $lockedCourier,
                resourceCityId: (int) $lockedOrder->city_id,
                notFoundMessage: 'Course introuvable.',
                offlineStatus: 422,
                offlineMessage: 'Vous devez être en ligne pour accepter une course.',
                settingsService: $settingsService,
            )) {
                return $denied;
            }

            if ($lockedOrder->courier_id !== null) {
                return response()->json([
                    'succes' => false,
                    'message' => (int) $lockedOrder->courier_id === (int) $lockedCourier->id
                        ? 'Vous avez déjà accepté cette course.'
                        : 'Course déjà assignée à un autre livreur.',
                ], 409);
            }

            if (! in_array($lockedOrder->status, [Order::STATUS_ACCEPTED, Order::STATUS_READY, Order::STATUS_WAITING], true)) {
                return response()->json([
                    'succes' => false,
                    'message' => 'Cette course ne peut plus être acceptée.',
                ], 422);
            }

            $offerService = app(CourseOfferService::class);
            if ($offerService->estReserveeAutreLivreur($lockedOrder, $lockedCourier)) {
                return response()->json([
                    'succes' => false,
                    'message' => 'Cette course est actuellement proposée en exclusivité à un autre livreur.',
                ], 409);
            }

            $montantAReverser = $lockedOrder->payment_method === 'cash'
                ? $walletService->calculerMontantAReverserOrder($lockedOrder)
                : 0;

            if ($denied = $this->verifierPlafondEtSoldeCash(
                lockedCourier: $lockedCourier,
                paymentMethod: (string) $lockedOrder->payment_method,
                montantAReverser: $montantAReverser,
                settingsService: $settingsService,
                walletService: $walletService,
            )) {
                return $denied;
            }

            $targetStatus = $lockedOrder->status === Order::STATUS_WAITING
                ? ($lockedOrder->ready_at !== null ? Order::STATUS_READY : Order::STATUS_ACCEPTED)
                : $lockedOrder->status;

            $affected = Order::whereKey($lockedOrder->id)
                ->whereNull('courier_id')
                ->whereIn('status', [Order::STATUS_ACCEPTED, Order::STATUS_READY, Order::STATUS_WAITING])
                ->update([
                    'courier_id' => $lockedCourier->id,
                    'status' => $targetStatus,
                    'accepted_at' => $lockedOrder->accepted_at ?? now(),
                ]);

            if ($affected === 0) {
                return response()->json([
                    'succes' => false,
                    'message' => 'Course déjà assignée à un autre livreur.',
                ], 409);
            }

            if ($lockedCourier->consecutive_refusals > 0) {
                $lockedCourier->update(['consecutive_refusals' => 0]);
            }

            $offerService->cloturerOffresPourCourse($lockedOrder);

            $freshOrder = $lockedOrder->fresh() ?? $lockedOrder;
            $notificationService->notifierCommandeAccepteeLivreur($freshOrder);

            return response()->json([
                'succes' => true,
                'donnees' => $freshOrder,
                'message' => 'Course acceptée.',
            ]);
        });
    }

    public function acceptParcel(
        Parcel $parcel,
        SettingsService $settingsService,
        WalletService $walletService,
        NotificationService $notificationService,
    ): JsonResponse {
        $courier = $this->getCourier();

        return DB::transaction(function () use ($parcel, $courier, $settingsService, $walletService, $notificationService): JsonResponse {
            /** @var Parcel $lockedParcel */
            $lockedParcel = Parcel::lockForUpdate()->findOrFail($parcel->id);
            /** @var Courier $lockedCourier */
            $lockedCourier = Courier::with('user')->lockForUpdate()->findOrFail($courier->id);

            if ($denied = $this->verifierLivreurEnLigneEtEligible(
                lockedCourier: $lockedCourier,
                resourceCityId: (int) $lockedParcel->city_id,
                notFoundMessage: 'Expédition introuvable.',
                offlineStatus: 403,
                offlineMessage: 'Vous devez être en ligne pour accepter une expédition.',
                settingsService: $settingsService,
            )) {
                return $denied;
            }

            if ($lockedParcel->courier_id !== null) {
                return response()->json([
                    'succes' => false,
                    'message' => (int) $lockedParcel->courier_id === (int) $lockedCourier->id
                        ? 'Vous avez déjà accepté cette expédition.'
                        : 'Expédition déjà assignée à un autre livreur.',
                ], 409);
            }

            if (! in_array($lockedParcel->status, [Parcel::STATUS_PENDING, Parcel::STATUS_WAITING], true)) {
                return response()->json([
                    'succes' => false,
                    'message' => 'Cette expédition n\'est plus disponible à l\'acceptation.',
                ], 422);
            }

            $offerService = app(CourseOfferService::class);
            if ($offerService->estReserveeAutreLivreur($lockedParcel, $lockedCourier)) {
                return response()->json([
                    'succes' => false,
                    'message' => 'Cette expédition est actuellement proposée en exclusivité à un autre livreur.',
                ], 409);
            }

            $montantAReverser = $lockedParcel->payment_method === 'cash'
                ? $walletService->calculerMontantAReverserParcel($lockedParcel)
                : 0;

            if ($denied = $this->verifierPlafondEtSoldeCash(
                lockedCourier: $lockedCourier,
                paymentMethod: (string) $lockedParcel->payment_method,
                montantAReverser: $montantAReverser,
                settingsService: $settingsService,
                walletService: $walletService,
            )) {
                return $denied;
            }

            $affected = Parcel::whereKey($lockedParcel->id)
                ->whereNull('courier_id')
                ->whereIn('status', [Parcel::STATUS_PENDING, Parcel::STATUS_WAITING])
                ->update([
                    'courier_id' => $lockedCourier->id,
                    'status' => Parcel::STATUS_ACCEPTED,
                ]);

            if ($affected === 0) {
                return response()->json([
                    'succes' => false,
                    'message' => 'Expédition déjà assignée à un autre livreur.',
                ], 409);
            }

            if ($lockedCourier->consecutive_refusals > 0) {
                $lockedCourier->update(['consecutive_refusals' => 0]);
            }

            $offerService->cloturerOffresPourCourse($lockedParcel);

            $freshParcel = $lockedParcel->fresh() ?? $lockedParcel;
            $notificationService->notifierColisAccepteLivreur($freshParcel);

            return response()->json([
                'succes' => true,
                'donnees' => $freshParcel,
                'message' => 'Expédition acceptée.',
            ]);
        });
    }

    public function refuseOrder(
        Request $request,
        Order $order,
        SettingsService $settingsService,
        AssignmentService $assignmentService,
        CourierRefusalService $refusalService,
    ): JsonResponse {
        $request->validate([
            'raison' => 'nullable|string|max:500',
        ]);

        $courier = $this->getCourier();

        /** @var array{response: JsonResponse, reassign_tentative?: int, exclude_courier_id?: int} $outcome */
        $outcome = DB::transaction(function () use ($order, $courier, $settingsService, $refusalService): array {
            /** @var Order $lockedOrder */
            $lockedOrder = Order::lockForUpdate()->findOrFail($order->id);
            /** @var Courier $lockedCourier */
            $lockedCourier = Courier::with('user')->lockForUpdate()->findOrFail($courier->id);

            if ($denied = $this->verifierLivreurEnLigneEtEligible(
                lockedCourier: $lockedCourier,
                resourceCityId: (int) $lockedOrder->city_id,
                notFoundMessage: 'Course introuvable.',
                offlineStatus: 403,
                offlineMessage: 'Vous devez être en ligne pour refuser une course.',
                settingsService: $settingsService,
            )) {
                return ['response' => $denied];
            }

            if ($lockedOrder->courier_id !== null && (int) $lockedOrder->courier_id !== (int) $lockedCourier->id) {
                return [
                    'response' => response()->json([
                        'succes' => false,
                        'message' => 'Course déjà assignée à un autre livreur.',
                    ], 409),
                ];
            }

            if (
                $lockedOrder->courier_id === null
                && in_array($lockedOrder->status, [Order::STATUS_ACCEPTED, Order::STATUS_READY, Order::STATUS_WAITING], true)
            ) {
                app(CourseOfferService::class)->cloturerOffresPourCourse($lockedOrder, (int) $lockedCourier->user_id);

                return $refusalService->enregistrerRefusProposition(
                    lockedCourier: $lockedCourier,
                    lockedCourse: $lockedOrder,
                    courseLabel: 'Course',
                );
            }

            return [
                'response' => response()->json([
                    'succes' => false,
                    'message' => 'Cette course ne peut pas être refusée dans son statut actuel.',
                ], 422),
            ];
        });

        if (isset($outcome['reassign_tentative'], $outcome['exclude_courier_id'])) {
            $freshOrder = $order->fresh();
            if ($freshOrder && $freshOrder->courier_id === null) {
                $exclusions = array_values(array_unique([
                    ...app(CourseOfferService::class)->obtenirExclusionsPourCourse($freshOrder),
                    $outcome['exclude_courier_id'],
                ]));
                $assignmentService->attribuerLivreurGoShop(
                    order: $freshOrder,
                    tentative: $outcome['reassign_tentative'],
                    exclusions: $exclusions,
                );
            }
        }

        return $outcome['response'];
    }

    public function refuseParcel(
        Request $request,
        Parcel $parcel,
        SettingsService $settingsService,
        AssignmentService $assignmentService,
        CourierRefusalService $refusalService,
    ): JsonResponse {
        $request->validate([
            'raison' => 'nullable|string|max:500',
        ]);

        $courier = $this->getCourier();

        /** @var array{response: JsonResponse, reassign_tentative?: int, exclude_courier_id?: int} $outcome */
        $outcome = DB::transaction(function () use ($parcel, $courier, $settingsService, $refusalService): array {
            /** @var Parcel $lockedParcel */
            $lockedParcel = Parcel::lockForUpdate()->findOrFail($parcel->id);
            /** @var Courier $lockedCourier */
            $lockedCourier = Courier::with('user')->lockForUpdate()->findOrFail($courier->id);

            if ($denied = $this->verifierLivreurEnLigneEtEligible(
                lockedCourier: $lockedCourier,
                resourceCityId: (int) $lockedParcel->city_id,
                notFoundMessage: 'Expédition introuvable.',
                offlineStatus: 403,
                offlineMessage: 'Vous devez être en ligne pour refuser une course.',
                settingsService: $settingsService,
            )) {
                return ['response' => $denied];
            }

            if ($lockedParcel->courier_id !== null && (int) $lockedParcel->courier_id !== (int) $lockedCourier->id) {
                return [
                    'response' => response()->json([
                        'succes' => false,
                        'message' => 'Expédition déjà assignée à un autre livreur.',
                    ], 409),
                ];
            }

            // Refus au ramassage d'un colis suspect ou interdit déjà assigné au livreur (CDC 5.2 / Annexe B)
            if (
                $lockedParcel->courier_id !== null
                && (int) $lockedParcel->courier_id === (int) $lockedCourier->id
                && in_array($lockedParcel->status, [Parcel::STATUS_ACCEPTED, Parcel::STATUS_AT_SENDER], true)
            ) {
                $lockedParcel->update(['status' => Parcel::STATUS_REFUSED]);

                return [
                    'response' => response()->json([
                        'succes' => true,
                        'donnees' => $lockedParcel->fresh(),
                        'message' => 'Expédition refusée.',
                    ]),
                ];
            }

            if ($lockedParcel->courier_id === null && in_array($lockedParcel->status, [Parcel::STATUS_PENDING, Parcel::STATUS_WAITING], true)) {
                app(CourseOfferService::class)->cloturerOffresPourCourse($lockedParcel, (int) $lockedCourier->user_id);

                return $refusalService->enregistrerRefusProposition(
                    lockedCourier: $lockedCourier,
                    lockedCourse: $lockedParcel,
                    courseLabel: 'Expédition',
                );
            }

            return [
                'response' => response()->json([
                    'succes' => false,
                    'message' => 'Cette expédition ne peut pas être refusée dans son statut actuel.',
                ], 422),
            ];
        });

        if (isset($outcome['reassign_tentative'], $outcome['exclude_courier_id'])) {
            $freshParcel = $parcel->fresh();
            if ($freshParcel && $freshParcel->courier_id === null) {
                $exclusions = array_values(array_unique([
                    ...app(CourseOfferService::class)->obtenirExclusionsPourCourse($freshParcel),
                    $outcome['exclude_courier_id'],
                ]));
                $assignmentService->attribuerLivreurGoColis(
                    parcel: $freshParcel,
                    tentative: $outcome['reassign_tentative'],
                    exclusions: $exclusions,
                );
            }
        }

        return $outcome['response'];
    }

    public function confirmPickup(Order $order, NotificationService $notificationService): JsonResponse
    {
        $courier = $this->getCourier();

        return DB::transaction(function () use ($order, $courier, $notificationService) {
            /** @var Order $lockedOrder */
            $lockedOrder = Order::lockForUpdate()->findOrFail($order->id);

            if (! $courier->city_id || (int) $lockedOrder->city_id !== (int) $courier->city_id) {
                return response()->json([
                    'succes' => false,
                    'message' => 'Course introuvable.',
                ], 404);
            }

            if ($lockedOrder->courier_id === null || (int) $lockedOrder->courier_id !== (int) $courier->id) {
                return response()->json([
                    'succes' => false,
                    'message' => 'Accès refusé.',
                ], 403);
            }

            if (! $lockedOrder->peutTransitionnerVers(Order::STATUS_PICKED_UP)) {
                return response()->json([
                    'succes' => false,
                    'message' => 'Cette commande ne peut pas être récupérée dans son statut actuel.',
                ], 422);
            }

            $affected = Order::whereKey($lockedOrder->id)
                ->where('courier_id', $courier->id)
                ->where('status', Order::STATUS_READY)
                ->update([
                    'status' => Order::STATUS_PICKED_UP,
                    'picked_up_at' => now(),
                ]);

            if ($affected === 0) {
                return response()->json([
                    'succes' => false,
                    'message' => 'Cette commande ne peut pas être récupérée dans son statut actuel.',
                ], 422);
            }

            $freshOrder = $lockedOrder->fresh() ?? $lockedOrder;
            $notificationService->notifierCommandeRamassage($freshOrder);

            return response()->json([
                'succes' => true,
                'donnees' => $freshOrder,
                'message' => 'Commande récupérée chez le commerce.',
            ]);
        });
    }

    public function confirmDelivery(Request $request, Order $order, OrderService $orderService): JsonResponse
    {
        $courier = $this->getCourier();

        if (! $courier->city_id || (int) $order->city_id !== (int) $courier->city_id) {
            return response()->json(['succes' => false, 'message' => 'Course introuvable.'], 404);
        }

        if ($order->courier_id === null || (int) $order->courier_id !== (int) $courier->id) {
            return response()->json(['succes' => false, 'message' => 'Accès refusé.'], 403);
        }

        if (! in_array($order->status, [Order::STATUS_PICKED_UP, Order::STATUS_IN_TRANSIT], true)) {
            return response()->json([
                'succes' => false,
                'message' => 'Cette commande n\'est pas au statut livrable.',
            ], 422);
        }

        $request->validate(['code' => 'required|string|size:4']);

        try {
            $updated = $orderService->validerCodeConfirmation($order, $request->input('code'));
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'succes' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'succes' => true,
            'donnees' => $updated,
            'message' => 'Livraison confirmée avec succès.',
        ]);
    }

    public function wallet(): JsonResponse
    {
        $courier = $this->getCourier();

        $wallet = Wallet::firstOrCreate(
            ['owner_type' => 'courier', 'owner_id' => $courier->id],
            ['balance' => 0, 'total_earned' => 0, 'total_withdrawn' => 0]
        );

        $transactions = WalletTransaction::where('wallet_id', $wallet->id)
            ->latest()
            ->paginate(20);

        $todayStart = now()->startOfDay();
        $weekStart = now()->startOfWeek();

        $ordersToday = Order::where('courier_id', $courier->id)
            ->where('status', Order::STATUS_DELIVERED)
            ->where('delivered_at', '>=', $todayStart);
        $parcelsToday = Parcel::where('courier_id', $courier->id)
            ->where('status', Parcel::STATUS_DELIVERED)
            ->where('delivered_at', '>=', $todayStart);

        $ordersWeek = Order::where('courier_id', $courier->id)
            ->where('status', Order::STATUS_DELIVERED)
            ->where('delivered_at', '>=', $weekStart);
        $parcelsWeek = Parcel::where('courier_id', $courier->id)
            ->where('status', Parcel::STATUS_DELIVERED)
            ->where('delivered_at', '>=', $weekStart);

        $coursesToday = (clone $ordersToday)->count() + (clone $parcelsToday)->count();
        $earningsToday = (int) round((float) (clone $ordersToday)->sum('courier_amount'))
            + (int) (clone $parcelsToday)->sum('courier_amount');

        $coursesWeek = (clone $ordersWeek)->count() + (clone $parcelsWeek)->count();
        $earningsWeek = (int) round((float) (clone $ordersWeek)->sum('courier_amount'))
            + (int) (clone $parcelsWeek)->sum('courier_amount');

        $totalCourses = Order::where('courier_id', $courier->id)
            ->where('status', Order::STATUS_DELIVERED)
            ->count()
            + Parcel::where('courier_id', $courier->id)
                ->where('status', Parcel::STATUS_DELIVERED)
                ->count();

        $recentOrders = Order::where('courier_id', $courier->id)
            ->where('status', Order::STATUS_DELIVERED)
            ->with('merchant:id,name')
            ->latest('delivered_at')
            ->limit(10)
            ->get()
            ->map(function (Order $o): array {
                return [
                    'id' => 'order-'.$o->id,
                    'type' => 'goshop',
                    'title' => $o->merchant->name ?? "Commande #{$o->id}",
                    'route' => (string) ($o->delivery_address ?? ''),
                    'amount' => (int) round((float) ($o->courier_amount ?? 0)),
                    'time' => $o->delivered_at?->format('d/m H:i') ?? '',
                    'delivered_at' => $o->delivered_at?->toIso8601String(),
                ];
            });

        $recentParcels = Parcel::where('courier_id', $courier->id)
            ->where('status', Parcel::STATUS_DELIVERED)
            ->latest('delivered_at')
            ->limit(10)
            ->get()
            ->map(function (Parcel $p): array {
                return [
                    'id' => 'parcel-'.$p->id,
                    'type' => 'gocolis',
                    'title' => "Colis #{$p->id}",
                    'route' => trim(($p->pickup_address ?? '').' -> '.($p->delivery_address ?? '')),
                    'amount' => (int) ($p->courier_amount ?? 0),
                    'time' => $p->delivered_at?->format('d/m H:i') ?? '',
                    'delivered_at' => $p->delivered_at?->toIso8601String(),
                ];
            });

        $recentCourses = $recentOrders
            ->concat($recentParcels)
            ->sortByDesc('delivered_at')
            ->take(15)
            ->values();

        return response()->json([
            'succes' => true,
            'donnees' => [
                'wallet' => $wallet,
                'transactions' => $transactions,
                'stats' => [
                    'courses_today' => $coursesToday,
                    'courses_week' => $coursesWeek,
                    'earnings_today' => $earningsToday,
                    'earnings_week' => $earningsWeek,
                    'total_courses' => $totalCourses,
                ],
                'recent_courses' => $recentCourses,
            ],
        ]);
    }

    private function verifierLivreurEnLigneEtEligible(
        Courier $lockedCourier,
        int $resourceCityId,
        string $notFoundMessage,
        int $offlineStatus,
        string $offlineMessage,
        SettingsService $settingsService,
    ): ?JsonResponse {
        if (! $lockedCourier->city_id || $resourceCityId !== (int) $lockedCourier->city_id) {
            return response()->json([
                'succes' => false,
                'message' => $notFoundMessage,
            ], 404);
        }

        if (($refusal = $this->verifierEligibiliteLivreur($lockedCourier, $settingsService)) !== null) {
            return $refusal;
        }

        if (! $lockedCourier->is_online) {
            return response()->json([
                'succes' => false,
                'message' => $offlineMessage,
            ], $offlineStatus);
        }

        return null;
    }

    private function verifierPlafondEtSoldeCash(
        Courier $lockedCourier,
        string $paymentMethod,
        int $montantAReverser,
        SettingsService $settingsService,
        WalletService $walletService,
    ): ?JsonResponse {
        $capacityService = new CourierCapacityService($settingsService);
        if (! $capacityService->peutAccepterNouvelleCourse($lockedCourier->id, (int) $lockedCourier->city_id)) {
            return response()->json([
                'succes' => false,
                'message' => 'Vous avez déjà une course active en cours. Terminez-la avant d\'en accepter une autre.',
            ], 409);
        }

        if ($paymentMethod === 'cash') {
            $controleSolde = $walletService->verifierSoldeCashSuffisant(
                courier: $lockedCourier,
                montantAReverser: $montantAReverser,
                settingsService: $settingsService,
                lockWallet: true,
            );

            if (! $controleSolde['ok']) {
                return response()->json([
                    'succes' => false,
                    'message' => $controleSolde['message'],
                ], 422);
            }
        }

        return null;
    }
}
