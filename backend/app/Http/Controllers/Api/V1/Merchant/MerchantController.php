<?php

namespace App\Http\Controllers\Api\V1\Merchant;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Product;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Modules\GoShop\Services\OrderService;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MerchantController extends Controller
{
    private function getMerchant(): Merchant
    {
        $merchant = Merchant::where('user_id', auth()->id())->first();
        if (! $merchant) {
            abort(404, 'Profil commerce introuvable.');
        }

        return $merchant;
    }

    public function status(): JsonResponse
    {
        $merchant = $this->getMerchant()->load('categoryModel');

        return response()->json([
            'succes' => true,
            'donnees' => [
                'id' => $merchant->id,
                'is_open' => $merchant->is_open,
                'is_active' => $merchant->is_active,
                'prep_time_minutes' => $merchant->prep_time_minutes,
                'name' => $merchant->name,
                'category' => $merchant->category,
                'category_name' => $merchant->categoryModel->name ?? ucfirst((string) $merchant->category),
                'emoji' => $merchant->resolved_emoji,
                'logo_url' => $merchant->logo_url ?? $merchant->categoryModel?->logo_url,
                'photo_url' => $merchant->photo_url,
            ],
        ]);
    }

    public function toggleStatus(Request $request): JsonResponse
    {
        $merchant = $this->getMerchant();
        $isOpen = $request->boolean('is_open', ! $merchant->is_open);

        $merchant->update(['is_open' => $isOpen]);

        return response()->json([
            'succes' => true,
            'donnees' => $merchant,
            'message' => $isOpen ? 'Commerce ouvert.' : 'Commerce fermé.',
        ]);
    }

    public function orders(): JsonResponse
    {
        $merchant = $this->getMerchant();

        $orders = Order::where('merchant_id', $merchant->id)
            ->whereIn('status', [
                Order::STATUS_PENDING,
                Order::STATUS_ACCEPTED,
                Order::STATUS_WAITING,
                'ready',
                'picked_up',
                'in_transit',
            ])
            ->with(['client', 'courier.user', 'items.product'])
            ->latest()
            ->get();

        return response()->json([
            'succes' => true,
            'donnees' => $orders,
        ]);
    }

    public function acceptOrder(Order $order, OrderService $orderService): JsonResponse
    {
        $merchant = $this->getMerchant();

        if ($order->merchant_id !== $merchant->id) {
            return response()->json(['succes' => false, 'message' => 'Cette commande ne concerne pas votre commerce.'], 403);
        }

        $updated = $orderService->accepterCommande($order, $merchant);

        return response()->json([
            'succes' => true,
            'donnees' => $updated,
            'message' => 'Commande acceptée avec succès.',
        ]);
    }

    public function refuseOrder(Request $request, Order $order, OrderService $orderService): JsonResponse
    {
        $merchant = $this->getMerchant();

        if ($order->merchant_id !== $merchant->id) {
            return response()->json(['succes' => false, 'message' => 'Cette commande ne concerne pas votre commerce.'], 403);
        }

        $reason = $request->input('raison', 'Rupture de stock');
        $updated = $orderService->refuserCommande($order, $reason);

        return response()->json([
            'succes' => true,
            'donnees' => $updated,
            'message' => 'Commande refusée.',
        ]);
    }

    public function readyOrder(Order $order, OrderService $orderService): JsonResponse
    {
        $merchant = $this->getMerchant();

        if ($order->merchant_id !== $merchant->id) {
            return response()->json(['succes' => false, 'message' => 'Cette commande ne concerne pas votre commerce.'], 403);
        }

        $updated = $orderService->signalerCommandePrete($order);

        return response()->json([
            'succes' => true,
            'donnees' => $updated,
            'message' => 'Commande signalée comme prête pour le livreur.',
        ]);
    }

    public function products(): JsonResponse
    {
        $merchant = $this->getMerchant();

        $products = Product::where('merchant_id', $merchant->id)
            ->orderBy('category')
            ->orderBy('sort_order')
            ->get();

        return response()->json([
            'succes' => true,
            'donnees' => $products,
        ]);
    }

    public function storeProduct(Request $request): JsonResponse
    {
        $merchant = $this->getMerchant();

        $validated = $request->validate([
            'name' => 'required|string|max:200',
            'description' => 'nullable|string',
            'price' => 'required|numeric|min:100',
            'category' => 'nullable|string|max:100',
            'is_available' => 'boolean',
        ]);

        $product = Product::create([
            ...$validated,
            'merchant_id' => $merchant->id,
        ]);

        return response()->json([
            'succes' => true,
            'donnees' => $product,
        ], 201);
    }

    public function updateProduct(Request $request, Product $product): JsonResponse
    {
        $merchant = $this->getMerchant();

        if ($product->merchant_id !== $merchant->id) {
            return response()->json(['succes' => false, 'message' => 'Accès refusé.'], 403);
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:200',
            'description' => 'nullable|string',
            'price' => 'sometimes|required|numeric|min:100',
            'category' => 'nullable|string|max:100',
            'is_available' => 'boolean',
        ]);

        $product->update($validated);

        return response()->json([
            'succes' => true,
            'donnees' => $product,
        ]);
    }

    public function deleteProduct(Product $product): JsonResponse
    {
        $merchant = $this->getMerchant();

        if ($product->merchant_id !== $merchant->id) {
            return response()->json(['succes' => false, 'message' => 'Accès refusé.'], 403);
        }

        $product->delete();

        return response()->json([
            'succes' => true,
            'message' => 'Produit retiré du catalogue.',
        ]);
    }

    public function wallet(): JsonResponse
    {
        $merchant = $this->getMerchant();

        $wallet = Wallet::firstOrCreate(
            ['owner_type' => 'merchant', 'owner_id' => $merchant->id],
            ['balance' => 0, 'total_earned' => 0, 'total_withdrawn' => 0]
        );

        $transactions = WalletTransaction::where('wallet_id', $wallet->id)
            ->latest()
            ->paginate(20);

        $todayStart = now()->startOfDay();
        $weekStart = now()->startOfWeek();
        $monthStart = now()->startOfMonth();

        $baseQuery = Order::where('merchant_id', $merchant->id)
            ->where('status', Order::STATUS_DELIVERED);

        $todayQuery = (clone $baseQuery)->where('delivered_at', '>=', $todayStart);
        $weekQuery = (clone $baseQuery)->where('delivered_at', '>=', $weekStart);
        $monthQuery = (clone $baseQuery)->where('delivered_at', '>=', $monthStart);

        $versements = WalletTransaction::where('wallet_id', $wallet->id)
            ->whereIn('type', ['withdrawal', 'payout', 'debit'])
            ->latest()
            ->limit(20)
            ->get()
            ->map(function (WalletTransaction $tx): array {
                return [
                    'id' => $tx->reference ?: 'VER-'.$tx->id,
                    'montant' => (int) round(abs((float) $tx->amount)),
                    'date' => $tx->created_at?->format('d/m/Y') ?? '',
                    'statut' => (string) ($tx->status ?? 'completed'),
                    'description' => (string) ($tx->description ?? ''),
                ];
            })
            ->values();

        return response()->json([
            'succes' => true,
            'donnees' => [
                'wallet' => $wallet,
                'transactions' => $transactions,
                'stats' => [
                    'ca_aujourdhui' => (int) round((float) (clone $todayQuery)->sum('merchant_amount')),
                    'commandes_aujourdhui' => (clone $todayQuery)->count(),
                    'ca_semaine' => (int) round((float) (clone $weekQuery)->sum('merchant_amount')),
                    'commandes_semaine' => (clone $weekQuery)->count(),
                    'ca_mois' => (int) round((float) (clone $monthQuery)->sum('merchant_amount')),
                    'commandes_mois' => (clone $monthQuery)->count(),
                ],
                'versements_recents' => $versements,
            ],
        ]);
    }

    /**
     * Retourne le livreur prioritaire configuré pour ce commerce + la liste des livreurs éligibles de la ville.
     * Exclut strictement le profil livreur appartenant au même utilisateur (anti-auto-assignation).
     */
    public function priorityCourier(SettingsService $settingsService): JsonResponse
    {
        $merchant = $this->getMerchant();
        $config = $settingsService->get("merchant_priority_courier:{$merchant->id}", null, null);
        $priorityCourierId = is_array($config) && isset($config['courier_id']) ? (int) $config['courier_id'] : null;

        $priorityCourier = $priorityCourierId
            ? Courier::with('user:id,name,phone')
                ->whereKey($priorityCourierId)
                ->where('is_active', true)
                ->where('city_id', $merchant->city_id)
                ->where('user_id', '!=', $merchant->user_id)
                ->first()
            : null;

        $eligibleCouriers = Courier::with('user:id,name,phone')
            ->where('is_active', true)
            ->where('city_id', $merchant->city_id)
            ->where('user_id', '!=', $merchant->user_id)
            ->orderByDesc('is_online')
            ->orderByDesc('average_rating')
            ->limit(50)
            ->get()
            ->map(fn (Courier $c): array => [
                'id' => $c->id,
                'name' => $c->user->name ?? "Livreur #{$c->id}",
                'phone' => $c->user->phone ?? '',
                'vehicle_type' => $c->vehicle_type,
                'vehicle_plate' => $c->vehicle_plate,
                'is_online' => (bool) $c->is_online,
                'average_rating' => (float) ($c->average_rating ?? 0),
            ])
            ->values();

        return response()->json([
            'succes' => true,
            'donnees' => [
                'priority_courier_id' => $priorityCourier?->id,
                'priority_courier' => $priorityCourier ? [
                    'id' => $priorityCourier->id,
                    'name' => $priorityCourier->user->name ?? "Livreur #{$priorityCourier->id}",
                    'phone' => $priorityCourier->user->phone ?? '',
                    'vehicle_type' => $priorityCourier->vehicle_type,
                    'vehicle_plate' => $priorityCourier->vehicle_plate,
                    'is_online' => (bool) $priorityCourier->is_online,
                    'average_rating' => (float) ($priorityCourier->average_rating ?? 0),
                ] : null,
                'available_couriers' => $eligibleCouriers,
            ],
        ]);
    }

    /**
     * Assigne (ou retire) un livreur prioritaire pour les commandes du commerce.
     * Bloque strictement toute auto-assignation (commerçant-livreur sur sa propre boutique).
     */
    public function updatePriorityCourier(Request $request, SettingsService $settingsService): JsonResponse
    {
        $merchant = $this->getMerchant();
        $validated = $request->validate([
            'courier_id' => ['nullable', 'integer', 'exists:couriers,id'],
        ]);

        $courierId = $validated['courier_id'] ?? null;
        if ($courierId === null) {
            $settingsService->set(
                "merchant_priority_courier:{$merchant->id}",
                null,
                null,
                (int) auth()->id(),
            );

            return response()->json([
                'succes' => true,
                'message' => 'Livreur prioritaire retiré. Attribution automatique standard rétablie.',
                'donnees' => ['priority_courier_id' => null],
            ]);
        }

        /** @var Courier $courier */
        $courier = Courier::with('user:id,name,phone')->findOrFail((int) $courierId);

        if ((int) $courier->user_id === (int) $merchant->user_id) {
            ActivityLog::create([
                'user_id' => auth()->id(),
                'action' => 'merchant_self_courier_assignment_blocked',
                'entity_type' => 'merchant',
                'entity_id' => $merchant->id,
                'properties' => ['courier_id' => $courier->id],
                'ip_address' => $request->ip(),
                'severity' => 'warning',
            ]);

            return response()->json([
                'succes' => false,
                'code' => 'SELF_ASSIGNMENT_FORBIDDEN',
                'message' => 'Conflit d\'intérêts : un commerçant-livreur ne peut pas s\'auto-assigner ses propres livraisons.',
            ], 403);
        }

        if (! $courier->is_active || (int) $courier->city_id !== (int) $merchant->city_id) {
            return response()->json([
                'succes' => false,
                'message' => 'Ce livreur n\'est pas actif dans votre ville.',
            ], 422);
        }

        $settingsService->set(
            "merchant_priority_courier:{$merchant->id}",
            [
                'courier_id' => $courier->id,
                'assigned_at' => now()->toIso8601String(),
                'assigned_by' => auth()->id(),
            ],
            null,
            (int) auth()->id(),
        );

        return response()->json([
            'succes' => true,
            'message' => 'Livreur prioritaire assigné à votre commerce.',
            'donnees' => [
                'priority_courier_id' => $courier->id,
                'priority_courier' => [
                    'id' => $courier->id,
                    'name' => $courier->user->name ?? "Livreur #{$courier->id}",
                    'phone' => $courier->user->phone ?? '',
                    'vehicle_plate' => $courier->vehicle_plate,
                    'is_online' => (bool) $courier->is_online,
                ],
            ],
        ]);
    }
}
