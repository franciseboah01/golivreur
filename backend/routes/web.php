<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'service' => 'GoLivreur API',
        'version' => '5.0',
        'status' => 'operational',
    ]);
});
