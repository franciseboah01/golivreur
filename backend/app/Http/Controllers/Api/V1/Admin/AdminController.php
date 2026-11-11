<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Parcel;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminController extends Controller
{
    private const LOCAL_ROLES = [
        'city_agent',
        'city_support',
        'city_finance',
        'city_manager',
    ];

    private const ROLES_LECTURE_OPERATIONS = [
        'super_admin',
        'dg_national',
        'national_finance',
        'national_tech',
        'national_support',
        'city_manager',
        'city_finance',
        'city_support',
        'admin',
    ];

    private const PLAFONDS_REMBOURSEMENT = [
        'city_agent' => 0,
        'city_support' => 2000,
        'city_finance' => 10000,
        'city_manager' => 50000,
        'national_finance' => PHP_INT_MAX,
        'dg_national' => PHP_INT_MAX,
        'super_admin' => PHP_INT_MAX,
    ];

    public function orders(Request $request): JsonResponse
    {
        $user = auth()->user();

        if ($denied = $this->verifierAccesOperations($user)) {
            return $denied;
        }

        $query = Order::query()->latest();

        $cityId = $request->get('city_context_id') ?? $user->city_id;
        $nationalRoles = ['super_admin', 'dg_national', 'national_finance', 'national_tech', 'national_support'];

        if ($cityId && ! in_array($user->role, $nationalRoles, true)) {
            $query->where('city_id', $cityId);
        }

        return response()->json([
            'succes' => true,
            'donnees' => $query->paginate(20),
        ]);
    }

    public function refund(Request $request, Order $order, WalletService $walletService): JsonResponse
    {
        $request->validate([
            'montant' => 'required|numeric|min:1',
            'raison' => 'nullable|string',
            'owner_type' => 'nullable|string',
            'owner_id' => 'nullable|integer',
        ]);

        $user = auth()->user();

        if ($this->estHorsVilleLocale($user, (int) $order->city_id)) {
            return response()->json([
                'succes' => false,
                'message' => 'Commande introuvable.',
            ], 404);
        }

        $montant = (float) $request->input('montant');
        $plafond = self::PLAFONDS_REMBOURSEMENT[$user->role] ?? 0;

        if ($montant > $plafond || $plafond === 0) {
            return response()->json([
                'succes' => false,
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
            'succes' => true,
            'donnees' => $tx,
            'message' => 'Remboursement effectué avec succès.',
        ]);
    }

    public function parcelRefund(Request $request, Parcel $parcel, WalletService $walletService): JsonResponse
    {
        $user = auth()->user();

        if ($this->estHorsVilleLocale($user, (int) $parcel->city_id)) {
            return response()->json([
                'succes' => false,
                'message' => 'Expédition introuvable.',
            ], 404);
        }

        $montant = (float) $request->input('montant', $parcel->declared_value);
        $plafond = self::PLAFONDS_REMBOURSEMENT[$user->role] ?? 0;

        if ($montant > $plafond || $plafond === 0) {
            return response()->json([
                'succes' => false,
                'message' => 'Plafond de remboursement insuffisant pour votre rôle.',
            ], 403);
        }

        $ownerType = $request->input('owner_type', 'courier');
        $ownerId = $request->input('owner_id', $parcel->courier_id ?? $parcel->sender_id);

        $tx = $walletService->effectuerRemboursement(
            ownerType: $ownerType,
            ownerId: (int) $ownerId,
            montant: $montant,
            description: $request->input('raison', 'Indemnisation assurance GoColis #'.$parcel->id),
            adminId: $user->id,
        );

        $parcel->update(['status' => 'refunded']);

        return response()->json([
            'succes' => true,
            'donnees' => $tx,
            'message' => 'Indemnisation assurance effectuée avec succès.',
        ]);
    }

    public function emergencyMode(Request $request, City $city): JsonResponse
    {
        $user = auth()->user();

        if (! in_array($user->role, ['super_admin', 'dg_national'], true)) {
            return response()->json([
                'succes' => false,
                'message' => 'Action réservée au Super Admin ou DG National.',
            ], 403);
        }

        $activer = $request->boolean('activer');
        $city->update([
            'emergency_mode_active' => $activer,
            'emergency_mode_message' => $request->input('message'),
            'emergency_mode_activated_at' => $activer ? now() : null,
        ]);

        return response()->json([
            'succes' => true,
            'donnees' => $city,
            'message' => 'Mode Urgence mis à jour.',
        ]);
    }

    public function unlockOrderCode(Request $request, Order $order, ActivityLogService $activityLogService): JsonResponse
    {
        $user = auth()->user();
        $localRoles = [
            'city_support',
            'city_finance',
            'city_manager',
        ];
        $nationalRoles = [
            'national_support',
            'national_finance',
            'dg_national',
            'super_admin',
        ];
        $rolesAutorises = [...$localRoles, ...$nationalRoles];

        if (! in_array($user->role, $rolesAutorises, true)) {
            return response()->json([
                'succes' => false,
                'message' => 'Action réservée au Support Ville ou niveau supérieur.',
            ], 403);
        }

        if (in_array($user->role, $localRoles, true)
            && (! $user->city_id || (int) $order->city_id !== (int) $user->city_id)) {
            return response()->json([
                'succes' => false,
                'message' => 'Commande introuvable.',
            ], 404);
        }

        if (in_array($order->status, [Order::STATUS_DELIVERED, Order::STATUS_CANCELLED, Order::STATUS_REFUSED], true)) {
            return response()->json([
                'succes' => false,
                'message' => 'Impossible de débloquer une commande clôturée.',
            ], 422);
        }

        $order->update([
            'confirmation_locked' => false,
            'confirmation_attempts' => 0,
            'confirmation_unlocked_by' => $user->id,
        ]);

        $order->loadMissing('city');

        $activityLogService->log(
            userId: $user->id,
            action: 'order_code_unlocked',
            entityType: 'order',
            entityId: $order->id,
            properties: [
                'order_id' => $order->id,
                'city_id' => $order->city_id,
                'ville' => $order->city?->name,
            ],
            severity: 'warning',
        );

        return response()->json([
            'succes' => true,
            'donnees' => $order->fresh(),
            'message' => 'Code de confirmation débloqué.',
        ]);
    }

    public function dashboard(Request $request): JsonResponse
    {
        $user = auth()->user();

        if ($denied = $this->verifierAccesOperations($user)) {
            return $denied;
        }

        $cityId = $request->get('city_context_id') ?? $user->city_id;

        $ordersCount = Order::when($cityId, fn ($q) => $q->where('city_id', $cityId))->count();
        $merchantsCount = Merchant::when($cityId, fn ($q) => $q->where('city_id', $cityId))->count();
        $couriersOnline = Courier::when($cityId, fn ($q) => $q->where('city_id', $cityId))->where('is_online', true)->count();

        return response()->json([
            'succes' => true,
            'donnees' => [
                'total_orders' => $ordersCount,
                'merchants_count' => $merchantsCount,
                'couriers_online' => $couriersOnline,
            ],
        ]);
    }

    private function estHorsVilleLocale(?User $user, int $resourceCityId): bool
    {
        if (! $user || ! in_array($user->role, self::LOCAL_ROLES, true)) {
            return false;
        }

        return ! $user->city_id || $resourceCityId !== (int) $user->city_id;
    }

    private function verifierAccesOperations(?User $user): ?JsonResponse
    {
        if (! $user || ! in_array($user->role, self::ROLES_LECTURE_OPERATIONS, true)) {
            return response()->json([
                'succes' => false,
                'message' => 'Action non autorisée pour le rôle Agent de Terrain.',
            ], 403);
        }

        return null;
    }
}
