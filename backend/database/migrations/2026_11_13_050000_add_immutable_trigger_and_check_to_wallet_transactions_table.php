<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Contrainte CHECK amount <> 0
        DB::statement(
            'ALTER TABLE wallet_transactions ADD CONSTRAINT wallet_transactions_amount_check CHECK (amount <> 0)'
        );

        // 2. Fonction et trigger BEFORE UPDATE OR DELETE pour bloquer toute mutation
        DB::statement("
            CREATE OR REPLACE FUNCTION prevent_wallet_transactions_mutation()
            RETURNS TRIGGER AS \$\$
            BEGIN
                RAISE EXCEPTION 'Les transactions de portefeuille sont immuables : interdiction formelle de modifier ou supprimer une écriture comptable (id=%).', OLD.id;
            END;
            \$\$ LANGUAGE plpgsql;
        ");

        DB::statement('DROP TRIGGER IF EXISTS trg_prevent_wallet_transactions_mutation ON wallet_transactions');

        DB::statement('
            CREATE TRIGGER trg_prevent_wallet_transactions_mutation
            BEFORE UPDATE OR DELETE ON wallet_transactions
            FOR EACH ROW
            EXECUTE FUNCTION prevent_wallet_transactions_mutation()
        ');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS trg_prevent_wallet_transactions_mutation ON wallet_transactions');
        DB::statement('DROP FUNCTION IF EXISTS prevent_wallet_transactions_mutation');
        DB::statement('ALTER TABLE wallet_transactions DROP CONSTRAINT IF EXISTS wallet_transactions_amount_check');
    }
};
