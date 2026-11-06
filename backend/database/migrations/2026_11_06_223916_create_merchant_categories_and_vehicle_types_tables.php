<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('slug', 100)->unique();
            $table->text('description')->nullable();
            $table->string('icon', 50)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('vehicle_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('slug', 50)->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Données initiales
        DB::table('merchant_categories')->insert([
            ['name' => 'Restaurant / Fast-Food / Maquis', 'slug' => 'restaurant', 'description' => 'Restauration, plats chauds, maquis et burgers', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Pharmacie & Parapharmacie', 'slug' => 'pharmacie', 'description' => 'Médicaments et produits de santé', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Supermarché & Épicerie', 'slug' => 'supermarche', 'description' => 'Produits alimentaires, boissons et ménagers', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Boulangerie & Pâtisserie', 'slug' => 'boulangerie', 'description' => 'Pains, viennoiseries et gâteaux', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Boutique Mode & Prêt-à-porter', 'slug' => 'boutique', 'description' => 'Vêtements, chaussures et accessoires', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'High-Tech & Téléphonie', 'slug' => 'hightech', 'description' => 'Smartphones, accessoires informatiques et gadgets', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Autre Commerce', 'slug' => 'autre', 'description' => 'Tous autres types d\'activités commerciales', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);

        DB::table('vehicle_types')->insert([
            ['name' => 'Moto / Scooter', 'slug' => 'moto', 'description' => 'Deux-roues motorisé idéal pour livraisons rapides', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Vélo', 'slug' => 'velo', 'description' => 'Vélo mécanique ou à assistance pour centre-ville', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Voiture / Véhicule léger', 'slug' => 'voiture', 'description' => 'Véhicule à quatre roues pour colis volumineux', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Tricycle motorisé', 'slug' => 'tricycle', 'description' => 'Tricycle pour transport de charges moyennes', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_types');
        Schema::dropIfExists('merchant_categories');
    }
};
