<?php

use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'service' => 'GoLivreur API',
        'version' => '5.0',
        'status' => 'operational',
    ]);
});

Route::get('/health', [HealthController::class, 'show'])->name('health.check');
