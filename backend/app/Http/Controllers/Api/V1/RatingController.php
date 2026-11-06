<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\{Courier, Order, Parcel, Rating};
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
            'order_id'   => 'nullable|exists:orders,id',
            'parcel_id'  => 'nullable|exists:parcels,id',
            'rating'     => 'required|integer|min:1|max:5',
            'comment'    => 'nullable|string|max:500',
        ]);

        if (empty($validated['order_id']) && empty($validated['parcel_id'])) {
            throw ValidationException::withMessages([
                'order_id' => ['Vous devez fournir un ID de commande ou de colis.'],
            ]);
        }

        $userId = auth()->id();

        // Vérifier que la commande ou le colis appartient bien à ce client
        if (!empty($validated['order_id'])) {
            $order = Order::findOrFail($validated['order_id']);
            if ($order->client_id !== $userId) {
                return response()->json([
                    'succes'  => false,
                    'message' => 'Cette commande ne vous appartient pas.',
                ], 403);
            }
        }

        if (!empty($validated['parcel_id'])) {
            $parcel = Parcel::findOrFail($validated['parcel_id']);
            if ($parcel->sender_id !== $userId) {
                return response()->json([
                    'succes'  => false,
                    'message' => 'Cette expédition ne vous appartient pas.',
                ], 403);
            }
        }

        return DB::transaction(function () use ($validated, $userId) {
            $rating = Rating::create([
                'rater_id'    => $userId,
                'rateable_id' => $validated['courier_id'],
                'type'        => 'courier',
                'rating'      => $validated['rating'],
                'comment'     => $validated['comment'] ?? null,
                'order_id'    => $validated['order_id'] ?? null,
                'parcel_id'   => $validated['parcel_id'] ?? null,
            ]);

            // Mettre à jour la moyenne du livreur
            $courier = Courier::findOrFail($validated['courier_id']);
            $nouveauTotal = $courier->total_ratings + 1;
            $nouvelleMoyenne = (($courier->average_rating * $courier->total_ratings) + $validated['rating']) / $nouveauTotal;

            $courier->update([
                'average_rating' => round($nouvelleMoyenne, 2),
                'total_ratings'  => $nouveauTotal,
            ]);

            return response()->json([
                'succes'  => true,
                'donnees' => [
                    'rating'         => $rating,
                    'average_rating' => $courier->fresh()->average_rating,
                    'total_ratings'  => $courier->fresh()->total_ratings,
                ],
                'message' => 'Merci pour votre évaluation !',
            ], 201);
        });
    }
}
