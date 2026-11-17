<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Enregistrement / mise à jour du jeton FCM de l'appareil connecté.
 */
class DeviceTokenController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        if (! $request->filled('token') && $request->filled('device_token')) {
            $request->merge(['token' => $request->input('device_token')]);
        }

        $validated = $request->validate([
            'token' => ['required', 'string', 'min:10', 'max:512'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $user->update([
            'device_token' => trim((string) $validated['token']),
        ]);

        return response()->json([
            'succes' => true,
            'message' => 'Jeton appareil enregistré avec succès.',
        ]);
    }
}
