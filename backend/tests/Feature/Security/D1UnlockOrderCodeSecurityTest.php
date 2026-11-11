<?php

use App\Models\ActivityLog;
use App\Models\City;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Tymon\JWTAuth\Facades\JWTAuth;

it('D1 nominal: un rôle local débloque une commande de sa ville, remet attempts à 0 et journalise sans le code', function (string $role) {
    $loggedMessages = [];
    Log::listen(function ($entry) use (&$loggedMessages) {
        $loggedMessages[] = $entry->message.' '.json_encode($entry->context);
    });

    $city = City::factory()->create(['name' => 'Daloa']);
    $actor = User::factory()->create(['role' => $role, 'city_id' => $city->id]);
    $order = Order::factory()->create([
        'city_id' => $city->id,
        'status' => Order::STATUS_PICKED_UP,
        'confirmation_code' => Hash::make('4829'),
        'confirmation_locked' => true,
        'confirmation_attempts' => 3,
    ]);

    $token = JWTAuth::fromUser($actor);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/orders/{$order->id}/unlock-code")
        ->assertOk()
        ->assertJsonPath('succes', true);

    expect($response->json('donnees'))->not->toHaveKey('confirmation_code');

    $order->refresh();
    expect($order->confirmation_locked)->toBeFalse()
        ->and($order->confirmation_attempts)->toBe(0)
        ->and($order->confirmation_unlocked_by)->toBe($actor->id);

    $log = ActivityLog::where('action', 'order_code_unlocked')
        ->where('entity_type', 'order')
        ->where('entity_id', $order->id)
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->user_id)->toBe($actor->id)
        ->and($log->properties['order_id'] ?? null)->toBe($order->id)
        ->and($log->properties['city_id'] ?? null)->toBe($city->id)
        ->and($log->properties['ville'] ?? null)->toBe('Daloa')
        ->and(json_encode($log->properties))->not->toContain('4829')
        ->and(implode("\n", $loggedMessages))->not->toContain('4829');
})->with(['city_support', 'city_finance', 'city_manager']);

it('D1 national: les rôles nationaux peuvent débloquer une commande de n\'importe quelle ville', function (string $role) {
    $cityDaloa = City::factory()->create(['name' => 'Daloa']);
    $cityBouake = City::factory()->create(['name' => 'Bouaké']);

    $actor = User::factory()->create(['role' => $role, 'city_id' => $cityDaloa->id]);
    $order = Order::factory()->create([
        'city_id' => $cityBouake->id,
        'status' => Order::STATUS_IN_TRANSIT,
        'confirmation_locked' => true,
        'confirmation_attempts' => 3,
    ]);

    $token = JWTAuth::fromUser($actor);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/orders/{$order->id}/unlock-code")
        ->assertOk()
        ->assertJsonPath('succes', true);

    expect($order->fresh()->confirmation_locked)->toBeFalse()
        ->and($order->fresh()->confirmation_attempts)->toBe(0);

    $this->assertDatabaseHas('activity_logs', [
        'user_id' => $actor->id,
        'action' => 'order_code_unlocked',
        'entity_type' => 'order',
        'entity_id' => $order->id,
    ]);
})->with(['national_support', 'national_finance', 'dg_national', 'super_admin']);

it('D1 autre ville: un rôle local ne peut pas débloquer une commande d\'une autre ville (404 sans fuite)', function (string $role) {
    $cityDaloa = City::factory()->create(['name' => 'Daloa']);
    $cityBouake = City::factory()->create(['name' => 'Bouaké']);

    $actor = User::factory()->create(['role' => $role, 'city_id' => $cityDaloa->id]);
    $orderBouake = Order::factory()->create([
        'city_id' => $cityBouake->id,
        'status' => Order::STATUS_PICKED_UP,
        'confirmation_locked' => true,
        'confirmation_attempts' => 3,
    ]);

    $token = JWTAuth::fromUser($actor);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/orders/{$orderBouake->id}/unlock-code")
        ->assertStatus(404)
        ->assertJsonPath('succes', false);

    expect($response->json('message'))->not->toContain('Bouaké');
    expect($orderBouake->fresh()->confirmation_locked)->toBeTrue()
        ->and($orderBouake->fresh()->confirmation_attempts)->toBe(3);

    expect(ActivityLog::where('action', 'order_code_unlocked')->where('entity_id', $orderBouake->id)->exists())->toBeFalse();
})->with(['city_support', 'city_finance', 'city_manager']);

it('D1 mauvais statut: refuse de débloquer une commande clôturée (delivered, cancelled, refused)', function (string $status) {
    $city = City::factory()->create();
    $support = User::factory()->create(['role' => 'city_support', 'city_id' => $city->id]);
    $order = Order::factory()->create([
        'city_id' => $city->id,
        'status' => $status,
        'confirmation_locked' => true,
        'confirmation_attempts' => 3,
    ]);

    $token = JWTAuth::fromUser($support);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/orders/{$order->id}/unlock-code")
        ->assertStatus(422)
        ->assertJsonPath('succes', false);

    expect($order->fresh()->confirmation_locked)->toBeTrue()
        ->and($order->fresh()->confirmation_attempts)->toBe(3);
})->with([Order::STATUS_DELIVERED, Order::STATUS_CANCELLED, Order::STATUS_REFUSED]);

it('D1 rôle interdit: refuse 403 aux rôles non habilités', function (string $role) {
    $city = City::factory()->create();
    $user = User::factory()->create(['role' => $role, 'city_id' => $city->id]);
    $order = Order::factory()->create([
        'city_id' => $city->id,
        'status' => Order::STATUS_PICKED_UP,
        'confirmation_locked' => true,
        'confirmation_attempts' => 3,
    ]);

    $token = JWTAuth::fromUser($user);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/orders/{$order->id}/unlock-code")
        ->assertForbidden();

    expect($order->fresh()->confirmation_locked)->toBeTrue();
})->with(['city_agent', 'national_tech', 'client', 'merchant', 'driver']);

it('D1 local sans city_id: refuse 404 si le rôle local n\'est rattaché à aucune ville', function () {
    $city = City::factory()->create();
    $supportSansVille = User::factory()->create(['role' => 'city_support', 'city_id' => null]);
    $order = Order::factory()->create([
        'city_id' => $city->id,
        'status' => Order::STATUS_PICKED_UP,
        'confirmation_locked' => true,
        'confirmation_attempts' => 3,
    ]);

    $token = JWTAuth::fromUser($supportSansVille);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/orders/{$order->id}/unlock-code")
        ->assertStatus(404);

    expect($order->fresh()->confirmation_locked)->toBeTrue()
        ->and($order->fresh()->confirmation_attempts)->toBe(3);
});
