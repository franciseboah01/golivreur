<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Transactions du portefeuille virtuel.
 * Dépend de : wallets, orders (nullable), parcels (nullable)
 *
 * Chaque crédit ou débit génère une ligne immuable.
 * balance_before et balance_after permettent de reconstruire l'historique complet.
 * PAS de soft delete : traçabilité financière absolue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')
                ->constrained('wallets')
                ->restrictOnDelete();
            // Type : credit_delivery | credit_shipping | withdrawal | refund | adjustment
            $table->string('type', 50);
            $table->decimal('amount', 12, 0);
            // Soldes avant/après pour audit et reconstruction
            $table->decimal('balance_before', 12, 0);
            $table->decimal('balance_after', 12, 0);
            // Référence unique de la transaction
            $table->string('reference', 100)->unique();
            $table->text('description')->nullable();
            // Statut : completed | pending | failed
            $table->string('status', 30)->default('completed');
            // Liens vers la commande ou l'expédition source (nullable)
            $table->foreignId('order_id')
                ->nullable()
                ->constrained('orders')
                ->nullOnDelete();
            $table->foreignId('parcel_id')
                ->nullable()
                ->constrained('parcels')
                ->nullOnDelete();
            // Admin ayant effectué l'ajustement manuel si applicable
            $table->foreignId('processed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamps();

            $table->index(['wallet_id', 'type']);
            $table->index(['wallet_id', 'created_at']);
            $table->index('reference');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
    }
};
