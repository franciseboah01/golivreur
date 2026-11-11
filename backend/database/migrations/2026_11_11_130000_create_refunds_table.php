<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('city_id')
                ->constrained('cities')
                ->restrictOnDelete();
            $table->foreignId('order_id')
                ->nullable()
                ->constrained('orders')
                ->restrictOnDelete();
            $table->foreignId('parcel_id')
                ->nullable()
                ->constrained('parcels')
                ->restrictOnDelete();
            $table->foreignId('client_id')
                ->constrained('users')
                ->restrictOnDelete();
            $table->decimal('amount', 10, 0);
            $table->text('reason');
            $table->string('status', 30)->default('requested');
            $table->string('cost_bearer', 30)->default('platform');
            $table->foreignId('requested_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignId('approved_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('paid_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->string('payment_reference', 100)->nullable();
            $table->timestamps();

            $table->unique('order_id', 'refunds_order_id_unique');
            $table->unique('parcel_id', 'refunds_parcel_id_unique');
            $table->index(['city_id', 'status']);
            $table->index(['client_id', 'status']);
        });

        DB::statement(
            'ALTER TABLE refunds ADD CONSTRAINT refunds_exactly_one_target_check CHECK ((order_id IS NOT NULL AND parcel_id IS NULL) OR (order_id IS NULL AND parcel_id IS NOT NULL))'
        );

        DB::statement(
            "ALTER TABLE refunds ADD CONSTRAINT refunds_status_check CHECK (status IN ('requested', 'approved', 'paid', 'rejected'))"
        );

        DB::statement(
            "ALTER TABLE refunds ADD CONSTRAINT refunds_cost_bearer_check CHECK (cost_bearer IN ('platform', 'merchant', 'courier'))"
        );

        DB::statement(
            'ALTER TABLE refunds ADD CONSTRAINT refunds_amount_positive_check CHECK (amount > 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
