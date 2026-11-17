<?php

use App\Jobs\NotifierLivreurGoShop;
use App\Models\City;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\User;
use App\Modules\GoShop\Services\AssignmentService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;

it('configure Horizon avec 3 files separees high, default et low dans config/horizon.php', function () {
    $environments = config('horizon.environments');

    expect($environments)->toBeArray()
        ->and($environments)->toHaveKey('production')
        ->and($environments)->toHaveKey('local');

    foreach (['production', 'staging', 'local'] as $env) {
        $supervisors = $environments[$env];

        expect($supervisors)->toBeArray()
            ->and($supervisors)->toHaveKey('supervisor-high')
            ->and($supervisors)->toHaveKey('supervisor-default')
            ->and($supervisors)->toHaveKey('supervisor-low')
            ->and($supervisors['supervisor-high']['queue'] ?? null)->toBe(['high'])
            ->and($supervisors['supervisor-default']['queue'] ?? null)->toBe(['default'])
            ->and($supervisors['supervisor-low']['queue'] ?? null)->toBe(['low']);
    }
});

it('restreint la gate viewHorizon et la route /horizon aux seuls super_admin et national_tech actifs', function () {
    $city = City::factory()->create();

    $superAdmin = User::factory()->create(['role' => 'super_admin', 'is_active' => true]);
    $nationalTech = User::factory()->create(['role' => 'national_tech', 'is_active' => true]);
    $inactiveSuperAdmin = User::factory()->create(['role' => 'super_admin', 'is_active' => false]);
    $inactiveTech = User::factory()->create(['role' => 'national_tech', 'is_active' => false]);

    $deniedRoles = [
        User::factory()->create(['role' => 'dg_national', 'is_active' => true]),
        User::factory()->create(['role' => 'national_finance', 'is_active' => true]),
        User::factory()->create(['role' => 'national_support', 'is_active' => true]),
        User::factory()->create(['role' => 'city_manager', 'city_id' => $city->id, 'is_active' => true]),
        User::factory()->create(['role' => 'city_finance', 'city_id' => $city->id, 'is_active' => true]),
        User::factory()->create(['role' => 'client', 'is_active' => true]),
        User::factory()->create(['role' => 'merchant', 'is_active' => true]),
        User::factory()->create(['role' => 'driver', 'is_active' => true]),
        $inactiveSuperAdmin,
        $inactiveTech,
    ];

    expect(Gate::forUser($superAdmin)->allows('viewHorizon'))->toBeTrue()
        ->and(Gate::forUser($nationalTech)->allows('viewHorizon'))->toBeTrue();

    foreach ($deniedRoles as $user) {
        expect(Gate::forUser($user)->allows('viewHorizon'))->toBeFalse();
    }

    // Invité -> 403
    $this->get('/horizon')->assertStatus(403);

    // Rôles refusés -> 403 sur /horizon
    $this->actingAs($deniedRoles[0], 'web')->get('/horizon')->assertStatus(403);
    $this->actingAs($inactiveSuperAdmin, 'web')->get('/horizon')->assertStatus(403);

    // super_admin et national_tech actifs -> 200
    $this->actingAs($superAdmin, 'web')->get('/horizon')->assertOk();
    $this->actingAs($nationalTech, 'web')->get('/horizon')->assertOk();
});

it('planifie horizon:snapshot toutes les 5 minutes et queue:prune-failed --hours=168 chaque jour', function () {
    $events = collect(app(Schedule::class)->events());

    $snapshot = $events->first(fn ($event): bool => str_contains((string) $event->command, 'horizon:snapshot'));
    $pruneFailed = $events->first(fn ($event): bool => str_contains((string) $event->command, 'queue:prune-failed --hours=168'));

    expect($snapshot)->not->toBeNull()
        ->and($snapshot->expression)->toBe('*/5 * * * *')
        ->and($snapshot->withoutOverlapping)->toBeTrue()
        ->and($snapshot->onOneServer)->toBeTrue();

    expect($pruneFailed)->not->toBeNull()
        ->and($pruneFailed->expression)->toBe('0 0 * * *')
        ->and($pruneFailed->withoutOverlapping)->toBeTrue()
        ->and($pruneFailed->onOneServer)->toBeTrue();
});

it('dispatche NotifierLivreurGoShop sur la file high', function () {
    Queue::fake();

    $city = City::factory()->create();
    $merchant = Merchant::factory()->create(['city_id' => $city->id, 'lat' => 6.8770, 'lng' => -6.4502]);
    $driver = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    Courier::factory()->create([
        'user_id' => $driver->id,
        'city_id' => $city->id,
        'is_online' => true,
        'is_active' => true,
        'current_lat' => 6.8771,
        'current_lng' => -6.4502,
        'location_updated_at' => now(),
    ]);
    $order = Order::factory()->create([
        'city_id' => $city->id,
        'merchant_id' => $merchant->id,
        'courier_id' => null,
        'status' => Order::STATUS_ACCEPTED,
        'pickup_lat' => $merchant->lat,
        'pickup_lng' => $merchant->lng,
    ]);

    app(AssignmentService::class)->attribuerLivreurGoShop($order);

    $pushed = Queue::pushedJobs()[NotifierLivreurGoShop::class] ?? [];
    expect($pushed)->toHaveCount(1)
        ->and($pushed[0]['queue'])->toBe('high');
});
