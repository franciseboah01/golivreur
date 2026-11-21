<?php

use App\Models\City;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Parcel;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Tymon\JWTAuth\Facades\JWTAuth;

it('Point 10 : GET /me/security renvoie password_set, telephone masque et codes actifs du proprietaire uniquement (isolation stricte)', function () {
    $city = City::factory()->create(['name' => 'Daloa']);
    $client = User::factory()->create([
        'role' => 'client',
        'phone' => '0701554433',
        'password' => Hash::make('Secret2026'),
        'city_id' => $city->id,
    ]);
    $otherClient = User::factory()->create([
        'role' => 'client',
        'phone' => '0709991111',
        'city_id' => $city->id,
    ]);
    $merchant = Merchant::factory()->create(['city_id' => $city->id]);

    $activeOrder = Order::factory()->create([
        'client_id' => $client->id,
        'merchant_id' => $merchant->id,
        'city_id' => $city->id,
        'status' => Order::STATUS_ACCEPTED,
        'confirmation_code' => Hash::make('4321'),
        'confirmation_code_enc' => Crypt::encryptString('4321'),
    ]);

    $deliveredOrder = Order::factory()->create([
        'client_id' => $client->id,
        'merchant_id' => $merchant->id,
        'city_id' => $city->id,
        'status' => Order::STATUS_DELIVERED,
        'confirmation_code' => Hash::make('9999'),
        'confirmation_code_enc' => Crypt::encryptString('9999'),
    ]);

    $activeParcel = Parcel::factory()->create([
        'sender_id' => $client->id,
        'city_id' => $city->id,
        'status' => Parcel::STATUS_ACCEPTED,
        'sender_code' => Hash::make('6789'),
        'sender_code_enc' => Crypt::encryptString('6789'),
    ]);

    // Propriétaire voit ses codes actifs uniquement (pas la commande déjà livrée)
    $res = $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($client))
        ->getJson('/api/v1/me/security')
        ->assertOk()
        ->assertJsonPath('donnees.password_set', true)
        ->assertJsonPath('donnees.masked_phone', '07****433');

    $codes = collect($res->json('donnees.active_delivery_codes'));
    expect($codes->pluck('code')->all())->toContain('4321', '6789')
        ->not->toContain('9999');

    // Autre utilisateur ne voit JAMAIS les codes de $client
    Auth::forgetGuards();
    $this->app->forgetScopedInstances();
    JWTAuth::unsetToken();

    $otherRes = $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($otherClient))
        ->getJson('/api/v1/me/security')
        ->assertOk();

    expect($otherRes->json('donnees.active_delivery_codes'))->toBeEmpty();
});
