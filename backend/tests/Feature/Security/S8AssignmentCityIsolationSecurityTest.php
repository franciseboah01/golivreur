<?php

use App\Jobs\AlerterAdminPasDelivreur;
use App\Jobs\NotifierLivreurGoShop;
use App\Models\City;
use App\Models\Courier;
use App\Models\Order;
use App\Models\User;
use App\Modules\GoShop\Services\AssignmentService;
use Illuminate\Support\Facades\Queue;

it('S8 isolation ville et statut: attribuerLivreurGoShop n\'assigne pas un livreur hors ligne/inactif d\'une autre ville avec penalty expirée', function () {
    Queue::fake();

    $cityDaloa = City::factory()->create(['name' => 'Daloa']);
    $cityBouake = City::factory()->create(['name' => 'Bouaké']);

    $driverBouake = User::factory()->create(['role' => 'driver', 'city_id' => $cityBouake->id]);
    Courier::factory()->create([
        'user_id' => $driverBouake->id,
        'city_id' => $cityBouake->id,
        'is_online' => false,
        'is_active' => false,
        'penalty_until' => now()->subHour(),
        'current_lat' => 6.8770,
        'current_lng' => -6.4502,
        'location_updated_at' => now(),
    ]);

    $orderDaloa = Order::factory()->create([
        'city_id' => $cityDaloa->id,
        'pickup_lat' => null,
        'pickup_lng' => null,
        'status' => Order::STATUS_ACCEPTED,
    ]);

    app(AssignmentService::class)->attribuerLivreurGoShop($orderDaloa);

    Queue::assertNotPushed(NotifierLivreurGoShop::class);
    Queue::assertPushed(AlerterAdminPasDelivreur::class);
});
