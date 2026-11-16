<?php

use App\Jobs\AnnulerCommandeTimeout;
use App\Jobs\NotifierLivreurGoShop;
use App\Jobs\SendPushNotification;
use App\Models\ActivityLog;
use App\Models\City;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\Notification;
use App\Models\Order;
use App\Models\User;
use App\Modules\GoShop\Services\AssignmentService;
use App\Services\SettingsService;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

it('planifie orders:sweep-timeouts toutes les minutes avec withoutOverlapping et onOneServer', function () {
    $schedule = app(Schedule::class);
    $events = collect($schedule->events());

    /** @var Event|null $event */
    $event = $events->first(
        fn (Event $item): bool => str_contains((string) $item->command, 'orders:sweep-timeouts')
    );

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->onOneServer)->toBeTrue()
        ->and(Schema::hasColumn('orders', 'last_assignment_attempt_at'))->toBeTrue();
});

it('balaye les commandes pending et accepted/ready bloquees sans toucher waiting ni doubler a l execution repetee', function () {
    Queue::fake([SendPushNotification::class, NotifierLivreurGoShop::class, AnnulerCommandeTimeout::class]);

    $city = City::factory()->create();
    $client = User::factory()->create(['role' => 'client', 'city_id' => $city->id]);
    $merchant = Merchant::factory()->create(['city_id' => $city->id]);

    $driverUser = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    $courier = Courier::factory()->create([
        'user_id' => $driverUser->id,
        'city_id' => $city->id,
        'is_online' => true,
        'is_active' => true,
        'current_lat' => 6.8770,
        'current_lng' => -6.4502,
        'location_updated_at' => now(),
    ]);

    $secondDriverUser = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    $assignedCourier = Courier::factory()->create([
        'user_id' => $secondDriverUser->id,
        'city_id' => $city->id,
        'is_online' => true,
        'is_active' => true,
    ]);

    // Attribution initiale enregistre last_assignment_attempt_at
    $probeOrder = Order::factory()->create([
        'city_id' => $city->id,
        'client_id' => $client->id,
        'merchant_id' => $merchant->id,
        'status' => Order::STATUS_ACCEPTED,
        'courier_id' => null,
        'payment_method' => 'orange_money',
        'payment_status' => 'paid',
        'pickup_lat' => null,
        'pickup_lng' => null,
    ]);
    app(AssignmentService::class)->attribuerLivreurGoShop($probeOrder);
    expect($probeOrder->fresh()->last_assignment_attempt_at)->not->toBeNull()
        ->and($probeOrder->fresh()->assignment_attempts)->toBe(1);

    // 1a. Pending cash expirée (65s >= 45s + 15s marge) -> annulée
    $pendingExpiredCash = Order::factory()->cash()->create([
        'city_id' => $city->id,
        'client_id' => $client->id,
        'merchant_id' => $merchant->id,
        'status' => Order::STATUS_PENDING,
        'created_at' => now()->subSeconds(65),
    ]);

    // 1b. Pending en ligne payée expirée (paid_at il y a 65s) -> annulée
    $pendingPaidExpired = Order::factory()->create([
        'city_id' => $city->id,
        'client_id' => $client->id,
        'merchant_id' => $merchant->id,
        'status' => Order::STATUS_PENDING,
        'payment_method' => 'orange_money',
        'payment_status' => 'paid',
        'paid_at' => now()->subSeconds(65),
        'created_at' => now()->subMinutes(5),
    ]);

    // 1c. Pending dans la marge (50s < 45s + 15s) -> laissée pending
    $pendingInMargin = Order::factory()->cash()->create([
        'city_id' => $city->id,
        'client_id' => $client->id,
        'merchant_id' => $merchant->id,
        'status' => Order::STATUS_PENDING,
        'created_at' => now()->subSeconds(50),
    ]);

    // 1d. Pending en ligne NON payée -> laissée pending (garde AnnulerCommandeTimeout)
    $pendingUnpaidOnline = Order::factory()->create([
        'city_id' => $city->id,
        'client_id' => $client->id,
        'merchant_id' => $merchant->id,
        'status' => Order::STATUS_PENDING,
        'payment_method' => 'orange_money',
        'payment_status' => 'pending',
        'paid_at' => null,
        'created_at' => now()->subMinutes(2),
    ]);

    // 2a. Accepted sans livreur, attempt=1, last_assignment_attempt_at expiré (50s >= 30s + 15s) -> relancée attempt=2
    $acceptedStale = Order::factory()->create([
        'city_id' => $city->id,
        'client_id' => $client->id,
        'merchant_id' => $merchant->id,
        'status' => Order::STATUS_ACCEPTED,
        'courier_id' => null,
        'payment_method' => 'orange_money',
        'payment_status' => 'paid',
        'assignment_attempts' => 1,
        'last_assignment_attempt_at' => now()->subSeconds(50),
        'pickup_lat' => null,
        'pickup_lng' => null,
    ]);

    // 2b. Ready sans livreur, attempt=2, expiré -> relancée attempt=3
    $readyStale = Order::factory()->create([
        'city_id' => $city->id,
        'client_id' => $client->id,
        'merchant_id' => $merchant->id,
        'status' => Order::STATUS_READY,
        'courier_id' => null,
        'payment_method' => 'orange_money',
        'payment_status' => 'paid',
        'assignment_attempts' => 2,
        'last_assignment_attempt_at' => now()->subSeconds(50),
        'pickup_lat' => null,
        'pickup_lng' => null,
    ]);

    // 2c. Accepted sans livreur mais tentative recente (35s < 30s + 15s) -> intacte
    $acceptedFresh = Order::factory()->create([
        'city_id' => $city->id,
        'client_id' => $client->id,
        'merchant_id' => $merchant->id,
        'status' => Order::STATUS_ACCEPTED,
        'courier_id' => null,
        'payment_method' => 'orange_money',
        'payment_status' => 'paid',
        'assignment_attempts' => 1,
        'last_assignment_attempt_at' => now()->subSeconds(35),
    ]);

    // 2d. Accepted déjà assignée -> intacte
    $alreadyAssigned = Order::factory()->create([
        'city_id' => $city->id,
        'client_id' => $client->id,
        'merchant_id' => $merchant->id,
        'status' => Order::STATUS_ACCEPTED,
        'courier_id' => $assignedCourier->id,
        'payment_method' => 'orange_money',
        'payment_status' => 'paid',
        'assignment_attempts' => 1,
        'last_assignment_attempt_at' => now()->subMinutes(5),
    ]);

    // 3. Waiting sans livreur -> INTACTE (jamais retouchée par le balayage)
    $waitingOrder = Order::factory()->create([
        'city_id' => $city->id,
        'client_id' => $client->id,
        'merchant_id' => $merchant->id,
        'status' => Order::STATUS_WAITING,
        'courier_id' => null,
        'payment_method' => 'orange_money',
        'payment_status' => 'paid',
        'assignment_attempts' => 2,
        'last_assignment_attempt_at' => now()->subMinutes(10),
    ]);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $exitCode = Artisan::call('orders:sweep-timeouts');

    $queries = strtolower(collect(DB::getQueryLog())->pluck('query')->implode("\n"));
    DB::disableQueryLog();

    expect($exitCode)->toBe(0)
        ->and($queries)->toContain('for update');

    // Vérifications Cas 1
    expect($pendingExpiredCash->fresh()->status)->toBe(Order::STATUS_CANCELLED)
        ->and($pendingPaidExpired->fresh()->status)->toBe(Order::STATUS_CANCELLED)
        ->and($pendingInMargin->fresh()->status)->toBe(Order::STATUS_PENDING)
        ->and($pendingUnpaidOnline->fresh()->status)->toBe(Order::STATUS_PENDING)
        ->and(Notification::where('user_id', $client->id)->where('type', 'order_refused')->count())->toBe(2)
        ->and(ActivityLog::where('action', 'order_sweep_pending_timeout_cancelled')->where('entity_id', $pendingExpiredCash->id)->count())->toBe(1)
        ->and(ActivityLog::where('action', 'order_sweep_pending_timeout_cancelled')->where('entity_id', $pendingPaidExpired->id)->count())->toBe(1);

    // Vérifications Cas 2
    expect($acceptedStale->fresh()->assignment_attempts)->toBe(2)
        ->and($readyStale->fresh()->assignment_attempts)->toBe(3)
        ->and($acceptedFresh->fresh()->assignment_attempts)->toBe(1)
        ->and($alreadyAssigned->fresh()->courier_id)->toBe($assignedCourier->id)
        ->and($alreadyAssigned->fresh()->assignment_attempts)->toBe(1)
        ->and(ActivityLog::where('action', 'order_sweep_assignment_retried')->where('entity_id', $acceptedStale->id)->count())->toBe(1)
        ->and(ActivityLog::where('action', 'order_sweep_assignment_retried')->where('entity_id', $readyStale->id)->count())->toBe(1);

    // Vérification Cas 3 ('waiting' intact)
    expect($waitingOrder->fresh()->status)->toBe(Order::STATUS_WAITING)
        ->and($waitingOrder->fresh()->assignment_attempts)->toBe(2);

    // Idempotence stricte : une 2e exécution immédiate ne double rien
    Artisan::call('orders:sweep-timeouts');

    expect($acceptedStale->fresh()->assignment_attempts)->toBe(2)
        ->and($readyStale->fresh()->assignment_attempts)->toBe(3)
        ->and(Notification::where('user_id', $client->id)->where('type', 'order_refused')->count())->toBe(2)
        ->and(ActivityLog::where('action', 'order_sweep_pending_timeout_cancelled')->count())->toBe(2)
        ->and(ActivityLog::where('action', 'order_sweep_assignment_retried')->count())->toBe(2);
});

it('lit dynamiquement les delais et marges depuis SettingsService sans constantes hardcodees', function () {
    Queue::fake([SendPushNotification::class, NotifierLivreurGoShop::class]);

    $city = City::factory()->create();
    $client = User::factory()->create(['role' => 'client', 'city_id' => $city->id]);
    $merchant = Merchant::factory()->create(['city_id' => $city->id]);

    $driverUser = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    Courier::factory()->create([
        'user_id' => $driverUser->id,
        'city_id' => $city->id,
        'is_online' => true,
        'is_active' => true,
        'current_lat' => 6.8770,
        'current_lng' => -6.4502,
        'location_updated_at' => now(),
    ]);

    app(SettingsService::class)->set('delais_regles', [
        'acceptation_commerce_secondes' => 90,
        'marge_balayage_commerce_secondes' => 20, // Total 110s
        'acceptation_livreur_secondes' => 50,
        'marge_balayage_livreur_secondes' => 20, // Total 70s
    ], $city->id);

    $pendingNotDue = Order::factory()->cash()->create([
        'city_id' => $city->id,
        'client_id' => $client->id,
        'merchant_id' => $merchant->id,
        'status' => Order::STATUS_PENDING,
        'created_at' => now()->subSeconds(95), // > 90s mais < 110s
    ]);

    $pendingDue = Order::factory()->cash()->create([
        'city_id' => $city->id,
        'client_id' => $client->id,
        'merchant_id' => $merchant->id,
        'status' => Order::STATUS_PENDING,
        'created_at' => now()->subSeconds(115), // >= 110s
    ]);

    $acceptedNotDue = Order::factory()->create([
        'city_id' => $city->id,
        'client_id' => $client->id,
        'merchant_id' => $merchant->id,
        'status' => Order::STATUS_ACCEPTED,
        'courier_id' => null,
        'payment_method' => 'orange_money',
        'payment_status' => 'paid',
        'assignment_attempts' => 1,
        'last_assignment_attempt_at' => now()->subSeconds(55), // > 50s mais < 70s
    ]);

    $acceptedDue = Order::factory()->create([
        'city_id' => $city->id,
        'client_id' => $client->id,
        'merchant_id' => $merchant->id,
        'status' => Order::STATUS_ACCEPTED,
        'courier_id' => null,
        'payment_method' => 'orange_money',
        'payment_status' => 'paid',
        'assignment_attempts' => 1,
        'last_assignment_attempt_at' => now()->subSeconds(75), // >= 70s
        'pickup_lat' => null,
        'pickup_lng' => null,
    ]);

    Artisan::call('orders:sweep-timeouts');

    expect($pendingNotDue->fresh()->status)->toBe(Order::STATUS_PENDING)
        ->and($pendingDue->fresh()->status)->toBe(Order::STATUS_CANCELLED)
        ->and($acceptedNotDue->fresh()->assignment_attempts)->toBe(1)
        ->and($acceptedDue->fresh()->assignment_attempts)->toBe(2);
});

