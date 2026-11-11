<?php

use App\Models\ActivityLog;
use App\Models\City;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Parcel;
use App\Models\Refund;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\RefundService;
use App\Services\SettingsService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('2.1 plafonds depuis SettingsService et frontières exactes 1999/2000/2001/10000/10001/50000/50001 par rôle', function (string $role, int $montant, int $expectedStatus, ?string $expectedRequiredRole) {
    $city = City::factory()->create();
    $admin = User::factory()->create(['role' => $role, 'city_id' => $role === 'super_admin' ? null : $city->id]);
    $client = User::factory()->create(['role' => 'client', 'city_id' => $city->id, 'phone' => '0707070707']);
    $merchant = Merchant::factory()->create(['city_id' => $city->id]);

    $defaults = SettingsService::DEFAULTS['remboursements']['plafonds'];
    expect($defaults['city_support'])->toBe(2000)
        ->and($defaults['city_finance'])->toBe(10000)
        ->and($defaults['city_manager'])->toBe(50000)
        ->and($defaults['super_admin'])->toBeNull();

    $order = Order::factory()->create([
        'city_id' => $city->id,
        'client_id' => $client->id,
        'merchant_id' => $merchant->id,
        'status' => Order::STATUS_DELIVERED,
        'payment_method' => 'orange_money',
        'payment_status' => 'paid',
        'products_total' => 90000,
        'delivery_fee' => 2000,
    ]);

    $res = $this->actingAs($admin, 'api')
        ->postJson("/api/v1/admin/orders/{$order->id}/refund", [
            'montant' => $montant,
            'raison' => "Test frontière {$role} {$montant}",
        ])
        ->assertStatus($expectedStatus);

    if ($expectedRequiredRole !== null) {
        expect($res->json('message'))->toContain($expectedRequiredRole);
    }
})->with([
    'city_support 1999 OK' => ['city_support', 1999, 200, null],
    'city_support 2000 OK' => ['city_support', 2000, 200, null],
    'city_support 2001 403' => ['city_support', 2001, 403, 'city_finance'],
    'city_finance 2000 OK' => ['city_finance', 2000, 200, null],
    'city_finance 2001 OK' => ['city_finance', 2001, 200, null],
    'city_finance 10000 OK' => ['city_finance', 10000, 200, null],
    'city_finance 10001 403' => ['city_finance', 10001, 403, 'city_manager'],
    'city_manager 10000 OK' => ['city_manager', 10000, 200, null],
    'city_manager 10001 OK' => ['city_manager', 10001, 200, null],
    'city_manager 50000 OK' => ['city_manager', 50000, 200, null],
    'city_manager 50001 403' => ['city_manager', 50001, 403, 'super_admin'],
    'super_admin 50000 OK' => ['super_admin', 50000, 200, null],
    'super_admin 50001 OK' => ['super_admin', 50001, 200, null],
    'city_agent 1999 403' => ['city_agent', 1999, 403, null],
]);

it('2.1 source unique settings: modifier le plafond dans settings s\'applique immédiatement à RefundService et API', function () {
    $city = City::factory()->create();
    $support = User::factory()->create(['role' => 'city_support', 'city_id' => $city->id]);
    $order = Order::factory()->create([
        'city_id' => $city->id,
        'status' => Order::STATUS_DELIVERED,
        'payment_status' => 'paid',
        'products_total' => 10000,
        'delivery_fee' => 1000,
    ]);

    app(SettingsService::class)->set('remboursements', [
        'plafonds' => [
            'city_support' => 3500,
            'city_finance' => 10000,
            'city_manager' => 50000,
            'national_support' => 2000,
            'national_finance' => 50000,
            'super_admin' => null,
            'dg_national' => null,
        ],
    ], $city->id);

    expect(app(RefundService::class)->plafondPourRole('city_support', $city->id))->toBe(3500);

    $this->actingAs($support, 'api')
        ->postJson("/api/v1/admin/orders/{$order->id}/refund", [
            'montant' => 3000,
            'raison' => 'Plafond dynamique relevé',
        ])
        ->assertOk();
});

it('2.2 table refunds : contraintes CHECK cible unique, client sans wallet sans 500, et double remboursement séquentiel & concurrent bloqué', function () {
    expect(Schema::hasTable('refunds'))->toBeTrue();

    $city = City::factory()->create();
    $finance = User::factory()->create(['role' => 'city_finance', 'city_id' => $city->id]);
    $client = User::factory()->create(['role' => 'client', 'city_id' => $city->id]);
    $driver = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    $courier = Courier::factory()->create(['user_id' => $driver->id, 'city_id' => $city->id]);

    $order = Order::factory()->create([
        'city_id' => $city->id,
        'client_id' => $client->id,
        'courier_id' => $courier->id,
        'status' => Order::STATUS_DELIVERED,
        'payment_status' => 'paid',
        'products_total' => 5000,
        'delivery_fee' => 1000,
    ]);

    // Contrainte CHECK : impossible d'avoir ni order_id ni parcel_id
    expect(fn () => DB::transaction(fn () => DB::table('refunds')->insert([
        'city_id' => $city->id,
        'order_id' => null,
        'parcel_id' => null,
        'client_id' => $client->id,
        'amount' => 1000,
        'reason' => 'Invalide',
        'status' => 'requested',
        'cost_bearer' => 'platform',
        'created_at' => now(),
        'updated_at' => now(),
    ])))->toThrow(QueryException::class);

    // 1er remboursement -> 200, aucun wallet client ou livreur créé/crédité
    $this->actingAs($finance, 'api')
        ->postJson("/api/v1/admin/orders/{$order->id}/refund", [
            'montant' => 3000,
            'raison' => 'Plat froid',
        ])
        ->assertOk()
        ->assertJsonPath('succes', true);

    expect(Refund::where('order_id', $order->id)->count())->toBe(1)
        ->and(Wallet::count())->toBe(0)
        ->and(WalletTransaction::where('type', 'refund')->count())->toBe(0);

    // 2e remboursement séquentiel -> 409
    $this->actingAs($finance, 'api')
        ->postJson("/api/v1/admin/orders/{$order->id}/refund", [
            'montant' => 3000,
            'raison' => 'Doublon',
        ])
        ->assertStatus(409);

    expect(Refund::where('order_id', $order->id)->count())->toBe(1);

    // Remboursement concurrent simulé sur une 2e commande -> contrainte UNIQUE protège
    $order2 = Order::factory()->create([
        'city_id' => $city->id,
        'client_id' => $client->id,
        'status' => Order::STATUS_DELIVERED,
        'payment_status' => 'paid',
        'products_total' => 5000,
        'delivery_fee' => 1000,
    ]);

    $injected = false;
    DB::listen(function ($query) use (&$injected, $order2, $city, $client) {
        if (! $injected && str_contains(strtolower($query->sql), 'for update') && str_contains(strtolower($query->sql), '"orders"')) {
            $injected = true;
            DB::table('refunds')->insert([
                'city_id' => $city->id,
                'order_id' => $order2->id,
                'parcel_id' => null,
                'client_id' => $client->id,
                'amount' => 2000,
                'reason' => 'Insertion concurrente',
                'status' => 'approved',
                'cost_bearer' => 'platform',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    });

    $this->actingAs($finance, 'api')
        ->postJson("/api/v1/admin/orders/{$order2->id}/refund", [
            'montant' => 2000,
            'raison' => 'Requête concurrente',
        ])
        ->assertStatus(409);

    expect(Refund::where('order_id', $order2->id)->count())->toBe(1);

    // Contrainte UNIQUE DB directe protège aussi hors applicatif
    expect(fn () => DB::transaction(fn () => DB::table('refunds')->insert([
        'city_id' => $city->id,
        'order_id' => $order2->id,
        'parcel_id' => null,
        'client_id' => $client->id,
        'amount' => 2000,
        'reason' => 'Doublon SQL direct',
        'status' => 'approved',
        'cost_bearer' => 'platform',
        'created_at' => now(),
        'updated_at' => now(),
    ])))->toThrow(QueryException::class);
});

it('2.4 éligibilité : refuse 422 commande/colis non payé, statut non éligible, in_transit sans litige/perte et montant > payé ; refuse 404 autre ville', function () {
    $cityA = City::factory()->create(['name' => 'Daloa']);
    $cityB = City::factory()->create(['name' => 'Bouaké']);
    $managerA = User::factory()->create(['role' => 'city_manager', 'city_id' => $cityA->id]);

    // Autre ville -> 404 sans fuite
    $orderB = Order::factory()->create(['city_id' => $cityB->id, 'status' => Order::STATUS_DELIVERED, 'payment_status' => 'paid']);
    $this->actingAs($managerA, 'api')
        ->postJson("/api/v1/admin/orders/{$orderB->id}/refund", ['montant' => 1000, 'raison' => 'Autre ville'])
        ->assertStatus(404);

    // Commande livrée NON payée -> 422
    $unpaidOrder = Order::factory()->create([
        'city_id' => $cityA->id,
        'status' => Order::STATUS_DELIVERED,
        'payment_status' => 'pending_cash',
        'products_total' => 4000,
        'delivery_fee' => 800,
    ]);
    $this->actingAs($managerA, 'api')
        ->postJson("/api/v1/admin/orders/{$unpaidOrder->id}/refund", ['montant' => 1000, 'raison' => 'Non payé'])
        ->assertStatus(422);

    // Commande pending même payée -> 422
    $pendingOrder = Order::factory()->create([
        'city_id' => $cityA->id,
        'status' => Order::STATUS_PENDING,
        'payment_status' => 'paid',
    ]);
    $this->actingAs($managerA, 'api')
        ->postJson("/api/v1/admin/orders/{$pendingOrder->id}/refund", ['montant' => 1000, 'raison' => 'En attente'])
        ->assertStatus(422);

    // Montant > total payé -> 422 (4801 > 4000 + 800)
    $deliveredOrder = Order::factory()->create([
        'city_id' => $cityA->id,
        'status' => Order::STATUS_DELIVERED,
        'payment_status' => 'paid_cash',
        'products_total' => 4000,
        'delivery_fee' => 800,
    ]);
    $this->actingAs($managerA, 'api')
        ->postJson("/api/v1/admin/orders/{$deliveredOrder->id}/refund", ['montant' => 4801, 'raison' => 'Trop élevé'])
        ->assertStatus(422);

    // Colis in_transit non litigieux non déclaré perdu -> 422 ; après declare-lost -> 200
    $insuredParcel = Parcel::factory()->create([
        'city_id' => $cityA->id,
        'status' => Parcel::STATUS_IN_TRANSIT,
        'payment_status' => 'paid',
        'shipping_fee' => 1200,
        'has_insurance' => true,
        'declared_value' => 30000,
        'insurance_amount' => 900,
    ]);

    $this->actingAs($managerA, 'api')
        ->postJson("/api/v1/admin/parcels/{$insuredParcel->id}/refund", ['montant' => 30000, 'raison' => 'En transit simple'])
        ->assertStatus(422);

    $this->actingAs($managerA, 'api')
        ->postJson("/api/v1/admin/parcels/{$insuredParcel->id}/declare-lost", ['raison' => 'Colis égaré confirmé après enquête'])
        ->assertOk();

    expect($insuredParcel->fresh()->status)->toBe('lost');

    $this->actingAs($managerA, 'api')
        ->postJson("/api/v1/admin/parcels/{$insuredParcel->id}/refund", ['montant' => 30000, 'raison' => 'Indemnisation perte'])
        ->assertOk();

    expect(Refund::where('parcel_id', $insuredParcel->id)->count())->toBe(1);
});

it('2.5 & 2.6 workflow complet requested -> approved -> paid et traçabilité activity_logs sans téléphone ni code en clair', function () {
    $city = City::factory()->create();
    $support = User::factory()->create(['role' => 'city_support', 'city_id' => $city->id]);
    $finance = User::factory()->create(['role' => 'city_finance', 'city_id' => $city->id]);
    $client = User::factory()->create(['role' => 'client', 'city_id' => $city->id, 'phone' => '0799887766']);

    $order = Order::factory()->create([
        'city_id' => $city->id,
        'client_id' => $client->id,
        'status' => Order::STATUS_CANCELLED,
        'payment_status' => 'paid',
        'products_total' => 5000,
        'delivery_fee' => 800,
    ]);

    // 1. Demande (requested)
    $createRes = $this->actingAs($support, 'api')
        ->postJson('/api/v1/admin/refunds', [
            'order_id' => $order->id,
            'montant' => 5800,
            'raison' => 'Annulée après paiement Orange Money',
        ])
        ->assertCreated();

    $refundId = $createRes->json('donnees.id');
    expect($createRes->json('donnees.status'))->toBe('requested')
        ->and($createRes->json('donnees.cost_bearer'))->toBe('platform');

    // 2. Support ne peut pas approuver 5800 -> 403 ; Finance approuve -> 200
    $this->actingAs($support, 'api')
        ->postJson("/api/v1/admin/refunds/{$refundId}/approve")
        ->assertStatus(403);

    $this->actingAs($finance, 'api')
        ->postJson("/api/v1/admin/refunds/{$refundId}/approve")
        ->assertOk()
        ->assertJsonPath('donnees.status', 'approved');

    // 3. Support ne peut pas marquer payé -> 403 ; Finance sans référence -> 422 ; avec référence OM -> paid
    $this->actingAs($support, 'api')
        ->postJson("/api/v1/admin/refunds/{$refundId}/mark-paid", ['payment_reference' => 'OM-REF-123'])
        ->assertStatus(403);

    $this->actingAs($finance, 'api')
        ->postJson("/api/v1/admin/refunds/{$refundId}/mark-paid", [])
        ->assertStatus(422);

    $this->actingAs($finance, 'api')
        ->postJson("/api/v1/admin/refunds/{$refundId}/mark-paid", ['payment_reference' => 'OM-REF-998877'])
        ->assertOk()
        ->assertJsonPath('donnees.status', 'paid');

    expect(Refund::find($refundId)?->payment_reference)->toBe('OM-REF-998877');

    expect(WalletTransaction::count())->toBe(0);

    // Traçabilité activity_logs sans numéro de téléphone
    $logs = ActivityLog::where('entity_type', 'refund')->where('entity_id', $refundId)->get();
    expect($logs->pluck('action')->all())->toContain('refund_requested', 'refund_approved', 'refund_paid')
        ->and(json_encode($logs->toArray()))->not->toContain('0799887766');
});
