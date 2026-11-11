# Classement & Traitement des 99 Erreurs Larastan (Niveau 5) — GoLivreur

> **Outil :** `php vendor/bin/phpstan analyse` (`phpstan.neon`, `level: 5` sur `app/` — 149 fichiers).  
> **Résultat avant :** `[ERROR] Found 99 errors`.  
> **Résultat après :** `[OK] No errors` (`phpstan-baseline.neon` limité strictement aux **4 faux positifs** documentés).

---

## Tableau de classement des 99 erreurs

| # | Catégorie / Groupe d'erreurs | Nb | Classe | Action effectuée |
|---|---|---|---|---|
| 1 | `property.notFound` sur propriétés inexistantes (`$order->order_number`, `$parcel->tracking_number`) dans `Filament\Resources\RefundResource\Pages\ListRefunds` (+ libellés `# - total` du modal) | **2** *(+2 associées)* | **BUG RÉEL** *(libellés vides à l'exécution + description `wallet_transactions` sans n°)* | Remplacé par `$order->id`, `$order->total_client` et `$parcel->id` avec `@var Order` / `@var Parcel` dans `ListRefunds.php` + test Pest dans `RefundAndRoleValidationTest.php`. |
| 2 | `arguments.count` : `CheckboxList::minItems(1, 'Veuillez cocher...')` appelé avec 2 arguments dans `RoleHabilitationResource.php:73` | **1** | **BUG RÉEL** *(Filament `CanLimitItemsLength::minItems()` ignore le 2e argument ; message français perdu)* | Remplacé par `->minItems(1)->validationMessages(['min' => ..., 'required' => ...])` + test Pest vérifiant l'affichage du message français. |
| 3 | `property.notFound` sur propriétés de modèles accédées via `$this` dans 5 `JsonResource` (`BusinessProfileResource` 11, `DriverProfileResource` 11, `ClientProfileResource` 11, `MerchantCategoryResource` 8, `MerchantResource` 16) | **57** | **DETTE DE TYPAGE** | Ajout des annotations `@mixin` (`User`, `Merchant`, `MerchantCategory`) sur les 5 Resources + `@property-read` sur `User`, `Merchant`, `MerchantCategory`, `Order`. |
| 4 | `nullsafe.neverNull` : usage redondant de `?->` juste à gauche de `??` (`BusinessProfileResource` 11, `DriverProfileResource` 7, `ClientController` 4, `OrderResource` 2, `CreateCourier` 2, `CreateMerchant` 2, `DisputesPage` 1, `ParcelResource` 1, `MerchantController` 1, `MerchantResource` 1, `ClientAddress` 1) | **33** | **DETTE DE TYPAGE** | Remplacement de `?->` par `->` juste à gauche de `??` (aucune alerte ni changement d'exécution en PHP 8, y compris sur valeurs `null`). |
| 5 | `argument.type` sur `ClientAddress` dans `ClientController.php` (`User::addresses()->get()->map(...)` et `formatAddress($address)`) | **2** | **DETTE DE TYPAGE** | Ajout des generics `@return HasMany<..., $this>` / `HasOne` / `BelongsTo` sur `User`, `Merchant`, `Courier` + typage `: ClientAddress` dans `DB::transaction`. |
| 6 | `property.notFound` sur `$this->form` dans `Filament\Pages\SettingsPage` (L66, L390) | **2** | **FAUX POSITIF** *(magie Filament / Livewire `InteractsWithForms::ResolvesDynamicLivewireProperties`)* | Conservé tel quel et ajouté dans `backend/phpstan-baseline.neon`. |
| 7 | `rules.modelAppends` sur `'photo_url'` dans `$appends` de `Courier.php` et `Merchant.php` | **2** | **FAUX POSITIF** *(colonne DB historique `photo_url` surchargée par accesseur fallback `getPhotoUrlAttribute()`, conservée dans `$appends` pour préserver `toArray()`)* | Conservé dans `$appends` sans altérer le comportement JSON et ajouté dans `backend/phpstan-baseline.neon`. |
| — | **TOTAL** | **99** | **3 Bugs réels / 92 Dette de typage / 4 Faux positifs** | **95 corrigées dans le code + 4 en `phpstan-baseline.neon` → `[OK] No errors`.** |

---

## Note d'audit connexe (hors périmètre des 99 erreurs)

- Des mentions similaires à `order_number` existent dans les tables/actions Filament de `DisputesPage.php` (L65, L102, L117, L126) et `OrderResource.php` (L326, L350, L384, L402), masquées par le filtre générique `phpstan.neon` (`App\Models\*::$*`). Elles n'ont pas été touchées dans cette passe afin de ne pas modifier d'autres écrans hors cible.

