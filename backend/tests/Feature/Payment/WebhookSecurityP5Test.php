<?php

use App\Models\City;
use App\Models\Order;
use App\Models\Parcel;
use App\Models\User;
use App\Models\WebhookLog;
use Illuminate\Database\QueryException;
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

it('P5 Point 4: valide la signature HMAC CinetPay avec services.cinetpay.secret_key et refuse le secret Hub2 ou un secret vide', function () {
    config([
        'services.hub2.webhook_secret' => 'hub2-only-secret-xyz',
        'services.cinetpay.secret_key' => 'cinetpay-only-secret-abc',
    ]);

    $city = City::factory()->create(['name' => 'Daloa']);
    $client = User::factory()->create(['role' => 'client', 'city_id' => $city->id]);
    $order = Order::factory()->create([
        'client_id' => $client->id,
        'city_id' => $city->id,
        'products_total' => 5000,
        'delivery_fee' => 800,
        'payment_method' => 'orange_money',
        'payment_status' => 'pending',
        'payment_reference' => 'TXN-CINETPAY-SECRET-001',
    ]);

    $payload = [
        'event' => 'payment.success',
        'transaction_id' => 'TXN-CINETPAY-SECRET-001',
        'status' => 'ACCEPTED',
        'amount' => 5800,
        'timestamp' => now()->timestamp,
    ];
    $raw = json_encode($payload);

    // 1. Webhook CinetPay signé avec le mauvais secret (Hub2) -> 401
    $wrongSignature = hash_hmac('sha256', $raw, 'hub2-only-secret-xyz');
    $this->call(
        'POST',
        '/api/v1/webhooks/payment',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CINETPAY_SIGNATURE' => $wrongSignature],
        $raw
    )->assertStatus(401)
        ->assertJson(['succes' => false, 'message' => 'Signature invalide.']);

    expect($order->fresh()->payment_status)->toBe('pending');

    // 2. Webhook CinetPay signé avec le vrai secret CinetPay -> 200 + paid
    $cinetpaySignature = hash_hmac('sha256', $raw, 'cinetpay-only-secret-abc');
    $this->call(
        'POST',
        '/api/v1/webhooks/payment',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CINETPAY_SIGNATURE' => $cinetpaySignature],
        $raw
    )->assertStatus(200)
        ->assertJson(['succes' => true]);

    expect($order->fresh()->payment_status)->toBe('paid');
    $this->assertDatabaseHas('webhook_logs', [
        'source' => 'cinetpay',
        'signature_valid' => true,
        'status' => 'processed',
    ]);

    // 3. Si secret CinetPay vide en config, rejet 401 même avec HMAC sur chaîne vide
    config(['services.cinetpay.secret_key' => '']);
    $emptySecretSig = hash_hmac('sha256', $raw, '');
    $this->call(
        'POST',
        '/api/v1/webhooks/payment',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CINETPAY_SIGNATURE' => $emptySecretSig],
        $raw
    )->assertStatus(401);
});

it('P5 Point 5: refuse un webhook dont l\'horodatage a plus de 5 minutes (payload ou header)', function () {
    $city = City::factory()->create(['name' => 'Daloa']);
    $client = User::factory()->create(['role' => 'client', 'city_id' => $city->id]);
    $order = Order::factory()->create([
        'client_id' => $client->id,
        'city_id' => $city->id,
        'products_total' => 5000,
        'delivery_fee' => 800,
        'payment_method' => 'orange_money',
        'payment_status' => 'pending',
        'payment_reference' => 'TXN-EXPIRED-5MIN',
    ]);

    // 1. Horodatage expiré dans le payload (> 5 minutes) -> refus 422
    $expiredPayload = [
        'event' => 'payment.success',
        'transaction_id' => 'TXN-EXPIRED-5MIN',
        'status' => 'SUCCESS',
        'amount' => 5800,
        'timestamp' => now()->subMinutes(6)->timestamp,
    ];
    $rawExpired = json_encode($expiredPayload);
    $sigExpired = hash_hmac('sha256', $rawExpired, config('services.hub2.webhook_secret'));

    $this->call(
        'POST',
        '/api/v1/webhooks/payment',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB2_SIGNATURE' => $sigExpired],
        $rawExpired
    )->assertStatus(422)
        ->assertJsonPath('succes', false);

    expect($order->fresh()->payment_status)->toBe('pending');
    $this->assertDatabaseHas('webhook_logs', [
        'source' => 'hub2',
        'status' => 'rejected',
    ]);

    // 2. Horodatage expiré dans l'en-tête HTTP X-Hub2-Timestamp (301s) -> refus 422
    $payloadHeaderExpired = [
        'event' => 'payment.success',
        'transaction_id' => 'TXN-EXPIRED-5MIN',
        'status' => 'SUCCESS',
        'amount' => 5800,
    ];
    $rawHeader = json_encode($payloadHeaderExpired);
    $sigHeader = hash_hmac('sha256', $rawHeader, config('services.hub2.webhook_secret'));

    $this->call(
        'POST',
        '/api/v1/webhooks/payment',
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB2_SIGNATURE' => $sigHeader,
            'HTTP_X_HUB2_TIMESTAMP' => (string) now()->subSeconds(301)->timestamp,
        ],
        $rawHeader
    )->assertStatus(422)
        ->assertJsonPath('succes', false);

    expect($order->fresh()->payment_status)->toBe('pending');
});

it('P5 Point 5: ignore un doublon transaction_id avec HTTP 200 "déjà traité" sans retraiter et garantit une clé unique en base', function () {
    $city = City::factory()->create(['name' => 'Daloa']);
    $client = User::factory()->create(['role' => 'client', 'city_id' => $city->id]);
    $order = Order::factory()->create([
        'client_id' => $client->id,
        'city_id' => $city->id,
        'products_total' => 5000,
        'delivery_fee' => 800,
        'payment_method' => 'orange_money',
        'payment_status' => 'pending',
        'payment_reference' => 'TXN-ANTI-REPLAY-UNIQUE-01',
    ]);

    $payload = [
        'event' => 'payment.success',
        'transaction_id' => 'TXN-ANTI-REPLAY-UNIQUE-01',
        'status' => 'SUCCESS',
        'amount' => 5800,
        'timestamp' => now()->subMinute()->timestamp,
    ];
    $raw = json_encode($payload);
    $sig = hash_hmac('sha256', $raw, config('services.hub2.webhook_secret'));

    // Premier envoi valide -> 200 + paid
    $this->call(
        'POST',
        '/api/v1/webhooks/payment',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB2_SIGNATURE' => $sig],
        $raw
    )->assertStatus(200);

    expect($order->fresh()->payment_status)->toBe('paid');

    // Deuxième envoi avec même transaction_id -> 200 {succes: true, message: "déjà traité"} sans retraiter
    $this->call(
        'POST',
        '/api/v1/webhooks/payment',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB2_SIGNATURE' => $sig],
        $raw
    )->assertStatus(200)
        ->assertJson([
            'succes' => true,
            'message' => 'déjà traité',
        ]);

    expect(WebhookLog::where('transaction_id', 'TXN-ANTI-REPLAY-UNIQUE-01')->where('status', 'processed')->count())->toBe(1);

    // Preuve clé unique en base : insertion SQL/Eloquent directe d'un second processed avec même transaction_id échoue
    expect(fn () => WebhookLog::create([
        'source' => 'hub2',
        'event_type' => 'payment.success',
        'transaction_id' => 'TXN-ANTI-REPLAY-UNIQUE-01',
        'payload' => $payload,
        'signature_received' => 'sig',
        'signature_valid' => true,
        'status' => 'processed',
    ]))->toThrow(QueryException::class);
});
