<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('couriers', function (Blueprint $table): void {
            if (! Schema::hasColumn('couriers', 'merchant_id')) {
                $table->foreignId('merchant_id')
                    ->nullable()
                    ->after('city_id')
                    ->constrained('merchants')
                    ->nullOnDelete();
                $table->index(['merchant_id', 'is_active']);
            }

            if (! Schema::hasColumn('couriers', 'validation_status')) {
                $table->string('validation_status', 20)
                    ->default('approved')
                    ->after('is_active');
            }
        });
    }

    public function down(): void
    {
        Schema::table('couriers', function (Blueprint $table): void {
            if (Schema::hasColumn('couriers', 'validation_status')) {
                $table->dropColumn('validation_status');
            }
            if (Schema::hasColumn('couriers', 'merchant_id')) {
                $table->dropConstrainedForeignId('merchant_id');
            }
        });
    }
};
