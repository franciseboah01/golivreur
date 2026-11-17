<?php

namespace App\Providers;

use App\Models\User;
use App\Policies\UserPolicy;
use App\Services\SettingsService;
use App\Services\Sms\Contracts\SmsGateway;
use App\Services\Sms\InvalidDriverSmsGateway;
use App\Services\Sms\LogSmsGateway;
use App\Services\Sms\YellikaSmsGateway;
use App\Services\SmsService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

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

        $this->app->bind(SmsGateway::class, function ($app): SmsGateway {
            $driver = strtolower(trim((string) config('sms.driver', 'log')));

            return match ($driver) {
                'log' => $app->make(LogSmsGateway::class),
                'yellika' => $app->make(YellikaSmsGateway::class),
                default => new InvalidDriverSmsGateway($driver),
            };
        });

        $this->app->bind(SmsService::class, function ($app): SmsService {
            return new SmsService(
                gateway: $app->make(SmsGateway::class),
                alertEmail: '',
                settingsService: $app->make(SettingsService::class),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(User::class, UserPolicy::class);

        RateLimiter::for('driver-location', function (Request $request) {
            return Limit::perMinute(12)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('client-courier-location', function (Request $request) {
            return Limit::perMinute(12)->by('client_courier_loc:'.($request->user()?->id ?: $request->ip()));
        });
    }
}
