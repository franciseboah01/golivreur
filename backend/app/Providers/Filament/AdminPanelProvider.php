<?php

namespace App\Providers\Filament;

use App\Filament\Widgets\BlockedCodesAlertWidget;
use App\Filament\Widgets\HorizonQueueWidget;
use App\Filament\Widgets\LatestOrdersWidget;
use App\Filament\Widgets\PaymentMethodsChart;
use App\Filament\Widgets\StatsOverview;
use App\Filament\Widgets\WeeklyOrdersChart;
use App\Filament\Widgets\WeeklyRevenueChart;
use App\Http\Middleware\AdminIpWhitelistMiddleware;
use App\Http\Middleware\EnsureAdminTwoFactorVerified;
use App\Services\SettingsService;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path(config('admin.path', 'admin'))
            ->login()
            ->brandLogo(fn () => view('filament.admin.logo'))
            ->brandLogoHeight('auto')
            ->favicon(function () {
                try {
                    return app(SettingsService::class)->getAppLogoUrl()
                        ?? asset('images/logo.png');
                } catch (\Throwable) {
                    return asset('images/logo.png');
                }
            })
            ->colors([
                'primary' => Color::Amber,
            ])
            ->navigationGroups([
                'Opérations GoShop',
                'Opérations GoColis',
                'Partenaires',
                'Finances',
                'Administration',
                'Paramètres & Système',
                'Autre',
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Pages\Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                StatsOverview::class,
                HorizonQueueWidget::class,
                WeeklyOrdersChart::class,
                WeeklyRevenueChart::class,
                PaymentMethodsChart::class,
                LatestOrdersWidget::class,
                BlockedCodesAlertWidget::class,
            ])
            ->middleware([
                AdminIpWhitelistMiddleware::class,
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                EnsureAdminTwoFactorVerified::class,
            ]);
    }
}
