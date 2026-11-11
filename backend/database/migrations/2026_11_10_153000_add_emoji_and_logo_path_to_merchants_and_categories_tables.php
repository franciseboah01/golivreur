<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ajoute les champs `emoji` et `logo_path` aux tables `merchants` et `merchant_categories`,
     * et initialise des emojis cohérents par défaut pour les données existantes.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('merchants', 'emoji')) {
            Schema::table('merchants', function (Blueprint $table) {
                $table->string('emoji', 32)->nullable()->after('category');
                $table->string('logo_path')->nullable()->after('emoji');
            });
        }

        if (! Schema::hasColumn('merchant_categories', 'emoji')) {
            Schema::table('merchant_categories', function (Blueprint $table) {
                $table->string('emoji', 32)->nullable()->after('icon');
                $table->string('logo_path')->nullable()->after('emoji');
            });
        }

        // Harmonisation du slug hightech -> high_tech si nécessaire pour correspondre aux commerces seedés
        if (DB::table('merchant_categories')->where('slug', 'hightech')->exists()
            && ! DB::table('merchant_categories')->where('slug', 'high_tech')->exists()) {
            DB::table('merchant_categories')
                ->where('slug', 'hightech')
                ->update(['slug' => 'high_tech', 'emoji' => '📱', 'updated_at' => now()]);
        }

        // Mise à jour / ajout des catégories standards et de leurs emojis par défaut
        $defaultCategories = [
            'restaurant' => ['name' => 'Restaurants', 'emoji' => '🍽️', 'description' => 'Restaurants et plats chauds locaux'],
            'maquis' => ['name' => 'Maquis & Grillades', 'emoji' => '🍖', 'description' => 'Maquis traditionnels, braisés, garba et grillades'],
            'fast_food' => ['name' => 'Fast-Foods', 'emoji' => '🍔', 'description' => 'Burgers, pizzas, shawarmas et snacks'],
            'pharmacie' => ['name' => 'Pharmacies', 'emoji' => '💊', 'description' => 'Médicaments et produits de santé'],
            'boulangerie' => ['name' => 'Boulangeries & Pâtisseries', 'emoji' => '🥐', 'description' => 'Pains, viennoiseries et gâteaux'],
            'supermarche' => ['name' => 'Supermarchés & Épiceries', 'emoji' => '🛒', 'description' => 'Produits alimentaires, boissons et ménagers'],
            'boissons' => ['name' => 'Boissons & Vins', 'emoji' => '🥤', 'description' => 'Boissons fraîches, jus naturels et caves'],
            'boutique' => ['name' => 'Mode & Tissus', 'emoji' => '👕', 'description' => 'Vêtements, pagnes, chaussures et accessoires'],
            'high_tech' => ['name' => 'High-Tech & Téléphonie', 'emoji' => '📱', 'description' => 'Smartphones, accessoires informatiques et gadgets'],
            'autre' => ['name' => 'Autre Commerce', 'emoji' => '🏪', 'description' => 'Tous autres types d\'activités commerciales'],
        ];

        foreach ($defaultCategories as $slug => $meta) {
            $existing = DB::table('merchant_categories')->where('slug', $slug)->first();
            if ($existing) {
                DB::table('merchant_categories')
                    ->where('id', $existing->id)
                    ->update([
                        'emoji' => $existing->emoji ?: $meta['emoji'],
                        'updated_at' => now(),
                    ]);
            } else {
                // Éviter collision sur le nom unique
                $nameExists = DB::table('merchant_categories')->where('name', $meta['name'])->exists();
                DB::table('merchant_categories')->insert([
                    'name' => $nameExists ? "{$meta['name']} ({$slug})" : $meta['name'],
                    'slug' => $slug,
                    'description' => $meta['description'],
                    'emoji' => $meta['emoji'],
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // Initialiser l'emoji par défaut sur les commerces existants sans emoji
        $categoryEmojis = DB::table('merchant_categories')->pluck('emoji', 'slug')->toArray();
        $merchants = DB::table('merchants')->whereNull('emoji')->get(['id', 'category']);
        foreach ($merchants as $merchant) {
            $fallbackEmoji = $categoryEmojis[$merchant->category] ?? '🏪';
            DB::table('merchants')
                ->where('id', $merchant->id)
                ->update(['emoji' => $fallbackEmoji, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('merchants', 'emoji')) {
            Schema::table('merchants', function (Blueprint $table) {
                $table->dropColumn(['emoji', 'logo_path']);
            });
        }

        if (Schema::hasColumn('merchant_categories', 'emoji')) {
            Schema::table('merchant_categories', function (Blueprint $table) {
                $table->dropColumn(['emoji', 'logo_path']);
            });
        }
    }
};
