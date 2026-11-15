<?php

use App\Jobs\AnnulerCommandePaiementTimeout;
use App\Jobs\AnnulerCommandeTimeout;
use App\Jobs\ProcessPaymentWebhook;
use App\Jobs\SendPushNotification;
use App\Models\City;
use App\Models\Merchant;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Parcel;
use App\Models\Product;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Mail::fake();
    config([
        'services.hub2.webhook_secret' => 'test-hub2-webhook-secret',
    ]);
});

it('Point B1: une commande payee en ligne ne notifie pas le commerce et ne demarre pas les 45s avant payment_status=paid (cash inchange)', function () {
    Queue::fake([SendPushNotification::class, AnnulerCommandeTimeout::class, AnnulerCommandePaiementTimeout::class]);

    $city = City::factory()->create(['name' => 'Daloa']);
    app(SettingsService::class)->set('paiements', ['modes_actifs' => ['cash', 'orange_money']], $city->id);

    $client = User::factory()->create(['role' => 'client', 'city_id' => $city->id, 'device_token' => 'tok-client-om']);
    $merchantUser = User::factory()->create(['role' => 'merchant', 'city_id' => $city->id, 'device_token' => 'tok-merch-om']);
    $merchant = Merchant::factory()->create([
        'user_id' => $merchantUser->id,
        'city_id' => $city->id,
        'is_open' => true,
        'is_active' => true,
        'lat' => 6.8770,
        'lng' => -6.4502,
    ]);
    $product = Product::factory()->create([
        'merchant_id' => $merchant->id,
        'price' => 5000,
        'is_available' => true,
    ]);

    // 1. Creation commande en ligne (orange_money) -> commerce NON notifie, AnnulerCommandeTimeout NON dispatche
    $response = $this->actingAs($client, 'api')
        ->postJson('/api/v1/client/orders', [
            'merchant_id' => $merchant->id,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'delivery_address' => 'Quartier Lobia',
            'delivery_lat' => 6.8800,
            'delivery_lng' => -6.4510,
            'payment_method' => 'orange_money',
        ]);

    $response->assertCreated();
    $onlineOrder = Order::findOrFail((int) $response->json('donnees.id'));
    $onlineOrder->update(['payment_reference' => 'TXN-OM-DELAY-001']);

    expect($onlineOrder->payment_status)->toBe('pending')
        ->and(Notification::where('user_id', $merchantUser->id)->where('type', 'new_order')->count())->toBe(0);

    Queue::assertNotPushed(AnnulerCommandeTimeout::class, fn (AnnulerCommandeTimeout $job): bool => $job->orderId === $onlineOrder->id);
    Queue::assertPushed(AnnulerCommandePaiementTimeout::class, fn (AnnulerCommandePaiementTimeout $job): bool => $job->orderId === $onlineOrder->id);

    // 2. Meme 2 minutes apres creation, AnnulerCommandeTimeout ne doit pas annuler une commande en ligne non payee
    $this->travel(2)->minutes();
    app()->call([new AnnulerCommandeTimeout($onlineOrder->id), 'handle']);
    expect($onlineOrder->fresh()->status)->toBe(Order::STATUS_PENDING);

    // 3. Confirmation paiement via webhook -> commerce notifie ET timeout 45s demarre a cet instant
    $expectedAmount = (int) round((float) $onlineOrder->products_total + (float) $onlineOrder->delivery_fee);
    $payload = [
        'event' => 'payment.success',
        'transaction_id' => 'TXN-OM-DELAY-001',
        'status' => 'SUCCESS',
        'amount' => $expectedAmount,
        'timestamp' => now()->timestamp,
    ];
    $raw = json_encode($payload);
    $sig = hash_hmac('sha256', $raw, config('services.hub2.webhook_secret'));

    $this->call(
        'POST',
        '/api/v1/webhooks/payment',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB2_SIGNATURE' => $sig],
        $raw
    )->assertStatus(200);

    expect($onlineOrder->fresh()->payment_status)->toBe('paid')
        ->and($onlineOrder->fresh()->paid_at)->not->toBeNull()
        ->and(Notification::where('user_id', $merchantUser->id)->where('type', 'new_order')->count())->toBe(1);

    Queue::assertPushed(AnnulerCommandeTimeout::class, fn (AnnulerCommandeTimeout $job): bool => $job->orderId === $onlineOrder->id);

    // Le delai de 45s part bien du paiement (pas de created_at)
    $this->travel(20)->seconds();
    app()->call([new AnnulerCommandeTimeout($onlineOrder->id), 'handle']);
    expect($onlineOrder->fresh()->status)->toBe(Order::STATUS_PENDING);

    $this->travel(26)->seconds();
    app()->call([new AnnulerCommandeTimeout($onlineOrder->id), 'handle']);
    expect($onlineOrder->fresh()->status)->toBe(Order::STATUS_CANCELLED);

    // 4. En cash : comportement inchange (notification + timeout 45s des la creation)
    $this->travelBack();
    $cashRes = $this->actingAs($client, 'api')
        ->postJson('/api/v1/client/orders', [
            'merchant_id' => $merchant->id,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'delivery_address' => 'Quartier Commerce',
            'delivery_lat' => 6.8800,
            'delivery_lng' => -6.4510,
            'payment_method' => 'cash',
        ]);
    $cashRes->assertCreated();
    $cashOrderId = (int) $cashRes->json('donnees.id');

    expect(Notification::where('user_id', $merchantUser->id)->where('type', 'new_order')->count())->toBe(2);
    Queue::assertPushed(AnnulerCommandeTimeout::class, fn (AnnulerCommandeTimeout $job): bool => $job->orderId === $cashOrderId);
});

it('Point B2: une commande en ligne restee pending non payee apres 10 minutes (settings) est annulee automatiquement', function () {
    Queue::fake([SendPushNotification::class, AnnulerCommandeTimeout::class, AnnulerCommandePaiementTimeout::class]);

    $city = City::factory()->create(['name' => 'Daloa']);
    app(SettingsService::class)->set('paiements', ['modes_actifs' => ['cash', 'orange_money']], $city->id);

    $client = User::factory()->create(['role' => 'client', 'city_id' => $city->id, 'device_token' => 'tok-client-timeout']);
    $merchant = Merchant::factory()->create(['city_id' => $city->id, 'is_open' => true, 'is_active' => true]);
    $product = Product::factory()->create(['merchant_id' => $merchant->id, 'price' => 3000, 'is_available' => true]);

    $res = $this->actingAs($client, 'api')
        ->postJson('/api/v1/client/orders', [
            'merchant_id' => $merchant->id,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'delivery_address' => 'Quartier Soleil',
            'delivery_lat' => 6.8800,
            'delivery_lng' => -6.4510,
            'payment_method' => 'orange_money',
        ])->assertCreated();

    $orderId = (int) $res->json('donnees.id');

    // Avant 10 minutes (9 min) -> reste pending
    $this->travel(9)->minutes();
    app()->call([new AnnulerCommandePaiementTimeout($orderId), 'handle']);
    expect(Order::find($orderId)?->status)->toBe(Order::STATUS_PENDING);

    // Apres 10 minutes -> annulee automatiquement
    $this->travel(2)->minutes();
    app()->call([new AnnulerCommandePaiementTimeout($orderId), 'handle']);
    $orderFresh = Order::find($orderId);
    expect($orderFresh?->status)->toBe(Order::STATUS_CANCELLED)
        ->and($orderFresh?->payment_status)->toBe('failed')
        ->and(Notification::where('user_id', $client->id)->where('type', 'payment_failed')->count())->toBe(1);

    // Parametrable par ville via SettingsService (ex: 5 min)
    $this->travelBack();
    app(SettingsService::class)->set('delais_regles', ['paiement_attente_minutes' => 5], $city->id);

    $resCustom = $this->actingAs($client, 'api')
        ->postJson('/api/v1/client/orders', [
            'merchant_id' => $merchant->id,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'delivery_address' => 'Quartier Soleil',
            'delivery_lat' => 6.8800,
            'delivery_lng' => -6.4510,
            'payment_method' => 'orange_money',
        ])->assertCreated();

    $customId = (int) $resCustom->json('donnees.id');
    Queue::assertPushed(AnnulerCommandePaiementTimeout::class, function (AnnulerCommandePaiementTimeout $job) use ($customId): bool {
        if ($job->orderId !== $customId || $job->delay === null) {
            return false;
        }

        $delaySec = (int) round(now()->diffInSeconds($job->delay, false));

        return $delaySec >= 295 && $delaySec <= 305;
    });

    $this->travel(6)->minutes();
    app()->call([new AnnulerCommandePaiementTimeout($customId), 'handle']);
    expect(Order::find($customId)?->status)->toBe(Order::STATUS_CANCELLED);
});

it('Point B3: un echec de paiement GoColis notifie l expediteur (base + push)', function () {
    Queue::fake([SendPushNotification::class]);

    $city = City::factory()->create(['name' => 'Daloa']);
    $sender = User::factory()->create([
        'role' => 'client',
        'city_id' => $city->id,
        'device_token' => 'tok-sender-gocolis-fail',
    ]);

    $parcel = Parcel::factory()->create([
        'sender_id' => $sender->id,
        'city_id' => $city->id,
        'status' => Parcel::STATUS_PENDING,
        'payment_method' => 'orange_money',
        'payment_status' => 'pending',
        'payment_reference' => 'TXN-GOCOLIS-FAIL-01',
    ]);

    $payload = [
        'event' => 'payment.failed',
        'transaction_id' => 'TXN-GOCOLIS-FAIL-01',
        'status' => 'FAILED',
        'timestamp' => now()->timestamp,
    ];
    $raw = json_encode($payload);
    $sig = hash_hmac('sha256', $raw, config('services.hub2.webhook_secret'));

    $this->call(
        'POST',
        '/api/v1/webhooks/payment',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB2_SIGNATURE' => $sig],
        $raw
    )->assertStatus(200);

    $parcelFresh = $parcel->fresh();
    expect($parcelFresh?->payment_status)->toBe('failed')
        ->and($parcelFresh?->status)->toBe(Parcel::STATUS_CANCELLED);

    expect(Notification::where('user_id', $sender->id)->where('type', 'payment_failed')->count())->toBe(1);
    Queue::assertPushed(SendPushNotification::class, function (SendPushNotification $job): bool {
        return $job->deviceToken === 'tok-sender-gocolis-fail'
            && str_contains(mb_strtolower($job->title), 'paiement');
    });
});

it('Point B4: un doublon webhook repond HTTP 200 succes=true message="déjà traité" sans retraiter', function () {
    $city = City::factory()->create(['name' => 'Daloa']);
    $client = User::factory()->create(['role' => 'client', 'city_id' => $city->id]);
    $order = Order::factory()->create([
        'client_id' => $client->id,
        'city_id' => $city->id,
        'products_total' => 5000,
        'delivery_fee' => 800,
        'payment_method' => 'orange_money',
        'payment_status' => 'pending',
        'payment_reference' => 'TXN-DUP-200-OK',
    ]);

    $payload = [
        'event' => 'payment.success',
        'transaction_id' => 'TXN-DUP-200-OK',
        'status' => 'SUCCESS',
        'amount' => 5800,
        'timestamp' => now()->timestamp,
    ];
    $raw = json_encode($payload);
    $sig = hash_hmac('sha256', $raw, config('services.hub2.webhook_secret'));

    // Premier webhook valide -> traite
    $this->call(
        'POST',
        '/api/v1/webhooks/payment',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB2_SIGNATURE' => $sig],
        $raw
    )->assertStatus(200)
        ->assertJson(['succes' => true, 'message' => 'Webhook reçu.']);

    expect($order->fresh()->payment_status)->toBe('paid');

    Queue::fake([ProcessPaymentWebhook::class]);

    // Deuxieme webhook identique -> HTTP 200 {succes:true, message:"déjà traité"} et aucun retraitement
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

    Queue::assertNotPushed(ProcessPaymentWebhook::class);
    expect($order->fresh()->payment_status)->toBe('paid');
});
