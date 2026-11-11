<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100);
            $table->json('value');
            $table->foreignId('city_id')
                ->nullable()
                ->constrained('cities')
                ->cascadeOnDelete();
            $table->string('description', 255)->nullable();
            $table->timestamps();

            // Index pour recherche rapide par clé et ville
            $table->index(['key', 'city_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
