<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Codes OTP temporaires pour inscription et connexion.
 * Dépend de : aucune (clé sur phone uniquement, pas de FK users)
 *
 * Les codes sont hashés bcrypt, jamais stockés en clair.
 * Durée de vie : 5 minutes.
 * Après validation ou expiration : supprimés via scheduler.
 * Après 3 tentatives échouées : numéro bloqué 1h dans Redis (pas ici).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('otp_codes', function (Blueprint $table) {
            $table->id();
            // Numéro de téléphone ciblé (pas de FK pour gérer l'inscription)
            $table->string('phone', 20);
            // Hash bcrypt du code à 6 chiffres — jamais en clair
            $table->string('code_hash', 255);
            // Purpose : register | login | admin_2fa
            $table->string('purpose', 30);
            // Compteur de tentatives (max 3)
            $table->unsignedTinyInteger('attempts')->default(0);
            // Expiration absolue : created_at + 5 minutes
            $table->timestamp('expires_at');
            // Marqué utilisé après validation réussie
            $table->boolean('is_used')->default(false);
            $table->timestamps();

            $table->index(['phone', 'purpose', 'is_used']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('otp_codes');
    }
};
