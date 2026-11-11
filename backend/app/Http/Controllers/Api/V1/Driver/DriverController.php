<?php

namespace App\Http\Controllers\Api\V1\Driver;

use App\Http\Controllers\Controller;
use App\Models\Courier;
use App\Models\Order;
use App\Models\Parcel;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Modules\GoShop\Services\OrderService;
use App\Services\SettingsService;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

    private function verifierEligibiliteLivreur(Courier $courier): ?JsonResponse
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
        } elseif ($courier->consecutive_refusals >= 3) {
            $courier->update([
                'penalty_until' => now()->addMinutes(15),
                'is_online' => false,
            ]);

            return response()->json([
                'succes' => false,
                'message' => 'Suite à 3 refus consécutifs, vous êtes hors ligne pour 15 minutes.',
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
            'lat' => 'required|numeric',
            'lng' => 'required|numeric',
        ]);

        $courier = $this->getCourier()->load('user');

        if (! $courier->is_active || ($courier->user && ! $courier->user->is_active)) {
            return response()->json([
                'succes' => false,
                'message' => 'Votre compte livreur est suspendu ou inactif.',
            ], 403);
        }

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

            if (! $lockedCourier->city_id || (int) $lockedOrder->city_id !== (int) $lockedCourier->city_id) {
                return response()->json([
                    'succes' => false,
                    'message' => 'Course introuvable.',
                ], 404);
            }

            if (($refusal = $this->verifierEligibiliteLivreur($lockedCourier)) !== null) {
                return $refusal;
            }

            if (! $lockedCourier->is_online) {
                return response()->json([
                    'succes' => false,
                    'message' => 'Vous devez être en ligne pour accepter une course.',
                ], 422);
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

            $maxActives = $this->obtenirMaxCoursesActives($settingsService, (int) $lockedCourier->city_id);
            if ($this->compterCoursesActives($lockedCourier->id) >= $maxActives) {
                return response()->json([
                    'succes' => false,
                    'message' => 'Vous avez déjà une course active en cours. Terminez-la avant d\'en accepter une autre.',
                ], 409);
            }

            if ($lockedOrder->payment_method === 'cash') {
                $montantAReverser = $walletService->calculerMontantAReverserOrder($lockedOrder);
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

        $updated = $orderService->validerCodeConfirmation($order, $request->input('code'));

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
}
