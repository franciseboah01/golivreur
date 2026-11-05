<?php

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\WebhookController;
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
    Route::get('client/profile', fn () => response()->json(['succes' => true, 'donnees' => auth()->user()]));
});
