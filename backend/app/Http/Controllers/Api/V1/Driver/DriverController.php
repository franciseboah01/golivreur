<?php

namespace App\Http\Controllers\Api\V1\Driver;

use App\Http\Controllers\Controller;
use App\Models\{Courier, Order, Parcel, Wallet, WalletTransaction};
use App\Modules\GoShop\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DriverController extends Controller
{
    private function getCourier(): Courier
    {
        $courier = Courier::where('user_id', auth()->id())->first();
        if (!$courier) {
            abort(404, 'Profil livreur introuvable.');
        }
        return $courier;
    }

    public function availability(Request $request): JsonResponse
    {
        $courier = $this->getCourier();
        $isOnline = $request->boolean('is_online', !$courier->is_online);

        $courier->update(['is_online' => $isOnline]);

        return response()->json([
            'succes'  => true,
            'donnees' => $courier,
            'message' => $isOnline ? 'Vous êtes en ligne.' : 'Vous êtes hors ligne.',
        ]);
    }

    public function updateLocation(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lat' => 'required|numeric',
            'lng' => 'required|numeric',
        ]);

        $courier = $this->getCourier();
        $courier->update([
            'current_lat'         => $validated['lat'],
            'current_lng'         => $validated['lng'],
            'location_updated_at' => now(),
        ]);

        return response()->json([
            'succes'  => true,
            'donnees' => [
                'lat'        => $courier->current_lat,
                'lng'        => $courier->current_lng,
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
            'succes'  => true,
            'donnees' => [
                'orders'  => $orders,
                'parcels' => $parcels,
            ],
        ]);
    }

    public function acceptOrder(Order $order): JsonResponse
    {
        $courier = $this->getCourier();

        return \Illuminate\Support\Facades\DB::transaction(function () use ($order, $courier) {
            $lockedOrder = Order::lockForUpdate()->findOrFail($order->id);

            if ($lockedOrder->courier_id && $lockedOrder->courier_id !== $courier->id) {
                return response()->json(['succes' => false, 'message' => 'Course déjà assignée.'], 409);
            }

            if ($lockedOrder->status === Order::STATUS_DELIVERED || $lockedOrder->status === Order::STATUS_CANCELLED) {
                return response()->json(['succes' => false, 'message' => 'Cette course ne peut plus être acceptée.'], 422);
            }

            $lockedOrder->update([
                'courier_id' => $courier->id,
                'status'     => Order::STATUS_ACCEPTED,
            ]);

            return response()->json([
                'succes'  => true,
                'donnees' => $lockedOrder,
                'message' => 'Course acceptée.',
            ]);
        });
    }

    public function confirmPickup(Order $order): JsonResponse
    {
        $courier = $this->getCourier();

        if ($order->courier_id !== $courier->id) {
            return response()->json(['succes' => false, 'message' => 'Accès refusé.'], 403);
        }

        $order->update([
            'status'       => 'picked_up',
            'picked_up_at' => now(),
        ]);

        return response()->json([
            'succes'  => true,
            'donnees' => $order,
            'message' => 'Commande récupérée chez le commerce.',
        ]);
    }

    public function confirmDelivery(Request $request, Order $order, OrderService $orderService): JsonResponse
    {
        $courier = $this->getCourier();

        if ($order->courier_id !== $courier->id) {
            return response()->json(['succes' => false, 'message' => 'Accès refusé.'], 403);
        }

        $request->validate(['code' => 'required|string|size:4']);

        $updated = $orderService->validerCodeConfirmation($order, $request->input('code'));

        return response()->json([
            'succes'  => true,
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
            'succes'  => true,
            'donnees' => [
                'wallet'       => $wallet,
                'transactions' => $transactions,
            ],
        ]);
    }
}
