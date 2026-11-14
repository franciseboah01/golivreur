<?php

use App\Jobs\AnnulerCommandeTimeout;
use App\Jobs\SendPushNotification;
use App\Models\City;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Parcel;
use App\Models\Product;
use App\Models\User;
use App\Modules\GoShop\Services\OrderService;
use App\Services\NotificationService;
use Illuminate\Queue\Connectors\ConnectorInterface;
use Illuminate\Queue\NullQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

function enregistrerQueueEnPanne(string $name = 'failing_redis'): void
{
    app('queue')->addConnector($name, fn () => new class implements ConnectorInterface
    {
        public function connect(array $config)
        {
            return new class extends NullQueue
            {
                public function push($job, $data = '', $queue = null)
                {
                    throw new RuntimeException('Redis connection refused [tcp://127.0.0.1:6379]');
                }

                public function later($delay, $job, $data = '', $queue = null)
                {
                    throw new RuntimeException('Redis connection refused [tcp://127.0.0.1:6379]');
                }
            };
        }
    });

    config([
        'queue.default' => $name,
        "queue.connections.{$name}" => ['driver' => $name],
    ]);
}

it('P6 Point 8: envoie les jobs apres commit sous Queue::fake() et ne pousse rien en cas de rollback', function () {
    Queue::fake();

    $city = City::factory()->create();
    $client = User::factory()->create(['role' => 'client', 'city_id' => $city->id, 'device_token' => 'tok-client-ac']);
    $merchantUser = User::factory()->create(['role' => 'merchant', 'city_id' => $city->id, 'device_token' => 'tok-merch-ac']);
    $merchant = Merchant::factory()->create([
        'user_id' => $merchantUser->id,
        'city_id' => $city->id,
        'is_open' => true,
        'is_active' => true,
        'lat' => 6.8770,
        'lng' => -6.4502,
    ]);
    $product = Product::factory()->create(['merchant_id' => $merchant->id, 'price' => 2500, 'is_available' => true]);

    $driver = User::factory()->create(['role' => 'driver', 'city_id' => $city->id, 'device_token' => 'tok-drv-ac']);
    Courier::factory()->create([
        'user_id' => $driver->id,
        'city_id' => $city->id,
        'is_online' => true,
        'is_active' => true,
        'current_lat' => 6.8771,
        'current_lng' => -6.4502,
    ]);

    // 1. Rollback d'une transaction DB -> aucun job queue ne doit partir
    $pendingOrder = Order::factory()->create([
        'city_id' => $city->id,
        'client_id' => $client->id,
        'merchant_id' => $merchant->id,
    ]);

    try {
        DB::transaction(function () use ($pendingOrder): void {
            app(NotificationService::class)->notifierCommandeAcceptee($pendingOrder);
            throw new RuntimeException('Rollback volontaire');
        });
    } catch (RuntimeException) {
    }

    Queue::assertNotPushed(SendPushNotification::class);

    // 2. Commit d'une commande GoShop -> jobs envoyes apres commit
    $order = app(OrderService::class)->creerCommande($client, [
        'merchant_id' => $merchant->id,
        'items' => [['product_id' => $product->id, 'quantity' => 1]],
        'delivery_address' => 'Quartier Commerce',
        'delivery_lat' => 6.8800,
        'delivery_lng' => -6.4510,
        'payment_method' => 'cash',
    ]);

    expect($order->id)->not->toBeNull();
    Queue::assertPushed(AnnulerCommandeTimeout::class, fn (AnnulerCommandeTimeout $job): bool => $job->orderId === $order->id);
    Queue::assertPushed(SendPushNotification::class, fn (SendPushNotification $job): bool => $job->deviceToken === 'tok-merch-ac');
});

it('P6 Point 8 panne Redis: capture exception queue apres commit, log critical et conserve la commande et le colis', function () {
    $previousQueue = config('queue.default');
    enregistrerQueueEnPanne('failing_redis_'.Str::lower(Str::random(6)));

    Log::spy();

    try {
        $city = City::factory()->create();
        $client = User::factory()->create(['role' => 'client', 'city_id' => $city->id, 'device_token' => 'tok-c-fail']);
        $merchantUser = User::factory()->create(['role' => 'merchant', 'city_id' => $city->id, 'device_token' => 'tok-m-fail']);
        $merchant = Merchant::factory()->create([
            'user_id' => $merchantUser->id,
            'city_id' => $city->id,
            'is_open' => true,
            'is_active' => true,
            'lat' => 6.8770,
            'lng' => -6.4502,
        ]);
        $product = Product::factory()->create(['merchant_id' => $merchant->id, 'price' => 3000, 'is_available' => true]);

        $driver = User::factory()->create(['role' => 'driver', 'city_id' => $city->id, 'device_token' => 'tok-d-fail']);
        Courier::factory()->create([
            'user_id' => $driver->id,
            'city_id' => $city->id,
            'is_online' => true,
            'is_active' => true,
            'current_lat' => 6.8771,
            'current_lng' => -6.4502,
        ]);

        // 1. Creation commande GoShop malgre Redis en panne -> 201 + commande persistee + Log::critical
        $resOrder = $this->actingAs($client, 'api')
            ->postJson('/api/v1/client/orders', [
                'merchant_id' => $merchant->id,
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
                'delivery_address' => 'Quartier Tazibouo',
                'delivery_lat' => 6.8800,
                'delivery_lng' => -6.4510,
                'payment_method' => 'cash',
            ]);

        $resOrder->assertStatus(201);
        $orderId = (int) $resOrder->json('donnees.id');
        expect(Order::find($orderId))->not->toBeNull();

        // 2. Creation colis GoColis malgre Redis en panne -> 201 + colis persiste + Log::critical
        $resParcel = $this->actingAs($client, 'api')
            ->postJson('/api/v1/client/parcels', [
                'recipient_name' => 'Konan Aya',
                'recipient_phone' => '0701020304',
                'pickup_address' => 'Gare routiere',
                'pickup_lat' => 6.8770,
                'pickup_lng' => -6.4502,
                'delivery_address' => 'Quartier Soleil',
                'delivery_lat' => 6.8800,
                'delivery_lng' => -6.4510,
                'size' => 'petit',
                'weight_kg' => 0.8,
                'payment_method' => 'cash',
            ]);

        $resParcel->assertStatus(201);
        $parcelId = (int) $resParcel->json('donnees.id');
        expect(Parcel::find($parcelId))->not->toBeNull();

        Log::shouldHaveReceived('critical')->atLeast()->times(2);
    } finally {
        config(['queue.default' => $previousQueue]);
    }
});
