<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catalogue produits des commerces GoShop.
 * Dépend de : merchants
 *
 * unit_price dans order_items capture le prix au moment de la commande.
 * Modifier le prix ici n'affecte pas les commandes déjà passées.
 * Soft delete : produit retiré temporairement sans supprimer l'historique.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')
                  ->constrained('merchants')
                  ->cascadeOnDelete();
            $table->string('name', 200);
            $table->text('description')->nullable();
            // Prix en francs CFA (pas de décimales en pratique, mais DECIMAL pour cohérence)
            $table->decimal('price', 10, 0);
            // Photo produit (URL DO Spaces)
            $table->string('photo_url', 500)->nullable();
            // Disponible à la commande (rupture de stock temporaire)
            $table->boolean('is_available')->default(true);
            // Catégorie interne au commerce (ex: "Plats", "Boissons", "Desserts")
            $table->string('category', 100)->nullable();
            // Ordre d'affichage dans le catalogue
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->softDeletes();
            $table->timestamps();

            $table->index(['merchant_id', 'is_available']);
            $table->index(['merchant_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
