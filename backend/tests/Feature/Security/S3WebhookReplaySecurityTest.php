<?php

use App\Filament\Resources\WebhookLogResource\Pages\ListWebhookLogs;
use App\Models\City;
use App\Models\Order;
use App\Models\User;
use App\Models\WebhookLog;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
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
})->with(['city_agent', 'city_support', 'city_finance', 'city_manager', 'national_support', 'dg_national', 'client', 'merchant', 'driver']);

it('S3 rôles autorisés: autorise national_tech et super_admin sur webhooks/{id}/replay', function (string $role) {
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
        'payload' => ['transaction_id' => 'TXN-ALLOW-'.$role, 'status' => 'SUCCESS', 'amount' => 5800],
        'signature_received' => 'valid-sig',
        'signature_valid' => true,
        'status' => 'failed',
    ]);

    $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($actor))
        ->postJson("/api/v1/admin/webhooks/{$webhookLog->id}/replay")
        ->assertOk()
        ->assertJsonPath('succes', true);
})->with(['national_tech', 'super_admin']);

it('S3 filament replay action: visible uniquement pour super_admin et national_tech', function () {
    $webhookLog = WebhookLog::create([
        'source' => 'hub2',
        'event_type' => 'payment',
        'payload' => ['transaction_id' => 'TXN-FILAMENT', 'status' => 'SUCCESS'],
        'signature_received' => 'valid-sig',
        'signature_valid' => true,
        'status' => 'failed',
    ]);

    $dg = User::factory()->create(['role' => 'dg_national']);
    $tech = User::factory()->create(['role' => 'national_tech']);
    $sa = User::factory()->create(['role' => 'super_admin']);

    $this->actingAs($dg);
    Livewire::test(ListWebhookLogs::class)
        ->assertTableActionHidden('replay', $webhookLog);

    $this->actingAs($tech);
    Livewire::test(ListWebhookLogs::class)
        ->assertTableActionVisible('replay', $webhookLog);

    $this->actingAs($sa);
    Livewire::test(ListWebhookLogs::class)
        ->assertTableActionVisible('replay', $webhookLog);
});
