<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhook_logs', function (Blueprint $table) {
            $table->string('transaction_id', 150)->nullable()->after('event_type');
            $table->index('transaction_id');
        });

        DB::statement(
            "CREATE UNIQUE INDEX webhook_logs_processed_transaction_unique ON webhook_logs (transaction_id) WHERE transaction_id IS NOT NULL AND signature_valid = true AND status IN ('pending', 'processed')"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS webhook_logs_processed_transaction_unique');

        Schema::table('webhook_logs', function (Blueprint $table) {
            $table->dropIndex(['transaction_id']);
            $table->dropColumn('transaction_id');
        });
    }
};
