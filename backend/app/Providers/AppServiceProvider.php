<?php

namespace App\Providers;

use App\Models\User;
use App\Policies\ProfilePolicy;
use App\Services\SmsService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Twilio\Rest\Client;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        if (! interface_exists('Tymon\JWTAuth\Contracts\JWTSubject') && interface_exists('PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject')) {
            class_alias('PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject', 'Tymon\JWTAuth\Contracts\JWTSubject');
        }
        if (! class_exists('Tymon\JWTAuth\Facades\JWTAuth') && class_exists('PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth')) {
            class_alias('PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth', 'Tymon\JWTAuth\Facades\JWTAuth');
        }

        $this->app->singleton(SmsService::class, function ($app) {
            $sid = config('services.twilio.sid');
            $token = config('services.twilio.token');
            $from = config('services.twilio.from');
            $alertEmail = config('services.admin_alert_email', config('mail.from.address', 'admin@golivreur.ci'));

            $client = ($sid && $token && class_exists('\Twilio\Rest\Client'))
                ? new Client($sid, $token)
                : null;

            return new SmsService($client, $from ?? '', $alertEmail);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(User::class, ProfilePolicy::class);

        RateLimiter::for('driver-location', function (Request $request) {
            return Limit::perMinute(12)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('client-courier-location', function (Request $request) {
            return Limit::perMinute(12)->by('client_courier_loc:'.($request->user()?->id ?: $request->ip()));
        });
    }
}
