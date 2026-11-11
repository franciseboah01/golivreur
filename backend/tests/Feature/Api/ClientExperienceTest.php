<?php

use App\Filament\Resources\CityResource\Pages\{CreateCity, ListCities};
use App\Filament\Resources\NeighborhoodResource\Pages\{CreateNeighborhood, ListNeighborhoods};
use App\Models\{City, ClientAddress, Merchant, Neighborhood, User};
use Livewire\Livewire;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

it('reflète l\'état réel du mode pluie de la ville dans city-status', function () {
    $city = City::factory()->create([
        'name'             => 'Daloa Test',
        'is_active'        => true,
        'rain_mode_active' => false,
    ]);

    $this->getJson("/api/v1/client/city-status?city_id={$city->id}")
        ->assertOk()
        ->assertJsonPath('donnees.rain_mode_active', false)
        ->assertJsonPath('donnees.rain_delay_minutes', 0)
        ->assertJsonStructure([
            'donnees' => ['bareme', 'neighborhoods'],
        ]);

    $city->update(['rain_mode_active' => true]);

    $this->getJson("/api/v1/client/city-status?city_id={$city->id}")
        ->assertOk()
        ->assertJsonPath('donnees.rain_mode_active', true)
        ->assertJsonPath('donnees.rain_delay_minutes', 15);
});

it('permet à un client d\'ajouter, modifier, choisir par défaut et supprimer ses adresses de livraison', function () {
    $city = City::factory()->create(['name' => 'Daloa', 'is_active' => true]);
    $client = User::factory()->create([
        'role'    => 'client',
        'city_id' => $city->id,
    ]);
    $token = JWTAuth::fromUser($client);

    // 1. Créer une première adresse -> devient automatiquement adresse par défaut
    $create1 = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/client/addresses', [
            'label'        => 'Maison',
            'neighborhood' => 'Lobia',
            'address_line' => 'Face Grande Mosquée, portail bleu',
        ])
        ->assertCreated()
        ->assertJsonPath('donnees.is_default', true)
        ->assertJsonPath('donnees.neighborhood', 'Lobia');

    $addr1Id = $create1->json('donnees.id');
    expect($client->fresh()->default_address)->toBe('Daloa, Lobia — Face Grande Mosquée, portail bleu');

    // 2. Créer une seconde adresse marquée par défaut
    $create2 = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/client/addresses', [
            'label'        => 'Bureau',
            'neighborhood' => 'Quartier Commerce',
            'address_line' => 'Immeuble Palmier',
            'is_default'   => true,
        ])
        ->assertCreated()
        ->assertJsonPath('donnees.is_default', true);

    $addr2Id = $create2->json('donnees.id');
    expect(ClientAddress::find($addr1Id)->is_default)->toBeFalse();
    expect($client->fresh()->default_address)->toBe('Daloa, Quartier Commerce — Immeuble Palmier');

    // 3. Modifier la première adresse et la remettre par défaut
    $this->withHeader('Authorization', "Bearer {$token}")
        ->putJson("/api/v1/client/addresses/{$addr1Id}", [
            'label'        => 'Domicile',
            'neighborhood' => 'Kennedy',
            'address_line' => 'Carrefour Pharmacie',
        ])
        ->assertOk()
        ->assertJsonPath('donnees.neighborhood', 'Kennedy');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/client/addresses/{$addr1Id}/default")
        ->assertOk()
        ->assertJsonPath('donnees.is_default', true);

    expect($client->fresh()->default_address)->toBe('Daloa, Kennedy — Carrefour Pharmacie');

    // 4. Supprimer l'adresse par défaut promouvra la restante
    $this->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson("/api/v1/client/addresses/{$addr1Id}")
        ->assertOk();

    expect(ClientAddress::find($addr2Id)->is_default)->toBeTrue();
    expect($client->fresh()->default_address)->toBe('Daloa, Quartier Commerce — Immeuble Palmier');
});

it('bloque la modification d\'une adresse appartenant à un autre client (403)', function () {
    $city = City::factory()->create(['is_active' => true]);
    $owner = User::factory()->create(['role' => 'client', 'city_id' => $city->id]);
    $attacker = User::factory()->create(['role' => 'client', 'city_id' => $city->id]);

    $address = ClientAddress::create([
        'user_id'      => $owner->id,
        'city_id'      => $city->id,
        'label'        => 'Maison',
        'neighborhood' => 'Hublot',
        'address_line' => 'Rue 12',
        'is_default'   => true,
    ]);

    $attackerToken = JWTAuth::fromUser($attacker);

    $this->withHeader('Authorization', "Bearer {$attackerToken}")
        ->putJson("/api/v1/client/addresses/{$address->id}", ['label' => 'Piraté'])
        ->assertStatus(403);

    $this->withHeader('Authorization', "Bearer {$attackerToken}")
        ->deleteJson("/api/v1/client/addresses/{$address->id}")
        ->assertStatus(403);
});

it('permet à un client d\'ajouter et retirer un commerce de ses favoris', function () {
    $city = City::factory()->create(['is_active' => true]);
    $merchantUser = User::factory()->create(['role' => 'merchant', 'city_id' => $city->id]);
    $merchant = Merchant::factory()->create([
        'user_id'   => $merchantUser->id,
        'city_id'   => $city->id,
        'name'      => 'Maquis Le Baoulé',
        'is_active' => true,
    ]);

    $client = User::factory()->create(['role' => 'client', 'city_id' => $city->id]);
    $token = JWTAuth::fromUser($client);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/client/favorites/{$merchant->id}/toggle")
        ->assertOk()
        ->assertJsonPath('donnees.is_favorite', true);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/client/favorites')
        ->assertOk()
        ->assertJsonCount(1, 'donnees')
        ->assertJsonPath('donnees.0.id', $merchant->id);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson("/api/v1/client/favorites/{$merchant->id}/toggle")
        ->assertOk()
        ->assertJsonPath('donnees.is_favorite', false);
});

it('retourne une liste de quartiers vide si aucun quartier en base et expose uniquement les quartiers actifs créés', function () {
    $city = City::factory()->create([
        'name'      => 'Bouaké Test',
        'is_active' => true,
    ]);

    // Aucune donnée statique ne doit être inventée lorsque la table est vide
    $this->getJson("/api/v1/client/city-status?city_id={$city->id}")
        ->assertOk()
        ->assertJsonPath('donnees.city_name', 'Bouaké Test')
        ->assertJsonCount(0, 'donnees.neighborhoods');

    Neighborhood::create([
        'city_id'     => $city->id,
        'name'        => 'Zone Active Test',
        'is_active'   => true,
        'distance_km' => 2.4,
    ]);

    Neighborhood::create([
        'city_id'   => $city->id,
        'name'      => 'Zone Inactive Test',
        'is_active' => false,
    ]);

    $this->getJson("/api/v1/client/city-status?city_id={$city->id}")
        ->assertOk()
        ->assertJsonCount(1, 'donnees.neighborhoods')
        ->assertJsonPath('donnees.neighborhoods.0.name', 'Zone Active Test');
});

it('expose photo_url et city_name issus de la relation ville dans le profil client', function () {
    $city = City::factory()->create([
        'name'      => 'Yamoussoukro',
        'is_active' => true,
    ]);

    $client = User::factory()->create([
        'role'       => 'client',
        'city_id'    => $city->id,
        'photo_path' => 'profils/avatar_test_client.png',
    ]);

    $token = JWTAuth::fromUser($client);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/client/profile')
        ->assertOk()
        ->assertJsonPath('donnees.city_id', $city->id)
        ->assertJsonPath('donnees.city_name', 'Yamoussoukro')
        ->assertJsonPath('donnees.photo_url', url('storage/profils/avatar_test_client.png'));
});

it('expose un fallback null propre si photo ou ville est absente du profil client', function () {
    $client = User::factory()->create([
        'role'       => 'client',
        'city_id'    => null,
        'photo_path' => null,
        'avatar_url' => null,
    ]);

    $token = JWTAuth::fromUser($client);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/client/profile')
        ->assertOk()
        ->assertJsonPath('donnees.city_id', null)
        ->assertJsonPath('donnees.city_name', null)
        ->assertJsonPath('donnees.photo_url', null);
});

it('permet à un admin de gérer Villes et Quartiers avec formulaires vides sans préremplissage', function () {
    $admin = User::factory()->create([
        'role'      => 'super_admin',
        'is_active' => true,
    ]);

    $this->actingAs($admin);

    // Le formulaire Villes est accessible et totalement vide à la création
    Livewire::test(CreateCity::class)
        ->assertSet('data.name', null)
        ->assertSet('data.center_lat', null)
        ->assertSet('data.center_lng', null);

    $city = City::factory()->create([
        'name'      => 'San Pedro',
        'is_active' => true,
    ]);

    // Le formulaire Quartiers est vide et permet de rattacher un quartier à une ville
    Livewire::test(CreateNeighborhood::class)
        ->assertSet('data.city_id', null)
        ->assertSet('data.name', null)
        ->assertSet('data.distance_km', null)
        ->fillForm([
            'city_id'   => $city->id,
            'name'      => 'Cité Balmer',
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas('neighborhoods', [
        'city_id'   => $city->id,
        'name'      => 'Cité Balmer',
        'is_active' => true,
    ]);

    Livewire::test(ListCities::class)
        ->assertOk()
        ->assertActionExists('neighborhoods');

    Livewire::test(ListNeighborhoods::class)
        ->assertOk()
        ->assertCanSeeTableRecords(Neighborhood::where('city_id', $city->id)->get());
});

