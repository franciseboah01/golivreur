<?php

namespace App\Http\Controllers\Api\V1\GoColis;

use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Http\Requests\Concerns\NormalizesIvorianPhone;
use App\Models\City;
use App\Models\Neighborhood;
use App\Models\Parcel;
use App\Models\ParcelPhoto;
use App\Models\User;
use App\Modules\GoColis\Services\ParcelService;
use App\Services\ConfirmationCodeService;
use App\Services\CourierLocationPollingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ParcelController extends Controller
{
    use NormalizesIvorianPhone;

    public function indexClient(): JsonResponse
    {
        $parcels = Parcel::where('sender_id', auth()->id())
            ->with(['courier.user', 'photos'])
            ->latest()
            ->limit(30)
            ->get();

        return response()->json([
            'succes' => true,
            'donnees' => $parcels,
        ]);
    }

    public function store(Request $request, ParcelService $service): JsonResponse
    {
        $user = auth()->user();
        $cityId = $user?->city_id;
        $isSimulatedTestMode = $request->boolean('simulate_payment')
            && (bool) config('payments.simulate_mobile_money', false);

        if ($isSimulatedTestMode) {
            $rawMethod = $request->input('payment_method');
            if (is_string($rawMethod)) {
                $canonical = PaymentMethod::tryFromInput($rawMethod);
                if ($canonical !== null) {
                    $request->merge(['payment_method' => $canonical->value]);
                }
            }
            $allowedModes = array_map(
                static fn (PaymentMethod $m): string => $m->value,
                PaymentMethod::clientCases(),
            );
        } else {
            $allowedModes = PaymentMethod::activeValues($cityId !== null ? (int) $cityId : null);
        }

        $validated = $request->validate([
            'recipient_name' => 'required|string|max:150',
            'recipient_phone' => 'required|string',
            'neighborhood_id' => 'nullable|integer|exists:neighborhoods,id',
            'delivery_neighborhood' => 'nullable|string|max:120',
            'delivery_address' => 'required_without_all:neighborhood_id,delivery_neighborhood|nullable|string|max:255',
            'delivery_lat' => 'nullable|numeric|between:-90,90',
            'delivery_lng' => 'nullable|numeric|between:-180,180',
            'pickup_address' => 'nullable|string|max:255',
            'pickup_lat' => 'nullable|numeric|between:-90,90',
            'pickup_lng' => 'nullable|numeric|between:-180,180',
            'size' => 'required|in:petit,moyen,grand',
            'weight_kg' => 'nullable|numeric|min:0.1',
            'declared_value' => 'nullable|numeric|min:0',
            'description' => 'nullable|string|max:500',
            'payment_method' => ['required', 'string', Rule::in($allowedModes)],
            'distance_km' => 'nullable|numeric|min:0',
            'simulate_payment' => 'nullable|boolean',
            'photo' => ['nullable', 'file', 'max:5120', 'mimetypes:image/jpeg,image/png,image/webp', 'mimes:jpg,jpeg,png,webp'],
        ]);

        // Résolution du quartier de livraison (Dropdown Admin) et des coordonnées associées
        $neighborhood = null;
        if (! empty($validated['neighborhood_id'])) {
            $neighborhood = Neighborhood::query()
                ->whereKey((int) $validated['neighborhood_id'])
                ->where('city_id', (int) $cityId)
                ->where('is_active', true)
                ->first();

            if (! $neighborhood) {
                throw ValidationException::withMessages([
                    'neighborhood_id' => ['Le quartier sélectionné est indisponible pour votre ville.'],
                ]);
            }
        } elseif (! empty($validated['delivery_neighborhood']) && $cityId !== null) {
            $neighborhoodName = trim((string) $validated['delivery_neighborhood']);
            $neighborhood = Neighborhood::query()
                ->where('city_id', (int) $cityId)
                ->where('is_active', true)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($neighborhoodName)])
                ->first();
        }

        if ($neighborhood !== null) {
            if (empty($validated['delivery_address'])) {
                $validated['delivery_address'] = $neighborhood->name;
            }
            $nbHasGps = $neighborhood->lat !== null
                && $neighborhood->lng !== null
                && ! (abs((float) $neighborhood->lat) < 0.0001 && abs((float) $neighborhood->lng) < 0.0001);

            if ($nbHasGps) {
                if (! isset($validated['delivery_lat'])) {
                    $validated['delivery_lat'] = (float) $neighborhood->lat;
                }
                if (! isset($validated['delivery_lng'])) {
                    $validated['delivery_lng'] = (float) $neighborhood->lng;
                }
            }
            if ($neighborhood->distance_km !== null && (float) $neighborhood->distance_km > 0 && (! isset($validated['distance_km']) || ! $nbHasGps)) {
                $validated['distance_km'] = (float) $neighborhood->distance_km;
            }
        } elseif (empty($validated['delivery_address']) && ! empty($validated['delivery_neighborhood'])) {
            $validated['delivery_address'] = trim((string) $validated['delivery_neighborhood']);
        }

        $phone = $this->normalizePhoneValue((string) $validated['recipient_phone']);
        if (! preg_match('/^(01|05|07|21|25|27)[0-9]{8}$/', $phone)) {
            throw ValidationException::withMessages([
                'recipient_phone' => ['Format de numéro ivoirien invalide : 10 chiffres attendus (ex: 0701020304).'],
            ]);
        }
        $validated['recipient_phone'] = $phone;

        $defaultWeights = [
            'petit' => 0.5,
            'moyen' => 2.0,
            'grand' => 8.0,
        ];
        $maxWeights = [
            'petit' => 1.0,
            'moyen' => 5.0,
            'grand' => 15.0,
        ];
        $weight = isset($validated['weight_kg'])
            ? (float) $validated['weight_kg']
            : ($defaultWeights[$validated['size']] ?? 2.0);
        $validated['weight_kg'] = $weight;

        $maxWeight = $maxWeights[$validated['size']] ?? 15.0;
        if ($weight > $maxWeight) {
            throw ValidationException::withMessages([
                'weight_kg' => ["Pour le format {$validated['size']}, le poids maximum autorisé est de {$maxWeight} kg."],
            ]);
        }

        $photoFile = $request->file('photo');
        $parcel = $service->creerExpedition(
            $user,
            $validated,
            $photoFile instanceof UploadedFile ? $photoFile : null,
        );

        /** @var ParcelPhoto|null $senderPhoto */
        $senderPhoto = $parcel->photos->firstWhere('type', 'parcel');

        return response()->json([
            'succes' => true,
            'donnees' => [
                'id' => $parcel->id,
                'sender_code_plain' => $parcel->sender_code_plain,
                'shipping_fee' => (float) $parcel->shipping_fee,
                'insurance_amount' => (float) $parcel->insurance_amount,
                'total_amount' => (float) $parcel->shipping_fee + (float) $parcel->insurance_amount,
                'has_insurance' => (bool) $parcel->has_insurance,
                'payment_method' => $parcel->payment_method,
                'payment_status' => $parcel->payment_status,
                'payment_reference' => $parcel->payment_reference,
                'photo_url' => $senderPhoto?->signed_url,
            ],
            'message' => 'Expédition créée avec succès.',
        ], 201);
    }

    public function verifySenderCode(Request $request, Parcel $parcel, ParcelService $service): JsonResponse
    {
        if ($denied = $this->authorizeAssignedCourier($parcel, allowNationalAdmins: true)) {
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
        if ($denied = $this->authorizeAssignedCourier($parcel, allowNationalAdmins: true)) {
            return $denied;
        }

        $request->validate([
            'code' => 'required|string|size:4',
        ]);

        try {
            $updated = $service->validerCodeDestinataire($parcel, $request->input('code'));
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'succes' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'succes' => true,
            'donnees' => $updated,
            'message' => 'Code destinataire validé et livraison confirmée.',
        ]);
    }

    public function uploadPhoto(Request $request, Parcel $parcel, ParcelService $service): JsonResponse
    {
        if ($denied = $this->authorizeAssignedCourier($parcel, allowNationalAdmins: false)) {
            return $denied;
        }

        $validated = $request->validate([
            'type' => ['required', 'string', 'in:pickup,delivery'],
            'photo' => ['required', 'file', 'max:5120', 'mimetypes:image/jpeg,image/png,image/webp', 'mimes:jpg,jpeg,png,webp'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        /** @var UploadedFile $photoFile */
        $photoFile = $request->file('photo');

        $photo = $service->sauvegarderPhoto(
            parcel: $parcel,
            photo: $photoFile,
            type: (string) $validated['type'],
            lat: isset($validated['lat']) ? (float) $validated['lat'] : null,
            lng: isset($validated['lng']) ? (float) $validated['lng'] : null,
        );

        return response()->json([
            'succes' => true,
            'donnees' => $photo,
            'message' => 'Photo de preuve enregistrée.',
        ], 201);
    }

    public function atSender(Parcel $parcel, ParcelService $service): JsonResponse
    {
        if ($denied = $this->authorizeAssignedCourier($parcel, allowNationalAdmins: false)) {
            return $denied;
        }

        $updated = $service->signalerArriveeExpediteur($parcel);

        return response()->json([
            'succes' => true,
            'donnees' => $updated,
            'message' => 'Arrivée chez l\'expéditeur enregistrée.',
        ]);
    }

    public function atRecipient(Parcel $parcel, ParcelService $service): JsonResponse
    {
        if ($denied = $this->authorizeAssignedCourier($parcel, allowNationalAdmins: false)) {
            return $denied;
        }

        $updated = $service->signalerArriveeDestinataire($parcel);

        return response()->json([
            'succes' => true,
            'donnees' => $updated,
            'message' => 'Arrivée chez le destinataire enregistrée.',
        ]);
    }

    private function authorizeAssignedCourier(Parcel $parcel, bool $allowNationalAdmins = true): ?JsonResponse
    {
        $user = auth()->user();

        if ($allowNationalAdmins && $user && in_array($user->role, ['super_admin', 'dg_national'], true)) {
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

        if ((int) $parcel->sender_id !== (int) $user->id && ! in_array($user->role, ['super_admin', 'dg_national', 'city_manager'])) {
            return response()->json(['succes' => false, 'message' => 'Accès refusé à cette expédition.'], 403);
        }

        if ($user->role === 'city_manager' && (int) $parcel->city_id !== (int) $user->city_id) {
            return response()->json(['succes' => false, 'message' => 'Accès refusé pour cette zone.'], 403);
        }

        $parcel->load(['courier.user', 'photos']);

        return response()->json([
            'succes' => true,
            'donnees' => $parcel,
        ]);
    }

    /**
     * Lecture dédiée du code expéditeur GoColis avant ramassage (D-CODE-1).
     */
    public function senderCode(Parcel $parcel, ConfirmationCodeService $codeService): JsonResponse
    {
        if ((int) $parcel->sender_id !== (int) auth()->id()) {
            return response()->json(['succes' => false, 'message' => 'Accès refusé à cette expédition.'], 403);
        }

        if (! in_array($parcel->status, [
            Parcel::STATUS_PENDING,
            Parcel::STATUS_WAITING,
            Parcel::STATUS_ACCEPTED,
            Parcel::STATUS_AT_SENDER,
        ], true)) {
            return response()->json([
                'succes' => false,
                'message' => 'Le code expéditeur n\'est disponible qu\'avant le ramassage du colis.',
            ], 422);
        }

        $code = $codeService->dechiffrer($parcel->sender_code_enc);
        if ($code === null) {
            return response()->json([
                'succes' => false,
                'message' => 'code indisponible, régénérer',
            ], 409);
        }

        return response()->json([
            'succes' => true,
            'donnees' => [
                'parcel_id' => $parcel->id,
                'sender_code' => $code,
            ],
            'message' => 'Code expéditeur récupéré.',
        ]);
    }

    /**
     * Annulation d'une expédition GoColis par le client avant ramassage (`picked_up`).
     */
    public function cancelClient(Request $request, Parcel $parcel, ParcelService $service): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $raison = is_string($request->input('reason')) ? trim((string) $request->input('reason')) : null;

        $cancelled = $service->annulerParClient($parcel, $user, $raison ?: null);

        return response()->json([
            'succes' => true,
            'message' => 'Expédition annulée avec succès.',
            'donnees' => $cancelled,
        ]);
    }

    /**
     * Suivi bêta de position livreur pour une expédition GoColis (D-BETA-1, 1 req / 5s).
     */
    public function courierLocation(Parcel $parcel, CourierLocationPollingService $pollingService): JsonResponse
    {
        $estEnCours = in_array($parcel->status, [
            Parcel::STATUS_ACCEPTED,
            Parcel::STATUS_AT_SENDER,
            Parcel::STATUS_PICKED_UP,
            Parcel::STATUS_IN_TRANSIT,
            Parcel::STATUS_AT_RECIPIENT,
        ], true);

        $parcel->loadMissing('courier');
        $uid = auth()->id();

        return $pollingService->repondrePosition(
            ownerId: (int) $parcel->sender_id,
            authenticatedUserId: $uid !== null ? (int) $uid : null,
            scopeKey: "parcel:{$parcel->id}",
            estEnCours: $estEnCours,
            courier: $parcel->courier,
            forbiddenMessage: 'Accès refusé à cette expédition.',
            inactiveMessage: 'Le suivi livreur n\'est disponible que pour une expédition en cours avec livreur assigné.',
        );
    }
}
