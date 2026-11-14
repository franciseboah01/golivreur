<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_payment_status_check');
        DB::statement(
            "ALTER TABLE orders ADD CONSTRAINT orders_payment_status_check CHECK (payment_status IN ('pending', 'paid', 'failed', 'pending_cash', 'paid_cash', 'amount_mismatch'))"
        );

        DB::statement('ALTER TABLE parcels DROP CONSTRAINT IF EXISTS parcels_payment_status_check');
        DB::statement(
            "ALTER TABLE parcels ADD CONSTRAINT parcels_payment_status_check CHECK (payment_status IN ('pending', 'paid', 'failed', 'pending_cash', 'paid_cash', 'amount_mismatch'))"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE parcels DROP CONSTRAINT IF EXISTS parcels_payment_status_check');
        DB::statement(
            "ALTER TABLE parcels ADD CONSTRAINT parcels_payment_status_check CHECK (payment_status IN ('pending', 'paid', 'failed', 'pending_cash', 'paid_cash'))"
        );

        DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_payment_status_check');
        DB::statement(
            "ALTER TABLE orders ADD CONSTRAINT orders_payment_status_check CHECK (payment_status IN ('pending', 'paid', 'failed', 'pending_cash', 'paid_cash'))"
        );
    }
};

