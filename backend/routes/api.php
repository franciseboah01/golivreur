<?php

use App\Http\Controllers\Api\V1\Admin\AdminController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Client\ClientController;
use App\Http\Controllers\Api\V1\DeviceTokenController;
use App\Http\Controllers\Api\V1\Driver\DriverController;
use App\Http\Controllers\Api\V1\GoColis\ParcelController;
use App\Http\Controllers\Api\V1\Merchant\MerchantController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\RatingController;
use App\Http\Controllers\Api\V1\WebhookController;
use App\Models\City;
use App\Models\VehicleType;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| API Routes — GoLivreur v5.0
| Préfixe : /api/v1/
| Auth    : JWT
|--------------------------------------------------------------------------
*/

Route::middleware('throttle:120,1')->group(function () {
    Route::get('/branding', function (SettingsService $settingsService) {
        $branding = $settingsService->getBranding();
        $logoPath = $branding['logo_path'] ?? null;
        $logoBase64 = null;
        if (is_string($logoPath) && $logoPath !== '' && Storage::disk('public')->exists($logoPath)) {
            $rawBytes = Storage::disk('public')->get($logoPath);
            if (is_string($rawBytes) && $rawBytes !== '') {
                $logoBase64 = base64_encode($rawBytes);
            }
        }

        return response()->json([
            'succes' => true,
            'donnees' => [
                ...$branding,
                'logo_base64' => $logoBase64,
            ],
        ]);
    });

    Route::get('/branding/logo', function (SettingsService $settingsService) {
        $branding = $settingsService->getBranding();
        $logoPath = $branding['logo_path'] ?? null;
        if (! is_string($logoPath) || $logoPath === '' || ! Storage::disk('public')->exists($logoPath)) {
            abort(404);
        }

        return Storage::disk('public')->response($logoPath, null, [
            'Access-Control-Allow-Origin' => '*',
            'Cache-Control' => 'no-cache, public',
        ]);
    });
});

// ─── Routes publiques throttlées (20 req/min - CDC 11.4) ───────────────────────
Route::middleware('throttle:20,1')->group(function () {
    Route::get('/health', fn () => response()->json([
        'status' => 'ok',
        'version' => '5.0',
        'timestamp' => now()->toIso8601String(),
        'service' => 'GoLivreur API',
    ]));

    // Catalogue GoShop Public
    Route::prefix('client')->group(function () {
        Route::get('city-status', [ClientController::class, 'cityStatus']);
        Route::get('payment-methods', [ClientController::class, 'paymentMethods']);
        Route::get('categories', [ClientController::class, 'categories']);
        Route::get('merchants', [ClientController::class, 'merchants']);
        Route::get('merchants/{merchant}/catalog', [ClientController::class, 'merchantCatalog']);
    });
    Route::get('categories', [ClientController::class, 'categories']);
    Route::get('vehicle-types', fn () => response()->json([
        'succes' => true,
        'donnees' => VehicleType::where('is_active', true)->orderBy('name')->get(['id', 'name', 'slug', 'description']),
    ]));
    Route::get('cities', fn () => response()->json([
        'succes' => true,
        'donnees' => City::where('is_active', true)->orderBy('name')->get([
            'id',
            'name',
            'center_lat',
            'center_lng',
            'rain_mode_active',
            'emergency_mode_active',
            'emergency_mode_message',
        ]),
    ]));
});

// ─── Authentification (60 req/min - CDC 11.4) ─────────────────────────────────
Route::prefix('auth')->middleware('throttle:60,1')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('register-driver', [AuthController::class, 'registerDriver']);
    Route::post('verify-register', [AuthController::class, 'verifierOtpInscription']);
    Route::post('login', [AuthController::class, 'login']);
    Route::post('login-password', [AuthController::class, 'loginPassword']);
    Route::post('verify-login', [AuthController::class, 'verifierOtpConnexion']);
    Route::post('refresh', [AuthController::class, 'refresh']);
    Route::post('logout', [AuthController::class, 'logout'])->middleware('auth:api');
});

// ─── Webhooks ────────────────────────────────────────────────────────────────
Route::prefix('webhooks')->group(function () {
    Route::post('payment', [WebhookController::class, 'paiement'])
        ->middleware('throttle:60,1');
});

// ─── Routes protégées par JWT ─────────────────────────────────────────────────
Route::middleware(['auth:api', 'active_account', 'single_session'])->group(function () {

    // ─── Profil unifié & mot de passe ─────────────────────────────────────────
    Route::get('profile', [ProfileController::class, 'show']);
    Route::put('profile/password', [ProfileController::class, 'updatePassword']);
    Route::get('profile/{user}', [ProfileController::class, 'showForUser']);
    Route::match(['put', 'post'], 'profile/{user}', [ProfileController::class, 'updateForUser']);

    // ─── Jeton FCM appareil ───────────────────────────────────────────────────
    Route::post('devices/token', [DeviceTokenController::class, 'store'])
        ->middleware('throttle:device-token');

    // ─── Client (GoShop + GoColis) ────────────────────────────────────────────
    Route::prefix('client')->middleware('role:client')->group(function () {
        Route::get('profile', [ProfileController::class, 'showClient']);
        Route::match(['put', 'post'], 'profile', [ProfileController::class, 'updateClient']);
        Route::get('addresses', [ClientController::class, 'addresses']);
        Route::post('addresses', [ClientController::class, 'storeAddress']);
        Route::put('addresses/{address}', [ClientController::class, 'updateAddress']);
        Route::post('addresses/{address}/default', [ClientController::class, 'setDefaultAddress']);
        Route::delete('addresses/{address}', [ClientController::class, 'destroyAddress']);
        Route::get('favorites', [ClientController::class, 'favorites']);
        Route::post('favorites/{merchant}/toggle', [ClientController::class, 'toggleFavorite']);
        Route::post('orders', [ClientController::class, 'createOrder']);
        Route::get('orders', [ClientController::class, 'orders']);
        Route::get('orders/{order}', [ClientController::class, 'showOrder']);
        Route::get('orders/{order}/confirmation-code', [ClientController::class, 'orderConfirmationCode']);
        Route::get('orders/{order}/courier-location', [ClientController::class, 'orderCourierLocation'])
            ->middleware('throttle:client-courier-location');
        Route::post('parcels', [ParcelController::class, 'store']);
        Route::get('parcels/{parcel}', [ParcelController::class, 'showClient']);
        Route::get('parcels/{parcel}/sender-code', [ParcelController::class, 'senderCode']);
        Route::get('parcels/{parcel}/courier-location', [ParcelController::class, 'courierLocation'])
            ->middleware('throttle:client-courier-location');
        Route::get('notifications', [ClientController::class, 'notifications']);
        Route::put('notifications/{notification}/read', [ClientController::class, 'markNotificationRead']);
        Route::post('ratings', [RatingController::class, 'store']);
        Route::post('ratings/merchant', [RatingController::class, 'storeMerchant']);
    });

    // ─── Merchant / Business (GoShop Business) ───────────────────────────────
    Route::prefix('business')->middleware('role:merchant')->group(function () {
        Route::get('profile', [ProfileController::class, 'showBusiness']);
        Route::match(['put', 'post'], 'profile', [ProfileController::class, 'updateBusiness'])
            ->middleware('active_merchant');
    });

    Route::prefix('merchant')->middleware('role:merchant')->group(function () {
        Route::get('profile', [ProfileController::class, 'showBusiness']);
        Route::get('status', [MerchantController::class, 'status']);

        Route::middleware('active_merchant')->group(function () {
            Route::match(['put', 'post'], 'profile', [ProfileController::class, 'updateBusiness']);
            Route::post('status', [MerchantController::class, 'toggleStatus']);
            Route::get('orders', [MerchantController::class, 'orders']);
            Route::post('orders/{order}/accept', [MerchantController::class, 'acceptOrder']);
            Route::post('orders/{order}/refuse', [MerchantController::class, 'refuseOrder']);
            Route::post('orders/{order}/ready', [MerchantController::class, 'readyOrder']);
            Route::get('products', [MerchantController::class, 'products']);
            Route::post('products', [MerchantController::class, 'storeProduct']);
            Route::put('products/{product}', [MerchantController::class, 'updateProduct']);
            Route::delete('products/{product}', [MerchantController::class, 'deleteProduct']);
        });
    });

    // ─── Driver (GoShop & GoColis Courier) ────────────────────────────────────
    Route::prefix('driver')->middleware('role:driver,courier')->group(function () {
        Route::get('profile', [ProfileController::class, 'showDriver']);
        Route::match(['put', 'post'], 'profile', [ProfileController::class, 'updateDriver']);
        Route::post('availability', [DriverController::class, 'availability']);
        Route::post('location', [DriverController::class, 'updateLocation'])
            ->middleware('throttle:driver-location');
        Route::get('courses', [DriverController::class, 'courses']);
        Route::post('orders/{order}/accept', [DriverController::class, 'acceptOrder']);
        Route::post('orders/{order}/refuse', [DriverController::class, 'refuseOrder']);
        Route::post('orders/{order}/confirm-pickup', [DriverController::class, 'confirmPickup']);
        Route::post('orders/{order}/confirm-delivery', [DriverController::class, 'confirmDelivery']);
        Route::get('parcels/{parcel}', [ParcelController::class, 'showDriver']);
        Route::post('parcels/{parcel}/accept', [DriverController::class, 'acceptParcel']);
        Route::post('parcels/{parcel}/refuse', [DriverController::class, 'refuseParcel']);
        Route::post('parcels/{parcel}/at-sender', [ParcelController::class, 'atSender']);
        Route::post('parcels/{parcel}/at-recipient', [ParcelController::class, 'atRecipient']);
        Route::post('parcels/{parcel}/photos', [ParcelController::class, 'uploadPhoto']);
        Route::get('wallet', [DriverController::class, 'wallet']);
    });

    // ─── Actions de livraison partagées / codes ──────────────────────────────
    Route::post('parcels/{parcel}/verify-sender-code', [ParcelController::class, 'verifySenderCode']);
    Route::post('parcels/{parcel}/verify-recipient-code', [ParcelController::class, 'verifyRecipientCode']);
    Route::post('orders/{order}/unlock-code', [AdminController::class, 'unlockOrderCode']);

    // ─── Administration (Filament API / Local & National) ─────────────────────
    Route::prefix('admin')->middleware(['role:admin', 'city_context'])->group(function () {
        Route::get('dashboard', [AdminController::class, 'dashboard']);
        Route::get('orders', [AdminController::class, 'orders']);
        Route::post('orders/{order}/refund', [AdminController::class, 'refund']);
        Route::post('parcels/{parcel}/refund', [AdminController::class, 'parcelRefund']);
        Route::post('parcels/{parcel}/declare-lost', [AdminController::class, 'declareParcelLost']);
        Route::post('refunds', [AdminController::class, 'requestRefund']);
        Route::post('refunds/{refund}/approve', [AdminController::class, 'approveRefund']);
        Route::post('refunds/{refund}/mark-paid', [AdminController::class, 'markRefundPaid']);
        Route::post('refunds/{refund}/reject', [AdminController::class, 'rejectRefund']);
        Route::post('cities/{city}/emergency-mode', [AdminController::class, 'emergencyMode']);
        Route::post('orders/{order}/regenerate-code', [AdminController::class, 'regenerateOrderCode']);
        Route::post('parcels/{parcel}/regenerate-sender-code', [AdminController::class, 'regenerateParcelSenderCode']);
        Route::post('parcels/{parcel}/resend-recipient-code', [AdminController::class, 'resendParcelRecipientCode']);
        Route::get('parcels/{parcel}/photos', [AdminController::class, 'parcelPhotos']);
        Route::post('webhooks/{id}/replay', [WebhookController::class, 'rejouer']);
    });
});
