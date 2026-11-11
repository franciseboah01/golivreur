<?php

namespace App\Http\Controllers\Api\V1\Merchant;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Product;
use App\Modules\GoShop\Services\OrderService;
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
}
