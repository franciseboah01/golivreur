<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Architecture du Catalogue Dynamique (Dictionnaire de champs contrôlé par l'Admin) :
 * - `catalog_fields` : bibliothèque centrale de tous les champs produits possibles.
 * - `merchant_category_catalog_field` : champs cochés, ordonnés et labellisés par Type de Commerce.
 * - `products.quantity` + `products.attributes` (JSON) : stockage des valeurs saisies par le commerçant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_fields', function (Blueprint $table) {
            $table->id();
            $table->string('code', 80)->unique();
            $table->string('label', 120);
            $table->string('field_type', 30)->default('text');
            $table->string('placeholder', 200)->nullable();
            $table->json('select_options')->nullable();
            $table->boolean('is_core')->default(false);
            $table->boolean('is_universal_default')->default(false);
            $table->boolean('is_required_default')->default(false);
            $table->unsignedSmallInteger('default_order')->default(100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'default_order']);
        });

        Schema::create('merchant_category_catalog_field', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_category_id')
                ->constrained('merchant_categories')
                ->cascadeOnDelete();
            $table->foreignId('catalog_field_id')
                ->constrained('catalog_fields')
                ->cascadeOnDelete();
            $table->string('custom_label', 120)->nullable();
            $table->boolean('is_required')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(100);
            $table->timestamps();

            $table->unique(['merchant_category_id', 'catalog_field_id'], 'mc_cf_unique');
            $table->index(['merchant_category_id', 'sort_order'], 'mc_cf_order_idx');
        });

        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'quantity')) {
                $table->unsignedInteger('quantity')->nullable()->after('is_available');
            }
            if (! Schema::hasColumn('products', 'custom_attributes')) {
                $table->json('custom_attributes')->nullable()->after('sort_order');
            }
        });

        $this->seedInitialDictionaryAndCategoryMappings();
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'custom_attributes')) {
                $table->dropColumn('custom_attributes');
            }
            if (Schema::hasColumn('products', 'quantity')) {
                $table->dropColumn('quantity');
            }
        });

        Schema::dropIfExists('merchant_category_catalog_field');
        Schema::dropIfExists('catalog_fields');
    }

    private function seedInitialDictionaryAndCategoryMappings(): void
    {
        $now = now();

        $fields = [
            // ── Champs universels prioritaires cochés par défaut (Nom en 1er, Quantité plus bas) ──
            [
                'code' => 'name',
                'label' => 'Nom du produit',
                'field_type' => 'text',
                'placeholder' => 'Ex: Crème hydratante, Foutou banane...',
                'select_options' => null,
                'is_core' => true,
                'is_universal_default' => true,
                'is_required_default' => true,
                'default_order' => 10,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'description',
                'label' => 'Description',
                'field_type' => 'textarea',
                'placeholder' => 'Décrivez brièvement votre article...',
                'select_options' => null,
                'is_core' => true,
                'is_universal_default' => true,
                'is_required_default' => false,
                'default_order' => 20,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'price',
                'label' => 'Prix (F CFA)',
                'field_type' => 'currency',
                'placeholder' => 'Ex: 2500',
                'select_options' => null,
                'is_core' => true,
                'is_universal_default' => true,
                'is_required_default' => true,
                'default_order' => 30,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'photo',
                'label' => 'Photo du produit',
                'field_type' => 'image',
                'placeholder' => null,
                'select_options' => null,
                'is_core' => true,
                'is_universal_default' => true,
                'is_required_default' => false,
                'default_order' => 40,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'quantity',
                'label' => 'Quantité en stock',
                'field_type' => 'number',
                'placeholder' => 'Ex: 10',
                'select_options' => null,
                'is_core' => true,
                'is_universal_default' => true,
                'is_required_default' => false,
                'default_order' => 90,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],

            // ── Champs métier prêts à cocher par Type de Commerce ──
            [
                'code' => 'category',
                'label' => 'Rayon / Sous-catégorie',
                'field_type' => 'text',
                'placeholder' => 'Ex: Plats, Boissons, Soins visage...',
                'select_options' => null,
                'is_core' => true,
                'is_universal_default' => false,
                'is_required_default' => false,
                'default_order' => 45,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'brand',
                'label' => 'Marque',
                'field_type' => 'text',
                'placeholder' => 'Ex: Nivea, Samsung, Nike...',
                'select_options' => null,
                'is_core' => false,
                'is_universal_default' => false,
                'is_required_default' => false,
                'default_order' => 50,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'ingredients',
                'label' => 'Ingrédients',
                'field_type' => 'textarea',
                'placeholder' => 'Ex: Attiéké, poisson braisé, oignons, tomates...',
                'select_options' => null,
                'is_core' => false,
                'is_universal_default' => false,
                'is_required_default' => false,
                'default_order' => 55,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'allergens',
                'label' => 'Allergènes',
                'field_type' => 'text',
                'placeholder' => 'Ex: Arachides, gluten, lait...',
                'select_options' => null,
                'is_core' => false,
                'is_universal_default' => false,
                'is_required_default' => false,
                'default_order' => 58,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'volume',
                'label' => 'Contenance',
                'field_type' => 'text',
                'placeholder' => 'Ex: 250 ml, 1 L, 50 g',
                'select_options' => null,
                'is_core' => false,
                'is_universal_default' => false,
                'is_required_default' => false,
                'default_order' => 60,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'size',
                'label' => 'Taille',
                'field_type' => 'text',
                'placeholder' => 'Ex: S, M, L, XL ou 42',
                'select_options' => null,
                'is_core' => false,
                'is_universal_default' => false,
                'is_required_default' => false,
                'default_order' => 65,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'color',
                'label' => 'Couleur',
                'field_type' => 'text',
                'placeholder' => 'Ex: Noir, Blanc, Bleu...',
                'select_options' => null,
                'is_core' => false,
                'is_universal_default' => false,
                'is_required_default' => false,
                'default_order' => 70,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'weight',
                'label' => 'Poids',
                'field_type' => 'text',
                'placeholder' => 'Ex: 500 g, 1 kg',
                'select_options' => null,
                'is_core' => false,
                'is_universal_default' => false,
                'is_required_default' => false,
                'default_order' => 72,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'expiry_date',
                'label' => 'Date d\'expiration',
                'field_type' => 'text',
                'placeholder' => 'Ex: 12/2027',
                'select_options' => null,
                'is_core' => false,
                'is_universal_default' => false,
                'is_required_default' => false,
                'default_order' => 75,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'dosage',
                'label' => 'Posologie / Mode d\'emploi',
                'field_type' => 'textarea',
                'placeholder' => 'Ex: 1 comprimé matin et soir...',
                'select_options' => null,
                'is_core' => false,
                'is_universal_default' => false,
                'is_required_default' => false,
                'default_order' => 78,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'warranty',
                'label' => 'Garantie',
                'field_type' => 'text',
                'placeholder' => 'Ex: 12 mois',
                'select_options' => null,
                'is_core' => false,
                'is_universal_default' => false,
                'is_required_default' => false,
                'default_order' => 80,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        DB::table('catalog_fields')->insert($fields);

        /** @var array<string, int> $fieldIds */
        $fieldIds = DB::table('catalog_fields')->pluck('id', 'code')->map(fn ($id) => (int) $id)->all();

        $categories = DB::table('merchant_categories')->get(['id', 'slug']);
        if ($categories->isEmpty()) {
            return;
        }

        $presetsBySlug = [
            'restaurant' => [
                ['code' => 'name', 'custom_label' => 'Nom du plat', 'is_required' => true, 'sort_order' => 10],
                ['code' => 'description', 'custom_label' => 'Description du plat', 'is_required' => false, 'sort_order' => 20],
                ['code' => 'price', 'custom_label' => 'Prix du plat (F CFA)', 'is_required' => true, 'sort_order' => 30],
                ['code' => 'photo', 'custom_label' => 'Photo du plat', 'is_required' => false, 'sort_order' => 40],
                ['code' => 'ingredients', 'custom_label' => 'Ingrédients', 'is_required' => false, 'sort_order' => 50],
                ['code' => 'category', 'custom_label' => 'Rubrique du menu', 'is_required' => false, 'sort_order' => 60],
                ['code' => 'quantity', 'custom_label' => 'Portions disponibles', 'is_required' => false, 'sort_order' => 90],
            ],
            'boulangerie' => [
                ['code' => 'name', 'custom_label' => 'Nom du produit', 'is_required' => true, 'sort_order' => 10],
                ['code' => 'description', 'custom_label' => 'Description', 'is_required' => false, 'sort_order' => 20],
                ['code' => 'price', 'custom_label' => 'Prix (F CFA)', 'is_required' => true, 'sort_order' => 30],
                ['code' => 'photo', 'custom_label' => 'Photo du produit', 'is_required' => false, 'sort_order' => 40],
                ['code' => 'ingredients', 'custom_label' => 'Ingrédients', 'is_required' => false, 'sort_order' => 50],
                ['code' => 'weight', 'custom_label' => 'Poids', 'is_required' => false, 'sort_order' => 60],
                ['code' => 'quantity', 'custom_label' => 'Quantité disponible', 'is_required' => false, 'sort_order' => 90],
            ],
            'boutique' => [
                ['code' => 'name', 'custom_label' => 'Nom du produit', 'is_required' => true, 'sort_order' => 10],
                ['code' => 'description', 'custom_label' => 'Description', 'is_required' => false, 'sort_order' => 20],
                ['code' => 'price', 'custom_label' => 'Prix (F CFA)', 'is_required' => true, 'sort_order' => 30],
                ['code' => 'photo', 'custom_label' => 'Photo du produit', 'is_required' => false, 'sort_order' => 40],
                ['code' => 'brand', 'custom_label' => 'Marque', 'is_required' => false, 'sort_order' => 50],
                ['code' => 'size', 'custom_label' => 'Taille', 'is_required' => false, 'sort_order' => 60],
                ['code' => 'color', 'custom_label' => 'Couleur', 'is_required' => false, 'sort_order' => 70],
                ['code' => 'quantity', 'custom_label' => 'Quantité en stock', 'is_required' => false, 'sort_order' => 90],
            ],
            'pharmacie' => [
                ['code' => 'name', 'custom_label' => 'Nom du produit', 'is_required' => true, 'sort_order' => 10],
                ['code' => 'description', 'custom_label' => 'Description', 'is_required' => false, 'sort_order' => 20],
                ['code' => 'price', 'custom_label' => 'Prix (F CFA)', 'is_required' => true, 'sort_order' => 30],
                ['code' => 'photo', 'custom_label' => 'Photo du produit', 'is_required' => false, 'sort_order' => 40],
                ['code' => 'brand', 'custom_label' => 'Laboratoire / Marque', 'is_required' => false, 'sort_order' => 50],
                ['code' => 'dosage', 'custom_label' => 'Posologie / Mode d\'emploi', 'is_required' => false, 'sort_order' => 60],
                ['code' => 'expiry_date', 'custom_label' => 'Date d\'expiration', 'is_required' => false, 'sort_order' => 70],
                ['code' => 'quantity', 'custom_label' => 'Quantité en stock', 'is_required' => false, 'sort_order' => 90],
            ],
            'hightech' => [
                ['code' => 'name', 'custom_label' => 'Nom du produit', 'is_required' => true, 'sort_order' => 10],
                ['code' => 'description', 'custom_label' => 'Description', 'is_required' => false, 'sort_order' => 20],
                ['code' => 'price', 'custom_label' => 'Prix (F CFA)', 'is_required' => true, 'sort_order' => 30],
                ['code' => 'photo', 'custom_label' => 'Photo du produit', 'is_required' => false, 'sort_order' => 40],
                ['code' => 'brand', 'custom_label' => 'Marque', 'is_required' => false, 'sort_order' => 50],
                ['code' => 'color', 'custom_label' => 'Couleur', 'is_required' => false, 'sort_order' => 60],
                ['code' => 'warranty', 'custom_label' => 'Garantie', 'is_required' => false, 'sort_order' => 70],
                ['code' => 'quantity', 'custom_label' => 'Quantité en stock', 'is_required' => false, 'sort_order' => 90],
            ],
            'supermarche' => [
                ['code' => 'name', 'custom_label' => 'Nom du produit', 'is_required' => true, 'sort_order' => 10],
                ['code' => 'description', 'custom_label' => 'Description', 'is_required' => false, 'sort_order' => 20],
                ['code' => 'price', 'custom_label' => 'Prix (F CFA)', 'is_required' => true, 'sort_order' => 30],
                ['code' => 'photo', 'custom_label' => 'Photo du produit', 'is_required' => false, 'sort_order' => 40],
                ['code' => 'brand', 'custom_label' => 'Marque', 'is_required' => false, 'sort_order' => 50],
                ['code' => 'volume', 'custom_label' => 'Contenance', 'is_required' => false, 'sort_order' => 60],
                ['code' => 'weight', 'custom_label' => 'Poids', 'is_required' => false, 'sort_order' => 70],
                ['code' => 'quantity', 'custom_label' => 'Quantité en stock', 'is_required' => false, 'sort_order' => 90],
            ],
        ];

        $defaultPreset = [
            ['code' => 'name', 'custom_label' => 'Nom du produit', 'is_required' => true, 'sort_order' => 10],
            ['code' => 'description', 'custom_label' => 'Description', 'is_required' => false, 'sort_order' => 20],
            ['code' => 'price', 'custom_label' => 'Prix (F CFA)', 'is_required' => true, 'sort_order' => 30],
            ['code' => 'photo', 'custom_label' => 'Photo du produit', 'is_required' => false, 'sort_order' => 40],
            ['code' => 'quantity', 'custom_label' => 'Quantité en stock', 'is_required' => false, 'sort_order' => 90],
        ];

        $pivotRows = [];
        foreach ($categories as $category) {
            $slug = strtolower((string) $category->slug);
            $preset = $presetsBySlug[$slug] ?? $defaultPreset;

            foreach ($preset as $item) {
                $fieldId = $fieldIds[$item['code']] ?? null;
                if ($fieldId === null) {
                    continue;
                }

                $pivotRows[] = [
                    'merchant_category_id' => (int) $category->id,
                    'catalog_field_id' => $fieldId,
                    'custom_label' => $item['custom_label'],
                    'is_required' => $item['is_required'],
                    'sort_order' => $item['sort_order'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        if ($pivotRows !== []) {
            DB::table('merchant_category_catalog_field')->insert($pivotRows);
        }
    }
};
