<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Table racine — aucune dépendance.
 * Toutes les entités locales (merchants, couriers, orders, parcels)
 * référencent cette table via city_id.
 * Ajouter une nouvelle ville = INSERT d'une ligne ici uniquement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cities', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->boolean('is_active')->default(true);
            // Centre géographique pour l'API météo OpenWeatherMap
            $table->decimal('center_lat', 10, 8)->nullable();
            $table->decimal('center_lng', 11, 8)->nullable();
            // Mode Pluie — activé manuellement ou via API météo
            $table->boolean('rain_mode_active')->default(false);
            $table->timestamp('rain_mode_activated_at')->nullable();
            // Mode Urgence — activé uniquement par Super Admin ou DG National
            $table->boolean('emergency_mode_active')->default(false);
            $table->text('emergency_mode_message')->nullable();
            $table->timestamp('emergency_mode_activated_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cities');
    }
};
