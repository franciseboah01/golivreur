<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Commandes GoShop.
 * Dépend de : users (client_id), merchants, couriers, cities
 *
 * PAS de soft delete : les commandes sont immuables même annulées.
 * Litiges possibles 30 jours après livraison — l'historique doit rester intact.
 * Les montants sont tous stockés au moment de la commande (snapshot financier).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')
                ->constrained('users')
                ->restrictOnDelete();
            $table->foreignId('merchant_id')
                ->constrained('merchants')
                ->restrictOnDelete();
            $table->foreignId('courier_id')
                ->nullable()
                ->constrained('couriers')
                ->nullOnDelete();
            $table->foreignId('city_id')
                ->constrained('cities')
                ->restrictOnDelete();

            // Statuts : pending | accepted | refused | ready | picked_up | in_transit | delivered | cancelled
            $table->string('status', 30)->default('pending');

            // Snapshot financier au moment de la commande
            $table->decimal('products_total', 10, 0);
            $table->decimal('delivery_fee', 10, 0);
            $table->decimal('platform_commission', 10, 0);
            $table->decimal('merchant_amount', 10, 0);  // products_total - commission
            $table->decimal('courier_amount', 10, 0);   // delivery_fee * 80%
            $table->boolean('rain_mode_applied')->default(false);
            $table->boolean('peak_hours_applied')->default(false);

            // Paiement
            $table->string('payment_method', 30)->default('cash'); // orange_money | cash
            $table->string('payment_status', 30)->default('pending'); // pending | paid | failed | pending_cash
            $table->string('payment_reference', 100)->nullable(); // Référence Hub2/CinetPay

            // Code de confirmation livraison (4 chiffres, hashé bcrypt)
            $table->string('confirmation_code', 255);
            $table->unsignedTinyInteger('confirmation_attempts')->default(0);
            $table->boolean('confirmation_locked')->default(false);
            $table->foreignId('confirmation_unlocked_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // Adresse de livraison
            $table->text('delivery_address');
            $table->decimal('delivery_lat', 10, 8)->nullable();
            $table->decimal('delivery_lng', 11, 8)->nullable();

            // Adresse de ramassage (commerce)
            $table->decimal('pickup_lat', 10, 8)->nullable();
            $table->decimal('pickup_lng', 11, 8)->nullable();

            $table->text('special_instructions')->nullable();

            // Horodatages des transitions d'état clés
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('picked_up_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 255)->nullable();

            $table->timestamps();

            $table->index(['client_id', 'status']);
            $table->index(['merchant_id', 'status']);
            $table->index(['courier_id', 'status']);
            $table->index(['city_id', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
