<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Table centrale — tous les acteurs de la plateforme :
 * clients, merchants, drivers, et tous les rôles admin.
 * Dépend de : cities
 *
 * Rôles possibles : client, merchant, driver,
 *   super_admin, national_dg, national_finance, national_tech,
 *   national_support, city_manager, city_finance, city_support, city_agent
 *
 * city_id = NULL pour les admins nationaux (accès toutes villes).
 * city_id = renseigné pour les admins locaux et tous les acteurs opérationnels.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            // Identifiant principal — numéro de téléphone ivoirien
            $table->string('phone', 20)->unique();
            $table->string('role', 50);
            // NULL pour admins nationaux, obligatoire pour admins locaux et opérationnels
            $table->foreignId('city_id')
                ->nullable()
                ->constrained('cities')
                ->nullOnDelete();
            // Token FCM pour push notifications Firebase
            $table->string('device_token', 255)->nullable();
            // Session unique pour merchants et drivers (révoquée à chaque nouvelle connexion)
            $table->string('session_token', 255)->nullable();
            // Compte actif (soft-désactivation sans suppression)
            $table->boolean('is_active')->default(true);
            $table->timestamp('phone_verified_at')->nullable();
            // Photo de profil (URL DigitalOcean Spaces)
            $table->string('avatar_url', 500)->nullable();
            // Pour les admins : email + mot de passe + 2FA
            $table->string('email', 191)->unique()->nullable();
            $table->string('password')->nullable();
            $table->boolean('two_factor_enabled')->default(false);
            // IP tracking pour les admins
            $table->string('last_login_ip', 45)->nullable();
            $table->timestamp('last_login_at')->nullable();
            // Soft delete — compte récupérable
            $table->softDeletes();
            $table->timestamps();

            // Index composites pour les requêtes fréquentes
            $table->index(['role', 'city_id']);
            $table->index(['role', 'is_active']);
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
