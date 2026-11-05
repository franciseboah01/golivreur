<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Photos de preuve GoColis.
 * Dépend de : parcels
 *
 * type = 'pickup'  : photo prise par le livreur chez l'expéditeur
 * type = 'delivery': photo prise par le livreur chez le destinataire
 * Les deux photos sont OBLIGATOIRES avant validation de chaque code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parcel_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parcel_id')
                  ->constrained('parcels')
                  ->cascadeOnDelete();
            $table->string('type', 20); // pickup | delivery
            $table->string('photo_url', 500);
            // Position GPS au moment de la photo (preuve géolocalisée)
            $table->decimal('lat', 10, 8)->nullable();
            $table->decimal('lng', 11, 8)->nullable();
            $table->timestamps();

            $table->index(['parcel_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parcel_photos');
    }
};
