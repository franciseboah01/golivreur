<?php

use App\Filament\Resources\CatalogFieldResource;
use App\Models\ActivityLog;
use App\Models\CatalogField;
use App\Models\City;
use App\Models\Merchant;
use App\Models\MerchantCategory;
use App\Models\Product;
use App\Models\User;
use App\Services\CatalogSchemaService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tymon\JWTAuth\Facades\JWTAuth;

it('pré-remplit le Dictionnaire de champs avec les universels prioritaires (Nom en 1er, Quantité plus bas) et interdit le libellé générique Nom', function () {
    $schemaService = app(CatalogSchemaService::class);

    // 1. Le dictionnaire central contient les champs universels et métier
    expect(CatalogField::where('code', 'name')->exists())->toBeTrue()
        ->and(CatalogField::where('code', 'brand')->exists())->toBeTrue()
        ->and(CatalogField::where('code', 'ingredients')->exists())->toBeTrue()
        ->and(CatalogField::where('code', 'volume')->exists())->toBeTrue()
        ->and(CatalogField::where('code', 'quantity')->exists())->toBeTrue();

    // 2. Interdiction du libellé générique "Nom"
    expect($schemaService->isForbiddenGenericLabel('Nom'))->toBeTrue()
        ->and($schemaService->isForbiddenGenericLabel('  nom * '))->toBeTrue()
        ->and($schemaService->isForbiddenGenericLabel('Nom du plat'))->toBeFalse()
        ->and($schemaService->isForbiddenGenericLabel('Nom du produit'))->toBeFalse();

    // 3. Nouveau Type de Commerce : champs universels cochés par défaut, Nom en 1er, Quantité plus bas
    $cosmetique = MerchantCategory::create([
        'name' => 'Cosmétique & Beauté',
        'slug' => 'cosmetique',
        'is_active' => true,
    ]);
    $schemaService->attachUniversalDefaults($cosmetique);

    $orderedCodes = $cosmetique->catalogFields()->pluck('code')->all();
    expect($orderedCodes[0])->toBe('name')
        ->and($orderedCodes)->toContain('description', 'price', 'photo', 'quantity')
        ->and(end($orderedCodes))->toBe('quantity');

    $nameField = $cosmetique->catalogFields()->where('code', 'name')->firstOrFail();
    expect($schemaService->resolvePreciseLabel($nameField, 'Nom', $cosmetique))->toBe('Nom du produit');
});

it('renvoie via API les champs cochés dans l\'ordre exact de l\'Admin, enregistre photo + attributs et rejette tout champ non autorisé en 403', function () {
    Storage::fake('public');

    $city = City::factory()->create(['name' => 'Abidjan', 'is_active' => true]);
    $schemaService = app(CatalogSchemaService::class);

    // 1. L'Admin crée le Type de Commerce "Cosmétique" avec un ordre personnalisé
    $cosmetique = MerchantCategory::create([
        'name' => 'Cosmétique',
        'slug' => 'cosmetique',
        'is_active' => true,
    ]);

    $fieldsByCode = CatalogField::whereIn('code', ['name', 'brand', 'volume', 'price', 'photo', 'quantity'])
        ->get()
        ->keyBy('code');

    $schemaService->syncCategoryFieldConfig($cosmetique, [
        ['catalog_field_id' => $fieldsByCode['name']->id, 'enabled' => true, 'custom_label' => 'Nom du produit', 'is_required' => true],
        ['catalog_field_id' => $fieldsByCode['brand']->id, 'enabled' => true, 'custom_label' => 'Marque', 'is_required' => true],
        ['catalog_field_id' => $fieldsByCode['volume']->id, 'enabled' => true, 'custom_label' => 'Contenance', 'is_required' => false],
        ['catalog_field_id' => $fieldsByCode['price']->id, 'enabled' => true, 'custom_label' => 'Prix (F CFA)', 'is_required' => true],
        ['catalog_field_id' => $fieldsByCode['photo']->id, 'enabled' => true, 'custom_label' => 'Photo du produit', 'is_required' => false],
        ['catalog_field_id' => $fieldsByCode['quantity']->id, 'enabled' => true, 'custom_label' => 'Quantité en stock', 'is_required' => false],
    ]);

    $cosmeticUser = User::factory()->create(['role' => 'merchant', 'city_id' => $city->id, 'is_active' => true]);
    $cosmeticMerchant = Merchant::factory()->create([
        'user_id' => $cosmeticUser->id,
        'city_id' => $city->id,
        'category' => 'cosmetique',
        'is_active' => true,
    ]);
    $cosmeticToken = JWTAuth::fromUser($cosmeticUser);

    // 2. GET /merchant/catalog-schema retourne exactement l'ordre Admin sans aucun champ non coché
    $schemaRes = $this->withHeader('Authorization', "Bearer {$cosmeticToken}")
        ->getJson('/api/v1/merchant/catalog-schema')
        ->assertOk()
        ->assertJsonPath('succes', true)
        ->assertJsonPath('donnees.merchant_category.slug', 'cosmetique');

    $returnedCodes = collect($schemaRes->json('donnees.fields'))->pluck('code')->all();
    $returnedLabels = collect($schemaRes->json('donnees.fields'))->pluck('label')->all();
    expect($returnedCodes)->toBe(['name', 'brand', 'volume', 'price', 'photo', 'quantity'])
        ->and($returnedLabels[0])->toBe('Nom du produit')
        ->and($returnedCodes)->not->toContain('ingredients')
        ->and($returnedCodes)->not->toContain('size');

    // 3. SÉCURITÉ 403 : tentative de contournement en envoyant `ingredients` (non coché pour Cosmétique)
    $this->withHeader('Authorization', "Bearer {$cosmeticToken}")
        ->postJson('/api/v1/merchant/products', [
            'name' => 'Crème Solaire',
            'brand' => 'Nivea',
            'price' => 5500,
            'ingredients' => 'Beurre de karité',
        ])
        ->assertStatus(403)
        ->assertJsonPath('code', 'UNAUTHORIZED_CATALOG_FIELD')
        ->assertJsonPath('forbidden_fields', ['ingredients']);

    // Même rejet 403 si le champ interdit est glissé dans `attributes`
    $this->withHeader('Authorization', "Bearer {$cosmeticToken}")
        ->postJson('/api/v1/merchant/products', [
            'name' => 'Crème Solaire',
            'brand' => 'Nivea',
            'price' => 5500,
            'attributes' => ['size' => 'XL'],
        ])
        ->assertStatus(403)
        ->assertJsonPath('code', 'UNAUTHORIZED_CATALOG_FIELD')
        ->assertJsonPath('forbidden_fields', ['size']);

    expect(Product::where('merchant_id', $cosmeticMerchant->id)->count())->toBe(0);
    expect(
        ActivityLog::where('action', 'merchant_unauthorized_product_field_blocked')
            ->where('entity_id', $cosmeticMerchant->id)
            ->exists()
    )->toBeTrue();

    // 4. Création légitime avec photo + attributs dynamiques + quantité
    $photo = UploadedFile::fake()->image('creme.jpg', 500, 500);
    $createRes = $this->withHeader('Authorization', "Bearer {$cosmeticToken}")
        ->post('/api/v1/merchant/products', [
            'name' => 'Lait Corporel Hydratant',
            'brand' => 'Nivea',
            'volume' => '250 ml',
            'price' => 4500,
            'quantity' => 12,
            'photo' => $photo,
        ], ['Accept' => 'application/json']);

    $createRes->assertCreated()
        ->assertJsonPath('succes', true)
        ->assertJsonPath('donnees.name', 'Lait Corporel Hydratant')
        ->assertJsonPath('donnees.price', 4500)
        ->assertJsonPath('donnees.quantity', 12)
        ->assertJsonPath('donnees.attributes.brand', 'Nivea')
        ->assertJsonPath('donnees.attributes.volume', '250 ml');

    $orderedAttrCodes = collect($createRes->json('donnees.ordered_attributes'))->pluck('code')->all();
    expect($orderedAttrCodes)->toBe(['brand', 'volume']);

    $product = Product::findOrFail($createRes->json('donnees.id'));
    $rawPhotoPath = (string) $product->getRawOriginal('photo_url');
    expect($rawPhotoPath)->toStartWith('products/photos/');
    Storage::disk('public')->assertExists($rawPhotoPath);

    // 5. Rejet 403 également sur PUT /merchant/products/{id} si champ interdit envoyé
    $this->withHeader('Authorization', "Bearer {$cosmeticToken}")
        ->putJson("/api/v1/merchant/products/{$product->id}", [
            'ingredients' => 'Tentative injection',
        ])
        ->assertStatus(403)
        ->assertJsonPath('code', 'UNAUTHORIZED_CATALOG_FIELD');

    // 6. Vérification libellé précis Restauration ("Nom du plat", jamais "Nom" ni "Marque")
    auth('api')->logout();
    $restoUser = User::factory()->create(['role' => 'merchant', 'city_id' => $city->id, 'is_active' => true]);
    Merchant::factory()->create([
        'user_id' => $restoUser->id,
        'city_id' => $city->id,
        'category' => 'restaurant',
        'is_active' => true,
    ]);
    $restoToken = JWTAuth::fromUser($restoUser);

    $restoSchema = $this->withHeader('Authorization', "Bearer {$restoToken}")
        ->getJson('/api/v1/merchant/catalog-schema')
        ->assertOk();

    $restoFields = collect($restoSchema->json('donnees.fields'));
    expect($restoFields->first()['code'])->toBe('name')
        ->and($restoFields->first()['label'])->toBe('Nom du plat')
        ->and($restoFields->pluck('code')->all())->toContain('ingredients')
        ->and($restoFields->pluck('code')->all())->not->toContain('brand');
});

it('autorise la suppression d\'un champ inutilisé du Dictionnaire mais bloque strictement la suppression d\'un champ utilisé par un produit', function () {
    $city = City::factory()->create(['name' => 'Daloa', 'is_active' => true]);
    $merchantUser = User::factory()->create(['role' => 'merchant', 'city_id' => $city->id, 'is_active' => true]);
    $merchant = Merchant::factory()->create([
        'user_id' => $merchantUser->id,
        'city_id' => $city->id,
        'category' => 'boutique',
        'is_active' => true,
    ]);

    // 1. Champ personnalisé inutilisé -> suppression autorisée
    $unusedField = CatalogField::create([
        'code' => 'origine_fabrication',
        'label' => 'Origine de fabrication',
        'field_type' => CatalogField::TYPE_TEXT,
        'default_order' => 85,
        'is_active' => true,
    ]);

    expect($unusedField->isUsedByAnyProduct())->toBeFalse()
        ->and(CatalogFieldResource::canDelete($unusedField))->toBeTrue();

    $unusedField->delete();
    expect(CatalogField::where('code', 'origine_fabrication')->exists())->toBeFalse();

    // 2. Champ utilisé par un produit existant -> suppression strictement bloquée
    $brandField = CatalogField::where('code', 'brand')->firstOrFail();
    Product::create([
        'merchant_id' => $merchant->id,
        'name' => 'Chemise Wax',
        'price' => 12000,
        'is_available' => true,
        'custom_attributes' => ['brand' => 'Woodin'],
    ]);

    expect($brandField->isUsedByAnyProduct())->toBeTrue()
        ->and($brandField->countProductsUsingField())->toBe(1)
        ->and(CatalogFieldResource::canDelete($brandField))->toBeFalse();

    expect(fn () => $brandField->delete())->toThrow(DomainException::class);
    expect(CatalogField::where('code', 'brand')->exists())->toBeTrue();
});
