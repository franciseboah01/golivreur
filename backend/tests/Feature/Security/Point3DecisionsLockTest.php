<?php

use App\Models\City;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\User;
use App\Services\SettingsService;

it('Point 3 verrouille les 4 décisions D-LIVREUR-1, D-VILLE-1, D-ROLE-1, D-MERCHANT-1 dans DECISIONS.md, audit/balayage-autorisations.md et en API', function () {
    $rootPath = dirname(base_path());

    $decisions = file_get_contents($rootPath.'/DECISIONS.md');
    expect($decisions)->not->toBeFalse()
        ->and($decisions)->toContain('D-LIVREUR-1')
        ->and($decisions)->toContain('D-VILLE-1')
        ->and($decisions)->toContain('D-ROLE-1')
        ->and($decisions)->toContain('D-MERCHANT-1');

    $audit = file_get_contents($rootPath.'/audit/balayage-autorisations.md');
    expect($audit)->not->toBeFalse()
        ->and($audit)->toContain('traitée')
        ->and($audit)->toContain('traitée par le Prompt 3 (argent)')
        ->and($audit)->not->toContain('**DÉCISION REQUISE**');

    // D-LIVREUR-1 : valeur par défaut paramétrée et blocage de la 2e course active
    $city = City::factory()->create();
    expect(app(SettingsService::class)->get('courses.max_actives'))->toBe(1);

    $courierUser = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    $courier = Courier::factory()->create([
        'user_id' => $courierUser->id,
        'city_id' => $city->id,
        'is_active' => true,
        'is_online' => true,
    ]);
    Order::factory()->create([
        'city_id' => $city->id,
        'courier_id' => $courier->id,
        'status' => Order::STATUS_ACCEPTED,
    ]);
    $secondOrder = Order::factory()->create([
        'city_id' => $city->id,
        'courier_id' => null,
        'status' => Order::STATUS_READY,
    ]);
    $this->actingAs($courierUser, 'api')
        ->postJson("/api/v1/driver/orders/{$secondOrder->id}/accept")
        ->assertStatus(409);

    // D-VILLE-1 : refus explicite 403 field_admin_only d'auto-changement de ville via /profile (Lot 9A Point 1)
    $otherCity = City::factory()->create();
    $clientUser = User::factory()->create(['role' => 'client', 'city_id' => $city->id]);
    $this->actingAs($clientUser, 'api')
        ->putJson('/api/v1/client/profile', ['city_id' => $otherCity->id])
        ->assertStatus(403)
        ->assertJsonPath('code', 'field_admin_only');

    // D-ROLE-1 : refus 403 de city_agent sur /admin/orders et /admin/dashboard
    $cityAgent = User::factory()->create(['role' => 'city_agent', 'city_id' => $city->id]);
    $this->actingAs($cityAgent, 'api')->getJson('/api/v1/admin/orders')->assertStatus(403);
    $this->actingAs($cityAgent, 'api')->getJson('/api/v1/admin/dashboard')->assertStatus(403);

    // D-MERCHANT-1 : lecture seule profile/status maintenue, opérations merchant/* bloquées 403 si !is_active
    $merchantUser = User::factory()->create(['role' => 'merchant', 'city_id' => $city->id]);
    Merchant::factory()->create([
        'user_id' => $merchantUser->id,
        'city_id' => $city->id,
        'is_active' => false,
    ]);
    $this->actingAs($merchantUser, 'api')->getJson('/api/v1/merchant/profile')->assertOk();
    $this->actingAs($merchantUser, 'api')->getJson('/api/v1/merchant/status')->assertOk();
    $this->actingAs($merchantUser, 'api')->getJson('/api/v1/merchant/orders')->assertStatus(403);
    $this->actingAs($merchantUser, 'api')->postJson('/api/v1/merchant/status', ['is_open' => true])->assertStatus(403);
});
