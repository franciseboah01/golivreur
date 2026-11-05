<?php

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\{WebhookController};
use App\Http\Controllers\Api\V1\Client\{OrderController as ClientOrderController, ParcelController as ClientParcelController, NotificationController, ProfileController as ClientProfileController};
use App\Http\Controllers\Api\V1\Merchant\{OrderController as MerchantOrderController, ProductController, ProfileController as MerchantProfileController};
use App\Http\Controllers\Api\V1\Driver\{CourseController, LocationController, ProfileController as DriverProfileController};
use App\Http\Controllers\Api\V1\Admin\{CityController, DashboardController, OrderController as AdminOrderController, ParcelController as AdminParcelController, UserController, WebhookAdminController};
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes — GoLivreur v5.0
| Préfixe : /api/v1/
| Auth    : JWT (tymon/jwt-auth)
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
    Route::post('verify-login',    [AuthController::class, 'verifierOtpConnexion']);
    Route::post('refresh',         [AuthController::class, 'refresh'])->middleware('auth:api');
    Route::post('logout',          [AuthController::class, 'logout'])->middleware('auth:api');
});

// ─── Webhooks (public, HMAC validé dans le controller) ───────────────────────
Route::prefix('webhooks')->group(function () {
    Route::post('payment', [WebhookController::class, 'paiement'])
         ->middleware('throttle:60,1'); // 60 req/min max
});

// ─── Routes protégées par JWT ─────────────────────────────────────────────────
Route::middleware('auth:api')->group(function () {

    // ── Codes de confirmation (partagés GoShop + GoColis) ──────────────────
    Route::post('orders/{order}/verify-code',         [ClientOrderController::class, 'verifierCode']);
    Route::post('orders/{order}/unlock-code',         [AdminOrderController::class, 'debloquerCode'])
         ->middleware('role:city_support,city_manager,national_support,national_dg,super_admin');
    Route::post('parcels/{parcel}/verify-sender-code',   [CourseController::class, 'validerCodeExpediteur']);
    Route::post('parcels/{parcel}/verify-recipient-code',[CourseController::class, 'validerCodeDestinataire']);
    Route::post('parcels/{parcel}/unlock-sender-code',   [AdminParcelController::class, 'debloquerCodeExpediteur'])
         ->middleware('role:city_support,city_manager,national_support,national_dg,super_admin');
    Route::post('parcels/{parcel}/unlock-recipient-code',[AdminParcelController::class, 'debloquerCodeDestinataire'])
         ->middleware('role:city_support,city_manager,national_support,national_dg,super_admin');

    // ── GPS Livreur ───────────────────────────────────────────────────────
    Route::post('courier/location', [LocationController::class, 'mettreAJour'])
         ->middleware(['role:driver', 'throttle:gps']);

    // ──────────────────────────────────────────────────────────────────────
    // CLIENT — GoLivreur Client app
    // ──────────────────────────────────────────────────────────────────────
    Route::prefix('client')->middleware('role:client')->group(function () {

        // Profil
        Route::get('profile',    [ClientProfileController::class, 'show']);
        Route::put('profile',    [ClientProfileController::class, 'update']);
        Route::post('addresses', [ClientProfileController::class, 'ajouterAdresse']);
        Route::delete('addresses/{id}', [ClientProfileController::class, 'supprimerAdresse']);

        // GoShop — Commandes
        Route::get('orders',              [ClientOrderController::class, 'index']);
        Route::post('orders',             [ClientOrderController::class, 'store']);
        Route::get('orders/{order}',      [ClientOrderController::class, 'show']);
        Route::post('orders/{order}/rate',[ClientOrderController::class, 'noter']);

        // GoShop — Commerces
        Route::get('merchants',            [\App\Http\Controllers\Api\V1\Client\MerchantController::class, 'index']);
        Route::get('merchants/{merchant}', [\App\Http\Controllers\Api\V1\Client\MerchantController::class, 'show']);

        // GoColis — Expéditions
        Route::get('parcels',              [ClientParcelController::class, 'index']);
        Route::post('parcels',             [ClientParcelController::class, 'store']);
        Route::get('parcels/{parcel}',     [ClientParcelController::class, 'show']);
        Route::post('parcels/{parcel}/rate',[ClientParcelController::class, 'noter']);

        // Notifications in-app (30 dernières)
        Route::get('notifications',                        [NotificationController::class, 'index']);
        Route::post('notifications/{id}/read',             [NotificationController::class, 'marquerLue']);
        Route::post('notifications/read-all',              [NotificationController::class, 'toutMarquerLu']);
        Route::get('notifications/unread-count',           [NotificationController::class, 'compteurNonLu']);
    });

    // ──────────────────────────────────────────────────────────────────────
    // MERCHANT — GoLivreur Business app
    // ──────────────────────────────────────────────────────────────────────
    Route::prefix('merchant')->middleware('role:merchant')->group(function () {

        // Profil commerce
        Route::get('profile',  [MerchantProfileController::class, 'show']);
        Route::put('profile',  [MerchantProfileController::class, 'update']);
        Route::post('profile/open',  [MerchantProfileController::class, 'ouvrir']);
        Route::post('profile/close', [MerchantProfileController::class, 'fermer']);

        // Commandes GoShop
        Route::get('orders',                      [MerchantOrderController::class, 'index']);
        Route::get('orders/{order}',              [MerchantOrderController::class, 'show']);
        Route::post('orders/{order}/accept',      [MerchantOrderController::class, 'accepter']);
        Route::post('orders/{order}/refuse',      [MerchantOrderController::class, 'refuser']);
        Route::post('orders/{order}/ready',       [MerchantOrderController::class, 'signalerPret']);

        // Catalogue produits
        Route::get('products',              [ProductController::class, 'index']);
        Route::post('products',             [ProductController::class, 'store']);
        Route::put('products/{product}',    [ProductController::class, 'update']);
        Route::delete('products/{product}', [ProductController::class, 'destroy']);
        Route::post('products/{product}/toggle-availability', [ProductController::class, 'toggleDisponibilite']);
    });

    // ──────────────────────────────────────────────────────────────────────
    // DRIVER — GoLivreur Driver app
    // ──────────────────────────────────────────────────────────────────────
    Route::prefix('driver')->middleware('role:driver')->group(function () {

        // Profil livreur
        Route::get('profile',       [DriverProfileController::class, 'show']);
        Route::put('profile',       [DriverProfileController::class, 'update']);
        Route::post('profile/online',  [DriverProfileController::class, 'passerEnLigne']);
        Route::post('profile/offline', [DriverProfileController::class, 'passerHorsLigne']);

        // Courses GoShop
        Route::get('orders',                          [CourseController::class, 'indexGoShop']);
        Route::get('orders/{order}',                  [CourseController::class, 'showGoShop']);
        Route::post('orders/{order}/accept',          [CourseController::class, 'accepterGoShop']);
        Route::post('orders/{order}/refuse',          [CourseController::class, 'refuserGoShop']);
        Route::post('orders/{order}/pickup',          [CourseController::class, 'ramasserGoShop']);
        Route::post('orders/{order}/arrive',          [CourseController::class, 'arriverClientGoShop']);

        // Courses GoColis
        Route::get('parcels',                              [CourseController::class, 'indexGoColis']);
        Route::get('parcels/{parcel}',                     [CourseController::class, 'showGoColis']);
        Route::post('parcels/{parcel}/accept',             [CourseController::class, 'accepterGoColis']);
        Route::post('parcels/{parcel}/refuse',             [CourseController::class, 'refuserGoColis']);
        Route::post('parcels/{parcel}/at-sender',          [CourseController::class, 'arriverExpediteur']);
        Route::post('parcels/{parcel}/photo',              [CourseController::class, 'prendrePhoto']);
        Route::post('parcels/{parcel}/at-recipient',       [CourseController::class, 'arriverDestinataire']);

        // Wallet livreur
        Route::get('wallet',          [\App\Http\Controllers\Api\V1\Driver\WalletController::class, 'show']);
        Route::get('wallet/history',  [\App\Http\Controllers\Api\V1\Driver\WalletController::class, 'historique']);
    });

    // ──────────────────────────────────────────────────────────────────────
    // ADMIN — Interface Filament + API admin
    // ──────────────────────────────────────────────────────────────────────
    Route::prefix('admin')
         ->middleware('role:super_admin,national_dg,national_finance,national_tech,national_support,city_manager,city_finance,city_support,city_agent')
         ->middleware('city.context') // Filtre automatique par city_id
         ->group(function () {

        // Dashboard
        Route::get('dashboard', [DashboardController::class, 'index']);

        // Gestion des villes
        Route::get('cities',                                      [CityController::class, 'index']);
        Route::post('cities/{city}/rain-mode',                    [CityController::class, 'toggleModePluie'])
             ->middleware('role:super_admin,national_dg,national_tech,city_manager');
        Route::post('cities/{city}/emergency-mode',              [CityController::class, 'toggleModeUrgence'])
             ->middleware('role:super_admin,national_dg');

        // Gestion des utilisateurs
        Route::get('users',              [UserController::class, 'index']);
        Route::get('users/{user}',       [UserController::class, 'show']);
        Route::post('users/{user}/activate',   [UserController::class, 'activer'])
             ->middleware('role:super_admin,national_dg,city_manager,city_support,city_agent');
        Route::post('users/{user}/deactivate', [UserController::class, 'desactiver'])
             ->middleware('role:super_admin,national_dg,city_manager');

        // Commandes GoShop (admin)
        Route::get('orders',              [AdminOrderController::class, 'index']);
        Route::get('orders/{order}',      [AdminOrderController::class, 'show']);
        Route::post('orders/{order}/cancel',  [AdminOrderController::class, 'annuler'])
             ->middleware('role:super_admin,national_dg,city_manager,city_support');
        Route::post('orders/{order}/refund',  [AdminOrderController::class, 'rembourser']);
        Route::post('orders/{order}/reassign',[AdminOrderController::class, 'reassigner'])
             ->middleware('role:super_admin,national_dg,city_manager');

        // Expéditions GoColis (admin)
        Route::get('parcels',              [AdminParcelController::class, 'index']);
        Route::get('parcels/{parcel}',     [AdminParcelController::class, 'show']);
        Route::post('parcels/{parcel}/refund', [AdminParcelController::class, 'rembourser']);

        // Webhooks (admin)
        Route::get('webhooks',              [WebhookAdminController::class, 'index'])
             ->middleware('role:super_admin,national_tech,national_dg');
        Route::post('webhooks/{id}/replay', [WebhookController::class, 'rejouer'])
             ->middleware('role:super_admin,national_tech');

        // Logs d'audit
        Route::get('activity-logs', [\App\Http\Controllers\Api\V1\Admin\ActivityLogController::class, 'index'])
             ->middleware('role:super_admin,national_dg,national_tech');
    });
});
