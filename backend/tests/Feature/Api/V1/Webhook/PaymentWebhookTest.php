<?php

use App\Jobs\ProcessPaymentWebhook;
use App\Mail\SmsBudgetAlertMail;
use App\Models\{City, Order, Parcel, User, WebhookLog};
use App\Services\SmsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Cache, Mail, Queue};
use Tymon\JWTAuth\Facades\JWTAuth;

uses(RefreshDatabase::class);

beforeEach(function () {
    Mail::fake();

    $this->city = City::factory()->create(['name' => 'Daloa']);
    $this->client = User::factory()->create([
        'city_id' => $this->city->id,
        'role'    => 'client',
        'phone'   => '0700000001',
    ]);

    config(['services.hub2.webhook_secret' => 'test-hub2-webhook-secret']);
});

test('un webhook avec signature HMAC invalide est rejeté avec HTTP 401', function () {
    $payload = [
        'event'          => 'payment.success',
        'transaction_id' => 'TXN-INVALID-123',
        'status'         => 'SUCCESS',
    ];

    $response = $this->postJson(
        '/api/v1/webhooks/payment',
        $payload,
        ['X-Hub2-Signature' => 'fake-hmac-signature']
    );

    $response->assertStatus(401)
        ->assertJson([
            'succes'  => false,
            'message' => 'Signature invalide.',
        ]);

    $this->assertDatabaseHas('webhook_logs', [
        'source'          => 'hub2',
        'signature_valid' => false,
        'status'          => 'rejected',
    ]);
});

test('un webhook Hub2 valide passe le statut du paiement en paid', function () {
    $order = Order::factory()->create([
        'client_id'         => $this->client->id,
        'city_id'           => $this->city->id,
        'payment_status'    => 'pending',
        'payment_reference' => 'TXN-HUB2-SUCCESS-999',
    ]);

    $payload = [
        'event'          => 'payment.success',
        'transaction_id' => 'TXN-HUB2-SUCCESS-999',
        'status'         => 'SUCCESS',
    ];

    $rawContent = json_encode($payload);
    $signature = hash_hmac('sha256', $rawContent, config('services.hub2.webhook_secret'));

    $response = $this->call(
        'POST',
        '/api/v1/webhooks/payment',
        [],
        [],
        [],
        [
            'CONTENT_TYPE'          => 'application/json',
            'HTTP_X_HUB2_SIGNATURE' => $signature,
        ],
        $rawContent
    );

    $response->assertStatus(200)
        ->assertJson([
            'succes'  => true,
            'message' => 'Webhook reçu.',
        ]);

    $this->assertDatabaseHas('webhook_logs', [
        'source'          => 'hub2',
        'signature_valid' => true,
        'status'          => 'processed',
    ]);

    expect($order->fresh()->payment_status)->toBe('paid');
});

test('un paiement échoué passe la commande en cancelled avec motif', function () {
    $order = Order::factory()->create([
        'client_id'         => $this->client->id,
        'city_id'           => $this->city->id,
        'status'            => Order::STATUS_PENDING,
        'payment_status'    => 'pending',
        'payment_reference' => 'TXN-FAILED-001',
    ]);

    $payload = [
        'event'          => 'payment.failed',
        'transaction_id' => 'TXN-FAILED-001',
        'status'         => 'FAILED',
    ];

    $rawContent = json_encode($payload);
    $signature = hash_hmac('sha256', $rawContent, config('services.hub2.webhook_secret'));

    $response = $this->call(
        'POST',
        '/api/v1/webhooks/payment',
        [],
        [],
        [],
        [
            'CONTENT_TYPE'          => 'application/json',
            'HTTP_X_HUB2_SIGNATURE' => $signature,
        ],
        $rawContent
    );

    $response->assertStatus(200);

    $orderFresh = $order->fresh();
    expect($orderFresh->payment_status)->toBe('failed');
    expect($orderFresh->status)->toBe(Order::STATUS_CANCELLED);
    expect($orderFresh->cancel_reason)->toBe('Paiement Mobile Money échoué.');
});

test('le traitement des webhooks est idempotent face aux doublons', function () {
    $order = Order::factory()->create([
        'client_id'         => $this->client->id,
        'city_id'           => $this->city->id,
        'payment_status'    => 'pending',
        'payment_reference' => 'TXN-IDEMPOTENT-456',
    ]);

    $payload = [
        'event'          => 'payment.success',
        'transaction_id' => 'TXN-IDEMPOTENT-456',
        'status'         => 'SUCCESS',
    ];

    $rawContent = json_encode($payload);
    $signature = hash_hmac('sha256', $rawContent, config('services.hub2.webhook_secret'));

    // Premier envoi
    $this->call(
        'POST',
        '/api/v1/webhooks/payment',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB2_SIGNATURE' => $signature],
        $rawContent
    )->assertStatus(200);

    expect($order->fresh()->payment_status)->toBe('paid');

    // Deuxième envoi identique
    $this->call(
        'POST',
        '/api/v1/webhooks/payment',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB2_SIGNATURE' => $signature],
        $rawContent
    )->assertStatus(200);

    expect($order->fresh()->payment_status)->toBe('paid');
});

test('un admin peut rejouer un webhook valide échoué', function () {
    $admin = User::factory()->create([
        'city_id' => $this->city->id,
        'role'    => 'super_admin',
        'email'   => 'admin-test@golivreur.ci',
    ]);

    $order = Order::factory()->create([
        'client_id'         => $this->client->id,
        'city_id'           => $this->city->id,
        'payment_status'    => 'pending',
        'payment_reference' => 'TXN-REPLAY-777',
    ]);

    $webhookLog = WebhookLog::create([
        'source'             => 'hub2',
        'event_type'         => 'payment',
        'payload'            => [
            'transaction_id' => 'TXN-REPLAY-777',
            'status'         => 'SUCCESS',
        ],
        'signature_received' => 'valid-sig',
        'signature_valid'    => true,
        'status'             => 'failed',
        'error_message'      => 'Erreur temporaire réseau simulée',
    ]);

    $token = JWTAuth::fromUser($admin);

    $response = $this->withHeaders([
        'Authorization' => "Bearer {$token}",
        'X-City-ID'     => $this->city->id,
    ])->postJson("/api/v1/admin/webhooks/{$webhookLog->id}/replay");

    $response->assertStatus(200)
        ->assertJson([
            'succes' => true,
        ]);

    expect($order->fresh()->payment_status)->toBe('paid');
});

test('un webhook avec signature invalide ne peut pas être rejoué', function () {
    $admin = User::factory()->create([
        'city_id' => $this->city->id,
        'role'    => 'super_admin',
        'email'   => 'admin-test2@golivreur.ci',
    ]);

    $webhookLog = WebhookLog::create([
        'source'             => 'hub2',
        'event_type'         => 'payment',
        'payload'            => ['transaction_id' => 'TXN-INVALID'],
        'signature_received' => 'bad-sig',
        'signature_valid'    => false,
        'status'             => 'rejected',
    ]);

    $token = JWTAuth::fromUser($admin);

    $response = $this->withHeaders([
        'Authorization' => "Bearer {$token}",
        'X-City-ID'     => $this->city->id,
    ])->postJson("/api/v1/admin/webhooks/{$webhookLog->id}/replay");

    $response->assertStatus(422)
        ->assertJson([
            'succes' => false,
        ]);
});

test('le compteur mensuel SMS s\'incrémente et alerte au seuil de 2500', function () {
    Cache::flush();

    $smsService = new SmsService(
        alertEmail: 'francis@golivreur.ci'
    );

    // Initial : compteur à 0
    expect($smsService->compteurMensuel())->toBe(0);

    // Envoyer un SMS
    $smsService->envoyerOtp('0700000001', '1234', 'inscription');
    expect($smsService->compteurMensuel())->toBe(1);

    // Forcer le cache à 2499
    $cle = 'sms_count_mensuel_' . date('Y_m');
    Cache::put($cle, 2499);

    // Le 2500ème SMS doit déclencher l'alerte email
    $smsService->envoyerOtp('0700000002', '5678', 'connexion');

    expect($smsService->compteurMensuel())->toBe(2500);
    Mail::assertSent(SmsBudgetAlertMail::class, 1);
});
