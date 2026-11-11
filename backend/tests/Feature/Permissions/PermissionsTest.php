<?php

use App\Models\City;
use App\Models\Courier;
use App\Models\Order;
use App\Models\Refund;
use App\Models\User;
use App\Models\WalletTransaction;
use Tymon\JWTAuth\Facades\JWTAuth;

// ─── City Context — isolation par ville ───────────────────────────────────────

it('un City Manager ne voit que les commandes de sa ville', function () {
    $daloa = City::factory()->create(['name' => 'Daloa']);
    $bouake = City::factory()->create(['name' => 'Bouaké']);

    $manager = User::factory()->create(['role' => 'city_manager', 'city_id' => $daloa->id]);

    $orderDaloa = Order::factory()->create(['city_id' => $daloa->id]);
    $orderBouake = Order::factory()->create(['city_id' => $bouake->id]);

    $token = JWTAuth::fromUser($manager);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/admin/orders');

    $response->assertOk();

    $ids = collect($response->json('donnees.data'))->pluck('id');
    expect($ids)->toContain($orderDaloa->id)
        ->not->toContain($orderBouake->id);
});

it('un City Agent ne peut pas valider un remboursement de 5000 F CFA', function () {
    $city = City::factory()->create();
    $agent = User::factory()->create(['role' => 'city_agent', 'city_id' => $city->id]);
    $order = Order::factory()->create(['city_id' => $city->id]);

    $token = JWTAuth::fromUser($agent);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/admin/orders/{$order->id}/refund", [
            'montant' => 5000,
            'raison' => 'Test',
        ])->assertForbidden(); // 403
});

it('un City Finance peut valider un remboursement entre 2000 et 10000 F CFA', function () {
    $city = City::factory()->create();
    $finance = User::factory()->create(['role' => 'city_finance', 'city_id' => $city->id]);
    $driver = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    $courier = Courier::factory()->create(['user_id' => $driver->id, 'city_id' => $city->id]);
    $order = Order::factory()->create([
        'city_id' => $city->id,
        'courier_id' => $courier->id,
        'status' => Order::STATUS_DELIVERED,
        'payment_status' => 'paid',
        'products_total' => 5000,
        'delivery_fee' => 800,
    ]);

    DB::table('wallets')->insert([
        'owner_type' => 'courier', 'owner_id' => $courier->id,
        'balance' => 15000, 'total_earned' => 15000, 'total_withdrawn' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $token = JWTAuth::fromUser($finance);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/admin/orders/{$order->id}/refund", [
            'montant' => 5000,
            'raison' => 'Commande non livrée confirmée',
        ])->assertOk()
        ->assertJsonPath('succes', true);

    expect(Refund::where('order_id', $order->id)->where('amount', 5000)->exists())->toBeTrue()
        ->and(WalletTransaction::where('type', 'refund')->count())->toBe(0)
        ->and((int) DB::table('wallets')->where('owner_type', 'courier')->where('owner_id', $courier->id)->value('balance'))->toBe(15000);
});

it('un City Finance reçoit 422 si la commande est en statut non éligible ou non payée', function () {
    $city = City::factory()->create();
    $finance = User::factory()->create(['role' => 'city_finance', 'city_id' => $city->id]);
    $pendingOrder = Order::factory()->create(['city_id' => $city->id, 'status' => Order::STATUS_PENDING, 'payment_status' => 'paid']);
    $unpaidOrder = Order::factory()->create(['city_id' => $city->id, 'status' => Order::STATUS_DELIVERED, 'payment_status' => 'pending']);

    $token = JWTAuth::fromUser($finance);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/admin/orders/{$pendingOrder->id}/refund", ['montant' => 2500, 'raison' => 'Statut invalide'])
        ->assertStatus(422);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/admin/orders/{$unpaidOrder->id}/refund", ['montant' => 2500, 'raison' => 'Non payée'])
        ->assertStatus(422);

    expect(Refund::count())->toBe(0);
});

it('un City Finance ne peut PAS valider un remboursement supérieur à 10000 F CFA', function () {
    $city = City::factory()->create();
    $finance = User::factory()->create(['role' => 'city_finance', 'city_id' => $city->id]);
    $order = Order::factory()->create(['city_id' => $city->id]);

    $token = JWTAuth::fromUser($finance);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/admin/orders/{$order->id}/refund", [
            'montant' => 15000, // Dépasse le plafond City Finance
        ])->assertForbidden();
});

// ─── Isolation rôles applicatifs ──────────────────────────────────────────────

it('un client ne peut pas accéder aux endpoints merchant', function () {
    $client = User::factory()->create(['role' => 'client', 'is_active' => true]);
    $token = JWTAuth::fromUser($client);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/merchant/orders')
        ->assertForbidden();
});

it('un driver ne peut pas accéder aux endpoints client', function () {
    $driver = User::factory()->create(['role' => 'driver', 'is_active' => true]);
    $token = JWTAuth::fromUser($driver);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/client/orders')
        ->assertForbidden();
});

it('un merchant ne peut pas accéder aux endpoints admin', function () {
    $user = User::factory()->create(['role' => 'merchant', 'is_active' => true]);
    $token = JWTAuth::fromUser($user);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/admin/dashboard')
        ->assertForbidden();
});

it('un utilisateur non authentifié est rejeté sur les routes protégées', function () {
    $this->getJson('/api/v1/client/orders')->assertUnauthorized();
    $this->getJson('/api/v1/merchant/orders')->assertUnauthorized();
    $this->getJson('/api/v1/driver/courses')->assertUnauthorized();
    $this->getJson('/api/v1/admin/dashboard')->assertUnauthorized();
});

// ─── Mode Urgence — permissions ───────────────────────────────────────────────

it('seul le Super Admin ou le DG National peut activer le Mode Urgence', function () {
    $city = City::factory()->create();
    $cityMgr = User::factory()->create(['role' => 'city_manager', 'city_id' => $city->id]);
    $token = JWTAuth::fromUser($cityMgr);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/admin/cities/{$city->id}/emergency-mode", ['activer' => true])
        ->assertForbidden();
});

it('le Super Admin peut activer le Mode Urgence', function () {
    $superAdmin = User::factory()->create(['role' => 'super_admin']);
    $city = City::factory()->create();
    $token = JWTAuth::fromUser($superAdmin);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/admin/cities/{$city->id}/emergency-mode", [
            'activer' => true,
            'message' => 'Incident réseau en cours — opérations suspendues.',
        ])->assertOk()
        ->assertJsonPath('succes', true);

    $this->assertDatabaseHas('cities', [
        'id' => $city->id,
        'emergency_mode_active' => true,
    ]);
});

// ─── Codes de confirmation — permissions ──────────────────────────────────────

it('seul un City Support ou supérieur peut débloquer un code bloqué', function () {
    $city = City::factory()->create();
    $agent = User::factory()->create(['role' => 'city_agent', 'city_id' => $city->id]);
    $order = Order::factory()->create([
        'city_id' => $city->id,
        'confirmation_locked' => true,
    ]);
    $token = JWTAuth::fromUser($agent);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/orders/{$order->id}/unlock-code")
        ->assertForbidden();
});

it('un City Support peut débloquer un code bloqué', function () {
    $city = City::factory()->create();
    $support = User::factory()->create(['role' => 'city_support', 'city_id' => $city->id]);
    $order = Order::factory()->create([
        'city_id' => $city->id,
        'confirmation_locked' => true,
        'confirmation_attempts' => 3,
    ]);
    $token = JWTAuth::fromUser($support);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/orders/{$order->id}/unlock-code")
        ->assertOk();

    $this->assertDatabaseHas('orders', [
        'id' => $order->id,
        'confirmation_locked' => false,
        'confirmation_attempts' => 0,
    ]);
});
