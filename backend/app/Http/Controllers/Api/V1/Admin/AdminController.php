<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\{City, Courier, Merchant, Order, User};
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminController extends Controller
{
    private const PLAFONDS_REMBOURSEMENT = [
        'city_agent'       => 0,
        'city_support'     => 2000,
        'city_finance'     => 10000,
        'city_manager'     => 50000,
        'national_finance' => PHP_INT_MAX,
        'dg_national'      => PHP_INT_MAX,
        'super_admin'      => PHP_INT_MAX,
    ];

    public function orders(Request $request): JsonResponse
    {
        $user = auth()->user();
        $query = Order::query()->latest();

        $cityId = $request->get('city_context_id') ?? $user->city_id;
        $nationalRoles = ['super_admin', 'dg_national', 'national_finance', 'national_tech', 'national_support'];

        if ($cityId && !in_array($user->role, $nationalRoles, true)) {
            $query->where('city_id', $cityId);
        }

        return response()->json([
            'succes'  => true,
            'donnees' => $query->paginate(20),
        ]);
    }

    public function refund(Request $request, Order $order, WalletService $walletService): JsonResponse
    {
        $request->validate([
            'montant'    => 'required|numeric|min:1',
            'raison'     => 'nullable|string',
            'owner_type' => 'nullable|string',
            'owner_id'   => 'nullable|integer',
        ]);

        $user = auth()->user();
        $montant = (float) $request->input('montant');
        $plafond = self::PLAFONDS_REMBOURSEMENT[$user->role] ?? 0;

        if ($montant > $plafond || $plafond === 0) {
            return response()->json([
                'succes'  => false,
                'message' => 'Plafond de remboursement insuffisant pour votre rôle.',
            ], 403);
        }

        $ownerType = $request->input('owner_type', 'courier');
        $ownerId = $request->input('owner_id', $order->courier_id ?? $order->client_id);

        $tx = $walletService->effectuerRemboursement(
            ownerType: $ownerType,
            ownerId: (int) $ownerId,
            montant: $montant,
            description: $request->input('raison', 'Remboursement commande #'.$order->id),
            adminId: $user->id,
        );

        return response()->json([
            'succes'  => true,
            'donnees' => $tx,
            'message' => 'Remboursement effectué avec succès.',
        ]);
    }

    public function emergencyMode(Request $request, City $city): JsonResponse
    {
        $user = auth()->user();

        if (!in_array($user->role, ['super_admin', 'dg_national'], true)) {
            return response()->json([
                'succes'  => false,
                'message' => 'Action réservée au Super Admin ou DG National.',
            ], 403);
        }

        $activer = $request->boolean('activer');
        $city->update([
            'emergency_mode_active'       => $activer,
            'emergency_mode_message'      => $request->input('message'),
            'emergency_mode_activated_at' => $activer ? now() : null,
        ]);

        return response()->json([
            'succes'  => true,
            'donnees' => $city,
            'message' => 'Mode Urgence mis à jour.',
        ]);
    }

    public function unlockOrderCode(Request $request, Order $order): JsonResponse
    {
        $user = auth()->user();
        $rolesAutorises = [
            'city_support',
            'city_finance',
            'city_manager',
            'national_support',
            'national_finance',
            'dg_national',
            'super_admin',
        ];

        if (!in_array($user->role, $rolesAutorises, true)) {
            return response()->json([
                'succes'  => false,
                'message' => 'Action réservée au Support Ville ou niveau supérieur.',
            ], 403);
        }

        $order->update([
            'confirmation_locked'      => false,
            'confirmation_attempts'    => 0,
            'confirmation_unlocked_by' => $user->id,
        ]);

        return response()->json([
            'succes'  => true,
            'donnees' => $order,
            'message' => 'Code de confirmation débloqué.',
        ]);
    }

    public function dashboard(Request $request): JsonResponse
    {
        $user = auth()->user();
        $cityId = $request->get('city_context_id') ?? $user->city_id;

        $ordersCount = Order::when($cityId, fn ($q) => $q->where('city_id', $cityId))->count();
        $merchantsCount = Merchant::when($cityId, fn ($q) => $q->where('city_id', $cityId))->count();
        $couriersOnline = Courier::when($cityId, fn ($q) => $q->where('city_id', $cityId))->where('is_online', true)->count();

        return response()->json([
            'succes'  => true,
            'donnees' => [
                'total_orders'    => $ordersCount,
                'merchants_count' => $merchantsCount,
                'couriers_online' => $couriersOnline,
            ],
        ]);
    }
}
