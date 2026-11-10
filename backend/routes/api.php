<?php

use App\Http\Controllers\Api\V1\Admin\AdminController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Client\ClientController;
use App\Http\Controllers\Api\V1\Driver\DriverController;
use App\Http\Controllers\Api\V1\GoColis\ParcelController;
use App\Http\Controllers\Api\V1\Merchant\MerchantController;
use App\Http\Controllers\Api\V1\RatingController;
use App\Http\Controllers\Api\V1\WebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes — GoLivreur v5.0
| Préfixe : /api/v1/
| Auth    : JWT
|--------------------------------------------------------------------------
*/

// ─── Health check (public) ────────────────────────────────────────────────────
Route::get('/health', fn () => response()->json([
    'status'    => 'ok',
    'version'   => '5.0',
    'timestamp' => now()->toIso8601String(),
    'service'   => 'GoLivreur API',
]));

// ─── Authentification (public) ───────────────────────────────────────────────
Route::prefix('auth')->group(function () {
    Route::post('register',        [AuthController::class, 'register']);
    Route::post('verify-register', [AuthController::class, 'verifierOtpInscription']);
    Route::post('login',           [AuthController::class, 'login']);
    Route::post('login-password',  [AuthController::class, 'loginPassword']);
    Route::post('verify-login',    [AuthController::class, 'verifierOtpConnexion']);
    Route::post('refresh',         [AuthController::class, 'refresh'])->middleware('auth:api');
    Route::post('logout',          [AuthController::class, 'logout'])->middleware('auth:api');
});

// ─── Catalogue GoShop Public (Accessible avec ou sans connexion) ──────────────
Route::prefix('client')->group(function () {
    Route::get('categories', [ClientController::class, 'categories']);
    Route::get('merchants', [ClientController::class, 'merchants']);
    Route::get('merchants/{merchant}/catalog', [ClientController::class, 'merchantCatalog']);
});
Route::get('categories', [ClientController::class, 'categories']);

// ─── Webhooks ────────────────────────────────────────────────────────────────
Route::prefix('webhooks')->group(function () {
    Route::post('payment', [WebhookController::class, 'paiement'])
         ->middleware('throttle:60,1');
});

// ─── Routes protégées par JWT ─────────────────────────────────────────────────
Route::middleware('auth:api')->group(function () {

    // ─── Client (GoShop + GoColis) ────────────────────────────────────────────
    Route::prefix('client')->middleware('role:client')->group(function () {
        Route::get('profile', fn () => response()->json(['succes' => true, 'donnees' => auth()->user()]));
        Route::post('orders', [ClientController::class, 'createOrder']);
        Route::get('orders', [ClientController::class, 'orders']);
        Route::get('orders/{order}', [ClientController::class, 'showOrder']);
        Route::post('parcels', [ParcelController::class, 'store']);
        Route::get('parcels/{parcel}', [ParcelController::class, 'showClient']);
        Route::get('notifications', [ClientController::class, 'notifications']);
        Route::put('notifications/{notification}/read', [ClientController::class, 'markNotificationRead']);
        Route::post('ratings', [RatingController::class, 'store']);
    });

    // ─── Merchant (GoShop Business) ──────────────────────────────────────────
    Route::prefix('merchant')->middleware('role:merchant')->group(function () {
        Route::get('status', [MerchantController::class, 'status']);
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

    // ─── Driver (GoShop & GoColis Courier) ────────────────────────────────────
    Route::prefix('driver')->middleware('role:driver')->group(function () {
        Route::post('availability', [DriverController::class, 'availability']);
        Route::post('location', [DriverController::class, 'updateLocation']);
        Route::get('courses', [DriverController::class, 'courses']);
        Route::post('orders/{order}/accept', [DriverController::class, 'acceptOrder']);
        Route::post('orders/{order}/confirm-pickup', [DriverController::class, 'confirmPickup']);
        Route::post('orders/{order}/confirm-delivery', [DriverController::class, 'confirmDelivery']);
        Route::get('parcels/{parcel}', [ParcelController::class, 'showDriver']);
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
        Route::post('cities/{city}/emergency-mode', [AdminController::class, 'emergencyMode']);
        Route::post('webhooks/{id}/replay', [WebhookController::class, 'rejouer']);
    });
});
