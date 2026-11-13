<?php

use App\Filament\Resources\WalletTransactionResource;
use App\Models\City;
use App\Models\Courier;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\WalletService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->city = City::factory()->create();
    $this->driverUser = User::factory()->create([
        'role' => 'driver',
        'city_id' => $this->city->id,
    ]);
    $this->courier = Courier::factory()->create([
        'user_id' => $this->driverUser->id,
        'city_id' => $this->city->id,
    ]);
    $this->wallet = Wallet::create([
        'owner_type' => 'courier',
        'owner_id' => $this->courier->id,
        'balance' => 5000,
        'total_earned' => 5000,
        'total_withdrawn' => 0,
    ]);
});

// ─── POINT 1 : MODÈLE ELOQUENT IMMUABLE ──────────────────────────────────────

it('bloque la mise à jour Eloquent d\'une transaction de portefeuille', function () {
    $transaction = WalletTransaction::create([
        'wallet_id' => $this->wallet->id,
        'type' => 'credit_delivery',
        'amount' => 1000,
        'balance_before' => 5000,
        'balance_after' => 6000,
        'reference' => 'TXN-TEST-UP-01',
        'description' => 'Course initiale',
        'status' => 'completed',
    ]);

    expect(function () use ($transaction) {
        $transaction->update(['description' => 'Tentative falsification']);
    })->toThrow(RuntimeException::class);
});

it('bloque la suppression Eloquent d\'une transaction de portefeuille', function () {
    $transaction = WalletTransaction::create([
        'wallet_id' => $this->wallet->id,
        'type' => 'credit_delivery',
        'amount' => 1000,
        'balance_before' => 5000,
        'balance_after' => 6000,
        'reference' => 'TXN-TEST-DEL-01',
        'description' => 'Course à supprimer',
        'status' => 'completed',
    ]);

    expect(function () use ($transaction) {
        $transaction->delete();
    })->toThrow(RuntimeException::class);
});

// ─── POINT 2 : TRIGGER POSTGRESQL ET CONTRAINTE AMOUNT <> 0 ──────────────────

it('bloque la mise à jour directe SQL via Query Builder grâce au trigger PostgreSQL', function () {
    $transaction = WalletTransaction::create([
        'wallet_id' => $this->wallet->id,
        'type' => 'credit_delivery',
        'amount' => 1000,
        'balance_before' => 5000,
        'balance_after' => 6000,
        'reference' => 'TXN-TEST-SQL-UP-01',
        'description' => 'Course SQL',
        'status' => 'completed',
    ]);

    expect(function () use ($transaction) {
        DB::table('wallet_transactions')
            ->where('id', $transaction->id)
            ->update(['amount' => 999999]);
    })->toThrow(QueryException::class);
});

it('bloque la suppression directe SQL grâce au trigger PostgreSQL', function () {
    $transaction = WalletTransaction::create([
        'wallet_id' => $this->wallet->id,
        'type' => 'credit_delivery',
        'amount' => 1000,
        'balance_before' => 5000,
        'balance_after' => 6000,
        'reference' => 'TXN-TEST-SQL-DEL-01',
        'description' => 'Course SQL à supprimer',
        'status' => 'completed',
    ]);

    expect(function () use ($transaction) {
        DB::table('wallet_transactions')
            ->where('id', $transaction->id)
            ->delete();
    })->toThrow(QueryException::class);
});

it('rejette une transaction avec montant nul via contrainte CHECK amount <> 0', function () {
    expect(function () {
        DB::table('wallet_transactions')->insert([
            'wallet_id' => $this->wallet->id,
            'type' => 'credit_delivery',
            'amount' => 0,
            'balance_before' => 5000,
            'balance_after' => 5000,
            'reference' => 'TXN-TEST-ZERO-01',
            'description' => 'Transaction montant nul interdite',
            'status' => 'completed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    })->toThrow(QueryException::class);
});

// ─── POINT 3 : FILAMENT LECTURE SEULE ────────────────────────────────────────

it('verifie que WalletTransactionResource est strictement en lecture seule sans actions de modification', function () {
    expect(WalletTransactionResource::canCreate())->toBeFalse();

    $transaction = WalletTransaction::create([
        'wallet_id' => $this->wallet->id,
        'type' => 'credit_delivery',
        'amount' => 1000,
        'balance_before' => 5000,
        'balance_after' => 6000,
        'reference' => 'TXN-TEST-FIL-01',
        'description' => 'Course Filament',
        'status' => 'completed',
    ]);

    expect(WalletTransactionResource::canEdit($transaction))->toBeFalse();
    expect(WalletTransactionResource::canDelete($transaction))->toBeFalse();

    $pages = WalletTransactionResource::getPages();
    expect($pages)->toHaveKey('index')
        ->and($pages)->not->toHaveKey('create')
        ->and($pages)->not->toHaveKey('edit');
});

// ─── POINT 4 : AJUSTEMENT COMPTABLE SÉCURISÉ ─────────────────────────────────

it('permet un ajustement comptable traçable par super_admin ou national_finance avec motif obligatoire', function () {
    $walletService = app(WalletService::class);

    $superAdmin = User::factory()->create([
        'role' => 'super_admin',
        'city_id' => $this->city->id,
    ]);

    $nationalFinance = User::factory()->create([
        'role' => 'national_finance',
        'city_id' => $this->city->id,
    ]);

    // 1. Ajustement positif par super_admin
    $txnSuper = $walletService->ajusterSolde(
        wallet: $this->wallet,
        montant: 1500,
        motif: 'Régularisation erreur saisie caisse',
        admin: $superAdmin,
    );

    expect($txnSuper->type)->toBe('adjustment')
        ->and($txnSuper->amount)->toEqual(1500)
        ->and($txnSuper->balance_before)->toEqual(5000)
        ->and($txnSuper->balance_after)->toEqual(6500)
        ->and($this->wallet->fresh()->balance)->toEqual(6500);

    // Vérification de la trace dans activity_logs
    $this->assertDatabaseHas('activity_logs', [
        'user_id' => $superAdmin->id,
        'action' => 'wallet_balance_adjusted',
        'entity_type' => 'wallet',
        'entity_id' => $this->wallet->id,
    ]);

    // 2. Ajustement négatif par national_finance
    $txnFin = $walletService->ajusterSolde(
        wallet: $this->wallet,
        montant: -1000,
        motif: 'Correction doublon crédit commission',
        admin: $nationalFinance,
    );

    expect($txnFin->type)->toBe('adjustment')
        ->and($txnFin->amount)->toEqual(-1000)
        ->and($txnFin->balance_before)->toEqual(6500)
        ->and($txnFin->balance_after)->toEqual(5500)
        ->and($this->wallet->fresh()->balance)->toEqual(5500);
});

it('refuse un ajustement comptable si le motif est absent ou si le rôle n\'est pas habilité', function () {
    $walletService = app(WalletService::class);

    $cityManager = User::factory()->create([
        'role' => 'city_manager',
        'city_id' => $this->city->id,
    ]);

    $superAdmin = User::factory()->create([
        'role' => 'super_admin',
        'city_id' => $this->city->id,
    ]);

    // Rôle non autorisé -> AuthorizationException
    expect(function () use ($walletService, $cityManager) {
        $walletService->ajusterSolde(
            wallet: $this->wallet,
            montant: 1000,
            motif: 'Tentative par city manager',
            admin: $cityManager,
        );
    })->toThrow(AuthorizationException::class);

    // Motif vide -> InvalidArgumentException
    expect(function () use ($walletService, $superAdmin) {
        $walletService->ajusterSolde(
            wallet: $this->wallet,
            montant: 1000,
            motif: '',
            admin: $superAdmin,
        );
    })->toThrow(InvalidArgumentException::class);

    // Montant zéro -> InvalidArgumentException
    expect(function () use ($walletService, $superAdmin) {
        $walletService->ajusterSolde(
            wallet: $this->wallet,
            montant: 0,
            motif: 'Ajustement nul',
            admin: $superAdmin,
        );
    })->toThrow(InvalidArgumentException::class);
});

// ─── POINT 5 : GARDE-FOU SOLDE >= 0 ──────────────────────────────────────────

it('applique le garde-fou solde >= 0 sur les wallets conformément au CDC 7.4.1', function () {
    expect(function () {
        DB::table('wallets')->where('id', $this->wallet->id)->update([
            'balance' => -100,
        ]);
    })->toThrow(QueryException::class);
});
