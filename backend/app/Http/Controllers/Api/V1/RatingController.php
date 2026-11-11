<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Courier;
use App\Models\Order;
use App\Models\Parcel;
use App\Models\Rating;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RatingController extends Controller
{
    /**
     * Note un livreur après une livraison GoShop ou GoColis.
     * POST /api/v1/client/ratings
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'courier_id' => 'required|exists:couriers,id',
            'order_id' => 'nullable|exists:orders,id',
            'parcel_id' => 'nullable|exists:parcels,id',
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:500',
        ]);

        if (empty($validated['order_id']) && empty($validated['parcel_id'])) {
            throw ValidationException::withMessages([
                'order_id' => ['Vous devez fournir un ID de commande ou de colis.'],
            ]);
        }

        if (! empty($validated['order_id']) && ! empty($validated['parcel_id'])) {
            throw ValidationException::withMessages([
                'order_id' => ['Une évaluation doit concerner soit une commande, soit un colis.'],
            ]);
        }

        $userId = auth()->id();

        if (! empty($validated['order_id'])) {
            $order = Order::findOrFail($validated['order_id']);
            if ((int) $order->client_id !== (int) $userId) {
                return response()->json([
                    'succes' => false,
                    'message' => 'Cette commande ne vous appartient pas.',
                ], 403);
            }

            if ($order->status !== Order::STATUS_DELIVERED) {
                return response()->json([
                    'succes' => false,
                    'message' => 'Vous ne pouvez noter une commande qu\'après sa livraison.',
                ], 422);
            }

            if (! $order->courier_id || (int) $order->courier_id !== (int) $validated['courier_id']) {
                return response()->json([
                    'succes' => false,
                    'message' => 'Le livreur spécifié ne correspond pas à cette commande.',
                ], 422);
            }
        }

        if (! empty($validated['parcel_id'])) {
            $parcel = Parcel::findOrFail($validated['parcel_id']);
            if ((int) $parcel->sender_id !== (int) $userId) {
                return response()->json([
                    'succes' => false,
                    'message' => 'Cette expédition ne vous appartient pas.',
                ], 403);
            }

            if ($parcel->status !== Parcel::STATUS_DELIVERED) {
                return response()->json([
                    'succes' => false,
                    'message' => 'Vous ne pouvez noter une expédition qu\'après sa livraison.',
                ], 422);
            }

            if (! $parcel->courier_id || (int) $parcel->courier_id !== (int) $validated['courier_id']) {
                return response()->json([
                    'succes' => false,
                    'message' => 'Le livreur spécifié ne correspond pas à cette expédition.',
                ], 422);
            }
        }

        return DB::transaction(function () use ($validated, $userId) {
            if (! empty($validated['order_id']) && Rating::where('rater_id', $userId)->where('order_id', $validated['order_id'])->exists()) {
                return response()->json([
                    'succes' => false,
                    'message' => 'Vous avez déjà évalué cette commande.',
                ], 409);
            }

            if (! empty($validated['parcel_id']) && Rating::where('rater_id', $userId)->where('parcel_id', $validated['parcel_id'])->exists()) {
                return response()->json([
                    'succes' => false,
                    'message' => 'Vous avez déjà évalué cette expédition.',
                ], 409);
            }

            $rating = Rating::create([
                'rater_id' => $userId,
                'rateable_id' => $validated['courier_id'],
                'type' => 'courier',
                'rating' => $validated['rating'],
                'comment' => $validated['comment'] ?? null,
                'order_id' => $validated['order_id'] ?? null,
                'parcel_id' => $validated['parcel_id'] ?? null,
            ]);

            // Mettre à jour la moyenne du livreur de façon atomique
            /** @var Courier $courier */
            $courier = Courier::lockForUpdate()->findOrFail($validated['courier_id']);
            $nouveauTotal = $courier->total_ratings + 1;
            $nouvelleMoyenne = (($courier->average_rating * $courier->total_ratings) + $validated['rating']) / $nouveauTotal;

            $courier->update([
                'average_rating' => round($nouvelleMoyenne, 2),
                'total_ratings' => $nouveauTotal,
            ]);

            return response()->json([
                'succes' => true,
                'donnees' => [
                    'rating' => $rating,
                    'average_rating' => $courier->fresh()->average_rating,
                    'total_ratings' => $courier->fresh()->total_ratings,
                ],
                'message' => 'Merci pour votre évaluation !',
            ], 201);
        });
    }
}
