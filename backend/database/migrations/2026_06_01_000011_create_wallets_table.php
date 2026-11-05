<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Portefeuilles virtuels — merchants et couriers UNIQUEMENT.
 * Dépend de : aucune FK directe (relation polymorphique owner_type/owner_id)
 *
 * Contrainte métier : owner_type IN ('merchant', 'courier')
 * Les clients n'ont PAS de wallet.
 * PAS de soft delete : traçabilité financière absolue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            // Relation polymorphique : merchant ou courier
            $table->string('owner_type', 30); // merchant | courier
            $table->unsignedBigInteger('owner_id');
            // Solde actuel en F CFA
            $table->decimal('balance', 12, 0)->default(0);
            // Cumuls pour les rapports financiers
            $table->decimal('total_earned', 12, 0)->default(0);
            $table->decimal('total_withdrawn', 12, 0)->default(0);
            $table->timestamps();

            $table->unique(['owner_type', 'owner_id']);
            $table->index(['owner_type', 'owner_id']);
        });

        // Contrainte CHECK PostgreSQL : owner_type uniquement 'merchant' ou 'courier'
        \DB::statement("ALTER TABLE wallets ADD CONSTRAINT wallets_owner_type_check CHECK (owner_type IN ('merchant', 'courier'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('wallets');
    }
};
