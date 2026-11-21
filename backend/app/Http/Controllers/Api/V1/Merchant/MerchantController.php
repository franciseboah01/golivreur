<?php

namespace App\Http\Controllers\Api\V1\Merchant;

use App\Exceptions\UnauthorizedCatalogFieldException;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Product;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Modules\GoShop\Services\OrderService;
use App\Services\CatalogSchemaService;
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

    /**
     * Retourne les champs du Dictionnaire cochés et ordonnés par l'Admin
     * pour le Type de Commerce du commerçant connecté.
     */
    public function catalogSchema(CatalogSchemaService $schemaService): JsonResponse
    {
        $merchant = $this->getMerchant()->load('categoryModel');

        return response()->json([
            'succes' => true,
            'donnees' => $schemaService->getSchemaForMerchant($merchant),
        ]);
    }

    public function products(CatalogSchemaService $schemaService): JsonResponse
    {
        $merchant = $this->getMerchant()->load('categoryModel');
        $schema = $schemaService->getSchemaForMerchant($merchant);

        $products = Product::where('merchant_id', $merchant->id)
            ->orderBy('category')
            ->orderBy('sort_order')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Product $product): array => $schemaService->formatProductForApi($product, $schema['fields']))
            ->values();

        return response()->json([
            'succes' => true,
            'donnees' => $products,
        ]);
    }

    public function storeProduct(Request $request, CatalogSchemaService $schemaService): JsonResponse
    {
        $merchant = $this->getMerchant()->load('categoryModel');

        try {
            $extracted = $schemaService->validateAndExtractProductPayload($merchant, $request);
        } catch (UnauthorizedCatalogFieldException $e) {
            return response()->json([
                'succes' => false,
                'code' => 'UNAUTHORIZED_CATALOG_FIELD',
                'forbidden_fields' => $e->forbiddenFields,
                'message' => $e->getMessage(),
            ], 403);
        }

        $product = Product::create([
            ...$extracted['core_data'],
            'merchant_id' => $merchant->id,
            'custom_attributes' => $extracted['custom_attributes'] !== []
                ? $extracted['custom_attributes']
                : null,
        ]);

        return response()->json([
            'succes' => true,
            'donnees' => $schemaService->formatProductForApi($product, $extracted['schema_fields']),
            'message' => 'Article ajouté au catalogue avec succès.',
        ], 201);
    }

    public function updateProduct(Request $request, Product $product, CatalogSchemaService $schemaService): JsonResponse
    {
        $merchant = $this->getMerchant()->load('categoryModel');

        if ($product->merchant_id !== $merchant->id) {
            return response()->json(['succes' => false, 'message' => 'Accès refusé.'], 403);
        }

        try {
            $extracted = $schemaService->validateAndExtractProductPayload($merchant, $request, $product);
        } catch (UnauthorizedCatalogFieldException $e) {
            return response()->json([
                'succes' => false,
                'code' => 'UNAUTHORIZED_CATALOG_FIELD',
                'forbidden_fields' => $e->forbiddenFields,
                'message' => $e->getMessage(),
            ], 403);
        }

        $updatePayload = $extracted['core_data'];
        if ($extracted['has_custom_attributes_update']) {
            $updatePayload['custom_attributes'] = $extracted['custom_attributes'] !== []
                ? $extracted['custom_attributes']
                : null;
        }

        $product->update($updatePayload);

        return response()->json([
            'succes' => true,
            'donnees' => $schemaService->formatProductForApi($product->fresh(), $extracted['schema_fields']),
            'message' => 'Article mis à jour avec succès.',
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
            ->whereIn('type', ['deposit', 'withdrawal', 'payout', 'debit', 'adjustment'])
            ->latest()
            ->limit(25)
            ->get()
            ->map(function (WalletTransaction $tx): array {
                $amount = (float) $tx->amount;
                $isCredit = $amount >= 0 && ! in_array($tx->type, ['withdrawal', 'payout', 'debit'], true);

                return [
                    'id' => $tx->reference ?: 'VER-'.$tx->id,
                    'type' => $isCredit ? 'credit' : 'debit',
                    'tx_type' => (string) $tx->type,
                    'montant' => (int) round(abs($amount)),
                    'date' => $tx->created_at?->format('d/m/Y H:i') ?? '',
                    'statut' => (string) ($tx->status ?? 'completed'),
                    'description' => (string) ($tx->description ?? ($isCredit ? 'Dépôt espèces' : 'Versement / Retrait')),
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

    /**
     * GET /api/v1/business/priority-drivers
     * Liste UNIQUEMENT les livreurs rattachés à CE commerçant (jamais de liste globale).
     */
    public function listPriorityDrivers(SettingsService $settingsService): JsonResponse
    {
        $merchant = $this->getMerchant();
        $config = $settingsService->get("merchant_priority_courier:{$merchant->id}", null, null);
        $priorityCourierId = is_array($config) && isset($config['courier_id']) ? (int) $config['courier_id'] : null;

        $drivers = Courier::with('user:id,name,phone,is_active')
            ->where('merchant_id', $merchant->id)
            ->orderByDesc('id')
            ->get()
            ->map(static fn (Courier $c): array => [
                'id' => $c->id,
                'name' => $c->user->name ?? "Livreur #{$c->id}",
                'phone' => $c->user->phone ?? '',
                'vehicle_type' => $c->vehicle_type,
                'vehicle_plate' => $c->vehicle_plate,
                'is_active' => (bool) $c->is_active,
                'is_online' => (bool) $c->is_online,
                'validation_status' => $c->validation_status ?? ($c->is_active ? 'approved' : 'pending'),
                'is_priority' => $priorityCourierId === (int) $c->id,
                'average_rating' => (float) ($c->average_rating ?? 0),
            ])
            ->values();

        return response()->json([
            'succes' => true,
            'donnees' => [
                'priority_courier_id' => $priorityCourierId,
                'drivers' => $drivers,
            ],
        ]);
    }

    /**
     * POST /api/v1/business/priority-drivers
     * Crée un livreur pré-inscrit INACTIF lié au commerçant (validation admin requise).
     */
    public function createPriorityDriver(Request $request): JsonResponse
    {
        $merchant = $this->getMerchant();

        $rawPhone = \App\Models\User::normalizePhone((string) $request->input('phone', ''));
        $request->merge(['phone' => $rawPhone]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'phone' => ['required', 'string', 'regex:/^(01|05|07|21|25|27)[0-9]{8}$/'],
            'password' => ['required', 'string', 'min:8', 'max:100'],
            'vehicle_type' => ['required', 'string', 'max:50'],
            'vehicle_plate' => ['required', 'string', 'min:3', 'max:30'],
        ], [
            'phone.regex' => 'Format ivoirien invalide : 10 chiffres attendus.',
        ]);

        $phone = (string) $validated['phone'];
        if (\App\Models\User::withTrashed()->where('phone', $phone)->exists()) {
            return response()->json([
                'succes' => false,
                'message' => 'Ce numéro de téléphone est déjà associé à un compte.',
                'errors' => ['phone' => ['Ce numéro de téléphone est déjà utilisé.']],
            ], 422);
        }

        $vehicleType = strtolower(trim((string) $validated['vehicle_type']));
        if (
            \App\Models\VehicleType::where('is_active', true)->exists()
            && ! \App\Models\VehicleType::where('slug', $vehicleType)->where('is_active', true)->exists()
        ) {
            return response()->json([
                'succes' => false,
                'message' => 'Le type de véhicule sélectionné n\'est pas disponible.',
                'errors' => ['vehicle_type' => ['Le type de véhicule sélectionné n\'est pas disponible.']],
            ], 422);
        }

        $courier = \Illuminate\Support\Facades\DB::transaction(function () use ($merchant, $validated, $phone, $vehicleType, $request): Courier {
            $user = \App\Models\User::create([
                'name' => trim((string) $validated['name']),
                'phone' => $phone,
                'password' => \Illuminate\Support\Facades\Hash::make((string) $validated['password']),
                'role' => 'driver',
                'city_id' => $merchant->city_id,
                'is_active' => false,
            ]);

            $courier = Courier::create([
                'user_id' => $user->id,
                'city_id' => $merchant->city_id,
                'merchant_id' => $merchant->id,
                'vehicle_type' => $vehicleType,
                'vehicle_plate' => trim((string) $validated['vehicle_plate']),
                'is_active' => false,
                'validation_status' => 'pending',
                'is_online' => false,
            ]);

            Wallet::firstOrCreate(
                ['owner_type' => 'courier', 'owner_id' => $courier->id],
                ['balance' => 0, 'total_earned' => 0, 'total_withdrawn' => 0],
            );

            ActivityLog::create([
                'user_id' => auth()->id(),
                'action' => 'merchant_priority_driver_created',
                'entity_type' => 'courier',
                'entity_id' => $courier->id,
                'properties' => [
                    'merchant_id' => $merchant->id,
                    'city_id' => $merchant->city_id,
                    'vehicle_type' => $vehicleType,
                ],
                'ip_address' => $request->ip(),
                'severity' => 'info',
            ]);

            return $courier->load('user');
        });

        return response()->json([
            'succes' => true,
            'message' => 'Livreur pré-inscrit en attente de validation par l\'administration GoLivreur.',
            'donnees' => [
                'id' => $courier->id,
                'name' => $courier->user->name ?? '',
                'phone' => $courier->user->phone ?? '',
                'vehicle_type' => $courier->vehicle_type,
                'vehicle_plate' => $courier->vehicle_plate,
                'is_active' => false,
                'validation_status' => 'pending',
            ],
        ], 201);
    }

    /**
     * PUT /api/v1/business/priority-drivers/{courier}/select
     * Permet au commerçant de choisir UNIQUEMENT un de SES livreurs déjà validés (autre livreur -> 404).
     */
    public function selectPriorityDriver(Courier $courier, SettingsService $settingsService): JsonResponse
    {
        $merchant = $this->getMerchant();

        if ((int) $courier->merchant_id !== (int) $merchant->id) {
            return response()->json([
                'succes' => false,
                'message' => 'Livreur introuvable dans votre liste de livreurs.',
            ], 404);
        }

        if (! $courier->is_active || ($courier->validation_status ?? 'pending') !== 'approved') {
            return response()->json([
                'succes' => false,
                'message' => 'Ce livreur est encore en attente de validation par GoLivreur.',
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
            'message' => 'Livreur prioritaire sélectionné.',
            'donnees' => [
                'priority_courier_id' => $courier->id,
            ],
        ]);
    }

    /**
     * GET /api/v1/business/orders/history?scope=today | ?week=YYYY-Www
     * Historique isolé par commerce en fuseau Africa/Abidjan (jour ou semaine ISO lundi-dimanche).
     */
    public function ordersHistory(Request $request): JsonResponse
    {
        $merchant = $this->getMerchant();
        $tz = 'Africa/Abidjan';
        $scope = strtolower(trim((string) $request->query('scope', '')));
        $weekParam = trim((string) $request->query('week', ''));

        if ($weekParam !== '' && preg_match('/^(\d{4})-W(\d{2})$/', $weekParam, $m)) {
            $year = (int) $m[1];
            $week = (int) $m[2];
            $start = \Carbon\Carbon::now($tz)->setISODate($year, $week, 1)->startOfDay();
            $end = $start->copy()->endOfWeek(\Carbon\CarbonInterface::SUNDAY);
            $mode = 'week';
            $weekLabel = sprintf('%04d-W%02d', $year, $week);
        } else {
            $now = \Carbon\Carbon::now($tz);
            if ($scope === 'today' || $weekParam === '') {
                $start = $now->copy()->startOfDay();
                $end = $now->copy()->endOfDay();
                $mode = 'today';
                $weekLabel = $now->format('o-\WW');
            } else {
                return response()->json([
                    'succes' => false,
                    'message' => 'Format de semaine invalide. Format attendu : YYYY-Www (ex: 2026-W47).',
                ], 422);
            }
        }

        $orders = Order::where('merchant_id', $merchant->id)
            ->whereBetween('created_at', [$start->copy()->utc(), $end->copy()->utc()])
            ->with(['client:id,name', 'items'])
            ->orderByDesc('created_at')
            ->get();

        $deliveredAmount = 0;
        $deliveredCount = 0;

        $formatted = $orders->map(static function (Order $order) use ($tz, &$deliveredAmount, &$deliveredCount): array {
            $outcome = match ($order->status) {
                Order::STATUS_DELIVERED => 'livrée',
                Order::STATUS_REFUSED => 'refusée',
                Order::STATUS_CANCELLED => 'annulée',
                Order::STATUS_DISPUTED => 'échec',
                default => $order->payment_status === 'failed' ? 'échec' : 'en cours',
            };

            if ($order->status === Order::STATUS_DELIVERED) {
                $deliveredCount++;
                $deliveredAmount += (int) round((float) $order->merchant_amount);
            }

            $eventTime = $order->delivered_at ?? $order->cancelled_at ?? $order->updated_at ?? $order->created_at;

            return [
                'id' => $order->id,
                'status' => $order->status,
                'outcome' => $outcome,
                'outcome_time' => $eventTime?->copy()->setTimezone($tz)->format('H:i'),
                'outcome_at' => $eventTime?->copy()->setTimezone($tz)->toIso8601String(),
                'client_name' => $order->client?->name ?? 'Client',
                'items_count' => $order->items->sum('quantity'),
                'products_total' => (int) round((float) $order->products_total),
                'delivery_fee' => (int) round((float) $order->delivery_fee),
                'merchant_amount' => (int) round((float) $order->merchant_amount),
                'payment_method' => $order->payment_method,
                'created_at' => $order->created_at?->copy()->setTimezone($tz)->toIso8601String(),
            ];
        })->values();

        $prevWeek = $start->copy()->subWeek()->format('o-\WW');
        $nextWeek = $start->copy()->addWeek()->format('o-\WW');

        return response()->json([
            'succes' => true,
            'donnees' => [
                'scope' => $mode,
                'week' => $weekLabel,
                'period_start' => $start->toDateString(),
                'period_end' => $end->toDateString(),
                'previous_week' => $prevWeek,
                'next_week' => $nextWeek,
                'orders_count' => $formatted->count(),
                'delivered_count' => $deliveredCount,
                'delivered_merchant_amount' => $deliveredAmount,
                'orders' => $formatted,
            ],
        ]);
    }

    /**
     * DELETE /api/v1/business/priority-drivers/select
     */
    public function clearPriorityDriver(SettingsService $settingsService): JsonResponse
    {
        $merchant = $this->getMerchant();
        $settingsService->set("merchant_priority_courier:{$merchant->id}", null, null, (int) auth()->id());

        return response()->json([
            'succes' => true,
            'message' => 'Livreur prioritaire retiré.',
            'donnees' => ['priority_courier_id' => null],
        ]);
    }
}
