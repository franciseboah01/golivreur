<?php

namespace App\Filament\Widgets;

use App\Filament\Support\FilamentCityScope;
use App\Models\WebhookLog;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Widget Horizon & Queues sur le Dashboard Filament.
 * Affiche l'état des files d'attente (jobs en attente / en échec) et des webhooks.
 */
class HorizonQueueWidget extends BaseWidget
{
    protected static ?int $sort = 2;

    protected static ?string $pollingInterval = '15s';

    public static function canView(): bool
    {
        return FilamentCityScope::user() !== null && ! FilamentCityScope::isLocal();
    }

    protected function getStats(): array
    {
        $pendingJobs = Schema::hasTable('jobs') ? DB::table('jobs')->count() : 0;
        $failedJobs = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0;
        $failedWebhooks = WebhookLog::where('status', 'failed')->orWhere('status', 'rejected')->count();
        $processedWebhooks = WebhookLog::where('status', 'processed')->whereDate('created_at', today())->count();

        return [
            Stat::make('Jobs en File (Queues)', $pendingJobs)
                ->description($pendingJobs === 0 ? 'File d\'attente fluide' : 'Traitement asynchrone en cours')
                ->descriptionIcon('heroicon-m-cpu-chip')
                ->color($pendingJobs > 50 ? 'warning' : 'success'),

            Stat::make('Jobs en Échec (Horizon)', $failedJobs)
                ->description($failedJobs === 0 ? 'Aucune erreur worker' : 'Inspection et rejeu requis')
                ->descriptionIcon('heroicon-m-exclamation-circle')
                ->color($failedJobs > 0 ? 'danger' : 'success'),

            Stat::make('Webhooks Payés Aujourd\'hui', $processedWebhooks)
                ->description("{$failedWebhooks} échec(s) de webhook")
                ->descriptionIcon('heroicon-m-arrow-path-rounded-square')
                ->color($failedWebhooks > 0 ? 'warning' : 'success'),
        ];
    }
}
