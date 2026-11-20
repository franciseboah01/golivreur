<?php

namespace App\Services;

use App\Exceptions\UnauthorizedCatalogFieldException;
use App\Models\ActivityLog;
use App\Models\CatalogField;
use App\Models\Merchant;
use App\Models\MerchantCategory;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Service central du Catalogue Dynamique (Dictionnaire de champs contrôlé par l'Admin).
 *
 * Responsabilités :
 * - Résolution des champs cochés, ordonnés et précisément libellés pour chaque Type de Commerce.
 * - Synchronisation des choix et de l'ordre depuis l'interface Admin Filament.
 * - Sécurité API stricte : rejet HTTP 403 si un commerçant soumet un champ non autorisé pour son type.
 * - Validation métier typée + persistance dans les colonnes natives `products` et `products.custom_attributes`.
 */
class CatalogSchemaService
{
    /** @var list<string> */
    private const TECHNICAL_META_KEYS = [
        '_method',
        'is_available',
        'sort_order',
        'attributes',
    ];

    /**
     * Vérifie si un libellé est trop générique (interdit d'utiliser juste « Nom »).
     */
    public function isForbiddenGenericLabel(string $label): bool
    {
        $normalized = mb_strtolower(trim(rtrim(trim($label), '*')));

        return $normalized === 'nom';
    }

    /**
     * Détermine si une catégorie relève de la restauration (libellé « Nom du plat »).
     */
    public function isFoodCategory(?MerchantCategory $category, ?string $slugHint = null): bool
    {
        $catSlug = $category !== null ? (string) $category->slug : '';
        $catName = $category !== null ? (string) $category->name : '';
        $haystack = mb_strtolower(trim("{$catSlug} {$catName} ".($slugHint ?? '')));

        foreach (['restaurant', 'resto', 'maquis', 'fast', 'traiteur', 'grill', 'cuisine', 'pizz', 'burger', 'snack'] as $keyword) {
            if (str_contains($haystack, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Résout toujours un libellé précis (jamais juste « Nom »).
     */
    public function resolvePreciseLabel(
        CatalogField $field,
        ?string $customLabel = null,
        ?MerchantCategory $category = null,
        ?string $slugHint = null,
    ): string {
        $candidate = is_string($customLabel) && trim($customLabel) !== ''
            ? trim($customLabel)
            : trim($field->label);

        if ($field->code === 'name' && ($candidate === '' || $this->isForbiddenGenericLabel($candidate))) {
            return $this->isFoodCategory($category, $slugHint) ? 'Nom du plat' : 'Nom du produit';
        }

        if ($this->isForbiddenGenericLabel($candidate)) {
            return $this->isFoodCategory($category, $slugHint) ? 'Nom du plat' : 'Nom du produit';
        }

        return $candidate;
    }

    /**
     * Attache les champs universels prioritaires par défaut (Nom en 1er, Quantité plus bas)
     * à un Type de Commerce nouvellement créé sans configuration manuelle.
     */
    public function attachUniversalDefaults(MerchantCategory $category): void
    {
        if ($category->catalogFields()->exists()) {
            return;
        }

        $isFood = $this->isFoodCategory($category);
        $universalFields = CatalogField::query()
            ->where('is_active', true)
            ->where('is_universal_default', true)
            ->orderBy('default_order')
            ->orderBy('id')
            ->get();

        $syncPayload = [];
        $order = 10;

        foreach ($universalFields as $field) {
            $customLabel = match ($field->code) {
                'name' => $isFood ? 'Nom du plat' : 'Nom du produit',
                'photo' => $isFood ? 'Photo du plat' : 'Photo du produit',
                'quantity' => $isFood ? 'Portions disponibles' : 'Quantité en stock',
                default => $field->label,
            };

            $syncPayload[$field->id] = [
                'custom_label' => $customLabel,
                'is_required' => (bool) $field->is_required_default,
                'sort_order' => $field->code === 'quantity' ? max(90, $order) : $order,
            ];
            $order += 10;
        }

        if ($syncPayload !== []) {
            $category->catalogFields()->sync($syncPayload);
        }
    }

    /**
     * Construit les lignes initiales du Repeater Filament lors de la création d'un Type de Commerce.
     * Les champs universels prioritaires sont cochés en tête (Nom en 1er, Quantité plus bas).
     *
     * @return list<array<string, mixed>>
     */
    public function buildDefaultConfigRowsForNewCategory(?string $slugHint = null): array
    {
        $isFood = $this->isFoodCategory(null, $slugHint);

        $fields = CatalogField::query()
            ->where('is_active', true)
            ->orderByDesc('is_universal_default')
            ->orderBy('default_order')
            ->orderBy('id')
            ->get();

        $rows = [];
        foreach ($fields as $field) {
            $defaultLabel = match ($field->code) {
                'name' => $isFood ? 'Nom du plat' : 'Nom du produit',
                'photo' => $isFood ? 'Photo du plat' : 'Photo du produit',
                'quantity' => $isFood ? 'Portions disponibles' : 'Quantité en stock',
                default => $field->label,
            };

            $rows[] = [
                'catalog_field_id' => $field->id,
                'code' => $field->code,
                'default_label' => $field->label,
                'field_type' => $field->field_type,
                'enabled' => (bool) $field->is_universal_default,
                'custom_label' => $defaultLabel,
                'is_required' => (bool) $field->is_required_default,
            ];
        }

        return $rows;
    }

    /**
     * Construit les lignes du Repeater Filament pour un Type de Commerce existant :
     * champs cochés en tête dans leur ordre exact (`pivot.sort_order`), suivis des champs décochés.
     *
     * @return list<array<string, mixed>>
     */
    public function buildConfigRowsForCategory(MerchantCategory $category): array
    {
        $attached = $category->catalogFields()
            ->where('catalog_fields.is_active', true)
            ->get();

        $attachedIds = [];
        $rows = [];

        foreach ($attached as $field) {
            $attachedIds[] = $field->id;
            /** @var object{custom_label?: string|null, is_required?: bool|int|null, sort_order?: int|null}|null $pivot */
            $pivot = $field->getRelationValue('pivot');
            $customLabel = is_object($pivot) && isset($pivot->custom_label) && is_string($pivot->custom_label)
                ? $pivot->custom_label
                : null;

            $rows[] = [
                'catalog_field_id' => $field->id,
                'code' => $field->code,
                'default_label' => $field->label,
                'field_type' => $field->field_type,
                'enabled' => true,
                'custom_label' => $this->resolvePreciseLabel($field, $customLabel, $category),
                'is_required' => is_object($pivot) ? (bool) ($pivot->is_required ?? false) : (bool) $field->is_required_default,
            ];
        }

        $unattached = CatalogField::query()
            ->where('is_active', true)
            ->when($attachedIds !== [], fn ($q) => $q->whereNotIn('id', $attachedIds))
            ->orderBy('default_order')
            ->orderBy('id')
            ->get();

        $isFood = $this->isFoodCategory($category);
        foreach ($unattached as $field) {
            $suggested = match ($field->code) {
                'name' => $isFood ? 'Nom du plat' : 'Nom du produit',
                'photo' => $isFood ? 'Photo du plat' : 'Photo du produit',
                'quantity' => $isFood ? 'Portions disponibles' : 'Quantité en stock',
                default => $field->label,
            };

            $rows[] = [
                'catalog_field_id' => $field->id,
                'code' => $field->code,
                'default_label' => $field->label,
                'field_type' => $field->field_type,
                'enabled' => false,
                'custom_label' => $suggested,
                'is_required' => (bool) $field->is_required_default,
            ];
        }

        return $rows;
    }

    /**
     * Synchronise les champs cochés et leur ordre exact (issu du drag-and-drop Filament)
     * dans `merchant_category_catalog_field`.
     *
     * @param  array<int|string, array<string, mixed>>  $rows
     */
    public function syncCategoryFieldConfig(MerchantCategory $category, array $rows): void
    {
        /** @var array<int, CatalogField> $fieldsById */
        $fieldsById = CatalogField::query()
            ->where('is_active', true)
            ->get()
            ->keyBy('id')
            ->all();

        $syncPayload = [];
        $order = 10;

        foreach (array_values($rows) as $row) {
            if (! is_array($row) || empty($row['enabled'])) {
                continue;
            }

            $fieldId = isset($row['catalog_field_id']) ? (int) $row['catalog_field_id'] : 0;
            $field = $fieldsById[$fieldId] ?? null;
            if (! $field instanceof CatalogField) {
                continue;
            }

            $rawCustom = isset($row['custom_label']) && is_string($row['custom_label'])
                ? trim($row['custom_label'])
                : null;

            $resolvedLabel = $this->resolvePreciseLabel($field, $rawCustom, $category);

            $syncPayload[$field->id] = [
                'custom_label' => $resolvedLabel,
                'is_required' => ! empty($row['is_required']),
                'sort_order' => $order,
            ];
            $order += 10;
        }

        $category->catalogFields()->sync($syncPayload);
    }

    /**
     * Résout le Type de Commerce (`MerchantCategory`) d'un commerçant.
     */
    public function resolveMerchantCategory(Merchant $merchant): ?MerchantCategory
    {
        if ($merchant->relationLoaded('categoryModel') && $merchant->categoryModel instanceof MerchantCategory) {
            return $merchant->categoryModel;
        }

        $slug = trim((string) $merchant->category);
        if ($slug === '') {
            return null;
        }

        return MerchantCategory::query()
            ->where('slug', $slug)
            ->first();
    }

    /**
     * Retourne le schéma complet des champs cochés et ordonnés pour un commerçant.
     *
     * @return array{
     *     merchant_category: array{id: int|null, slug: string, name: string, is_food: bool},
     *     fields: list<array{
     *         id: int,
     *         code: string,
     *         label: string,
     *         field_type: string,
     *         is_core: bool,
     *         is_required: bool,
     *         sort_order: int,
     *         placeholder: string|null,
     *         options: list<string>
     *     }>
     * }
     */
    public function getSchemaForMerchant(Merchant $merchant): array
    {
        $category = $this->resolveMerchantCategory($merchant);
        $isFood = $this->isFoodCategory($category, (string) $merchant->category);

        if ($category instanceof MerchantCategory) {
            $attached = $category->catalogFields()
                ->where('catalog_fields.is_active', true)
                ->get();

            $fields = [];
            foreach ($attached as $field) {
                /** @var object{custom_label?: string|null, is_required?: bool|int|null, sort_order?: int|null}|null $pivot */
                $pivot = $field->getRelationValue('pivot');
                $customLabel = is_object($pivot) && isset($pivot->custom_label) && is_string($pivot->custom_label)
                    ? $pivot->custom_label
                    : null;

                $fields[] = [
                    'id' => $field->id,
                    'code' => $field->code,
                    'label' => $this->resolvePreciseLabel($field, $customLabel, $category),
                    'field_type' => $field->field_type,
                    'is_core' => (bool) $field->is_core,
                    'is_required' => is_object($pivot) ? (bool) ($pivot->is_required ?? false) : (bool) $field->is_required_default,
                    'sort_order' => is_object($pivot) ? (int) ($pivot->sort_order ?? $field->default_order) : (int) $field->default_order,
                    'placeholder' => $field->placeholder,
                    'options' => is_array($field->select_options) ? array_values(array_map('strval', $field->select_options)) : [],
                ];
            }

            return [
                'merchant_category' => [
                    'id' => $category->id,
                    'slug' => (string) $category->slug,
                    'name' => (string) $category->name,
                    'is_food' => $isFood,
                ],
                'fields' => $fields,
            ];
        }

        // Repli sécurisé si un ancien commerce possède un slug non rattaché : champs universels uniquement
        $universal = CatalogField::query()
            ->where('is_active', true)
            ->where('is_universal_default', true)
            ->orderBy('default_order')
            ->orderBy('id')
            ->get();

        $fields = [];
        foreach ($universal as $field) {
            $fields[] = [
                'id' => $field->id,
                'code' => $field->code,
                'label' => $this->resolvePreciseLabel($field, null, null, (string) $merchant->category),
                'field_type' => $field->field_type,
                'is_core' => (bool) $field->is_core,
                'is_required' => (bool) $field->is_required_default,
                'sort_order' => (int) $field->default_order,
                'placeholder' => $field->placeholder,
                'options' => is_array($field->select_options) ? array_values(array_map('strval', $field->select_options)) : [],
            ];
        }

        return [
            'merchant_category' => [
                'id' => null,
                'slug' => (string) $merchant->category,
                'name' => ucfirst((string) $merchant->category),
                'is_food' => $isFood,
            ],
            'fields' => $fields,
        ];
    }

    /**
     * Contrôle de sécurité strict (HTTP 403 si champ non autorisé) puis validation typée
     * et extraction des données prêtes à être persistées sur `products`.
     *
     * @return array{
     *     core_data: array<string, mixed>,
     *     custom_attributes: array<string, mixed>,
     *     has_custom_attributes_update: bool,
     *     schema_fields: list<array<string, mixed>>
     * }
     *
     * @throws UnauthorizedCatalogFieldException
     * @throws ValidationException
     */
    public function validateAndExtractProductPayload(
        Merchant $merchant,
        Request $request,
        ?Product $existingProduct = null,
    ): array {
        $isUpdate = $existingProduct !== null;
        $schema = $this->getSchemaForMerchant($merchant);
        $schemaFields = $schema['fields'];

        /** @var array<string, array<string, mixed>> $fieldsByCode */
        $fieldsByCode = [];
        foreach ($schemaFields as $field) {
            $fieldsByCode[(string) $field['code']] = $field;
        }
        $allowedCodes = array_keys($fieldsByCode);

        // 1. Fusionner les champs soumis (racine + fichiers + éventuel sous-objet/JSON `attributes`)
        $rawAttributes = $this->decodeSubmittedAttributes($request->input('attributes'));
        $submittedValues = [];
        $submittedCodes = [];

        foreach ($request->all() as $key => $value) {
            $keyStr = (string) $key;
            if (in_array($keyStr, self::TECHNICAL_META_KEYS, true)) {
                continue;
            }
            $canonicalCode = $keyStr === 'photo_url' ? 'photo' : $keyStr;
            $submittedCodes[] = $canonicalCode;
            $submittedValues[$canonicalCode] = $value;
        }

        foreach ($request->allFiles() as $fileKey => $file) {
            $keyStr = (string) $fileKey;
            $canonicalCode = $keyStr === 'photo_url' ? 'photo' : $keyStr;
            $submittedCodes[] = $canonicalCode;
            $submittedValues[$canonicalCode] = $file;
        }

        foreach ($rawAttributes as $attrKey => $attrVal) {
            $code = (string) $attrKey;
            $submittedCodes[] = $code;
            $submittedValues[$code] = $attrVal;
        }

        $submittedCodes = array_values(array_unique($submittedCodes));

        // 2. SÉCURITÉ STRICTE (403) : aucun champ hors de la liste cochée pour ce Type de Commerce
        $forbiddenFields = array_values(array_diff($submittedCodes, $allowedCodes));
        if ($forbiddenFields !== []) {
            ActivityLog::create([
                'user_id' => auth()->id(),
                'action' => 'merchant_unauthorized_product_field_blocked',
                'entity_type' => 'merchant',
                'entity_id' => $merchant->id,
                'properties' => [
                    'merchant_category' => $schema['merchant_category']['slug'],
                    'forbidden_fields' => $forbiddenFields,
                    'allowed_fields' => $allowedCodes,
                ],
                'ip_address' => $request->ip(),
                'severity' => 'warning',
            ]);

            throw new UnauthorizedCatalogFieldException($forbiddenFields);
        }

        // 3. Validation dynamique champ par champ selon le Dictionnaire Admin
        $rules = [
            'is_available' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ];
        $niceNames = [];
        $dataToValidate = [];

        if ($request->has('is_available')) {
            $dataToValidate['is_available'] = $request->input('is_available');
        }
        if ($request->has('sort_order')) {
            $dataToValidate['sort_order'] = $request->input('sort_order');
        }

        foreach ($schemaFields as $field) {
            $code = (string) $field['code'];
            $type = (string) $field['field_type'];
            $isRequired = (bool) $field['is_required'];
            $options = is_array($field['options']) ? $field['options'] : [];
            $niceNames[$code] = (string) $field['label'];

            $wasSubmitted = array_key_exists($code, $submittedValues);
            if ($wasSubmitted) {
                $val = $submittedValues[$code];
                // Normaliser les chaînes vides soumises via multipart en null pour les champs facultatifs
                if (is_string($val) && trim($val) === '' && ! $isRequired && $type !== CatalogField::TYPE_IMAGE) {
                    $val = null;
                }
                $dataToValidate[$code] = $val;
            }

            // À la création, name et price restent indispensables pour l'intégrité GoShop s'ils sont dans le schéma
            $effectivelyRequired = $isRequired || (! $isUpdate && in_array($code, ['name', 'price'], true));
            $presenceRule = $isUpdate
                ? ($isRequired ? ['sometimes', 'required'] : ['sometimes', 'nullable'])
                : ($effectivelyRequired ? ['required'] : ['nullable']);

            $typeRules = match ($type) {
                CatalogField::TYPE_CURRENCY => ['numeric', 'min:100'],
                CatalogField::TYPE_NUMBER => ['integer', 'min:0'],
                CatalogField::TYPE_TEXTAREA => ['string', 'max:2000'],
                CatalogField::TYPE_BOOLEAN => ['boolean'],
                CatalogField::TYPE_SELECT => $options !== []
                    ? ['string', 'max:255', Rule::in($options)]
                    : ['string', 'max:255'],
                CatalogField::TYPE_IMAGE => $this->imageFieldRules($dataToValidate[$code] ?? null),
                default => ['string', $code === 'name' ? 'max:200' : 'max:255'],
            };

            $rules[$code] = [...$presenceRule, ...$typeRules];
        }

        $validator = Validator::make($dataToValidate, $rules, [], $niceNames);
        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $validated = $validator->validated();

        // 4. Répartition entre colonnes natives `products` et JSON `custom_attributes`
        $coreData = [];
        $customAttributes = is_array($existingProduct?->custom_attributes)
            ? $existingProduct->custom_attributes
            : [];
        $hasCustomAttributesUpdate = false;

        if (array_key_exists('is_available', $validated)) {
            $coreData['is_available'] = (bool) $validated['is_available'];
        }
        if (array_key_exists('sort_order', $validated)) {
            $coreData['sort_order'] = (int) $validated['sort_order'];
        }

        foreach ($schemaFields as $field) {
            $code = (string) $field['code'];
            if (! array_key_exists($code, $validated)) {
                continue;
            }

            $value = $validated[$code];
            $type = (string) $field['field_type'];

            if ($code === 'photo') {
                $rawOldPhoto = $existingProduct?->getRawOriginal('photo_url');
                $oldPhotoPath = is_string($rawOldPhoto) ? $rawOldPhoto : null;

                if ($value instanceof UploadedFile) {
                    $storedPath = Storage::disk('public')->putFile('products/photos', $value);
                    if (is_string($storedPath) && $storedPath !== '') {
                        $this->deleteLocalProductPhotoIfOwned($oldPhotoPath);
                        $coreData['photo_url'] = $storedPath;
                    }
                } elseif (is_string($value)) {
                    $trimmed = trim($value);
                    $coreData['photo_url'] = $trimmed !== '' ? $trimmed : null;
                } elseif ($value === null) {
                    $this->deleteLocalProductPhotoIfOwned($oldPhotoPath);
                    $coreData['photo_url'] = null;
                }

                continue;
            }

            if ($code === 'name') {
                $coreData['name'] = trim((string) $value);

                continue;
            }

            if ($code === 'description') {
                $coreData['description'] = $value !== null ? trim((string) $value) : null;

                continue;
            }

            if ($code === 'price') {
                $coreData['price'] = (int) round((float) $value);

                continue;
            }

            if ($code === 'quantity') {
                $coreData['quantity'] = $value !== null ? (int) $value : null;

                continue;
            }

            if ($code === 'category') {
                $coreData['category'] = $value !== null ? trim((string) $value) : null;

                continue;
            }

            // Champ dynamique stocké dans `products.custom_attributes`
            $hasCustomAttributesUpdate = true;
            if ($value === null || (is_string($value) && trim($value) === '')) {
                unset($customAttributes[$code]);
            } elseif ($type === CatalogField::TYPE_BOOLEAN) {
                $customAttributes[$code] = (bool) $value;
            } elseif ($type === CatalogField::TYPE_NUMBER) {
                $customAttributes[$code] = (int) $value;
            } elseif ($type === CatalogField::TYPE_CURRENCY) {
                $customAttributes[$code] = (int) round((float) $value);
            } elseif ($type === CatalogField::TYPE_IMAGE && $value instanceof UploadedFile) {
                $storedPath = Storage::disk('public')->putFile('products/attributes', $value);
                if (is_string($storedPath) && $storedPath !== '') {
                    $customAttributes[$code] = url('storage/'.$storedPath);
                }
            } else {
                $customAttributes[$code] = is_string($value) ? trim($value) : $value;
            }
        }

        // Garantit que les colonnes SQL NOT NULL ont toujours une valeur valide à la création
        if (! $isUpdate) {
            if (! array_key_exists('name', $coreData)) {
                throw ValidationException::withMessages([
                    'name' => ['Le nom du produit/plat est requis pour créer un article.'],
                ]);
            }
            if (! array_key_exists('price', $coreData)) {
                throw ValidationException::withMessages([
                    'price' => ['Le prix est requis pour créer un article.'],
                ]);
            }
        }

        return [
            'core_data' => $coreData,
            'custom_attributes' => $customAttributes,
            'has_custom_attributes_update' => $hasCustomAttributesUpdate,
            'schema_fields' => $schemaFields,
        ];
    }

    /**
     * Formate un produit pour les API mobiles (avec attributs dynamiques ordonnés selon l'Admin).
     *
     * @param  list<array<string, mixed>>  $schemaFields
     * @return array<string, mixed>
     */
    public function formatProductForApi(Product $product, array $schemaFields = []): array
    {
        $rawAttrs = is_array($product->custom_attributes) ? $product->custom_attributes : [];
        $allowedDynamicCodes = [];
        $orderedAttributes = [];

        foreach ($schemaFields as $field) {
            $code = (string) $field['code'];
            if (! empty($field['is_core'])) {
                continue;
            }
            $allowedDynamicCodes[] = $code;
            if (array_key_exists($code, $rawAttrs) && $rawAttrs[$code] !== null && $rawAttrs[$code] !== '') {
                $orderedAttributes[] = [
                    'code' => $code,
                    'label' => (string) $field['label'],
                    'field_type' => (string) $field['field_type'],
                    'value' => $rawAttrs[$code],
                ];
            }
        }

        $filteredAttributes = $allowedDynamicCodes !== []
            ? array_intersect_key($rawAttrs, array_flip($allowedDynamicCodes))
            : $rawAttrs;

        return [
            'id' => $product->id,
            'merchant_id' => $product->merchant_id,
            'name' => $product->name,
            'description' => $product->description,
            'price' => (int) $product->price,
            'photo_url' => $product->resolved_photo_url,
            'is_available' => (bool) $product->is_available,
            'quantity' => $product->quantity !== null ? (int) $product->quantity : null,
            'category' => $product->category,
            'sort_order' => (int) $product->sort_order,
            'attributes' => $filteredAttributes,
            'ordered_attributes' => $orderedAttributes,
            'created_at' => $product->created_at?->toIso8601String(),
            'updated_at' => $product->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeSubmittedAttributes(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }

        if (is_string($raw) && trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function imageFieldRules(mixed $value): array
    {
        if ($value instanceof UploadedFile) {
            return ['file', 'max:5120', 'mimetypes:image/jpeg,image/png,image/webp', 'mimes:jpg,jpeg,png,webp'];
        }

        return ['string', 'max:500'];
    }

    private function deleteLocalProductPhotoIfOwned(?string $path): void
    {
        if (! is_string($path) || $path === '') {
            return;
        }
        if (str_starts_with($path, 'products/photos/') && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }
}
