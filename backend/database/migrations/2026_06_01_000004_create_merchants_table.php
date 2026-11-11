<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Profils des commerces partenaires GoShop.
 * Dépend de : users (user_id), cities (city_id)
 *
 * Un merchant est un user avec role='merchant' qui a un profil enrichi ici.
 * Soft delete : le commerce peut être réactivé ultérieurement.
 * commission_rate : configurable par la plateforme (défaut 15%).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->unique()
                ->constrained('users')
                ->cascadeOnDelete();
            $table->foreignId('city_id')
                ->constrained('cities')
                ->restrictOnDelete();
            $table->string('name', 200);
            // Catégorie GoShop : restaurant, maquis, pharmacie, boutique, etc.
            $table->string('category', 100);
            $table->text('description')->nullable();
            $table->string('address', 500);
            // Coordonnées GPS pour le calcul de distance et l'attribution des livreurs
            $table->decimal('lat', 10, 8);
            $table->decimal('lng', 11, 8);
            // Horaires d'ouverture — JSON : {"lun": {"open": "08:00", "close": "22:00"}, ...}
            $table->json('open_hours')->nullable();
            // Temps de préparation estimé en minutes (affiché au client)
            $table->unsignedSmallInteger('prep_time_minutes')->default(15);
            // Statut ouvert/fermé (modifiable manuellement par le commerce)
            $table->boolean('is_open')->default(false);
            // Compte validé par un City Agent ou City Support
            $table->boolean('is_active')->default(false);
            $table->timestamp('activated_at')->nullable();
            $table->foreignId('activated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            // Photo de couverture (URL DO Spaces)
            $table->string('photo_url', 500)->nullable();
            // Commission plateforme configurable par commerce (défaut global : 15%)
            $table->decimal('commission_rate', 5, 2)->default(15.00);
            // Note moyenne calculée (mise à jour après chaque évaluation)
            $table->decimal('average_rating', 3, 2)->default(0.00);
            $table->unsignedInteger('total_ratings')->default(0);
            // Soft delete
            $table->softDeletes();
            $table->timestamps();

            $table->index(['city_id', 'is_active', 'is_open']);
            $table->index(['city_id', 'category']);
            $table->index('average_rating');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchants');
    }
};
