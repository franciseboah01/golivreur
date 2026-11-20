<?php

use App\Filament\Pages\CashWalletOperationsPage;
use App\Models\City;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\User;
use App\Services\WalletService;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;
use Tymon\JWTAuth\Facades\JWTAuth;

beforeEach(function () {
    $this->daloa = City::factory()->create([
        'name' => 'Daloa',
        'is_active' => true,
        'center_lat' => 6.8770,
        'center_lng' => -6.4502,
    ]);

    $this->abidjan = City::factory()->create([
        'name' => 'Abidjan',
        'is_active' => true,
        'center_lat' => 5.3600,
        'center_lng' => -4.0083,
    ]);
});

it('permet au gestionnaire de crediter et debiter en especes un commerce par son numero avec visibilite immediate dans GL Business', function () {
    $manager = User::factory()->create([
        'role' => 'city_manager',
        'city_id' => $this->daloa->id,
        'is_active' => true,
    ]);

    $merchantUser = User::factory()->create([
        'role' => 'merchant',
        'phone' => '0705112233',
        'city_id' => $this->daloa->id,
        'is_active' => true,
    ]);
    $merchant = Merchant::factory()->create([
        'user_id' => $merchantUser->id,
        'city_id' => $this->daloa->id,
        'name' => 'Maquis Espèces Daloa',
        'is_active' => true,
    ]);

    $this->actingAs($manager);

    // 1. Recherche par numéro + crédit espèces de 15 000 F CFA via la page Filament
    Livewire::test(CashWalletOperationsPage::class)
        ->set('searchPhone', '+225 07 05 11 22 33')
        ->call('rechercherPartenaire')
        ->assertSet('selectedOwnerType', 'merchant')
        ->assertSet('selectedOwnerId', $merchant->id)
        ->set('direction', 'credit')
        ->set('amount', '15000')
        ->set('reason', 'Dépôt espèces reçu guichet #01')
        ->call('executerOperationEspeces')
        ->assertNotSet('lastReceipt', null)
        ->assertSet('lastReceipt.balance_after', 15000);

    // 2. Visible immédiatement dans l'API GL Business (/api/v1/merchant/wallet)
    $merchantToken = JWTAuth::fromUser($merchantUser);
    $resCredit = $this->withHeader('Authorization', "Bearer {$merchantToken}")
        ->getJson('/api/v1/merchant/wallet')
        ->assertOk()
        ->assertJsonPath('donnees.wallet.balance', 15000)
        ->assertJsonPath('donnees.versements_recents.0.type', 'credit')
        ->assertJsonPath('donnees.versements_recents.0.montant', 15000);

    expect((string) $resCredit->json('donnees.versements_recents.0.description'))
        ->toContain('Dépôt espèces en agence');

    // 3. Débit espèces de 4 000 F CFA -> solde passe à 11 000 F CFA
    $this->actingAs($manager);
    Livewire::test(CashWalletOperationsPage::class)
        ->set('searchPhone', '0705112233')
        ->call('rechercherPartenaire')
        ->set('direction', 'debit')
        ->set('amount', '4000')
        ->set('reason', 'Retrait liquide guichet #02')
        ->call('executerOperationEspeces')
        ->assertSet('lastReceipt.balance_after', 11000);

    $this->actingAs($merchantUser, 'api')
        ->withHeader('Authorization', "Bearer {$merchantToken}")
        ->getJson('/api/v1/merchant/wallet')
        ->assertOk()
        ->assertJsonPath('donnees.wallet.balance', 11000)
        ->assertJsonPath('donnees.versements_recents.0.type', 'debit')
        ->assertJsonPath('donnees.versements_recents.0.montant', 4000);

    // 4. Notification in-app bien envoyée au commerçant
    $this->assertDatabaseHas('notifications', [
        'user_id' => $merchantUser->id,
        'type' => 'wallet_cash_deposit',
    ]);
    $this->assertDatabaseHas('notifications', [
        'user_id' => $merchantUser->id,
        'type' => 'wallet_cash_withdrawal',
    ]);
});

it('permet au gestionnaire de crediter et debiter en especes un livreur par son numero avec visibilite immediate dans GL Driver', function () {
    $financeManager = User::factory()->create([
        'role' => 'city_finance',
        'city_id' => $this->daloa->id,
        'is_active' => true,
    ]);

    $driverUser = User::factory()->create([
        'role' => 'driver',
        'name' => 'Kouassi Livreur',
        'phone' => '0504223344',
        'city_id' => $this->daloa->id,
        'is_active' => true,
    ]);
    $courier = Courier::factory()->create([
        'user_id' => $driverUser->id,
        'city_id' => $this->daloa->id,
        'is_active' => true,
    ]);

    $this->actingAs($financeManager);

    // 1. Dépôt espèces de 20 000 F CFA sur le portefeuille livreur
    Livewire::test(CashWalletOperationsPage::class)
        ->set('searchPhone', '0504223344')
        ->call('rechercherPartenaire')
        ->assertSet('selectedOwnerType', 'courier')
        ->assertSet('selectedOwnerId', $courier->id)
        ->set('direction', 'credit')
        ->set('amount', '20000')
        ->set('reason', 'Recharge caution cash moto')
        ->call('executerOperationEspeces')
        ->assertSet('lastReceipt.balance_after', 20000);

    // 2. Visible immédiatement dans l'API GL Driver (/api/v1/driver/wallet)
    $driverToken = JWTAuth::fromUser($driverUser);
    $res = $this->withHeader('Authorization', "Bearer {$driverToken}")
        ->getJson('/api/v1/driver/wallet')
        ->assertOk()
        ->assertJsonPath('donnees.wallet.balance', 20000)
        ->assertJsonPath('donnees.transactions.data.0.type', 'deposit')
        ->assertJsonPath('donnees.transactions.data.0.amount', 20000);

    expect((string) $res->json('donnees.transactions.data.0.description'))
        ->toContain('Dépôt espèces en agence');

    // 3. Tentative de débit supérieur au solde (25 000 > 20 000) -> refusée, solde intact à 20 000
    expect(fn () => app(WalletService::class)->effectuerOperationEspeces(
        ownerType: 'courier',
        ownerId: $courier->id,
        direction: 'debit',
        montant: 25000,
        motif: 'Tentative découvert',
        gestionnaire: $financeManager,
    ))->toThrow(InvalidArgumentException::class);

    expect(app(WalletService::class)->solde('courier', $courier->id))->toBe(20000.0);
});

it('bloque strictement toute operation especes par un gestionnaire local sur un partenaire dune autre ville ou par un role non autorise', function () {
    $daloaManager = User::factory()->create([
        'role' => 'city_manager',
        'city_id' => $this->daloa->id,
        'is_active' => true,
    ]);

    $abidjanMerchantUser = User::factory()->create([
        'role' => 'merchant',
        'phone' => '0102998877',
        'city_id' => $this->abidjan->id,
        'is_active' => true,
    ]);
    $abidjanMerchant = Merchant::factory()->create([
        'user_id' => $abidjanMerchantUser->id,
        'city_id' => $this->abidjan->id,
        'is_active' => true,
    ]);

    // Gestionnaire Daloa ne peut pas créditer un commerce d'Abidjan
    expect(fn () => app(WalletService::class)->effectuerOperationEspeces(
        ownerType: 'merchant',
        ownerId: $abidjanMerchant->id,
        direction: 'credit',
        montant: 5000,
        motif: 'Cross-city interdit',
        gestionnaire: $daloaManager,
    ))->toThrow(AuthorizationException::class);

    // Un client ordinaire ne peut pas exécuter d'opération espèces
    $client = User::factory()->create(['role' => 'client', 'city_id' => $this->abidjan->id]);
    expect(fn () => app(WalletService::class)->effectuerOperationEspeces(
        ownerType: 'merchant',
        ownerId: $abidjanMerchant->id,
        direction: 'credit',
        montant: 5000,
        motif: 'Client non autorisé',
        gestionnaire: $client,
    ))->toThrow(AuthorizationException::class);
});
