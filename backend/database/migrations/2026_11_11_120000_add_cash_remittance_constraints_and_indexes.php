<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            "ALTER TABLE orders ADD CONSTRAINT orders_payment_status_check CHECK (payment_status IN ('pending', 'paid', 'failed', 'pending_cash', 'paid_cash'))"
        );

        DB::statement(
            "ALTER TABLE parcels ADD CONSTRAINT parcels_payment_status_check CHECK (payment_status IN ('pending', 'paid', 'failed', 'pending_cash', 'paid_cash'))"
        );

        DB::statement(
            "ALTER TABLE wallet_transactions ADD CONSTRAINT wallet_transactions_type_check CHECK (type IN ('credit_delivery', 'credit_shipping', 'withdrawal', 'refund', 'adjustment', 'debit_cash_remittance', 'deposit', 'parcel_payment', 'order_payment', 'commission', 'delivery_fee'))"
        );

        DB::statement(
            'CREATE UNIQUE INDEX wallet_transactions_wallet_type_order_unique ON wallet_transactions (wallet_id, type, order_id) WHERE order_id IS NOT NULL'
        );

        DB::statement(
            'CREATE UNIQUE INDEX wallet_transactions_wallet_type_parcel_unique ON wallet_transactions (wallet_id, type, parcel_id) WHERE parcel_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS wallet_transactions_wallet_type_parcel_unique');
        DB::statement('DROP INDEX IF EXISTS wallet_transactions_wallet_type_order_unique');
        DB::statement('ALTER TABLE wallet_transactions DROP CONSTRAINT IF EXISTS wallet_transactions_type_check');
        DB::statement('ALTER TABLE parcels DROP CONSTRAINT IF EXISTS parcels_payment_status_check');
        DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_payment_status_check');
    }
};
