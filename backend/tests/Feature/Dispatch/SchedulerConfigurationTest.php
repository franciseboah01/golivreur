<?php

use App\Console\Commands\SweepOrderTimeoutsCommand;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

it('liste les 3 taches planifiees via schedule:list avec withoutOverlapping et onOneServer', function () {
    $exitCode = Artisan::call('schedule:list');
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('orders:sweep-timeouts')
        ->and($output)->toContain('horizon:snapshot')
        ->and($output)->toContain('queue:prune-failed --hours=168');

    $events = collect(app(Schedule::class)->events());

    $expected = [
        'orders:sweep-timeouts' => '* * * * *',
        'horizon:snapshot' => '*/5 * * * *',
        'queue:prune-failed --hours=168' => '0 0 * * *',
    ];

    foreach ($expected as $commandFragment => $expression) {
        $event = $events->first(fn ($item): bool => str_contains((string) $item->command, $commandFragment));

        expect($event)->not->toBeNull()
            ->and($event->expression)->toBe($expression)
            ->and($event->withoutOverlapping)->toBeTrue()
            ->and($event->onOneServer)->toBeTrue();
    }
});

it('enregistre un heartbeat horodate dans le cache a chaque execution de orders:sweep-timeouts', function () {
    $cacheKey = SweepOrderTimeoutsCommand::LAST_RUN_CACHE_KEY;
    Cache::forget($cacheKey);

    $this->artisan('orders:sweep-timeouts')->assertSuccessful();

    $heartbeat = Cache::get($cacheKey);

    expect($heartbeat)->toBeString()
        ->and(Carbon::parse($heartbeat)->diffInSeconds(now()))->toBeLessThanOrEqual(5);
});

