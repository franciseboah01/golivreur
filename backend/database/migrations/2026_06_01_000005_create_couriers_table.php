<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Profils des livreurs à moto GoLivreur.
 * Dépend de : users (user_id), cities (city_id)
 *
 * Un courier est un user avec role='driver' qui a un profil enrichi ici.
 * Soft delete : le livreur peut être réactivé.
 * La position GPS est mise à jour toutes les 5 secondes (filtre 10m).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('couriers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                  ->unique()
                  ->constrained('users')
                  ->cascadeOnDelete();
            $table->foreignId('city_id')
                  ->constrained('cities')
                  ->restrictOnDelete();
            // Disponibilité temps réel (bouton En ligne / Hors ligne)
            $table->boolean('is_online')->default(false);
            // Position GPS actuelle (mise à jour via /api/v1/courier/location)
            $table->decimal('current_lat', 10, 8)->nullable();
            $table->decimal('current_lng', 11, 8)->nullable();
            $table->timestamp('location_updated_at')->nullable();
            // Type de véhicule : moto (obligatoire au lancement)
            $table->string('vehicle_type', 50)->default('moto');
            $table->string('vehicle_plate', 20)->nullable();
            // Compte validé par City Agent (vérification physique moto + identité)
            $table->boolean('is_active')->default(false);
            $table->timestamp('activated_at')->nullable();
            $table->foreignId('activated_by')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();
            // Pièce d'identité (vérifiée en présentiel par City Agent)
            $table->string('id_card_number', 50)->nullable();
            $table->string('id_card_photo_url', 500)->nullable();
            // Photo de profil (affichée au client pendant la livraison)
            $table->string('photo_url', 500)->nullable();
            // Pénalité anti-filtrage : 3 refus consécutifs = hors ligne 15 min
            $table->unsignedTinyInteger('consecutive_refusals')->default(0);
            $table->timestamp('penalty_until')->nullable();
            // Note moyenne
            $table->decimal('average_rating', 3, 2)->default(0.00);
            $table->unsignedInteger('total_ratings')->default(0);
            $table->softDeletes();
            $table->timestamps();

            $table->index(['city_id', 'is_active', 'is_online']);
            $table->index(['current_lat', 'current_lng']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('couriers');
    }
};
