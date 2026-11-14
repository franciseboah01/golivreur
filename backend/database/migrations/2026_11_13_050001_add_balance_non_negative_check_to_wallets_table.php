<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Garde-fou solde : balance >= 0 (conforme CDC 7.4.1 solde minimum positif sans découvert autorisé)
        DB::statement(
            'ALTER TABLE wallets ADD CONSTRAINT wallets_balance_check CHECK (balance >= 0)'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE wallets DROP CONSTRAINT IF EXISTS wallets_balance_check');
    }
};
