<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Expéditions GoColis.
 * Dépend de : users (sender_id), couriers, cities
 *
 * PAS de soft delete : historique immuable pour les litiges.
 * Double code de confirmation :
 *   - sender_code : donné par l'expéditeur au livreur au ramassage
 *   - recipient_code : reçu par SMS par le destinataire, donné au livreur à la livraison
 * Les deux codes sont hashés bcrypt, jamais stockés en clair.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parcels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sender_id')
                  ->constrained('users')
                  ->restrictOnDelete();
            $table->foreignId('courier_id')
                  ->nullable()
                  ->constrained('couriers')
                  ->nullOnDelete();
            $table->foreignId('city_id')
                  ->constrained('cities')
                  ->restrictOnDelete();

            // Statuts : pending|waiting|accepted|at_sender|picked_up|in_transit|at_recipient|delivered|refused|cancelled
            $table->string('status', 30)->default('pending');

            // Taille du colis (visible par le livreur AVANT acceptation)
            $table->string('size', 20); // petit | moyen | grand
            $table->decimal('weight_kg', 5, 2)->nullable();
            $table->decimal('declared_value', 10, 0)->default(0);

            // Assurance (3% si valeur déclarée > 20 000 F CFA)
            $table->boolean('has_insurance')->default(false);
            $table->decimal('insurance_amount', 10, 0)->default(0);

            // Codes de confirmation (hashés bcrypt)
            $table->string('sender_code', 255);
            $table->unsignedTinyInteger('sender_code_attempts')->default(0);
            $table->boolean('sender_code_locked')->default(false);

            $table->string('recipient_code', 255);
            $table->unsignedTinyInteger('recipient_code_attempts')->default(0);
            $table->boolean('recipient_code_locked')->default(false);

            // Informations destinataire (pas besoin d'avoir l'app)
            $table->string('recipient_name', 150);
            $table->string('recipient_phone', 20);
            $table->text('delivery_address');
            $table->decimal('delivery_lat', 10, 8)->nullable();
            $table->decimal('delivery_lng', 11, 8)->nullable();

            // Adresse ramassage (expéditeur)
            $table->text('pickup_address')->nullable();
            $table->decimal('pickup_lat', 10, 8)->nullable();
            $table->decimal('pickup_lng', 11, 8)->nullable();

            $table->text('description')->nullable();

            // Snapshot financier
            $table->decimal('shipping_fee', 10, 0);
            $table->decimal('platform_commission', 10, 0); // 25% des frais
            $table->decimal('courier_amount', 10, 0);      // 75% des frais

            // Paiement
            $table->string('payment_method', 30)->default('cash');
            $table->string('payment_status', 30)->default('pending');
            $table->string('payment_reference', 100)->nullable();

            // Tentatives d'attribution (alerte Admin après 3 échecs)
            $table->unsignedTinyInteger('assignment_attempts')->default(0);

            // Déblocage codes par City Support
            $table->foreignId('sender_code_unlocked_by')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();
            $table->foreignId('recipient_code_unlocked_by')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();

            // Horodatages des transitions clés
            $table->timestamp('picked_up_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->timestamps();

            $table->index(['sender_id', 'status']);
            $table->index(['courier_id', 'status']);
            $table->index(['city_id', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parcels');
    }
};
