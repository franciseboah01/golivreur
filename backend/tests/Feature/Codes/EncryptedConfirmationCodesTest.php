<?php

use App\Filament\Resources\OrderResource\Pages\ListOrders;
use App\Filament\Resources\ParcelResource\Pages\ListParcels;
use App\Models\ActivityLog;
use App\Models\City;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Parcel;
use App\Models\Product;
use App\Models\RoleHabilitation;
use App\Models\User;
use App\Services\SettingsService;
use Database\Seeders\RoleHabilitationSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tymon\JWTAuth\Facades\JWTAuth;

it('P7 Point 1 GoShop: stocke confirmation_code_enc, masque dans JSON generiques et autorise seulement le client proprietaire sur statuts actifs', function () {
    Queue::fake();
    $this->seed(RoleHabilitationSeeder::class);

    $city = City::factory()->create(['center_lat' => 6.8770, 'center_lng' => -6.4502]);
    app(SettingsService::class)->set('paiements', ['modes_actifs' => ['cash']], $city->id);

    $client = User::factory()->create(['role' => 'client', 'city_id' => $city->id]);
    $otherClient = User::factory()->create(['role' => 'client', 'city_id' => $city->id]);
    $merchantUser = User::factory()->create(['role' => 'merchant', 'city_id' => $city->id]);
    $merchant = Merchant::factory()->create([
        'user_id' => $merchantUser->id,
        'city_id' => $city->id,
        'lat' => 6.8770,
        'lng' => -6.4502,
        'is_active' => true,
        'is_open' => true,
        'open_hours' => null,
    ]);
    $product = Product::factory()->create(['merchant_id' => $merchant->id, 'price' => 2500, 'is_available' => true]);

    $clientToken = JWTAuth::fromUser($client);

    $createRes = $this->withHeader('Authorization', "Bearer {$clientToken}")
        ->postJson('/api/v1/client/orders', [
            'merchant_id' => $merchant->id,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'delivery_address' => 'Quartier Lobia',
            'delivery_lat' => 6.8810,
            'delivery_lng' => -6.4530,
            'payment_method' => 'cash',
        ])
        ->assertCreated();

    $orderId = $createRes->json('donnees.id');
    $order = Order::findOrFail($orderId);

    expect($order->confirmation_code_enc)->not->toBeNull();
    $plainCode = Crypt::decryptString((string) $order->confirmation_code_enc);
    expect($plainCode)->toMatch('/^\d{4}$/')
        ->and(Hash::check($plainCode, $order->confirmation_code))->toBeTrue();

    // Jamais expose dans les JSON generiques
    expect($createRes->json('donnees'))->not->toHaveKeys(['confirmation_code', 'confirmation_code_enc']);
    $showRes = $this->withHeader('Authorization', "Bearer {$clientToken}")
        ->getJson("/api/v1/client/orders/{$order->id}")
        ->assertOk();
    expect($showRes->json('donnees'))->not->toHaveKeys(['confirmation_code', 'confirmation_code_enc']);
    $listRes = $this->withHeader('Authorization', "Bearer {$clientToken}")
        ->getJson('/api/v1/client/orders')
        ->assertOk();
    expect($listRes->json('donnees.data.0'))->not->toHaveKeys(['confirmation_code', 'confirmation_code_enc']);

    // Avant acceptation (pending) -> 422
    $this->withHeader('Authorization', "Bearer {$clientToken}")
        ->getJson("/api/v1/client/orders/{$order->id}/confirmation-code")
        ->assertStatus(422);

    // Statuts valides : accepted, waiting, ready, picked_up, in_transit -> 200
    foreach ([Order::STATUS_ACCEPTED, Order::STATUS_WAITING, Order::STATUS_READY, Order::STATUS_PICKED_UP, Order::STATUS_IN_TRANSIT] as $validStatus) {
        $order->update(['status' => $validStatus]);
        $this->withHeader('Authorization', "Bearer {$clientToken}")
            ->getJson("/api/v1/client/orders/{$order->id}/confirmation-code")
            ->assertOk()
            ->assertJsonPath('succes', true)
            ->assertJsonPath('donnees.confirmation_code', $plainCode);
    }

    // Tiers (autre client, livreur, commerce, admin) -> 403
    Auth::forgetGuards();
    $this->app->forgetScopedInstances();
    JWTAuth::unsetToken();
    $otherToken = JWTAuth::fromUser($otherClient);
    $this->withHeader('Authorization', "Bearer {$otherToken}")
        ->getJson("/api/v1/client/orders/{$order->id}/confirmation-code")
        ->assertStatus(403);

    $driver = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    Courier::factory()->create(['user_id' => $driver->id, 'city_id' => $city->id]);
    Auth::forgetGuards();
    $this->app->forgetScopedInstances();
    JWTAuth::unsetToken();
    $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($driver))
        ->getJson("/api/v1/client/orders/{$order->id}/confirmation-code")
        ->assertStatus(403);

    Auth::forgetGuards();
    $this->app->forgetScopedInstances();
    JWTAuth::unsetToken();
    $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($merchantUser))
        ->getJson("/api/v1/client/orders/{$order->id}/confirmation-code")
        ->assertStatus(403);

    // Apres livraison -> 422
    $order->update(['status' => Order::STATUS_DELIVERED]);
    Auth::forgetGuards();
    $this->app->forgetScopedInstances();
    JWTAuth::unsetToken();
    $this->withHeader('Authorization', "Bearer {$clientToken}")
        ->getJson("/api/v1/client/orders/{$order->id}/confirmation-code")
        ->assertStatus(422);
});

it('P7 Point 1 GoColis: stocke sender_code_enc, bloque tiers/apres picked_up et ne permet jamais de lire recipient_code', function () {
    Queue::fake();

    $city = City::factory()->create(['center_lat' => 6.8770, 'center_lng' => -6.4502]);
    app(SettingsService::class)->set('paiements', ['modes_actifs' => ['cash']], $city->id);

    $sender = User::factory()->create(['role' => 'client', 'city_id' => $city->id]);
    $otherClient = User::factory()->create(['role' => 'client', 'city_id' => $city->id]);
    $senderToken = JWTAuth::fromUser($sender);

    $createRes = $this->withHeader('Authorization', "Bearer {$senderToken}")
        ->postJson('/api/v1/client/parcels', [
            'recipient_name' => 'Awa Kone',
            'recipient_phone' => '0701020304',
            'delivery_address' => 'Quartier Soleil',
            'delivery_lat' => 6.8820,
            'delivery_lng' => -6.4520,
            'pickup_address' => 'Quartier Commerce',
            'pickup_lat' => 6.8770,
            'pickup_lng' => -6.4502,
            'size' => 'petit',
            'weight_kg' => 0.5,
            'payment_method' => 'cash',
        ])
        ->assertCreated();

    $parcel = Parcel::findOrFail($createRes->json('donnees.id'));
    expect($parcel->sender_code_enc)->not->toBeNull();
    expect($parcel->recipient_code_enc)->not->toBeNull();
    $senderPlain = Crypt::decryptString((string) $parcel->sender_code_enc);
    $recipientPlain = Crypt::decryptString((string) $parcel->recipient_code_enc);
    expect($senderPlain)->toMatch('/^\d{4}$/')
        ->and(Hash::check($senderPlain, $parcel->sender_code))->toBeTrue()
        ->and($recipientPlain)->toMatch('/^\d{4}$/')
        ->and(Hash::check($recipientPlain, $parcel->recipient_code))->toBeTrue()
        ->and($parcel->admin_sender_code)->toBe($senderPlain)
        ->and($parcel->admin_recipient_code)->toBe($recipientPlain);

    // JSON generique showClient ne contient aucun code
    $showRes = $this->withHeader('Authorization', "Bearer {$senderToken}")
        ->getJson("/api/v1/client/parcels/{$parcel->id}")
        ->assertOk();
    expect($showRes->json('donnees'))->not->toHaveKeys(['sender_code', 'sender_code_enc', 'recipient_code', 'recipient_code_enc', 'sender_code_plain']);

    // Avant picked_up (pending, waiting, accepted, at_sender) -> 200
    foreach ([Parcel::STATUS_PENDING, Parcel::STATUS_WAITING, Parcel::STATUS_ACCEPTED, Parcel::STATUS_AT_SENDER] as $statusBefore) {
        $parcel->update(['status' => $statusBefore]);
        $this->withHeader('Authorization', "Bearer {$senderToken}")
            ->getJson("/api/v1/client/parcels/{$parcel->id}/sender-code")
            ->assertOk()
            ->assertJsonPath('succes', true)
            ->assertJsonPath('donnees.sender_code', $senderPlain);
    }

    // Tiers -> 403
    Auth::forgetGuards();
    $this->app->forgetScopedInstances();
    JWTAuth::unsetToken();
    $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($otherClient))
        ->getJson("/api/v1/client/parcels/{$parcel->id}/sender-code")
        ->assertStatus(403);

    // Aucun endpoint pour lire recipient_code
    Auth::forgetGuards();
    $this->app->forgetScopedInstances();
    JWTAuth::unsetToken();
    $this->withHeader('Authorization', "Bearer {$senderToken}")
        ->getJson("/api/v1/client/parcels/{$parcel->id}/recipient-code")
        ->assertStatus(404);

    // Apres picked_up -> 422
    foreach ([Parcel::STATUS_PICKED_UP, Parcel::STATUS_IN_TRANSIT, Parcel::STATUS_AT_RECIPIENT, Parcel::STATUS_DELIVERED] as $statusAfter) {
        $parcel->update(['status' => $statusAfter]);
        $this->withHeader('Authorization', "Bearer {$senderToken}")
            ->getJson("/api/v1/client/parcels/{$parcel->id}/sender-code")
            ->assertStatus(422);
    }
});

it('P7 Point 1 legacy et regeneration admin: renvoie 409 sur code sans chiffrement et regenere de facon tracee (API + Filament)', function () {
    $this->seed(RoleHabilitationSeeder::class);

    $cityDaloa = City::factory()->create(['name' => 'Daloa']);
    $cityBouake = City::factory()->create(['name' => 'Bouaké']);

    $client = User::factory()->create(['role' => 'client', 'city_id' => $cityDaloa->id]);
    $merchant = Merchant::factory()->create(['city_id' => $cityDaloa->id]);
    $driver = User::factory()->create(['role' => 'driver', 'city_id' => $cityDaloa->id]);
    $courier = Courier::factory()->create(['user_id' => $driver->id, 'city_id' => $cityDaloa->id]);

    $supportHab = RoleHabilitation::where('slug', 'city_support')->first();
    $supportDaloa = User::factory()->create([
        'role' => 'city_support',
        'role_habilitation_id' => $supportHab?->id,
        'city_id' => $cityDaloa->id,
    ]);
    $managerBouake = User::factory()->create([
        'role' => 'city_manager',
        'role_habilitation_id' => RoleHabilitation::where('slug', 'city_manager')->value('id'),
        'city_id' => $cityBouake->id,
    ]);
    $superAdmin = User::factory()->create([
        'role' => 'super_admin',
        'role_habilitation_id' => RoleHabilitation::where('slug', 'super_admin')->value('id'),
        'city_id' => null,
    ]);

    // Ancienne commande sans confirmation_code_enc
    $legacyOrder = Order::factory()->create([
        'client_id' => $client->id,
        'merchant_id' => $merchant->id,
        'courier_id' => $courier->id,
        'city_id' => $cityDaloa->id,
        'status' => Order::STATUS_ACCEPTED,
        'confirmation_code' => Hash::make('1234'),
        'confirmation_code_enc' => null,
    ]);

    // Ancien colis sans sender_code_enc
    $legacyParcel = Parcel::factory()->create([
        'sender_id' => $client->id,
        'city_id' => $cityDaloa->id,
        'status' => Parcel::STATUS_ACCEPTED,
        'sender_code' => Hash::make('5678'),
        'sender_code_enc' => null,
    ]);

    $clientToken = JWTAuth::fromUser($client);

    $this->withHeader('Authorization', "Bearer {$clientToken}")
        ->getJson("/api/v1/client/orders/{$legacyOrder->id}/confirmation-code")
        ->assertStatus(409)
        ->assertJsonPath('succes', false)
        ->assertJsonPath('message', 'code indisponible, régénérer');

    $this->withHeader('Authorization', "Bearer {$clientToken}")
        ->getJson("/api/v1/client/parcels/{$legacyParcel->id}/sender-code")
        ->assertStatus(409)
        ->assertJsonPath('succes', false)
        ->assertJsonPath('message', 'code indisponible, régénérer');

    // Admin d'une autre ville bloque (404)
    Auth::forgetGuards();
    JWTAuth::unsetToken();
    $this->actingAs($managerBouake, 'api')
        ->postJson("/api/v1/admin/orders/{$legacyOrder->id}/regenerate-code")
        ->assertStatus(404);

    // Regeneration API tracee pour la commande
    Auth::forgetGuards();
    JWTAuth::unsetToken();
    $this->actingAs($supportDaloa, 'api');
    $regenOrderRes = $this->postJson("/api/v1/admin/orders/{$legacyOrder->id}/regenerate-code")
        ->assertOk()
        ->assertJsonPath('succes', true);

    $legacyOrder->refresh();
    expect($legacyOrder->confirmation_code_enc)->not->toBeNull();
    $newOrderCode = Crypt::decryptString((string) $legacyOrder->confirmation_code_enc);
    expect($regenOrderRes->getContent())->not->toContain($newOrderCode)
        ->and(Hash::check($newOrderCode, $legacyOrder->confirmation_code))->toBeTrue();

    $orderLog = ActivityLog::where('action', 'order_confirmation_code_regenerated')
        ->where('entity_id', $legacyOrder->id)
        ->latest('id')
        ->first();
    expect($orderLog)->not->toBeNull()
        ->and(json_encode($orderLog->properties))->not->toContain($newOrderCode);

    // Le client lit maintenant son nouveau code
    Auth::forgetGuards();
    JWTAuth::unsetToken();
    $this->withHeader('Authorization', "Bearer {$clientToken}")
        ->getJson("/api/v1/client/orders/{$legacyOrder->id}/confirmation-code")
        ->assertOk()
        ->assertJsonPath('donnees.confirmation_code', $newOrderCode);

    // Regeneration API tracee pour le colis
    Auth::forgetGuards();
    JWTAuth::unsetToken();
    $regenParcelRes = $this->actingAs($supportDaloa, 'api')
        ->postJson("/api/v1/admin/parcels/{$legacyParcel->id}/regenerate-sender-code")
        ->assertOk()
        ->assertJsonPath('succes', true);

    $legacyParcel->refresh();
    expect($legacyParcel->sender_code_enc)->not->toBeNull();
    $newParcelCode = Crypt::decryptString((string) $legacyParcel->sender_code_enc);
    expect($regenParcelRes->getContent())->not->toContain($newParcelCode)
        ->and(Hash::check($newParcelCode, $legacyParcel->sender_code))->toBeTrue();

    $parcelLog = ActivityLog::where('action', 'parcel_sender_code_regenerated')
        ->where('entity_id', $legacyParcel->id)
        ->latest('id')
        ->first();
    expect($parcelLog)->not->toBeNull()
        ->and(json_encode($parcelLog->properties))->not->toContain($newParcelCode);

    // Verification hash fonctionne toujours avec le nouveau code
    Auth::forgetGuards();
    JWTAuth::unsetToken();
    $legacyOrder->update(['status' => Order::STATUS_PICKED_UP]);
    $this->actingAs($driver, 'api')
        ->postJson("/api/v1/driver/orders/{$legacyOrder->id}/confirm-delivery", ['code' => $newOrderCode])
        ->assertOk();

    // Actions Filament de regeneration egalement tracees
    Auth::forgetGuards();
    JWTAuth::unsetToken();
    $activeOrder = Order::factory()->create([
        'client_id' => $client->id,
        'merchant_id' => $merchant->id,
        'city_id' => $cityDaloa->id,
        'status' => Order::STATUS_ACCEPTED,
        'confirmation_code_enc' => null,
    ]);
    $this->actingAs($superAdmin);
    Livewire::test(ListOrders::class)
        ->callTableAction('regenerateCode', $activeOrder)
        ->assertHasNoTableActionErrors();
    expect($activeOrder->fresh()->confirmation_code_enc)->not->toBeNull();

    Livewire::test(ListParcels::class)
        ->callTableAction('regenerateSenderCode', $legacyParcel)
        ->assertHasNoTableActionErrors();
    expect(ActivityLog::where('action', 'parcel_sender_code_regenerated')->where('entity_id', $legacyParcel->id)->count())->toBe(2);
});

it('rend les OTP de connexion, de livraison GoShop et d\'expedition/livraison GoColis visibles pour l\'admin', function () {
    $this->seed(RoleHabilitationSeeder::class);

    $city = City::factory()->create(['name' => 'Daloa']);
    $client = User::factory()->create(['role' => 'client', 'phone' => '0701122334', 'city_id' => $city->id, 'is_active' => true]);

    // 1. OTP de connexion stocké chiffré et lisible par l'admin
    $this->postJson('/api/v1/auth/login', ['phone' => '0701122334'])
        ->assertOk();

    $otpRecord = \App\Models\OtpCode::where('phone', '0701122334')->where('purpose', 'login')->latest('id')->first();
    expect($otpRecord)->not->toBeNull()
        ->and($otpRecord->code_enc)->not->toBeNull()
        ->and($otpRecord->admin_otp_code)->toMatch('/^\d{4}$/')
        ->and($client->fresh()->admin_latest_otp_code)->toBe($otpRecord->admin_otp_code);

    // 2. OTP de livraison GoShop et d'expédition/livraison GoColis déchiffrables pour l'admin
    $order = Order::factory()->create(['client_id' => $client->id, 'city_id' => $city->id]);
    $parcel = Parcel::factory()->create(['sender_id' => $client->id, 'city_id' => $city->id]);

    expect($order->admin_confirmation_code)->toBe('1234')
        ->and($parcel->admin_sender_code)->toBe('1234')
        ->and($parcel->admin_recipient_code)->toBe('5678');
});
