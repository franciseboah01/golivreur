<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lignes de commande GoShop.
 * Dépend de : orders, products
 *
 * unit_price : prix au moment de la commande (snapshot).
 * Si le commerce change son prix après, les commandes passées conservent l'ancien prix.
 * product_name_snapshot : nom du produit au moment de la commande (en cas de renommage).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')
                  ->constrained('orders')
                  ->cascadeOnDelete();
            $table->foreignId('product_id')
                  ->constrained('products')
                  ->restrictOnDelete();
            $table->unsignedSmallInteger('quantity');
            // Prix unitaire au moment de la commande (immuable)
            $table->decimal('unit_price', 10, 0);
            // Nom du produit au moment de la commande (immuable)
            $table->string('product_name_snapshot', 200);
            $table->timestamps();

            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
