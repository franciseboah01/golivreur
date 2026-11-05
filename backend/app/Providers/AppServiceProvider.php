<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        if (!interface_exists('Tymon\JWTAuth\Contracts\JWTSubject') && interface_exists('PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject')) {
            class_alias('PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject', 'Tymon\JWTAuth\Contracts\JWTSubject');
        }
        if (!class_exists('Tymon\JWTAuth\Facades\JWTAuth') && class_exists('PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth')) {
            class_alias('PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth', 'Tymon\JWTAuth\Facades\JWTAuth');
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
