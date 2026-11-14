<?php

use App\Models\City;
use App\Models\Order;
use App\Models\Parcel;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
    config([
        'services.hub2.webhook_secret' => 'test-hub2-webhook-secret',
        'services.cinetpay.secret_key' => 'test-cinetpay-secret-key',
    ]);
});

it('P5 Point 3: refuse de passer paid quand payload.amount diffère du total dû (5 800 F dû, webhook 100 F -> amount_mismatch + alerte critique)', function () {
    $city = City::factory()->create(['name' => 'Daloa']);
    $client = User::factory()->create(['role' => 'client', 'city_id' => $city->id]);

    $order = Order::factory()->create([
        'client_id' => $client->id,
        'city_id' => $city->id,
        'products_total' => 5000,
        'delivery_fee' => 800, // total dû = 5 800 F
        'payment_method' => 'orange_money',
        'payment_status' => 'pending',
        'payment_reference' => 'TXN-MISMATCH-5800',
    ]);

    $payload = [
        'event' => 'payment.success',
        'transaction_id' => 'TXN-MISMATCH-5800',
        'status' => 'SUCCESS',
        'amount' => 100,
        'timestamp' => now()->timestamp,
    ];
    $raw = json_encode($payload);
    $signature = hash_hmac('sha256', $raw, config('services.hub2.webhook_secret'));

    $this->call(
        'POST',
        '/api/v1/webhooks/payment',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB2_SIGNATURE' => $signature],
        $raw
    )->assertStatus(200);

    $freshOrder = $order->fresh();
    expect($freshOrder->payment_status)->toBe('amount_mismatch')
        ->and($freshOrder->payment_status)->not->toBe('paid');

    $this->assertDatabaseHas('webhook_logs', [
        'source' => 'hub2',
        'signature_valid' => true,
        'status' => 'rejected',
    ]);

    $this->assertDatabaseHas('activity_logs', [
        'action' => 'webhook_amount_mismatch',
        'entity_type' => 'order',
        'entity_id' => $order->id,
        'severity' => 'critical',
    ]);
});

it('P5 Point 3: vérifie aussi le total dû sur GoColis (shipping_fee + insurance_amount) et bloque si montant absent', function () {
    $city = City::factory()->create(['name' => 'Daloa']);
    $client = User::factory()->create(['role' => 'client', 'city_id' => $city->id]);

    $parcel = Parcel::factory()->create([
        'sender_id' => $client->id,
        'city_id' => $city->id,
        'shipping_fee' => 1200,
        'has_insurance' => true,
        'insurance_amount' => 900, // total dû = 2 100 F
        'payment_method' => 'orange_money',
        'payment_status' => 'pending',
        'payment_reference' => 'TXN-PARCEL-MISMATCH-2100',
    ]);

    $payload = [
        'event' => 'payment.success',
        'transaction_id' => 'TXN-PARCEL-MISMATCH-2100',
        'status' => 'SUCCESS',
        'amount' => 1200, // oublie l'assurance 900 F
        'timestamp' => now()->timestamp,
    ];
    $raw = json_encode($payload);
    $signature = hash_hmac('sha256', $raw, config('services.hub2.webhook_secret'));

    $this->call(
        'POST',
        '/api/v1/webhooks/payment',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB2_SIGNATURE' => $signature],
        $raw
    )->assertStatus(200);

    expect($parcel->fresh()->payment_status)->toBe('amount_mismatch');
    $this->assertDatabaseHas('activity_logs', [
        'action' => 'webhook_amount_mismatch',
        'entity_type' => 'parcel',
        'entity_id' => $parcel->id,
        'severity' => 'critical',
    ]);

    // Webhook sans champ amount -> refusé également (amount_mismatch)
    $orderNoAmount = Order::factory()->create([
        'client_id' => $client->id,
        'city_id' => $city->id,
        'products_total' => 5000,
        'delivery_fee' => 800,
        'payment_method' => 'orange_money',
        'payment_status' => 'pending',
        'payment_reference' => 'TXN-NO-AMOUNT-5800',
    ]);

    $payloadNoAmount = [
        'event' => 'payment.success',
        'transaction_id' => 'TXN-NO-AMOUNT-5800',
        'status' => 'SUCCESS',
        'timestamp' => now()->timestamp,
    ];
    $rawNoAmount = json_encode($payloadNoAmount);
    $sigNoAmount = hash_hmac('sha256', $rawNoAmount, config('services.hub2.webhook_secret'));

    $this->call(
        'POST',
        '/api/v1/webhooks/payment',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB2_SIGNATURE' => $sigNoAmount],
        $rawNoAmount
    )->assertStatus(200);

    expect($orderNoAmount->fresh()->payment_status)->toBe('amount_mismatch');
});
