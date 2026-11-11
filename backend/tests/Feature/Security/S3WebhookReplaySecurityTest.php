<?php

use App\Models\City;
use App\Models\Order;
use App\Models\User;
use App\Models\WebhookLog;
use Illuminate\Support\Facades\Mail;
use Tymon\JWTAuth\Facades\JWTAuth;

it('S3 rôles interdits: refuse 403 aux rôles locaux et non habilités sur webhooks/{id}/replay', function (string $role) {
    Mail::fake();
    $city = City::factory()->create();
    $user = User::factory()->create(['role' => $role, 'city_id' => $city->id]);

    $webhookLog = WebhookLog::create([
        'source' => 'hub2',
        'event_type' => 'payment',
        'payload' => ['transaction_id' => 'TXN-TEST-REPLAY', 'status' => 'SUCCESS'],
        'signature_received' => 'valid-sig',
        'signature_valid' => true,
        'status' => 'failed',
    ]);

    $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($user))
        ->postJson("/api/v1/admin/webhooks/{$webhookLog->id}/replay")
        ->assertForbidden();

    expect($webhookLog->fresh()->status)->toBe('failed')
        ->and($webhookLog->fresh()->retry_count)->toBe(0);
})->with(['city_agent', 'city_support', 'city_finance', 'city_manager', 'national_support', 'client', 'merchant', 'driver']);

it('S3 rôles autorisés: autorise national_tech, dg_national et super_admin sur webhooks/{id}/replay', function (string $role) {
    Mail::fake();
    $city = City::factory()->create();
    Order::factory()->create([
        'city_id' => $city->id,
        'payment_status' => 'pending',
        'payment_reference' => 'TXN-ALLOW-'.$role,
    ]);

    $actor = User::factory()->create(['role' => $role, 'city_id' => null]);
    $webhookLog = WebhookLog::create([
        'source' => 'hub2',
        'event_type' => 'payment',
        'payload' => ['transaction_id' => 'TXN-ALLOW-'.$role, 'status' => 'SUCCESS'],
        'signature_received' => 'valid-sig',
        'signature_valid' => true,
        'status' => 'failed',
    ]);

    $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($actor))
        ->postJson("/api/v1/admin/webhooks/{$webhookLog->id}/replay")
        ->assertOk()
        ->assertJsonPath('succes', true);
})->with(['national_tech', 'dg_national', 'super_admin']);
