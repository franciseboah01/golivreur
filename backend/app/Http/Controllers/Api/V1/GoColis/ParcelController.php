<?php

namespace App\Http\Controllers\Api\V1\GoColis;

use App\Http\Controllers\Controller;
use App\Models\Parcel;
use App\Modules\GoColis\Services\ParcelService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ParcelController extends Controller
{
    public function store(Request $request, ParcelService $service): JsonResponse
    {
        $validated = $request->validate([
            'recipient_name'   => 'required|string|max:150',
            'recipient_phone'  => 'required|string|max:20',
            'delivery_address' => 'required|string',
            'delivery_lat'     => 'nullable|numeric',
            'delivery_lng'     => 'nullable|numeric',
            'pickup_address'   => 'nullable|string',
            'pickup_lat'       => 'nullable|numeric',
            'pickup_lng'       => 'nullable|numeric',
            'size'             => 'required|in:petit,moyen,grand',
            'weight_kg'        => 'nullable|numeric',
            'declared_value'   => 'nullable|numeric|min:0',
            'description'      => 'nullable|string',
            'payment_method'   => 'required|string|in:cash,orange_money,wave,mtn',
            'distance_km'      => 'required|numeric|min:0.1',
        ]);

        $parcel = $service->creerExpedition(auth()->user(), $validated);

        return response()->json([
            'succes'  => true,
            'donnees' => [
                'id'                => $parcel->id,
                'sender_code_plain' => $parcel->sender_code_plain,
                'shipping_fee'      => (float) $parcel->shipping_fee,
                'has_insurance'     => (bool) $parcel->has_insurance,
            ],
            'message' => 'Expédition créée avec succès.',
        ], 201);
    }

    public function verifySenderCode(Request $request, Parcel $parcel, ParcelService $service): JsonResponse
    {
        $request->validate([
            'code' => 'required|string|size:4',
        ]);

        $updated = $service->validerCodeExpediteur($parcel, $request->input('code'));

        return response()->json([
            'succes'  => true,
            'donnees' => $updated,
            'message' => 'Code expéditeur validé et colis pris en charge.',
        ]);
    }

    public function verifyRecipientCode(Request $request, Parcel $parcel, ParcelService $service): JsonResponse
    {
        $request->validate([
            'code' => 'required|string|size:4',
        ]);

        $updated = $service->validerCodeDestinataire($parcel, $request->input('code'));

        return response()->json([
            'succes'  => true,
            'donnees' => $updated,
            'message' => 'Code destinataire validé et livraison confirmée.',
        ]);
    }

    public function showDriver(Parcel $parcel): JsonResponse
    {
        return response()->json([
            'succes'  => true,
            'donnees' => [
                'id'               => $parcel->id,
                'size'             => $parcel->size,
                'size_label'       => $parcel->size_label,
                'status'           => $parcel->status,
                'shipping_fee'     => (float) $parcel->shipping_fee,
                'courier_amount'   => (float) $parcel->courier_amount,
                'pickup_address'   => $parcel->pickup_address,
                'pickup_lat'       => $parcel->pickup_lat,
                'pickup_lng'       => $parcel->pickup_lng,
                'delivery_address' => $parcel->delivery_address,
                'delivery_lat'     => $parcel->delivery_lat,
                'delivery_lng'     => $parcel->delivery_lng,
                'recipient_name'   => $parcel->recipient_name,
                'recipient_phone'  => $parcel->recipient_phone,
                'created_at'       => $parcel->created_at,
            ],
        ]);
    }

    public function showClient(Parcel $parcel): JsonResponse
    {
        return response()->json([
            'succes'  => true,
            'donnees' => $parcel,
        ]);
    }
}
