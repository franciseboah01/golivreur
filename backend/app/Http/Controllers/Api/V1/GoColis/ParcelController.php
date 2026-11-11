<?php

namespace App\Http\Controllers\Api\V1\GoColis;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\Neighborhood;
use App\Models\Parcel;
use App\Modules\GoColis\Services\ParcelService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ParcelController extends Controller
{
    public function store(Request $request, ParcelService $service): JsonResponse
    {
        $validated = $request->validate([
            'recipient_name' => 'required|string|max:150',
            'recipient_phone' => 'required|string|max:20',
            'delivery_address' => 'required|string',
            'delivery_lat' => 'nullable|numeric',
            'delivery_lng' => 'nullable|numeric',
            'pickup_address' => 'nullable|string',
            'pickup_lat' => 'nullable|numeric',
            'pickup_lng' => 'nullable|numeric',
            'size' => 'required|in:petit,moyen,grand',
            'weight_kg' => 'nullable|numeric',
            'declared_value' => 'nullable|numeric|min:0',
            'description' => 'nullable|string',
            'payment_method' => 'required|string|in:cash,orange_money,wave,mtn,moov',
            'distance_km' => 'required|numeric|min:0.1',
        ]);

        $parcel = $service->creerExpedition(auth()->user(), $validated);

        return response()->json([
            'succes' => true,
            'donnees' => [
                'id' => $parcel->id,
                'sender_code_plain' => $parcel->sender_code_plain,
                'shipping_fee' => (float) $parcel->shipping_fee,
                'has_insurance' => (bool) $parcel->has_insurance,
            ],
            'message' => 'Expédition créée avec succès.',
        ], 201);
    }

    public function verifySenderCode(Request $request, Parcel $parcel, ParcelService $service): JsonResponse
    {
        if ($denied = $this->authorizeCodeVerification($parcel)) {
            return $denied;
        }

        $request->validate([
            'code' => 'required|string|size:4',
        ]);

        $updated = $service->validerCodeExpediteur($parcel, $request->input('code'));

        return response()->json([
            'succes' => true,
            'donnees' => $updated,
            'message' => 'Code expéditeur validé et colis pris en charge.',
        ]);
    }

    public function verifyRecipientCode(Request $request, Parcel $parcel, ParcelService $service): JsonResponse
    {
        if ($denied = $this->authorizeCodeVerification($parcel)) {
            return $denied;
        }

        $request->validate([
            'code' => 'required|string|size:4',
        ]);

        $updated = $service->validerCodeDestinataire($parcel, $request->input('code'));

        return response()->json([
            'succes' => true,
            'donnees' => $updated,
            'message' => 'Code destinataire validé et livraison confirmée.',
        ]);
    }

    private function authorizeCodeVerification(Parcel $parcel): ?JsonResponse
    {
        $user = auth()->user();

        if ($user && in_array($user->role, ['super_admin', 'dg_national'], true)) {
            return null;
        }

        $courier = $user?->courier;

        if (! $courier) {
            return response()->json(['succes' => false, 'message' => 'Accès réservé aux livreurs.'], 403);
        }

        if (! $courier->city_id || (int) $parcel->city_id !== (int) $courier->city_id) {
            return response()->json(['succes' => false, 'message' => 'Expédition introuvable.'], 404);
        }

        if ($parcel->courier_id === null) {
            return response()->json(['succes' => false, 'message' => 'Ce colis n\'est attribué à aucun livreur.'], 403);
        }

        if ((int) $parcel->courier_id !== (int) $courier->id) {
            return response()->json(['succes' => false, 'message' => 'Ce colis est assigné à un autre livreur.'], 403);
        }

        return null;
    }

    public function showDriver(Parcel $parcel): JsonResponse
    {
        $user = auth()->user();
        $courier = $user?->courier;

        if (! $courier) {
            return response()->json(['succes' => false, 'message' => 'Accès réservé aux livreurs.'], 403);
        }

        if (! $courier->city_id || (int) $parcel->city_id !== (int) $courier->city_id) {
            return response()->json(['succes' => false, 'message' => 'Expédition introuvable.'], 404);
        }

        if ($parcel->courier_id !== null && (int) $parcel->courier_id !== (int) $courier->id) {
            return response()->json(['succes' => false, 'message' => 'Ce colis est assigné à un autre livreur.'], 403);
        }

        if ($parcel->courier_id === null) {
            if (! $courier->is_active || ($user && ! $user->is_active)) {
                return response()->json(['succes' => false, 'message' => 'Votre compte livreur est suspendu ou inactif.'], 403);
            }

            if (! in_array($parcel->status, [Parcel::STATUS_PENDING, Parcel::STATUS_WAITING], true)) {
                return response()->json([
                    'succes' => false,
                    'message' => 'Cette expédition n\'est plus disponible à l\'acceptation.',
                ], 422);
            }

            return response()->json([
                'succes' => true,
                'donnees' => [
                    'id' => $parcel->id,
                    'size' => $parcel->size,
                    'size_label' => $parcel->size_label,
                    'status' => $parcel->status,
                    'shipping_fee' => (float) $parcel->shipping_fee,
                    'courier_amount' => (float) $parcel->courier_amount,
                    'distance_km' => $this->estimerDistanceKm($parcel),
                    'pickup_zone' => $this->resoudreZoneApproximative(
                        $parcel,
                        $parcel->pickup_lat !== null ? (float) $parcel->pickup_lat : null,
                        $parcel->pickup_lng !== null ? (float) $parcel->pickup_lng : null,
                    ),
                    'delivery_zone' => $this->resoudreZoneApproximative(
                        $parcel,
                        $parcel->delivery_lat !== null ? (float) $parcel->delivery_lat : null,
                        $parcel->delivery_lng !== null ? (float) $parcel->delivery_lng : null,
                    ),
                    'created_at' => $parcel->created_at,
                ],
            ]);
        }

        return response()->json([
            'succes' => true,
            'donnees' => [
                'id' => $parcel->id,
                'size' => $parcel->size,
                'size_label' => $parcel->size_label,
                'status' => $parcel->status,
                'shipping_fee' => (float) $parcel->shipping_fee,
                'courier_amount' => (float) $parcel->courier_amount,
                'pickup_address' => $parcel->pickup_address,
                'pickup_lat' => $parcel->pickup_lat,
                'pickup_lng' => $parcel->pickup_lng,
                'delivery_address' => $parcel->delivery_address,
                'delivery_lat' => $parcel->delivery_lat,
                'delivery_lng' => $parcel->delivery_lng,
                'recipient_name' => $parcel->recipient_name,
                'recipient_phone' => $parcel->recipient_phone,
                'created_at' => $parcel->created_at,
            ],
        ]);
    }

    private function estimerDistanceKm(Parcel $parcel): ?float
    {
        if (
            $parcel->pickup_lat === null
            || $parcel->pickup_lng === null
            || $parcel->delivery_lat === null
            || $parcel->delivery_lng === null
        ) {
            return null;
        }

        return round(
            $this->distanceHaversineKm(
                (float) $parcel->pickup_lat,
                (float) $parcel->pickup_lng,
                (float) $parcel->delivery_lat,
                (float) $parcel->delivery_lng
            ),
            1
        );
    }

    private function resoudreZoneApproximative(Parcel $parcel, ?float $lat, ?float $lng): string
    {
        if ($lat !== null && $lng !== null) {
            $quartiers = Neighborhood::query()
                ->where('city_id', $parcel->city_id)
                ->where('is_active', true)
                ->whereNotNull('lat')
                ->whereNotNull('lng')
                ->get();

            $plusProche = null;
            $distanceMin = INF;
            foreach ($quartiers as $quartier) {
                $d = $this->distanceHaversineKm($lat, $lng, (float) $quartier->lat, (float) $quartier->lng);
                if ($d < $distanceMin) {
                    $distanceMin = $d;
                    $plusProche = $quartier->name;
                }
            }

            if ($plusProche !== null) {
                return $plusProche;
            }
        }

        $cityName = City::whereKey($parcel->city_id)->value('name');

        return is_string($cityName) && $cityName !== '' ? "Secteur {$cityName}" : 'Zone urbaine';
    }

    private function distanceHaversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $terreKm = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $terreKm * asin(min(1.0, sqrt($a)));
    }

    public function showClient(Parcel $parcel): JsonResponse
    {
        $user = auth()->user();

        if ($parcel->sender_id !== $user->id && ! in_array($user->role, ['super_admin', 'dg_national', 'city_manager'])) {
            return response()->json(['succes' => false, 'message' => 'Accès refusé à cette expédition.'], 403);
        }

        if ($user->role === 'city_manager' && $parcel->city_id !== $user->city_id) {
            return response()->json(['succes' => false, 'message' => 'Accès refusé pour cette zone.'], 403);
        }

        return response()->json([
            'succes' => true,
            'donnees' => $parcel,
        ]);
    }
}
