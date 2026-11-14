<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Courier;
use App\Models\Merchant;
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
            if (! empty($validated['order_id'])
                && Rating::where('rater_id', $userId)
                    ->where('order_id', $validated['order_id'])
                    ->where('type', 'goshop_courier')
                    ->exists()) {
                return response()->json([
                    'succes' => false,
                    'message' => 'Vous avez déjà évalué cette commande.',
                ], 409);
            }

            if (! empty($validated['parcel_id'])
                && Rating::where('rater_id', $userId)
                    ->where('parcel_id', $validated['parcel_id'])
                    ->where('type', 'gocolis_courier')
                    ->exists()) {
                return response()->json([
                    'succes' => false,
                    'message' => 'Vous avez déjà évalué cette expédition.',
                ], 409);
            }

            $type = ! empty($validated['parcel_id']) ? 'gocolis_courier' : 'goshop_courier';

            $rating = Rating::create([
                'rater_id' => $userId,
                'rateable_id' => $validated['courier_id'],
                'type' => $type,
                'rating' => $validated['rating'],
                'comment' => $validated['comment'] ?? null,
                'order_id' => $validated['order_id'] ?? null,
                'parcel_id' => $validated['parcel_id'] ?? null,
            ]);

            /** @var Courier $courier */
            $courier = Courier::lockForUpdate()->findOrFail($validated['courier_id']);
            $stats = $this->appliquerNoteMoyenne($courier, (int) $validated['rating']);

            return response()->json([
                'succes' => true,
                'donnees' => [
                    'rating' => $rating,
                    'average_rating' => $stats['average_rating'],
                    'total_ratings' => $stats['total_ratings'],
                ],
                'message' => 'Merci pour votre évaluation !',
            ], 201);
        });
    }

    /**
     * Note un commerce GoShop après livraison effectuée (1 à 5, une seule fois par commande).
     * POST /api/v1/client/ratings/merchant
     */
    public function storeMerchant(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'order_id' => 'required|exists:orders,id',
            'merchant_id' => 'nullable|exists:merchants,id',
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:500',
        ]);

        $userId = auth()->id();
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
                'message' => 'Vous ne pouvez noter un commerce qu\'après sa livraison.',
            ], 422);
        }

        if (! empty($validated['merchant_id']) && (int) $validated['merchant_id'] !== (int) $order->merchant_id) {
            return response()->json([
                'succes' => false,
                'message' => 'Le commerce spécifié ne correspond pas à cette commande.',
            ], 422);
        }

        return DB::transaction(function () use ($validated, $order, $userId): JsonResponse {
            if (Rating::where('rater_id', $userId)
                ->where('order_id', $order->id)
                ->where('type', 'goshop_merchant')
                ->exists()) {
                return response()->json([
                    'succes' => false,
                    'message' => 'Vous avez déjà évalué ce commerce pour cette commande.',
                ], 409);
            }

            $rating = Rating::create([
                'rater_id' => $userId,
                'rateable_id' => $order->merchant_id,
                'type' => 'goshop_merchant',
                'rating' => $validated['rating'],
                'comment' => $validated['comment'] ?? null,
                'order_id' => $order->id,
                'parcel_id' => null,
            ]);

            /** @var Merchant $merchant */
            $merchant = Merchant::lockForUpdate()->findOrFail($order->merchant_id);
            $stats = $this->appliquerNoteMoyenne($merchant, (int) $validated['rating']);

            return response()->json([
                'succes' => true,
                'donnees' => [
                    'rating' => $rating,
                    'average_rating' => $stats['average_rating'],
                    'total_ratings' => $stats['total_ratings'],
                ],
                'message' => 'Merci pour votre évaluation du commerce !',
            ], 201);
        });
    }

    /**
     * Recalcule atomiquement la moyenne et le total de notes d'un livreur ou commerce.
     *
     * @return array{average_rating: float, total_ratings: int}
     */
    private function appliquerNoteMoyenne(Courier|Merchant $rateable, int $note): array
    {
        $ancienTotal = (int) $rateable->total_ratings;
        $ancienneMoyenne = (float) $rateable->average_rating;
        $nouveauTotal = $ancienTotal + 1;
        $nouvelleMoyenne = round((($ancienneMoyenne * $ancienTotal) + $note) / $nouveauTotal, 2);

        $rateable->update([
            'average_rating' => $nouvelleMoyenne,
            'total_ratings' => $nouveauTotal,
        ]);

        return [
            'average_rating' => (float) $rateable->fresh()->average_rating,
            'total_ratings' => (int) $rateable->fresh()->total_ratings,
        ];
    }
}
