<?php

namespace App\Http\Controllers\Api\V1\Client;

use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Http\Resources\MerchantCategoryResource;
use App\Http\Resources\MerchantResource;
use App\Models\City;
use App\Models\ClientAddress;
use App\Models\Merchant;
use App\Models\MerchantCategory;
use App\Models\Neighborhood;
use App\Models\Notification;
use App\Models\Order;
use App\Models\User;
use App\Modules\GoShop\Services\OrderService;
use App\Services\ConfirmationCodeService;
use App\Services\CourierLocationPollingService;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

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
            'succes' => true,
            'donnees' => MerchantCategoryResource::collection($categories),
        ]);
    }

    /**
     * Bannières promotionnelles dynamiques actives (auto-expiration stricte par plage de dates).
     */
    public function banners(Request $request, SettingsService $settingsService): JsonResponse
    {
        $cityId = $request->input('city_id', auth('api')->user()?->city_id);
        $raw = $settingsService->get('promotional_banners', $cityId !== null && $cityId !== '' ? (int) $cityId : null, []);
        $now = now();

        $activeBanners = collect(is_array($raw) ? $raw : [])
            ->filter(function (mixed $banner) use ($now): bool {
                if (! is_array($banner)) {
                    return false;
                }
                if (isset($banner['is_active']) && ! $banner['is_active']) {
                    return false;
                }
                $startsAt = ! empty($banner['starts_at']) ? Carbon::parse((string) $banner['starts_at']) : null;
                $endsAt = ! empty($banner['ends_at']) ? Carbon::parse((string) $banner['ends_at']) : null;

                if ($startsAt !== null && $now->lt($startsAt)) {
                    return false;
                }
                if ($endsAt !== null && $now->gt($endsAt)) {
                    return false;
                }

                return true;
            })
            ->sortBy(fn (array $b): int => (int) ($b['sort_order'] ?? 0))
            ->map(function (array $banner): array {
                $imagePath = isset($banner['image_path']) && is_string($banner['image_path']) ? trim($banner['image_path']) : '';
                $imageUrl = $imagePath !== ''
                    ? url('storage/'.ltrim($imagePath, '/'))
                    : (isset($banner['image_url']) && is_string($banner['image_url']) ? $banner['image_url'] : null);

                return [
                    'id' => (string) ($banner['id'] ?? ''),
                    'title' => (string) ($banner['title'] ?? ''),
                    'subtitle' => (string) ($banner['subtitle'] ?? ''),
                    'image_url' => $imageUrl,
                    'deep_link' => isset($banner['deep_link']) && is_string($banner['deep_link']) && trim($banner['deep_link']) !== ''
                        ? trim($banner['deep_link'])
                        : null,
                    'starts_at' => $banner['starts_at'] ?? null,
                    'ends_at' => $banner['ends_at'] ?? null,
                ];
            })
            ->values();

        return response()->json([
            'succes' => true,
            'donnees' => $activeBanners,
        ]);
    }

    /**
     * Liste des Commerces Partenaires actifs avec nom, emoji, logo_url, photo_url et catalogue.
     * Filtre invisible : un commerçant (même multi-rôle client) ne voit jamais son propre commerce.
     */
    public function merchants(Request $request): JsonResponse
    {
        $authUser = auth('api')->user();
        $cityId = $request->input('city_id', $authUser?->city_id);

        $merchants = Merchant::query()
            ->when($cityId !== null && $cityId !== '', fn ($q) => $q->where('city_id', (int) $cityId))
            ->when($authUser !== null, fn ($q) => $q->where('user_id', '!=', $authUser->id))
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
            'succes' => true,
            'donnees' => MerchantResource::collection($merchants),
        ]);
    }

    public function merchantCatalog(Merchant $merchant): JsonResponse
    {
        if (! $merchant->is_active) {
            return response()->json([
                'succes' => false,
                'message' => 'Commerce introuvable.',
            ], 404);
        }

        $authUser = auth('api')->user();
        if ($authUser !== null && (int) $merchant->user_id === (int) $authUser->id) {
            return response()->json([
                'succes' => false,
                'code' => 'SELF_PURCHASE_FORBIDDEN',
                'message' => 'Un commerçant ne peut pas consulter ou commander ses propres produits.',
            ], 403);
        }

        $merchant->load('categoryModel');

        $products = $merchant->products()
            ->where('is_available', true)
            ->orderBy('sort_order')
            ->get()
            ->groupBy('category');

        return response()->json([
            'succes' => true,
            'donnees' => [
                'merchant' => new MerchantResource($merchant),
                'catalog' => $products,
            ],
        ]);
    }

    public function createOrder(Request $request, OrderService $orderService): JsonResponse
    {
        $merchantId = $request->input('merchant_id');
        $merchantCityId = is_numeric($merchantId)
            ? Merchant::whereKey((int) $merchantId)->value('city_id')
            : null;
        $cityId = $merchantCityId ?? auth()->user()?->city_id;
        $activeModes = PaymentMethod::activeValues($cityId !== null ? (int) $cityId : null);

        $validated = $request->validate([
            'merchant_id' => 'required|exists:merchants,id',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'delivery_address' => 'required|string',
            'delivery_lat' => 'required|numeric|between:-90,90',
            'delivery_lng' => 'required|numeric|between:-180,180',
            'payment_method' => ['required', 'string', Rule::in($activeModes)],
            'distance_km' => 'nullable|numeric',
            'special_instructions' => 'nullable|string',
        ]);

        $order = $orderService->creerCommande(auth()->user(), $validated);

        return response()->json([
            'succes' => true,
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
            'succes' => true,
            'donnees' => $orders,
        ]);
    }

    public function showOrder(Order $order): JsonResponse
    {
        $user = auth()->user();

        if ($order->client_id !== $user->id && ! in_array($user->role, ['super_admin', 'dg_national', 'city_manager'])) {
            return response()->json(['succes' => false, 'message' => 'Accès refusé.'], 403);
        }

        if ($user->role === 'city_manager' && $order->city_id !== $user->city_id) {
            return response()->json(['succes' => false, 'message' => 'Accès refusé pour cette zone.'], 403);
        }

        $order->load(['merchant', 'courier.user', 'items.product']);

        return response()->json([
            'succes' => true,
            'donnees' => $order,
        ]);
    }

    /**
     * Lecture dédiée du code de confirmation GoShop par son client propriétaire (D-CODE-1).
     */
    public function orderConfirmationCode(Order $order, ConfirmationCodeService $codeService): JsonResponse
    {
        if ((int) $order->client_id !== (int) auth()->id()) {
            return response()->json(['succes' => false, 'message' => 'Accès refusé.'], 403);
        }

        if (! in_array($order->status, [
            Order::STATUS_ACCEPTED,
            Order::STATUS_WAITING,
            Order::STATUS_READY,
            Order::STATUS_PICKED_UP,
            Order::STATUS_IN_TRANSIT,
        ], true)) {
            return response()->json([
                'succes' => false,
                'message' => 'Le code de confirmation n\'est accessible qu\'une fois la commande acceptée et avant sa livraison.',
            ], 422);
        }

        $code = $codeService->dechiffrer($order->confirmation_code_enc);
        if ($code === null) {
            return response()->json([
                'succes' => false,
                'message' => 'code indisponible, régénérer',
            ], 409);
        }

        return response()->json([
            'succes' => true,
            'donnees' => [
                'order_id' => $order->id,
                'confirmation_code' => $code,
            ],
            'message' => 'Code de confirmation récupéré.',
        ]);
    }

    /**
     * Suivi bêta de position livreur par polling REST (D-BETA-1, 1 req / 5s).
     */
    public function orderCourierLocation(Order $order, CourierLocationPollingService $pollingService): JsonResponse
    {
        $estEnCours = in_array($order->status, [
            Order::STATUS_ACCEPTED,
            Order::STATUS_WAITING,
            Order::STATUS_READY,
            Order::STATUS_PICKED_UP,
            Order::STATUS_IN_TRANSIT,
        ], true);

        $order->loadMissing('courier');
        $uid = auth()->id();

        return $pollingService->repondrePosition(
            ownerId: (int) $order->client_id,
            authenticatedUserId: $uid !== null ? (int) $uid : null,
            scopeKey: "order:{$order->id}",
            estEnCours: $estEnCours,
            courier: $order->courier,
            forbiddenMessage: 'Accès refusé.',
            inactiveMessage: 'Le suivi livreur n\'est disponible que pour une commande en cours avec livreur assigné.',
        );
    }

    public function notifications(): JsonResponse
    {
        $notifications = Notification::where('user_id', auth()->id())
            ->latest()
            ->limit(30)
            ->get();

        return response()->json([
            'succes' => true,
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
            'succes' => true,
            'donnees' => $notification,
        ]);
    }

    /**
     * Statut temps réel de la ville (Mode Pluie, Urgence, Barème & Quartiers).
     */
    public function cityStatus(Request $request, SettingsService $settingsService): JsonResponse
    {
        $requestedCityId = $request->input('city_id', auth('api')->user()?->city_id);

        $city = $requestedCityId
            ? City::find($requestedCityId)
            : City::where('is_active', true)->orderBy('id')->first();

        if (! $city) {
            $city = City::orderBy('id')->first();
        }

        $tarifs = $settingsService->get('tarifs_goshop', $city?->id);
        $tarifsColis = $settingsService->get('tarifs_gocolis', $city?->id);
        $defaultBareme = [
            ['max_km' => 2,  'normal' => 500,  'pointe' => 700,  'pluie' => 1000],
            ['max_km' => 4,  'normal' => 800,  'pointe' => 1000, 'pluie' => 1300],
            ['max_km' => 7,  'normal' => 1200, 'pointe' => 1500, 'pluie' => 1800],
            ['max_km' => 99, 'normal' => 1500, 'pointe' => 1800, 'pluie' => 2000],
        ];
        $defaultTarifsColis = SettingsService::DEFAULTS['tarifs_gocolis'];

        $isRainMode = (bool) ($city->rain_mode_active ?? false);
        $branding = $settingsService->getBranding();

        $neighborhoods = $city
            ? Neighborhood::query()
                ->where('city_id', $city->id)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'distance_km', 'lat', 'lng'])
                ->map(fn (Neighborhood $n) => [
                    'id' => $n->id,
                    'name' => $n->name,
                    'distance_km' => $n->distance_km,
                    'lat' => $n->lat,
                    'lng' => $n->lng,
                ])
                ->values()
            : collect();

        return response()->json([
            'succes' => true,
            'donnees' => [
                'city_id' => $city?->id,
                'city_name' => $city?->name,
                'rain_mode_active' => $isRainMode,
                'rain_delay_minutes' => $isRainMode ? 15 : 0,
                'emergency_mode_active' => (bool) ($city->emergency_mode_active ?? false),
                'emergency_mode_message' => $city?->emergency_mode_message,
                'center_lat' => $city?->center_lat !== null ? (float) $city->center_lat : null,
                'center_lng' => $city?->center_lng !== null ? (float) $city->center_lng : null,
                'bareme' => $tarifs['bareme'] ?? $defaultBareme,
                'tarifs_colis' => is_array($tarifsColis) ? $tarifsColis : $defaultTarifsColis,
                'neighborhoods' => $neighborhoods,
                'app_logo_url' => $branding['logo_url'],
                'app_default_emoji' => $branding['default_emoji'],
            ],
        ]);
    }

    /**
     * Liste des modes de paiement actifs pour le client (pilotés par SettingsService).
     */
    public function paymentMethods(Request $request, SettingsService $settingsService): JsonResponse
    {
        $requestedCityId = $request->input('city_id', auth('api')->user()?->city_id);

        $city = $requestedCityId
            ? City::find($requestedCityId)
            : City::where('is_active', true)->orderBy('id')->first();

        if (! $city) {
            $city = City::orderBy('id')->first();
        }

        $cityId = $city?->id;
        $cases = PaymentMethod::activeCases($cityId, $settingsService);
        $default = PaymentMethod::defaultActive($cityId, $settingsService);

        return response()->json([
            'succes' => true,
            'donnees' => [
                'city_id' => $cityId,
                'default' => $default,
                'modes_actifs' => array_map(fn (PaymentMethod $m): string => $m->value, $cases),
                'methodes' => array_map(fn (PaymentMethod $m): array => [
                    'id' => $m->value,
                    'label' => $m->label(),
                    'short_label' => $m->shortLabel(),
                    'is_default' => $m->value === $default,
                ], $cases),
            ],
        ]);
    }

    // ─── Adresses de livraison du Client ──────────────────────────────────────

    public function addresses(): JsonResponse
    {
        /** @var User $user */
        $user = auth()->user();

        $addresses = $user->addresses()
            ->with('city')
            ->orderByDesc('is_default')
            ->latest('id')
            ->get()
            ->map(fn (ClientAddress $addr) => $this->formatAddress($addr));

        return response()->json([
            'succes' => true,
            'donnees' => $addresses,
        ]);
    }

    public function storeAddress(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = auth()->user();

        $validated = $request->validate([
            'label' => ['required', 'string', 'min:2', 'max:60'],
            'neighborhood' => ['required', 'string', 'min:2', 'max:100'],
            'address_line' => ['required', 'string', 'min:3', 'max:255'],
            'city_id' => ['nullable', 'integer', 'exists:cities,id'],
            'lat' => ['nullable', 'numeric'],
            'lng' => ['nullable', 'numeric'],
            'is_default' => ['sometimes', 'boolean'],
        ]);

        /** @var ClientAddress $address */
        $address = DB::transaction(function () use ($user, $validated): ClientAddress {
            $hasExisting = $user->addresses()->exists();
            $makeDefault = (bool) ($validated['is_default'] ?? ! $hasExisting);

            if ($makeDefault) {
                $user->addresses()->update(['is_default' => false]);
            }

            $cityId = $validated['city_id'] ?? $user->city_id;
            if (! $cityId || ! City::whereKey($cityId)->exists()) {
                $cityId = City::where('is_active', true)->value('id');
            }

            /** @var ClientAddress $addr */
            $addr = $user->addresses()->create([
                'city_id' => $cityId,
                'label' => trim($validated['label']),
                'neighborhood' => trim($validated['neighborhood']),
                'address_line' => trim($validated['address_line']),
                'lat' => $validated['lat'] ?? null,
                'lng' => $validated['lng'] ?? null,
                'is_default' => $makeDefault,
            ]);

            $this->syncUserDefaultAddress($user);

            return $addr->load('city');
        });

        return response()->json([
            'succes' => true,
            'message' => 'Adresse de livraison ajoutée avec succès.',
            'donnees' => $this->formatAddress($address),
        ], 201);
    }

    public function updateAddress(Request $request, ClientAddress $address): JsonResponse
    {
        /** @var User $user */
        $user = auth()->user();

        if ($address->user_id !== $user->id) {
            return response()->json(['succes' => false, 'message' => 'Accès refusé.'], 403);
        }

        $validated = $request->validate([
            'label' => ['sometimes', 'required', 'string', 'min:2', 'max:60'],
            'neighborhood' => ['sometimes', 'required', 'string', 'min:2', 'max:100'],
            'address_line' => ['sometimes', 'required', 'string', 'min:3', 'max:255'],
            'city_id' => ['sometimes', 'nullable', 'integer', 'exists:cities,id'],
            'lat' => ['sometimes', 'nullable', 'numeric'],
            'lng' => ['sometimes', 'nullable', 'numeric'],
            'is_default' => ['sometimes', 'boolean'],
        ]);

        $updated = DB::transaction(function () use ($user, $address, $validated) {
            if (! empty($validated['is_default'])) {
                $user->addresses()->where('id', '!=', $address->id)->update(['is_default' => false]);
            }

            $address->update([
                'label' => array_key_exists('label', $validated) ? trim($validated['label']) : $address->label,
                'neighborhood' => array_key_exists('neighborhood', $validated) ? trim($validated['neighborhood']) : $address->neighborhood,
                'address_line' => array_key_exists('address_line', $validated) ? trim($validated['address_line']) : $address->address_line,
                'city_id' => $validated['city_id'] ?? $address->city_id,
                'lat' => array_key_exists('lat', $validated) ? $validated['lat'] : $address->lat,
                'lng' => array_key_exists('lng', $validated) ? $validated['lng'] : $address->lng,
                'is_default' => array_key_exists('is_default', $validated) ? (bool) $validated['is_default'] : $address->is_default,
            ]);

            $this->syncUserDefaultAddress($user);

            return $address->fresh('city');
        });

        return response()->json([
            'succes' => true,
            'message' => 'Adresse mise à jour.',
            'donnees' => $this->formatAddress($updated),
        ]);
    }

    public function setDefaultAddress(ClientAddress $address): JsonResponse
    {
        /** @var User $user */
        $user = auth()->user();

        if ($address->user_id !== $user->id) {
            return response()->json(['succes' => false, 'message' => 'Accès refusé.'], 403);
        }

        $updated = DB::transaction(function () use ($user, $address) {
            $user->addresses()->update(['is_default' => false]);
            $address->update(['is_default' => true]);
            $this->syncUserDefaultAddress($user);

            return $address->fresh('city');
        });

        return response()->json([
            'succes' => true,
            'message' => 'Adresse par défaut mise à jour.',
            'donnees' => $this->formatAddress($updated),
        ]);
    }

    public function destroyAddress(ClientAddress $address): JsonResponse
    {
        /** @var User $user */
        $user = auth()->user();

        if ($address->user_id !== $user->id) {
            return response()->json(['succes' => false, 'message' => 'Accès refusé.'], 403);
        }

        DB::transaction(function () use ($user, $address) {
            $wasDefault = $address->is_default;
            $address->delete();

            if ($wasDefault) {
                $replacement = $user->addresses()->latest('id')->first();
                if ($replacement) {
                    $replacement->update(['is_default' => true]);
                }
            }

            $this->syncUserDefaultAddress($user);
        });

        return response()->json([
            'succes' => true,
            'message' => 'Adresse supprimée.',
        ]);
    }

    // ─── Commerces Favoris du Client ──────────────────────────────────────────

    public function favorites(): JsonResponse
    {
        /** @var User $user */
        $user = auth()->user();

        $merchants = $user->favoriteMerchants()
            ->where('is_active', true)
            ->with([
                'categoryModel',
                'products' => fn ($q) => $q->where('is_available', true)->orderBy('sort_order'),
            ])
            ->withCount('products')
            ->get();

        return response()->json([
            'succes' => true,
            'donnees' => MerchantResource::collection($merchants),
        ]);
    }

    public function toggleFavorite(Merchant $merchant): JsonResponse
    {
        if (! $merchant->is_active) {
            return response()->json([
                'succes' => false,
                'message' => 'Commerce introuvable.',
            ], 404);
        }

        /** @var User $user */
        $user = auth()->user();

        $result = $user->favoriteMerchants()->toggle($merchant->id);
        $isFavorite = count($result['attached']) > 0;
        $favoriteIds = $user->favoriteMerchants()->pluck('merchants.id')->values();

        return response()->json([
            'succes' => true,
            'message' => $isFavorite
                ? "{$merchant->name} ajouté aux favoris."
                : "{$merchant->name} retiré des favoris.",
            'donnees' => [
                'is_favorite' => $isFavorite,
                'favorite_ids' => $favoriteIds,
            ],
        ]);
    }

    private function formatAddress(ClientAddress $address): array
    {
        return [
            'id' => $address->id,
            'city_id' => $address->city_id,
            'city_name' => $address->city->name ?? '',
            'label' => $address->label,
            'neighborhood' => $address->neighborhood,
            'address_line' => $address->address_line,
            'formatted_address' => $address->formattedFullAddress(),
            'lat' => $address->lat,
            'lng' => $address->lng,
            'is_default' => (bool) $address->is_default,
        ];
    }

    private function syncUserDefaultAddress(User $user): void
    {
        $default = $user->addresses()
            ->with('city')
            ->orderByDesc('is_default')
            ->latest('id')
            ->first();

        if ($default && ! $default->is_default) {
            $default->update(['is_default' => true]);
        }

        $user->update([
            'default_address' => $default?->formattedFullAddress(),
        ]);
    }
}
