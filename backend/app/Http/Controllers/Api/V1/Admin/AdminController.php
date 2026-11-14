<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Parcel;
use App\Models\Refund;
use App\Models\User;
use App\Modules\GoColis\Services\ParcelService;
use App\Modules\GoShop\Services\OrderService;
use App\Services\ActivityLogService;
use App\Services\RefundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AdminController extends Controller
{
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

    public function refund(Request $request, Order $order, RefundService $refundService): JsonResponse
    {
        $request->validate([
            'montant' => 'required|numeric|gt:0',
            'raison' => 'nullable|string|max:1000',
        ]);

        /** @var User $user */
        $user = auth()->user();
        $montant = (int) round((float) $request->input('montant'));
        $raison = (string) $request->input('raison', 'Remboursement commande #'.$order->id);

        $res = $refundService->creerEtApprouverDirect(
            order: $order,
            parcel: null,
            montant: $montant,
            raison: $raison,
            admin: $user,
        );

        if (! $res['ok']) {
            return response()->json([
                'succes' => false,
                'message' => $res['message'],
            ], $res['status']);
        }

        return response()->json([
            'succes' => true,
            'donnees' => $res['refund'],
            'message' => 'Remboursement validé avec succès.',
        ]);
    }

    public function parcelRefund(Request $request, Parcel $parcel, RefundService $refundService): JsonResponse
    {
        $request->validate([
            'montant' => 'nullable|numeric|gt:0',
            'raison' => 'nullable|string|max:1000',
        ]);

        /** @var User $user */
        $user = auth()->user();
        $rawMontant = $request->input('montant', $parcel->declared_value);
        $montant = (int) round((float) $rawMontant);
        if ($montant <= 0) {
            return response()->json([
                'succes' => false,
                'message' => 'Le montant du remboursement doit être supérieur à 0 F CFA.',
            ], 422);
        }

        $raison = (string) $request->input('raison', 'Indemnisation assurance GoColis #'.$parcel->id);

        $res = $refundService->creerEtApprouverDirect(
            order: null,
            parcel: $parcel,
            montant: $montant,
            raison: $raison,
            admin: $user,
        );

        if (! $res['ok']) {
            return response()->json([
                'succes' => false,
                'message' => $res['message'],
            ], $res['status']);
        }

        return response()->json([
            'succes' => true,
            'donnees' => $res['refund'],
            'message' => 'Indemnisation assurance validée avec succès.',
        ]);
    }

    public function requestRefund(Request $request, RefundService $refundService): JsonResponse
    {
        $validated = $request->validate([
            'order_id' => 'nullable|integer|exists:orders,id|required_without:parcel_id',
            'parcel_id' => 'nullable|integer|exists:parcels,id|required_without:order_id',
            'montant' => 'required|integer|min:1',
            'raison' => 'required|string|max:1000',
        ]);

        if (! empty($validated['order_id']) && ! empty($validated['parcel_id'])) {
            return response()->json([
                'succes' => false,
                'message' => 'Un remboursement ne peut cibler qu\'une seule commande ou expédition.',
            ], 422);
        }

        /** @var User $user */
        $user = auth()->user();
        $order = ! empty($validated['order_id']) ? Order::findOrFail((int) $validated['order_id']) : null;
        $parcel = ! empty($validated['parcel_id']) ? Parcel::findOrFail((int) $validated['parcel_id']) : null;

        $res = $refundService->demanderRemboursement(
            order: $order,
            parcel: $parcel,
            montant: (int) $validated['montant'],
            raison: (string) $validated['raison'],
            demandeur: $user,
        );

        if (! $res['ok']) {
            return response()->json([
                'succes' => false,
                'message' => $res['message'],
            ], $res['status']);
        }

        return response()->json([
            'succes' => true,
            'donnees' => $res['refund'],
            'message' => 'Demande de remboursement enregistrée.',
        ], 201);
    }

    public function approveRefund(Refund $refund, RefundService $refundService): JsonResponse
    {
        /** @var User $user */
        $user = auth()->user();
        $res = $refundService->approuverRemboursement($refund, $user);

        if (! $res['ok']) {
            return response()->json([
                'succes' => false,
                'message' => $res['message'],
            ], $res['status']);
        }

        return response()->json([
            'succes' => true,
            'donnees' => $res['refund'],
            'message' => 'Remboursement approuvé.',
        ]);
    }

    public function markRefundPaid(Request $request, Refund $refund, RefundService $refundService): JsonResponse
    {
        /** @var User $user */
        $user = auth()->user();
        $reference = (string) $request->input('payment_reference', '');

        $res = $refundService->marquerPayeRemboursement($refund, $user, $reference);

        if (! $res['ok']) {
            return response()->json([
                'succes' => false,
                'message' => $res['message'],
            ], $res['status']);
        }

        return response()->json([
            'succes' => true,
            'donnees' => $res['refund'],
            'message' => 'Remboursement marqué payé.',
        ]);
    }

    public function rejectRefund(Request $request, Refund $refund, RefundService $refundService): JsonResponse
    {
        /** @var User $user */
        $user = auth()->user();
        $motif = $request->input('raison');

        $res = $refundService->rejeterRemboursement(
            refund: $refund,
            admin: $user,
            motifRejet: is_string($motif) ? $motif : null,
        );

        if (! $res['ok']) {
            return response()->json([
                'succes' => false,
                'message' => $res['message'],
            ], $res['status']);
        }

        return response()->json([
            'succes' => true,
            'donnees' => $res['refund'],
            'message' => 'Remboursement rejeté.',
        ]);
    }

    public function declareParcelLost(Request $request, Parcel $parcel, RefundService $refundService): JsonResponse
    {
        /** @var User $user */
        $user = auth()->user();
        $raison = (string) $request->input('raison', '');

        $res = $refundService->declarerColisPerdu($parcel, $user, $raison);

        if (! $res['ok']) {
            return response()->json([
                'succes' => false,
                'message' => $res['message'],
            ], $res['status']);
        }

        return response()->json([
            'succes' => true,
            'donnees' => $res['parcel'],
            'message' => 'Colis déclaré perdu et journalisé.',
        ]);
    }

    public function emergencyMode(Request $request, City $city): JsonResponse
    {
        $user = auth()->user();

        $validated = $request->validate([
            'activer' => ['required', 'boolean'],
            'message' => ['nullable', 'string', 'max:255'],
        ]);

        $activer = (bool) $validated['activer'];

        if ($activer) {
            if (! in_array($user->role, ['super_admin', 'dg_national'], true)) {
                return response()->json([
                    'succes' => false,
                    'message' => 'L\'activation du mode urgence est réservée au Super Admin ou DG National.',
                ], 403);
            }
        } else {
            if ($user->role !== 'super_admin') {
                return response()->json([
                    'succes' => false,
                    'message' => 'La désactivation du mode urgence est réservée au Super Admin seul.',
                ], 403);
            }
        }

        $oldState = (bool) $city->emergency_mode_active;
        $city->update([
            'emergency_mode_active' => $activer,
            'emergency_mode_message' => $activer ? ($validated['message'] ?? null) : null,
            'emergency_mode_activated_at' => $activer ? now() : null,
        ]);

        app(ActivityLogService::class)->log(
            userId: $user->id,
            action: $activer ? 'emergency_mode_activated' : 'emergency_mode_deactivated',
            entityType: 'city',
            entityId: $city->id,
            properties: [
                'user_id' => $user->id,
                'user_name' => $user->name,
                'user_role' => $user->role,
                'city_id' => $city->id,
                'city_name' => $city->name,
                'ancien_etat' => $oldState,
                'nouvel_etat' => $activer,
                'message' => $validated['message'] ?? null,
            ],
            severity: 'critical',
        );

        return response()->json([
            'succes' => true,
            'donnees' => $city,
            'message' => 'Mode Urgence mis à jour.',
        ]);
    }

    public function unlockOrderCode(Request $request, Order $order, ActivityLogService $activityLogService): JsonResponse
    {
        $user = auth()->user();

        if ($denied = $this->verifierHabilitationCode($user, (int) $order->city_id, 'Commande introuvable.')) {
            return $denied;
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

    public function regenerateOrderCode(Order $order, OrderService $orderService): JsonResponse
    {
        /** @var User|null $user */
        $user = auth()->user();

        if ($denied = $this->verifierHabilitationCode($user, (int) $order->city_id, 'Commande introuvable.')) {
            return $denied;
        }

        try {
            $updated = $orderService->regenererCodeConfirmation($order, $user);
        } catch (ValidationException $e) {
            return response()->json([
                'succes' => false,
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
            ], 422);
        }

        return response()->json([
            'succes' => true,
            'donnees' => [
                'order_id' => $updated->id,
                'regenerated' => true,
            ],
            'message' => 'Code de confirmation régénéré.',
        ]);
    }

    public function regenerateParcelSenderCode(Parcel $parcel, ParcelService $parcelService): JsonResponse
    {
        /** @var User|null $user */
        $user = auth()->user();

        if ($denied = $this->verifierHabilitationCode($user, (int) $parcel->city_id, 'Expédition introuvable.')) {
            return $denied;
        }

        try {
            $updated = $parcelService->regenererCodeExpediteur($parcel, $user);
        } catch (ValidationException $e) {
            return response()->json([
                'succes' => false,
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
            ], 422);
        }

        return response()->json([
            'succes' => true,
            'donnees' => [
                'parcel_id' => $updated->id,
                'regenerated' => true,
            ],
            'message' => 'Code expéditeur régénéré.',
        ]);
    }

    private function verifierHabilitationCode(?User $user, int $entityCityId, string $notFoundMessage): ?JsonResponse
    {
        if (! $user || ! $user->canManageCodes()) {
            return response()->json([
                'succes' => false,
                'message' => 'Action réservée au Support Ville ou niveau supérieur.',
            ], 403);
        }

        if (in_array($user->role, User::CODE_MANAGEMENT_LOCAL_ROLES, true)
            && (! $user->city_id || $entityCityId !== (int) $user->city_id)) {
            return response()->json([
                'succes' => false,
                'message' => $notFoundMessage,
            ], 404);
        }

        return null;
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
