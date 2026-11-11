# Prévalidation Globale — GoLivreur (Bloc D)

> **Rôle :** Contrôleur qualité — aucune modification de code métier effectuée.  
> **Base vérifiée avant exécution :** `backend/.env` → `APP_ENV=local`, `DB_CONNECTION=pgsql`, `DB_HOST=127.0.0.1`, `DB_DATABASE=golivreur_dev`.

---

## Tableau de synthèse des vérifications (dans l'ordre d'exécution)

| # | Dossier | Commande | Résultat | Ligne de résumé / Extrait d'erreur | Fichiers concernés | Dû aux Blocs A/B ? |
|---|---------|----------|----------|-----------------------------------|--------------------|-------------------|
| 1 | `backend/` | `php artisan migrate:fresh --seed` | **OK** | `26` migrations `DONE` + `DaloaSeeder`, `DemoOrdersAndParcelsSeeder`, `RoleHabilitationSeeder` `DONE` (exit `0`). | `backend/database/migrations/*`, `backend/database/seeders/*` | Non |
| 2 | `backend/` | `php artisan test` | **OK** | `Tests:    102 passed (526 assertions)` — `Duration: 144.74s` (exit `0`). | `backend/tests/Feature/**/*` | Inclut tests A & B (`ParcelCodeSecurityTest`, `CommissionServiceTest` : tous passés) |
| 3 | `backend/` | `php vendor/bin/phpstan analyse` (`phpstan.neon`, niveau `5`) | **ÉCHEC** (exit `1`) | `[ERROR] Found 99 errors` (sur `149` fichiers analysés sous `app/`). | **18 fichiers préexistants** (liste détaillée ci-dessous ; `0` erreur dans `ParcelController.php`, `ParcelService.php`, `CommissionService.php`). | **Non** |
| 4 | `backend/` | `php vendor/bin/pint --test` | **ÉCHEC** (exit `1`) | `{"tool":"pint","result":"fail","files":[...]}` : **187 fichiers** en échec de formatage. | Ensemble du dépôt `backend/` (`ParcelController.php`, `ParcelCodeSecurityTest.php` et `CommissionServiceTest.php` passent Pint ; `ParcelService.php` échoue sur des règles de style déjà présentes avant Bloc A + FQCN `\App\Models\Courier`). | **Non** (préexistant hors tests créés) |
| 5a | `mobile/packages/core` | `flutter analyze` | **OK** | `No issues found! (ran in 70.6s)` (exit `0`). | `mobile/packages/core/lib/**/*` | Non |
| 5b | `mobile/packages/core` | `flutter test` | **ÉCHEC** (exit `1`) | `Test directory "test" not found.` | `mobile/packages/core/` (dossier `test/` absent) | **Non** |
| 5c | `mobile/apps/golivreur_client` | `flutter analyze` | **ÉCHEC** (exit `1`) | `5 issues found. (ran in 22.4s)` (`info - prefer_const_constructors`). | `mobile/apps/golivreur_client/lib/mocks/mock_gocolis_api.dart:168,178,183,208,218` | **Non** |
| 5d | `mobile/apps/golivreur_client` | `flutter test` | **ÉCHEC** (exit `1`) | `00:04 +0 -3: Some tests failed.` — `A Timer is still pending even after the widget tree was disposed.` (`Failed assertion: line 2543 pos 12: '!timersPending'`). | `mobile/apps/golivreur_client/test/widget_test.dart` (3 tests : splash, login, `MainNavigationScreen` via timers HTTP `DioMixin` dans `AppBrandingNotifier.chargerBranding`, `WeatherNotifier.chargerStatutVille`, `AuthNotifier.chargerProfil`) | **Non** |
| 5e | `mobile/apps/golivreur_business` | `flutter analyze` | **ÉCHEC** (exit `1`) | `2 issues found. (ran in 6.1s)` (`info - prefer_const_constructors`). | `mobile/apps/golivreur_business/lib/features/auth/screens/business_login_screen.dart:141,142` | **Non** |
| 5f | `mobile/apps/golivreur_business` | `flutter test` | **ÉCHEC** (exit `1`) | `00:03 +2 -1: Some tests failed.` (`BusinessMainNavigationScreen - affiche les 4 onglets avec Profil` : `A Timer is still pending even after the widget tree was disposed.`). | `mobile/apps/golivreur_business/test/widget_test.dart` (`BusinessProfileNotifier.chargerProfil` → `DioMixin`) | **Non** |
| 5g | `mobile/apps/golivreur_driver` | `flutter analyze` | **ÉCHEC** (exit `1`) | `2 issues found. (ran in 25.8s)` (`info - prefer_const_constructors`). | `mobile/apps/golivreur_driver/lib/features/auth/screens/driver_login_screen.dart:141,142` | **Non** |
| 5h | `mobile/apps/golivreur_driver` | `flutter test` | **ÉCHEC** (exit `1`) | `00:03 +2 -1: Some tests failed.` (`DriverMainNavigationScreen - affiche les 4 onglets avec Profil` : `A Timer is still pending even after the widget tree was disposed.`). | `mobile/apps/golivreur_driver/test/widget_test.dart` (`DriverProfileNotifier.chargerProfil` → `DioMixin`) | **Non** |
| 6 | `backend/` | `php artisan route:list --path=api` | **OK** | `Showing [94] routes` (`88` contrôleurs/méthodes vérifiés existants par réflexion PHP, `6` routes `Closure` valides, `0` contrôleur ou méthode manquant). | `backend/routes/api.php`, routes Horizon & Scramble | Non |

---

## Détail des échecs listés (sans modification)

### 1. PHPStan niveau 5 (`99 errors` dans `18` fichiers)
- `Http\Resources\BusinessProfileResource.php` (`22`) — accès propriétés non typées `$this->merchant`, `$this->id`, etc. et nullsafe `?->` superflu.
- `Http\Resources\DriverProfileResource.php` (`18`) — accès propriétés non typées et nullsafe `?->` superflu.
- `Http\Resources\MerchantResource.php` (`17`) — accès propriétés non typées sur `JsonResource`.
- `Http\Resources\ClientProfileResource.php` (`11`) — accès propriétés non typées sur `JsonResource`.
- `Http\Resources\MerchantCategoryResource.php` (`8`) — accès propriétés non typées sur `JsonResource`.
- `Http\Controllers\Api\V1\Client\ClientController.php` (`6`) — type `Closure(ClientAddress)` sur `Collection<Model>` (L238, L292) et `?->` inutiles (L38, L188, L214, L443).
- `Filament\Pages\SettingsPage.php` (`2`) — propriété `$form` non résolue (L65, L389).
- `Filament\Resources\RefundResource\Pages\ListRefunds.php` (`2`) — propriétés inexistantes `$order->order_number` (L108) et `$parcel->tracking_number` (L113).
- `Filament\Resources\CourierResource\Pages\CreateCourier.php` (`2`) — `?->center_lat/lng` inutiles (L18, L19).
- `Filament\Resources\MerchantResource\Pages\CreateMerchant.php` (`2`) — `?->center_lat/lng` inutiles (L19, L20).
- `Filament\Resources\OrderResource.php` (`2`) — `?->name` inutile (L74, L360).
- `Filament\Pages\DisputesPage.php` (`1`) — `?->phone` inutile (L69).
- `Filament\Resources\ParcelResource.php` (`1`) — `?->name` inutile (L64).
- `Filament\Resources\RoleHabilitationResource.php` (`1`) — `CheckboxList::minItems()` appelé avec 2 arguments (L72).
- `Http\Controllers\Api\V1\Merchant\MerchantController.php` (`1`) — `?->name` inutile (L35).
- `Models\ClientAddress.php` (`1`) — `?->name` inutile (L42).
- `Models\Courier.php` (`1`) — `'photo_url'` déclaré dans `$appends` sans accesseur calculé (L52).
- `Models\Merchant.php` (`1`) — `'photo_url'` déclaré dans `$appends` sans accesseur calculé (L46).

### 2. Laravel Pint (`187` fichiers en échec)
- Principalement des écarts de style préexistants (`line_ending`, `single_blank_line_at_eof`, `binary_operator_spaces`, `single_import_per_statement`, `concat_space`, `no_unused_imports`, `fully_qualified_strict_types`).
- Concernant les fichiers modifiés ou ajoutés dans les Blocs A et B :
  - `ParcelController.php`, `ParcelCodeSecurityTest.php` et `CommissionServiceTest.php` passent `pint --test`.
  - `ParcelService.php` (comme `CommissionService.php`, non modifié) échoue sur des règles déjà présentes avant le Bloc A (`single_import_per_statement`, `type_declaration_spaces`, `unary_operator_spaces`, `braces_position`, `not_operator_with_successor_space`, `single_line_empty_body`, `binary_operator_spaces`, `fully_qualified_strict_types`).

### 3. Tests et analyse Flutter
- `packages/core` : aucun dossier `test/` (`flutter test` retourne code `1`).
- `golivreur_client` : 5 avertissements `prefer_const_constructors` dans `lib/mocks/mock_gocolis_api.dart`, et `3/3` tests de `test/widget_test.dart` échouent (`!timersPending` à cause d'appels `Dio` déclenchés au montage sans mock/override dans les tests).
- `golivreur_business` : 2 avertissements `prefer_const_constructors` dans `lib/features/auth/screens/business_login_screen.dart:141-142`, et `1/3` test échoue dans `test/widget_test.dart` (`BusinessMainNavigationScreen`, `!timersPending`).
- `golivreur_driver` : 2 avertissements `prefer_const_constructors` dans `lib/features/auth/screens/driver_login_screen.dart:141-142`, et `1/3` test échoue dans `test/widget_test.dart` (`DriverMainNavigationScreen`, `!timersPending`).

### 4. Vérification des routes API (`php artisan route:list --path=api`)
- `94` routes listées (`api/v1/*`, `docs/api*`, `horizon/api/*`).
- `88` actions contrôleurs vérifiées (`class_exists` et `method_exists` tous vrais) + `6` closures légitimes (`api/v1/health`, `api/v1/branding`, `api/v1/vehicle-types`, `api/v1/cities`, `docs/api`, `docs/api.json`). Aucune route cassée.

---

**Prêt pour l'audit final : NON**

**Raison :**
1. **Échecs techniques préexistants à corriger :** `phpstan analyse` (niveau 5) rapporte `99` erreurs (dont deux bogues réels dans `ListRefunds.php:108,113` sur `$order->order_number` et `$parcel->tracking_number`), `pint --test` échoue sur `187` fichiers, les 3 applications Flutter échouent à `flutter analyze` (`prefer_const_constructors`) et à `flutter test` (`!timersPending` dans les widget tests), et `packages/core` ne contient aucun dossier `test/`.
2. **Décisions financières bloquantes non tranchées (`audit/decisions-argent.md`) :** les flux espèces (double crédit / absence de réconciliation) et remboursements (wallet livreur crédité au lieu du client, absence d'idempotence et plafonds contradictoires) nécessitent arbitrage et implémentation avant mise en production ou validation d'audit final.

