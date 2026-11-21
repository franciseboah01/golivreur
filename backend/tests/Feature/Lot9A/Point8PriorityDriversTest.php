<?php

use App\Models\City;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\User;
use Tymon\JWTAuth\Facades\JWTAuth;

it('Point 8 : POST /business/priority-drivers cree un livreur inactif lie au commercant, isolation stricte (autre livreur -> 404), non valide bloque', function () {
    $city = City::factory()->create(['name' => 'Daloa']);
    $merchantUserA = User::factory()->create(['role' => 'merchant', 'city_id' => $city->id, 'is_active' => true]);
    $merchantA = Merchant::factory()->create(['user_id' => $merchantUserA->id, 'city_id' => $city->id, 'is_active' => true]);

    $merchantUserB = User::factory()->create(['role' => 'merchant', 'city_id' => $city->id, 'is_active' => true]);
    $merchantB = Merchant::factory()->create(['user_id' => $merchantUserB->id, 'city_id' => $city->id, 'is_active' => true]);

    $tokenA = JWTAuth::fromUser($merchantUserA);

    // 1. Création d'un livreur prioritaire pré-inscrit INACTIF lié au commerçant A
    $createRes = $this->withHeader('Authorization', "Bearer {$tokenA}")
        ->postJson('/api/v1/business/priority-drivers', [
            'name' => 'Yao Livreur Boutique A',
            'phone' => '0703881122',
            'password' => 'Livreur2026!',
            'vehicle_type' => 'moto',
            'vehicle_plate' => '4567 CI 01',
        ])
        ->assertCreated()
        ->assertJsonPath('succes', true)
        ->assertJsonPath('donnees.is_active', false)
        ->assertJsonPath('donnees.validation_status', 'pending');

    $courierAId = (int) $createRes->json('donnees.id');
    $courierA = Courier::findOrFail($courierAId);
    expect($courierA->is_active)->toBeFalse()
        ->and((int) $courierA->merchant_id)->toBe($merchantA->id);

    // 2. Doublon de numéro refusé (422)
    $this->withHeader('Authorization', "Bearer {$tokenA}")
        ->postJson('/api/v1/business/priority-drivers', [
            'name' => 'Autre',
            'phone' => '0703881122',
            'password' => 'Livreur2026!',
            'vehicle_type' => 'moto',
            'vehicle_plate' => '9999 CI 01',
        ])
        ->assertStatus(422);

    // 3. Impossible de sélectionner un livreur non encore validé -> 422
    $this->withHeader('Authorization', "Bearer {$tokenA}")
        ->putJson("/api/v1/business/priority-drivers/{$courierA->id}/select")
        ->assertStatus(422);

    // 4. Un livreur d'un autre commerçant (ou global) -> 404 (accès croisé bloqué)
    $otherDriverUser = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    $otherCourier = Courier::factory()->create([
        'user_id' => $otherDriverUser->id,
        'city_id' => $city->id,
        'merchant_id' => $merchantB->id,
        'is_active' => true,
        'validation_status' => 'approved',
    ]);

    $this->withHeader('Authorization', "Bearer {$tokenA}")
        ->putJson("/api/v1/business/priority-drivers/{$otherCourier->id}/select")
        ->assertStatus(404);

    // 5. GET /business/priority-drivers ne liste QUE les livreurs de A (pas ceux de B)
    $listRes = $this->withHeader('Authorization', "Bearer {$tokenA}")
        ->getJson('/api/v1/business/priority-drivers')
        ->assertOk();
    $listedIds = collect($listRes->json('donnees.drivers'))->pluck('id')->all();
    expect($listedIds)->toContain($courierA->id)
        ->not->toContain($otherCourier->id);

    // 6. Après validation admin -> le commerçant A peut le choisir comme prioritaire
    $courierA->update(['is_active' => true, 'activated_at' => now(), 'validation_status' => 'approved']);
    $courierA->user->update(['is_active' => true]);

    $this->withHeader('Authorization', "Bearer {$tokenA}")
        ->putJson("/api/v1/business/priority-drivers/{$courierA->id}/select")
        ->assertOk()
        ->assertJsonPath('donnees.priority_courier_id', $courierA->id);
});
