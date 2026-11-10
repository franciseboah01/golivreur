<?php

namespace App\Http\Controllers\Api\V1\Client;

use App\Http\Controllers\Controller;
use App\Http\Resources\MerchantCategoryResource;
use App\Http\Resources\MerchantResource;
use App\Models\{Merchant, MerchantCategory, Notification, Order};
use App\Modules\GoShop\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClientController extends Controller
{
    /**
     * Liste des Catégories d'Activité actives avec emoji et logo depuis la base de données.
     */
    public function categories(): JsonResponse
    {
        $categories = MerchantCategory::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return response()->json([
            'succes'  => true,
            'donnees' => MerchantCategoryResource::collection($categories),
        ]);
    }

    /**
     * Liste des Commerces Partenaires actifs avec nom, emoji, logo_url, photo_url et catalogue.
     */
    public function merchants(Request $request): JsonResponse
    {
        $cityId = $request->input('city_id', auth()->user()?->city_id ?? 1);

        $merchants = Merchant::query()
            ->when($cityId, fn ($q) => $q->where('city_id', $cityId))
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->input('category')))
            ->where('is_active', true)
            ->with([
                'categoryModel',
                'products' => fn ($q) => $q->where('is_available', true)->orderBy('sort_order'),
            ])
            ->withCount('products')
            ->orderByDesc('is_open')
            ->orderBy('name')
            ->get();

        return response()->json([
            'succes'  => true,
            'donnees' => MerchantResource::collection($merchants),
        ]);
    }

    public function merchantCatalog(Merchant $merchant): JsonResponse
    {
        $merchant->load('categoryModel');

        $products = $merchant->products()
            ->where('is_available', true)
            ->orderBy('sort_order')
            ->get()
            ->groupBy('category');

        return response()->json([
            'succes'  => true,
            'donnees' => [
                'merchant' => new MerchantResource($merchant),
                'catalog'  => $products,
            ],
        ]);
    }

    public function createOrder(Request $request, OrderService $orderService): JsonResponse
    {
        $validated = $request->validate([
            'merchant_id'          => 'required|exists:merchants,id',
            'items'                => 'required|array|min:1',
            'items.*.product_id'   => 'required|exists:products,id',
            'items.*.quantity'     => 'required|integer|min:1',
            'delivery_address'     => 'required|string',
            'delivery_lat'         => 'nullable|numeric',
            'delivery_lng'         => 'nullable|numeric',
            'payment_method'       => 'required|in:cash,orange_money,wave,mtn,moov',
            'distance_km'          => 'required|numeric|min:0.1',
            'special_instructions' => 'nullable|string',
        ]);

        $order = $orderService->creerCommande(auth()->user(), $validated);

        return response()->json([
            'succes'  => true,
            'donnees' => $order,
            'message' => 'Commande passée avec succès.',
        ], 201);
    }

    public function orders(): JsonResponse
    {
        $orders = Order::where('client_id', auth()->id())
            ->with(['merchant', 'courier', 'items.product'])
            ->latest()
            ->paginate(15);

        return response()->json([
            'succes'  => true,
            'donnees' => $orders,
        ]);
    }

    public function showOrder(Order $order): JsonResponse
    {
        $user = auth()->user();

        if ($order->client_id !== $user->id && !in_array($user->role, ['super_admin', 'dg_national', 'city_manager'])) {
            return response()->json(['succes' => false, 'message' => 'Accès refusé.'], 403);
        }

        if ($user->role === 'city_manager' && $order->city_id !== $user->city_id) {
            return response()->json(['succes' => false, 'message' => 'Accès refusé pour cette zone.'], 403);
        }

        $order->load(['merchant', 'courier.user', 'items.product']);

        return response()->json([
            'succes'  => true,
            'donnees' => $order,
        ]);
    }

    public function notifications(): JsonResponse
    {
        $notifications = Notification::where('user_id', auth()->id())
            ->latest()
            ->limit(30)
            ->get();

        return response()->json([
            'succes'  => true,
            'donnees' => $notifications,
        ]);
    }

    public function markNotificationRead(Notification $notification): JsonResponse
    {
        if ($notification->user_id !== auth()->id()) {
            return response()->json(['succes' => false, 'message' => 'Accès refusé.'], 403);
        }

        $notification->update([
            'is_read' => true,
            'read_at' => now(),
        ]);

        return response()->json([
            'succes'  => true,
            'donnees' => $notification,
        ]);
    }
}
