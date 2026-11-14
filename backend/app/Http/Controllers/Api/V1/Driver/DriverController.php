<?php

namespace App\Http\Controllers\Api\V1\Driver;

use App\Http\Controllers\Controller;
use App\Models\Courier;
use App\Models\Order;
use App\Models\Parcel;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Modules\GoShop\Services\AssignmentService;
use App\Modules\GoShop\Services\OrderService;
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
        if (! $courier->is_active || ($courier->user && ! $courier->user->is_active)) {
            return response()->json([
                'succes' => false,
                'message' => 'Votre compte livreur est suspendu ou inactif.',
            ], 403);
        }

        if ($courier->penalty_until !== null) {
            if ($courier->penalty_until->isFuture()) {
                if ($courier->is_online) {
                    $courier->update(['is_online' => false]);
                }

                return response()->json([
                    'succes' => false,
                    'message' => 'Votre compte est temporairement suspendu suite à des refus répétés.',
                ], 403);
            }

            $courier->update([
                'penalty_until' => null,
                'consecutive_refusals' => 0,
            ]);

            return null;
        }

        [$seuilRefus, $dureePenalite] = $this->obtenirReglesPenaliteRefus($settingsService, $courier->city_id);

        if ($courier->consecutive_refusals >= $seuilRefus) {
            $courier->update([
                'penalty_until' => now()->addMinutes($dureePenalite),
                'is_online' => false,
            ]);

            return response()->json([
                'succes' => false,
                'message' => "Suite à {$seuilRefus} refus consécutifs, vous êtes hors ligne pour {$dureePenalite} minutes.",
            ], 403);
        }

        return null;
    }

    public function availability(Request $request): JsonResponse
    {
        $courier = $this->getCourier();

        return DB::transaction(function () use ($request, $courier): JsonResponse {
            /** @var Courier $lockedCourier */
            $lockedCourier = Courier::with('user')->lockForUpdate()->findOrFail($courier->id);
            $isOnline = $request->boolean('is_online', ! $lockedCourier->is_online);

            if ($isOnline && ($refusal = $this->verifierEligibiliteLivreur($lockedCourier)) !== null) {
                return $refusal;
            }

            $lockedCourier->update(['is_online' => $isOnline]);

            return response()->json([
                'succes' => true,
                'donnees' => $lockedCourier->fresh(),
                'message' => $isOnline ? 'Vous êtes en ligne.' : 'Vous êtes hors ligne.',
            ]);
        });
    }

    public function updateLocation(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lat' => 'required|numeric|between:-90,90',
            'lng' => 'required|numeric|between:-180,180',
        ]);

        $courier = $this->getCourier()->load('user');

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

        $courier->update([
            'current_lat' => $validated['lat'],
            'current_lng' => $validated['lng'],
            'location_updated_at' => now(),
        ]);

        return response()->json([
            'succes' => true,
            'donnees' => [
                'lat' => $courier->current_lat,
                'lng' => $courier->current_lng,
                'updated_at' => $courier->location_updated_at,
            ],
        ]);
    }

    public function courses(): JsonResponse
    {
        $courier = $this->getCourier();

        $orders = Order::where('courier_id', $courier->id)
            ->whereIn('status', [
                Order::STATUS_ACCEPTED,
                'ready',
                'picked_up',
                'in_transit',
            ])
            ->with(['merchant', 'client', 'items.product'])
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

        return response()->json([
            'succes' => true,
            'donnees' => [
                'orders' => $orders,
                'parcels' => $parcels,
            ],
        ]);
    }

    public function acceptOrder(Order $order, SettingsService $settingsService, WalletService $walletService): JsonResponse
    {
        $courier = $this->getCourier();

        return DB::transaction(function () use ($order, $courier, $settingsService, $walletService) {
            /** @var Order $lockedOrder */
            $lockedOrder = Order::lockForUpdate()->findOrFail($order->id);
            /** @var Courier $lockedCourier */
            $lockedCourier = Courier::with('user')->lockForUpdate()->findOrFail($courier->id);

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

            if (! in_array($lockedOrder->status, [Order::STATUS_ACCEPTED, Order::STATUS_READY], true)) {
                return response()->json([
                    'succes' => false,
                    'message' => 'Cette course ne peut plus être acceptée.',
                ], 422);
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

            $affected = Order::whereKey($lockedOrder->id)
                ->whereNull('courier_id')
                ->whereIn('status', [Order::STATUS_ACCEPTED, Order::STATUS_READY])
                ->update([
                    'courier_id' => $lockedCourier->id,
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

            return response()->json([
                'succes' => true,
                'donnees' => $lockedOrder->fresh(),
                'message' => 'Course acceptée.',
            ]);
        });
    }

    public function acceptParcel(Parcel $parcel, SettingsService $settingsService, WalletService $walletService): JsonResponse
    {
        $courier = $this->getCourier();

        return DB::transaction(function () use ($parcel, $courier, $settingsService, $walletService): JsonResponse {
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

            return response()->json([
                'succes' => true,
                'donnees' => $lockedParcel->fresh(),
                'message' => 'Expédition acceptée.',
            ]);
        });
    }

    public function refuseParcel(
        Request $request,
        Parcel $parcel,
        SettingsService $settingsService,
        AssignmentService $assignmentService,
    ): JsonResponse {
        $request->validate([
            'raison' => 'nullable|string|max:500',
        ]);

        $courier = $this->getCourier();

        /** @var array{response: JsonResponse, reassign_tentative?: int, exclude_courier_id?: int} $outcome */
        $outcome = DB::transaction(function () use ($parcel, $courier, $settingsService): array {
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
                [$seuilRefus, $dureePenalite] = $this->obtenirReglesPenaliteRefus($settingsService, $lockedCourier->city_id);
                $nouveauxRefus = (int) $lockedCourier->consecutive_refusals + 1;

                if ($nouveauxRefus >= $seuilRefus) {
                    $lockedCourier->update([
                        'consecutive_refusals' => $nouveauxRefus,
                        'penalty_until' => now()->addMinutes($dureePenalite),
                        'is_online' => false,
                    ]);
                } else {
                    $lockedCourier->update([
                        'consecutive_refusals' => $nouveauxRefus,
                    ]);
                }

                $tentativeSuivante = ((int) $lockedParcel->assignment_attempts) + 1;
                $lockedParcel->update(['assignment_attempts' => $tentativeSuivante]);

                return [
                    'response' => response()->json([
                        'succes' => true,
                        'donnees' => $lockedParcel->fresh(),
                        'message' => $nouveauxRefus >= $seuilRefus
                            ? "Expédition refusée. Suite à {$seuilRefus} refus consécutifs, vous êtes hors ligne pour {$dureePenalite} minutes."
                            : 'Expédition refusée et transmise au livreur suivant.',
                    ]),
                    'reassign_tentative' => $tentativeSuivante + 1,
                    'exclude_courier_id' => $lockedCourier->id,
                ];
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
                $assignmentService->attribuerLivreurGoColis(
                    parcel: $freshParcel,
                    tentative: $outcome['reassign_tentative'],
                    exclusions: [$outcome['exclude_courier_id']],
                );
            }
        }

        return $outcome['response'];
    }

    public function confirmPickup(Order $order): JsonResponse
    {
        $courier = $this->getCourier();

        return DB::transaction(function () use ($order, $courier) {
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
                ->whereIn('status', [Order::STATUS_ACCEPTED, Order::STATUS_READY])
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

            return response()->json([
                'succes' => true,
                'donnees' => $lockedOrder->fresh(),
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

        return response()->json([
            'succes' => true,
            'donnees' => [
                'wallet' => $wallet,
                'transactions' => $transactions,
            ],
        ]);
    }

    private function obtenirMaxCoursesActives(SettingsService $settingsService, int $cityId): int
    {
        $raw = $settingsService->get('courses.max_actives', $cityId);
        $valeur = is_array($raw)
            ? (int) ($raw['max'] ?? $raw['value'] ?? reset($raw) ?: 1)
            : (int) ($raw ?? 1);

        return max(1, $valeur);
    }

    private function compterCoursesActives(int $courierId): int
    {
        $commandesActives = Order::where('courier_id', $courierId)
            ->whereIn('status', [
                Order::STATUS_ACCEPTED,
                Order::STATUS_READY,
                Order::STATUS_PICKED_UP,
                Order::STATUS_IN_TRANSIT,
            ])
            ->count();

        $colisActifs = Parcel::where('courier_id', $courierId)
            ->whereIn('status', [
                Parcel::STATUS_ACCEPTED,
                Parcel::STATUS_AT_SENDER,
                Parcel::STATUS_PICKED_UP,
                Parcel::STATUS_IN_TRANSIT,
                Parcel::STATUS_AT_RECIPIENT,
            ])
            ->count();

        return $commandesActives + $colisActifs;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function obtenirReglesPenaliteRefus(?SettingsService $settingsService, ?int $cityId): array
    {
        $service = $settingsService ?? app(SettingsService::class);
        $delais = $service->get('delais_regles', $cityId);

        $seuil = is_array($delais) ? (int) ($delais['refus_avant_penalite'] ?? 3) : 3;
        $duree = is_array($delais) ? (int) ($delais['duree_penalite_minutes'] ?? 15) : 15;

        return [max(1, $seuil), max(1, $duree)];
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
        $maxActives = $this->obtenirMaxCoursesActives($settingsService, (int) $lockedCourier->city_id);
        if ($this->compterCoursesActives($lockedCourier->id) >= $maxActives) {
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
