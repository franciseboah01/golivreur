<?php

namespace App\Providers\Filament;

use App\Filament\Widgets\BlockedCodesAlertWidget;
use App\Filament\Widgets\HorizonQueueWidget;
use App\Filament\Widgets\LatestOrdersWidget;
use App\Filament\Widgets\PaymentMethodsChart;
use App\Filament\Widgets\StatsOverview;
use App\Filament\Widgets\WeeklyOrdersChart;
use App\Filament\Widgets\WeeklyRevenueChart;
use App\Services\SettingsService;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\View\View;
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
            ->path('admin')
            ->login()
            ->brandLogo(fn () => view('filament.admin.logo'))
            ->brandLogoHeight('3rem')
            ->favicon(function () {
                try {
                    return app(SettingsService::class)->getAppLogoUrl()
                        ?? asset('images/logo.png');
                } catch (\Throwable) {
                    return asset('images/logo.png');
                }
            })
            ->renderHook(
                PanelsRenderHook::AUTH_LOGIN_FORM_AFTER,
                fn (): View|string => app()->isLocal()
                    ? view('filament.admin.login-credentials')
                    : '',
            )
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
            ]);
    }
}
