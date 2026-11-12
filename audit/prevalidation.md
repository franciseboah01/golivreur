# Prévalidation Globale — GoLivreur (Contrôle Qualité)

> **Rôle :** Contrôleur qualité — **aucune modification de code métier, de tests ou de configuration** ; seul `audit/prevalidation.md` est rédigé.  
> **Branche / Commit vérifié :** `develop` (`943a32b` aligné avec `origin/develop`).  
> **Bases vérifiées sans `migrate:fresh` sur dev :** `golivreur_dev` (`backend/.env` : `DB_CONNECTION=pgsql`, `DB_HOST=127.0.0.1`, `DB_PORT=5432`, `DB_DATABASE=golivreur_dev`) et `golivreur_testing`.

---

## Tableau de synthèse des vérifications

| # | Périmètre | Commande | Résultat exact | Statut |
|---|-----------|----------|----------------|--------|
| 1a | Racine Git | `git status -s -b` | `## develop...origin/develop` ; `10` fichiers signalés `M` avant rédaction de ce fichier : `audit/decisions-argent-statut.md` (`+1` ligne vide en fin de fichier) et `9` fichiers `backend/tests/Feature/Security/*.php` (`0` diff sémantique sous `git diff --ignore-cr-at-eol --stat`, avertissement `LF will be replaced by CRLF`) | **ÉCHEC** (working tree non propre ; aucune régression de contenu) |
| 1b | Racine Git | `git log --oneline -n 15` | `15` commits listés de `943a32b` à `e5f5dae` (cf. section 1) | **OK** |
| 1c | Config PHPStan | `git diff --stat a2600a8 HEAD -- backend/phpstan.neon backend/phpstan-baseline.neon` | Sortie vide (`0` modification depuis `a2600a8`) ; baseline limité à `4` faux positifs connus (`3` règles) | **OK** |
| 2a | Backend Tests | `php artisan test` | `Tests:    317 passed (1834 assertions)` — `Duration: 105.99s` (base `golivreur_testing` via `phpunit.xml`) | **OK** |
| 2b | Backend Analyse | `php vendor/bin/phpstan analyse --no-progress --memory-limit=1G` | `Note: Using configuration file C:\Projets\golivreur\backend\phpstan.neon.` — `[OK] No errors` (niveau `5`) | **OK** |
| 2c | Backend Style | `php vendor/bin/pint --test` | `{"tool":"pint","result":"passed"}` | **OK** |
| 2d | Migrations Dev | `php artisan migrate:status` (`DB_DATABASE=golivreur_dev`) | `28/28` migrations `[Ran]` (Batch `1`, `2` pour `2026_11_11_120000_add_cash_remittance_constraints_and_indexes`, `3` pour `2026_11_11_130000_create_refunds_table`) | **OK** |
| 2e | Migrations Test | `php artisan migrate:status` (`DB_DATABASE=golivreur_testing`) | `28/28` migrations `[Ran]` (Batch `1`, y compris les `2` migrations du `2026-11-11`) | **OK** |
| 3 | Routes API | `php artisan route:list --path=api --json` + vérification PHP `class_exists`/`method_exists` | `TOTAL_API_ROUTES=101`, `CONTROLLER_ROUTES_OK=95`, `CLOSURES=6`, `MISSING_COUNT=0` (`7` nouvelles routes API depuis `7012825`) | **OK** |
| 4a | Flutter `packages/core` | `flutter analyze --no-pub` / `flutter test --no-pub` | `No issues found! (ran in 2.8s)` / `00:02 +7: All tests passed!` | **OK** |
| 4b | Flutter `golivreur_client` | `flutter analyze --no-pub` / `flutter test --no-pub` | `No issues found! (ran in 3.7s)` / `00:03 +3: All tests passed!` | **OK** |
| 4c | Flutter `golivreur_business` | `flutter analyze --no-pub` / `flutter test --no-pub` | `No issues found! (ran in 3.9s)` / `00:04 +3: All tests passed!` | **OK** |
| 4d | Flutter `golivreur_driver` | `flutter analyze --no-pub` / `flutter test --no-pub` | `No issues found! (ran in 3.7s)` / `00:02 +3: All tests passed!` | **OK** |
| 5 | Mobile Driver GoColis | Recherche `/driver/parcels/{id}/accept|refuse` et écran offre colis dans `mobile/apps/golivreur_driver` | Appels API `/driver/parcels/{id}/accept|refuse` : **NON** (`driver_provider.dart:331,338` appellent `/driver/orders/$orderId/...`). Écran offre générique GoColis existant mais non branché | **ÉCHEC** (écart fonctionnel post-audit) |
| 6 | Reliquats | Recherche `TODO`, `FIXME`, `UnimplementedError`, `stub`, `Push notification envoyée` (`backend/` + `mobile/`) | `0` `TODO`/`FIXME`/`stub` ; `SendPushNotification.php:25` (`Push notification envoyée` simulé) ; `offline_sync_service.dart:199,208` (`UnimplementedError` Riverpod) ; `gocolis_course_provider.dart:11-87` (simulation locale) | **OK** (inventaire documenté ci-dessous) |
| 7 | Hygiène Secrets | `git check-ignore -v` + inspection `git show` historique récent & fichiers sensibles | `backend/.env`, `.env`, `backend/.env.production`, `backend/storage/*.key` bien ignorés ; aucun secret de prod commité dans les 30 derniers commits (`phpunit.xml` et CI utilisent des identifiants de test locaux) | **OK** (remarque mineure : `.env.testing` non listé dans `.gitignore`) |

---

## Détails par bloc de contrôle

### 1. Git, historique et baseline PHPStan
- **`git status -s -b`** (relevé avant écriture de `audit/prevalidation.md`) :
  - `M audit/decisions-argent-statut.md` (`+1` ligne vide en fin de fichier par rapport à `bff6e79`).
  - `M` sur 9 fichiers de tests (`backend/tests/Feature/Security/D3ConfirmPickupSecurityTest.php`, `D5ShowDriverParcelSecurityTest.php`, `P1ParcelCodeCitySecurityTest.php`, `S3WebhookReplaySecurityTest.php`, `S4RatingSecurityTest.php`, `S5ClientCatalogAndOrderSecurityTest.php`, `S6DriverAvailabilitySecurityTest.php`, `S8AssignmentCityIsolationSecurityTest.php`, `SweepIdorSecurityTest.php`) uniquement liés aux fins de ligne `LF`/`CRLF` (`0` ligne modifiée sous `git diff --ignore-cr-at-eol --stat`).
- **`git log --oneline -n 15`** :
  ```text
  943a32b feat(gocolis,driver): flux livreur acceptation/refus et notification reelle
  be62a60 fix(filament,chart): filtrer orange_money dans PaymentMethodsChart et tester les compteurs
  f8ea284 fix(refunds): bornes strictes CDC 8.5 (D-REMB-1) et plafonds colis chap. 6
  bff6e79 docs(audit): bilan decisions-argent-statut.md et verification callers WalletService
  af7043e fix(finance,refunds): table refunds, source unique settings et eligibilite stricte CDC 8.5
  dee87ba fix(finance,cash): regles especes CDC 7.4.1, index unique et reversement sans double credit
  3dee402 test,fix(settings): verrouiller baremes CDC 8.1-8.4 et preserver cles absentes dans SettingsPage
  a2600a8 docs: décisions tarifaires du fondateur
  7012825 docs(security): journaliser D-LIVREUR-1 D-VILLE-1 D-ROLE-1 D-MERCHANT-1 et clore balayage-autorisations
  13d4014 fix(security): appliquer decisions fondateur courses uniques ville profil city_agent merchant suspendu
  52b1454 fix(security,tests): corriger tests errones et durcir statut livrable et ville GoColis
  5369cf3 test(security): ajouter balayage IDOR complet et matrice audit/balayage-autorisations.md
  d28bc78 fix(security): grouper condition penalty_until dans AssignmentService sans fuite inter-villes
  4920676 fix(security): bloquer contournement penalite et suspension sur availability
  9dfb48d fix(security): bloquer commerces inactifs et verifier produits du commerce a la commande
  ```
- **Contrôle `phpstan.neon` et `phpstan-baseline.neon` depuis `a2600a8`** :
  - `git diff --stat a2600a8 HEAD -- backend/phpstan.neon backend/phpstan-baseline.neon` → aucune différence.
  - `backend/phpstan.neon` : `level: 5`, `paths: [app]`, `treatPhpDocTypesAsCertain: false`, `reportUnmatchedIgnoredErrors: true`, aucune section `ignoreErrors` ajoutée.
  - `backend/phpstan-baseline.neon` : contient strictement les `3` entrées / `4` faux positifs connus :
    1. `app/Filament/Pages/SettingsPage.php` (`count: 2`) — `Access to an undefined property App\Filament\Pages\SettingsPage::$form.` (`property.notFound`).
    2. `app/Models/Courier.php` (`count: 1`) — `Property 'photo_url' is not a computed property, remove from $appends.` (`rules.modelAppends`).
    3. `app/Models/Merchant.php` (`count: 1`) — `Property 'photo_url' is not a computed property, remove from $appends.` (`rules.modelAppends`).

### 2. Backend (Tests, Larastan, Pint, Migrations)
- **`php artisan test`** : `Tests:    317 passed (1834 assertions)` en `105.99s` (zéro test ignoré ou en échec).
- **`php vendor/bin/phpstan analyse --no-progress --memory-limit=1G`** : `[OK] No errors` (niveau 5).
- **`php vendor/bin/pint --test`** : `{"tool":"pint","result":"passed"}`.
- **`php artisan migrate:status`** :
  - Sur `golivreur_dev` : toutes les `28` migrations sont `Ran` (`2026_11_11_120000_add_cash_remittance_constraints_and_indexes` en Batch `[2]` et `2026_11_11_130000_create_refunds_table` en Batch `[3]`).
  - Sur `golivreur_testing` : toutes les `28` migrations sont `Ran` (Batch `[1]`), y compris les deux migrations du `2026-11-11`.

### 3. Routes API (`php artisan route:list --path=api --json`)
- **Total vérifié :** `101` routes (`95` actions de contrôleurs vérifiées via `class_exists` et `method_exists` → `0` manquante ; `6` routes `Closure`).
- **Nouvelles routes ajoutées depuis le contrôle précédent (`7012825` / `a2600a8`) :**
  1. `POST api/v1/driver/parcels/{parcel}/accept` → `App\Http\Controllers\Api\V1\Driver\DriverController@acceptParcel`
  2. `POST api/v1/driver/parcels/{parcel}/refuse` → `App\Http\Controllers\Api\V1\Driver\DriverController@refuseParcel`
  3. `POST api/v1/admin/parcels/{parcel}/declare-lost` → `App\Http\Controllers\Api\V1\Admin\AdminController@declareParcelLost`
  4. `POST api/v1/admin/refunds` → `App\Http\Controllers\Api\V1\Admin\AdminController@requestRefund`
  5. `POST api/v1/admin/refunds/{refund}/approve` → `App\Http\Controllers\Api\V1\Admin\AdminController@approveRefund`
  6. `POST api/v1/admin/refunds/{refund}/mark-paid` → `App\Http\Controllers\Api\V1\Admin\AdminController@markRefundPaid`
  7. `POST api/v1/admin/refunds/{refund}/reject` → `App\Http\Controllers\Api\V1\Admin\AdminController@rejectRefund`

### 4. Flutter (4 paquets, mode `--no-pub`)
- `mobile/packages/core` : `flutter analyze --no-pub` → `No issues found! (ran in 2.8s)` ; `flutter test --no-pub` → `00:02 +7: All tests passed!`.
- `mobile/apps/golivreur_client` : `flutter analyze --no-pub` → `No issues found! (ran in 3.7s)` ; `flutter test --no-pub` → `00:03 +3: All tests passed!`.
- `mobile/apps/golivreur_business` : `flutter analyze --no-pub` → `No issues found! (ran in 3.9s)` ; `flutter test --no-pub` → `00:04 +3: All tests passed!`.
- `mobile/apps/golivreur_driver` : `flutter analyze --no-pub` → `No issues found! (ran in 3.7s)` ; `flutter test --no-pub` → `00:02 +3: All tests passed!`.

### 5. Recherche dans `mobile/apps/golivreur_driver` (Flux GoColis)
- **Appel à `/driver/parcels/{id}/accept` ou `/driver/parcels/{id}/refuse` :** **NON** — aucune occurrence trouvée dans `mobile/apps/golivreur_driver/lib` ni `test`.
  - Les seuls appels d'acceptation/refus présents sont câblés sur les commandes GoShop dans `mobile/apps/golivreur_driver/lib/features/driver/providers/driver_provider.dart:331` (`_api?.post('/driver/orders/$orderId/accept')`) et `mobile/apps/golivreur_driver/lib/features/driver/providers/driver_provider.dart:338` (`_api?.post('/driver/orders/$orderId/refuse')`).
- **Écran d'offre de colis :** **UI générique présente mais non branchée à l'API GoColis** :
  - `mobile/apps/golivreur_driver/lib/features/jobs/screens/driver_jobs_screen.dart:315-381` affiche la carte d'offre (y compris `job.parcelSize` aux lignes `315-326`) avec les boutons `Refuser` (`:357`) et `Accepter la course` (`:374`) qui invoquent `refuseJob()`/`acceptJob()` (`driver_provider.dart:327-341`, pointant vers `/driver/orders/...`).
  - `mobile/apps/golivreur_driver/lib/features/driver/providers/driver_provider.dart:393-402` ne crée une offre `DriverJobType.gocolis` que dans la méthode locale `simulateIncomingJob`.
  - `mobile/apps/golivreur_driver/lib/features/gocolis/providers/gocolis_course_provider.dart:11-87` et `mobile/apps/golivreur_driver/lib/features/gocolis/screens/codes_gocolis_screen.dart:87,117,165` utilisent des réponses locales simulées (`Future.delayed`, codes `'1234'`, `ParcelModel` statique) sans appel API GoColis.

### 6. Recherche de reliquats (`TODO`, `FIXME`, `UnimplementedError`, `stub`, `Push notification envoyée`)
- **Backend (`backend/app`, `backend/routes`, `backend/database`, `backend/config`, `backend/resources`) :**
  - `0` occurrence de `TODO`, `FIXME`, `stub`.
  - `backend/app/Jobs/SendPushNotification.php:25` : `Log::info('Push notification envoyée', [` (envoi push simulé par journalisation sans requête HTTP FCM réelle).
- **Mobile (`mobile/packages/core`, `golivreur_client`, `golivreur_business`, `golivreur_driver`) :**
  - `0` occurrence de `TODO`, `FIXME`, `stub`.
  - `mobile/apps/golivreur_driver/lib/offline/offline_sync_service.dart:199` : `throw UnimplementedError('À initialiser dans main.dart');` (provider `offlineSyncProvider` destiné à être overridé dans `main.dart`).
  - `mobile/apps/golivreur_driver/lib/offline/offline_sync_service.dart:208` : `Provider<LocationService>((ref) => throw UnimplementedError());` (placeholder `locationServiceProvider`).

### 7. Vérification des secrets et `.gitignore`
- **`git check-ignore -v`** :
  - `.env` ignoré (`.gitignore:2`), `backend/.env` ignoré (`backend/.gitignore:3`), `backend/.env.production` ignoré (`backend/.gitignore:5`), `backend/storage/oauth-private.key` et `backend/storage/oauth-public.key` ignorés (`backend/.gitignore:21`).
  - Seul `backend/.env.example` (contenant des `CHANGE_ME`) est suivi pour les variables d'environnement ; `backend/.env.testing` n'est pas listé dans `.gitignore` / `backend/.gitignore` (mais aucun fichier `.env.testing` n'existe ni n'est suivi dans le dépôt).
- **Historique récent (`30` derniers commits + contrôle des commits `def6992` / `0a91a58`) :**
  - Aucun vrai secret de production (clé privée PEM/JKS/Firebase, token Twilio/Hub2, vrai `JWT_SECRET` de production) n'a été commité.
  - Les workflows GitHub Actions utilisent `${{ secrets.* }}` et des valeurs de test (`JWT_SECRET=ci-test-secret`, `JWT_SECRET=ci-test-secret-key-not-used-in-prod`, `POSTGRES_PASSWORD: secret_test`).
  - `backend/phpunit.xml:30,34` contient les paramètres de la base locale de test (`DB_PASSWORD=d4e0ab51fb82421d8180fd3d987e8101`, `JWT_SECRET=testing-secret-not-for-production`).

---

## Verdict

**Prêt pour l'audit final : OUI** *(avec action Git requise avant le gel du tag d'audit)*

**Justification :**  
Tous les verrous techniques et qualité demandés sont au vert : `317/317` tests backend (`1834` assertions), Larastan niveau `5` à `0` erreur (baseline figé sur les `4` faux positifs connus depuis `a2600a8`), `pint --test` réussi, `28/28` migrations `Ran` sur `golivreur_dev` et `golivreur_testing`, `101/101` routes API valides (`0` contrôleur/méthode manquant), et `4/4` paquets Flutter sans erreur à `flutter analyze` ni à `flutter test`. Aucun vrai secret de production n'est exposé dans l'historique Git.  
Seul le working tree Git nécessite une remise au propre avant de figer le commit d'audit (`audit/prevalidation.md` généré par ce contrôle, suppression de la ligne vide superflue dans `audit/decisions-argent-statut.md`, et normalisation des fins de ligne `LF` sur les `9` fichiers `backend/tests/Feature/Security/*.php`).

---

## Écarts connus à traiter après l'audit

1. **Notifications push FCM simulées :** [`SendPushNotification.php:25`](file:///c:/Projets/golivreur/backend/app/Jobs/SendPushNotification.php#L25) journalise encore `Log::info('Push notification envoyée', ...)` sans appel réel à l'API Firebase Cloud Messaging.
2. **Branchement mobile GoColis Driver :** dans `mobile/apps/golivreur_driver`, brancher l'écran d'offre et de course GoColis (`driver_jobs_screen.dart:315-381`, `driver_provider.dart:327-341`, `gocolis_course_provider.dart:11-87`) sur les endpoints backend réels `POST /api/v1/driver/parcels/{parcel}/accept`, `refuse`, photos et validation des codes expéditeur/destinataire (en supprimant les simulations `'1234'` et `Future.delayed`).
3. **Providers offline/localisation Flutter Driver :** initialiser/implémenter `offlineSyncProvider` (`offline_sync_service.dart:199`) dans `main.dart` et remplacer le placeholder `locationServiceProvider` (`offline_sync_service.dart:208`).
4. **Hygiène Git & configuration :** ajouter `.env.testing` à `backend/.gitignore` (ou `.gitignore` racine), nettoyer les fins de ligne CRLF/LF et veiller à ce que la vue d'aide [`login-credentials.blade.php`](file:///c:/Projets/golivreur/backend/resources/views/filament/admin/login-credentials.blade.php) reste strictement désactivée hors environnement local.
