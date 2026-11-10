<?php

namespace App\Providers;

use App\Models\User;
use App\Policies\ProfilePolicy;
use Illuminate\Support\Facades\Gate;
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

        $this->app->singleton(\App\Services\SmsService::class, function ($app) {
            $sid = config('services.twilio.sid');
            $token = config('services.twilio.token');
            $from = config('services.twilio.from');
            $alertEmail = config('services.admin_alert_email', config('mail.from.address', 'admin@golivreur.ci'));

            $client = ($sid && $token && class_exists('\Twilio\Rest\Client'))
                ? new \Twilio\Rest\Client($sid, $token)
                : null;

            return new \App\Services\SmsService($client, $from ?? '', $alertEmail);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(User::class, ProfilePolicy::class);
    }
}
