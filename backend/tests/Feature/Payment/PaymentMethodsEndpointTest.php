<?php

use App\Models\City;
use App\Models\User;
use App\Services\SettingsService;
use Tymon\JWTAuth\Facades\JWTAuth;

it('P5 GET /client/payment-methods renvoie ["cash"] par défaut avec default="cash"', function () {
    $response = $this->getJson('/api/v1/client/payment-methods');

    $response->assertOk()
        ->assertJsonPath('succes', true)
        ->assertJsonPath('donnees.default', 'cash')
        ->assertJsonPath('donnees.modes_actifs', ['cash'])
        ->assertJsonCount(1, 'donnees.methodes')
        ->assertJsonPath('donnees.methodes.0.id', 'cash')
        ->assertJsonPath('donnees.methodes.0.label', 'Espèces à la livraison')
        ->assertJsonPath('donnees.methodes.0.is_default', true);
});

it('P5 GET /client/payment-methods reflète dynamiquement les surcharges globales et par ville de SettingsService', function () {
    $daloa = City::factory()->create(['name' => 'Daloa']);
    $bouake = City::factory()->create(['name' => 'Bouaké']);

    $clientDaloa = User::factory()->create(['role' => 'client', 'city_id' => $daloa->id]);
    $tokenDaloa = JWTAuth::fromUser($clientDaloa);

    app(SettingsService::class)->set('paiements', [
        'modes_actifs' => ['cash', 'orange_money', 'mtn_momo'],
    ], $daloa->id);

    // Client connecté à Daloa reçoit cash + orange_money + mtn_momo
    $resDaloa = $this->withHeader('Authorization', "Bearer {$tokenDaloa}")
        ->getJson('/api/v1/client/payment-methods');

    $resDaloa->assertOk()
        ->assertJsonPath('donnees.city_id', $daloa->id)
        ->assertJsonPath('donnees.default', 'cash')
        ->assertJsonPath('donnees.modes_actifs', ['cash', 'orange_money', 'mtn_momo'])
        ->assertJsonCount(3, 'donnees.methodes');

    // Bouaké reste sur ["cash"]
    $resBouake = $this->getJson("/api/v1/client/payment-methods?city_id={$bouake->id}");
    $resBouake->assertOk()
        ->assertJsonPath('donnees.city_id', $bouake->id)
        ->assertJsonPath('donnees.default', 'cash')
        ->assertJsonPath('donnees.modes_actifs', ['cash'])
        ->assertJsonCount(1, 'donnees.methodes');
});
