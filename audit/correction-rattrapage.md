# Rapport de Corrections — Lot Rattrapage

**Date :** 15 novembre 2026  
**Branche :** `develop` (base avant Lot Rattrapage : `13a8516`)  
**Périmètre :** Points `0`, `A`, `B`, `C`, `D` (`D1` inventaire puis `D2` correctifs), `E`, `F` et `G`.  
*(Ce rapport est créé au Point D1 et enrichi au fil des points D2 à G jusqu'à la vérification finale complète.)*

---

## POINT 0 — `.gitignore`, secrets suivis et fins de ligne `MainActivity.kt`

- **Commit :** `50caf91` (`chore(git): ignore .env* except .env.example and google-services.json, commit MainActivity EOF`)
- **Fichiers modifiés :**
  - `.gitignore` (ajout de `.env*` suivi de `!.env.example` et de `**/google-services.json`)
  - `mobile/apps/golivreur_client/android/app/src/main/kotlin/ci/golivreur/client/MainActivity.kt`
  - `mobile/apps/golivreur_business/android/app/src/main/kotlin/ci/golivreur/business/MainActivity.kt`
  - `mobile/apps/golivreur_driver/android/app/src/main/kotlin/ci/golivreur/driver/MainActivity.kt`
- **Vérification exécutée (`git check-ignore` & `git ls-files`) :**
  ```text
  .gitignore:3:.env*	.env
  .gitignore:3:.env*	.env.local
  .gitignore:4:!.env.example	.env.example
  backend/.gitignore:3:.env	backend/.env
  .gitignore:13:**/google-services.json	mobile/apps/golivreur_client/android/app/google-services.json
  --- LS-FILES .ENV ---
  backend/.env.example
  ```
- **Ce qui reste NON fait :** Néant sur le Point 0 (seul `backend/.env.example` apparaît sous suivi Git ; aucun `.env` ni `google-services.json` n'est suivi).

---

## POINT A — Suppression des contournements d'authentification dev

- **Commits :**
  - `6fb4458` (`fix(auth): supprime les contournements OTP et mots de passe dev (Point A)`)
  - `77af9cc` (`style(factory): corrige fin de fichier OtpCodeFactory pour pint`)
- **Fichiers modifiés / créés :**
  - `backend/app/Http/Controllers/Api/V1/Auth/AuthController.php` (suppression de `$responseData['debug_otp']` sur inscription et connexion, suppression de `$isMasterDevOtp` (`'1234'`) sur `verifierOtpInscription` et `verifierOtpConnexion`, suppression de `$validDevPasswords` (`['123456', '1234', 'password', '1234-JK-01']`) dans `loginPassword`)
  - `backend/database/factories/OtpCodeFactory.php` (créée avec helper `withCode(string $code)`)
  - `backend/tests/Feature/Auth/AuthTest.php` (adapté pour créer/préparer un enregistrement `OtpCode::factory()->withCode(...)` au lieu de lire `debug_otp ?? '1234'`)
  - `backend/tests/Feature/Security/RefreshTokenAndDevBypassSecurityTest.php` (ajout de tests prouvant l'absence de tout contournement en `testing` et `local`)
- **Justification écrite de l'adaptation de `AuthTest.php` :**
  - Les deux tests d'historique OTP (`AuthTest.php:207-252`) lisaient `$reg->json('debug_otp') ?? '1234'` et `$loginRes->json('debug_otp') ?? '1234'` pour récupérer le code généré. Une fois `debug_otp` et le code maître `1234` supprimés, le contrôleur continue d'envoyer un OTP aléatoire hashé sans jamais l'exposer dans la réponse HTTP ; les tests remplacent la lecture du JSON par l'injection explicite en base via `OtpCode::factory()->withCode(...)`, tout en conservant toutes les assertions sur le statut HTTP, la création utilisateur, la révocation de session et la suppression de l'OTP après validation.
- **Preuve Rouge (avant correctif dans `AuthController.php`) :**
  ```text
     FAIL  Tests\Feature\Security\RefreshTokenAndDevBypassSecurityTest
    ✓ it renouvelle access_token et refresh_token avec un refresh token opaque de 7 jours                        129.01s
    ✓ it configure le refresh_ttl par defaut a 10080 minutes (7 jours) selon le CDC 11.4                           0.19s
    ✓ it prouve qu en environnement de production AUCUN bypass dev ne fonctionne                                   2.68s
    ⨯ it prouve qu en environnement local et testing AUCUN bypass dev ne fonctionne with ('testing')               2.39s
    ⨯ it prouve qu en environnement local et testing AUCUN bypass dev ne fonctionne with ('local')                 0.36s
  ...
    Expected response status code [422] but received 200.
    Failed asserting that 200 is identical to 422.
    at tests\Feature\Security\RefreshTokenAndDevBypassSecurityTest.php:129

    Tests:    2 failed, 3 passed (30 assertions)
    Duration: 157.70s
  ```
- **Preuve Verte (après correctif) :**
  ```text
  $ grep -rn "1234\|debug_otp" backend/app
  backend/app/Filament/Resources/CourierResource.php:150:                            ->placeholder('Ex: 1234 AB 01')
  ```
  *(Uniquement un placeholder d'immatriculation dans `CourierResource.php:150`, zéro résultat applicatif dans l'authentification.)*
  ```text
    Tests:    27 passed (160 assertions)
    Duration: 19.09s
  {"tool":"pint","result":"passed"}
     0/162 [>---------------------------]   0% 162/162 [============================] 100%
   [OK] No errors
  ```
  *(Exécuté sur `AuthTest`, `OtpSecurityP3Test`, `RefreshTokenAndDevBypassSecurityTest`, `SingleSessionSecurityTest`, `HiddenFieldsSecurityTest`, suivi de `pint --test` et `phpstan analyse --level=5`.)*
- **Ce qui reste NON fait :** Néant.

---

## POINT B — Paiement (notification différée, timeouts, échec GoColis, doublon 200, horodatage manquant)

- **Commit :** `031734d` (`fix(payment): differe notification commerce au paiement, timeouts et doublon webhook 200 (Point B)`)
- **Fichiers modifiés / créés :**
  - `backend/database/migrations/2026_11_15_100000_add_paid_at_to_orders_table.php` (ajout colonne nullable `paid_at` sur `orders`)
  - `backend/app/Models/Order.php` (`paid_at` dans `$fillable` et `$casts`)
  - `backend/app/Services/SettingsService.php` (`delais_regles.paiement_attente_minutes => 10` par défaut ; `paiements.modes_actifs` conservé à `['cash']`)
  - `backend/app/Filament/Pages/SettingsPage.php` (champ `delais_regles.paiement_attente_minutes`)
  - `backend/app/Jobs/AnnulerCommandePaiementTimeout.php` (nouveau job annulant sous `lockForUpdate()` une commande GoShop en ligne restée `pending`/`payment_status='pending'` au-delà de `paiement_attente_minutes`, avec trace `activity_logs` et notification `notifierPaiementEchoue`)
  - `backend/app/Jobs/AnnulerCommandeTimeout.php` (garde sur `payment_method !== 'cash' && payment_status !== 'paid'`, référence temporelle `$order->paid_at ?? $order->created_at`, et transaction `lockForUpdate()`)
  - `backend/app/Modules/GoShop/Services/OrderService.php` (sur commande non cash : pas de notification commerce ni `AnnulerCommandeTimeout` à la création ; dispatch `AnnulerCommandePaiementTimeout` différé de `paiement_attente_minutes`)
  - `backend/app/Jobs/ProcessPaymentWebhook.php` (au passage à `paid` : renseigne `paid_at`, notifie le commerce si commande encore `pending` et planifie `AnnulerCommandeTimeout` de `acceptation_commerce_secondes` ; sur échec GoColis : appelle `$notificationService->notifierPaiementEchoue($parcel->sender, null, $parcel)`)
  - `backend/app/Http/Controllers/Api/V1/WebhookController.php` (`rejeterRejeuTransaction` retourne HTTP `200` `{"succes":true,"message":"déjà traité"}` sans redispatcher `ProcessPaymentWebhook`)
  - `backend/tests/Feature/Payment/PaymentRattrapageTest.php` (nouvelle suite B1–B4)
  - `backend/tests/Feature/Api/V1/Webhook/PaymentWebhookTest.php` & `backend/tests/Feature/Payment/WebhookSecurityP5Test.php` (retour à l'attente HTTP `200 {"succes":true,"message":"déjà traité"}` sur doublon `transaction_id`)
- **Vérification Git pour B4 et mise à jour du rapport P5 :**
  - `git log -p backend/tests/Feature/Api/V1/Webhook/PaymentWebhookTest.php` confirme qu'à l'origine (`4a81185`), le test `le traitement des webhooks est idempotent face aux doublons` attendait bien `->assertStatus(200)` ; c'est le commit P5 `444a0ed` qui l'avait basculé à `409` (`Expected 409`).
  - L'ancien statut `409` et sa justification dans `audit/correction-6.md` (rapport P5, non suivi) ont été remplacés pour documenter l'idempotence en HTTP `200 {"succes":true,"message":"déjà traité"}` rétablie au commit `031734d`.
- **Justification écrite des modifications de tests existants :**
  - `PaymentWebhookTest.php:171-185` : rétablissement de l'assertion HTTP `200` d'origine (+ vérification du JSON `{"succes":true,"message":"déjà traité"}`) en remplacement du `409` introduit en `444a0ed`.
  - `WebhookSecurityP5Test.php` (`P5 Point 5`) : alignement de l'assertion du second appel avec même `transaction_id` sur `200 {"succes":true,"message":"déjà traité"}` tout en conservant la vérification qu'un seul `WebhookLog` passe à `processed` et que l'index unique PostgreSQL reste actif.
- **Documentation B5 — Comportement exact lorsqu'un fournisseur PSP n'envoie aucun horodatage :**
  - Dans `backend/app/Http/Controllers/Api/V1/WebhookController.php:94-123` et `:252-265`, `extractWebhookTimestamp($request, $payloadData)` recherche un horodatage dans les en-têtes `X-Hub2-Timestamp`, `X-Webhook-Timestamp`, `X-Timestamp`, `X-CinetPay-Timestamp` puis dans les clés JSON `timestamp`, `created_at`, `createdAt`, `sent_at`, `cpm_trans_date`, `data.timestamp`, `data.created_at`.
  - La condition ligne 95 (`if ($rawTimestamp !== null && $rawTimestamp !== '')`) n'est exécutée **que si une valeur non vide est présente**.
  - **Conséquence exacte lorsque le fournisseur n'envoie aucun horodatage (`$rawTimestamp === null` ou `''`) :** le contrôle de la fenêtre anti-rejeu temporelle de 5 minutes (`300 s`) est **ignoré sans rejet ni alerte** ; la requête poursuit vers le contrôle d'unicité `transaction_id` (étape 4, ligne 126) puis le dispatch asynchrone de `ProcessPaymentWebhook`, la protection reposant alors uniquement sur la signature HMAC (obligatoire, HTTP `401`) et sur l'unicité de `transaction_id`. *À l'inverse, si un champ d'horodatage non vide est présent mais non parsable (`! $parsedTimestamp`), la requête est bien rejetée en HTTP `422`.*
- **Preuve Rouge (avant correctifs Point B) :**
  ```text
     FAIL  Tests\Feature\Payment\PaymentRattrapageTest
    ⨯ it Point B1: une commande payee en ligne ne notifie pas le commerce et ne demarre pas les 45s avant payment… 6.57s
    ⨯ it Point B2: une commande en ligne restee pending non payee apres 10 minutes (settings) est annulee automat… 0.46s
    ⨯ it Point B3: un echec de paiement GoColis notifie l expediteur (base + push)                                 0.41s
    ⨯ it Point B4: un doublon webhook repond HTTP 200 succes=true message="déjà traité" sans retraiter             0.42s
  ...
    at tests\Feature\Payment\PaymentRattrapageTest.php:63 (Notification count expected 0, actual 1)
    at tests\Feature\Payment\PaymentRattrapageTest.php:152 (Class "App\Jobs\AnnulerCommandePaiementTimeout" not found)
    at tests\Feature\Payment\PaymentRattrapageTest.php:235 (Notification payment_failed count expected 1, actual 0)
    at tests\Feature\Payment\PaymentRattrapageTest.php:290 (Expected response status code [200] but received 409)

    Tests:    4 failed (12 assertions)
    Duration: 8.40s
  ```
- **Preuve Verte (après correctifs + `77af9cc`) :**
  ```text
  {"tool":"pint","result":"passed"}
     0/163 [>---------------------------]   0% 163/163 [============================] 100%
   [OK] No errors
    Tests:    18 passed (115 assertions)
    Duration: 11.20s
  ```
  *(Suites exécutées : `PaymentRattrapageTest`, `PaymentWebhookTest`, `WebhookSecurityP5Test`, `SettingsDelaysAndTimeoutTest`.)*
- **Ce qui reste NON fait :** Prérequis d'initiation sortante PSP Hub2/CinetPay conservés en attente jusqu'à activation explicite hors `["cash"]`.

---

## POINT C — Positions livreurs nulles par défaut et fraîcheur GPS configurable

- **Commit :** `d1f146f` (`fix(dispatch): supprime GPS par defaut et exige location_updated_at < 2 min (Point C)`)
- **Fichiers modifiés / créés :**
  - `backend/app/Filament/Resources/CourierResource/Pages/CreateCourier.php` (suppression de `mutateFormDataBeforeCreate` qui injectait `center_lat`/`center_lng` par défaut)
  - `backend/database/factories/CourierFactory.php` (`current_lat`, `current_lng`, `location_updated_at` à `null` par défaut ; ajout du state `withGps()`)
  - `backend/database/seeders/DaloaSeeder.php` (suppression des coordonnées codées en dur pour les livreurs de démo : valeurs `null` tant qu'aucun GPS n'est envoyé)
  - `backend/app/Services/SettingsService.php` (`delais_regles.gps_fraicheur_minutes => 2` par défaut)
  - `backend/app/Filament/Pages/SettingsPage.php` (champ de paramétrage `delais_regles.gps_fraicheur_minutes`)
  - `backend/app/Modules/GoShop/Services/AssignmentService.php` (`trouverLivreurProche` exige désormais `whereNotNull('current_lat')`, `whereNotNull('current_lng')`, `whereNotNull('location_updated_at')` et `location_updated_at >= now()->subMinutes($fraicheurMinutes)` lu depuis `SettingsService`)
  - `backend/tests/Feature/Dispatch/CourierLocationFreshnessTest.php` (nouvelle suite C1–C2)
- **Justification écrite des adaptations de tests existants :**
  - Les fixtures de 7 suites d'attribution existantes (`AssignmentMaxActivesTest.php`, `DriverRefuseOrderTest.php`, `GoShopAssignmentAndTimeoutTest.php`, `SettingsDelaysAndTimeoutTest.php`, `GoColisDriverFlowTest.php`, `CashFlowSecurityTest.php`, `S8AssignmentCityIsolationSecurityTest.php`) créaient des livreurs en ligne via `Courier::factory()` sans renseigner `location_updated_at` (et dans deux cas sans coordonnées pour simuler un livreur éligible à un contrôle métier autre que le GPS). Elles ont été complétées avec `'current_lat'`, `'current_lng'` et `'location_updated_at' => now()` afin que ces livreurs simulés comme actifs et géolocalisés restent conformes aux conditions d'éligibilité GPS, sans supprimer ni assouplir aucune assertion métier.
- **Preuve Rouge (avant correctifs Point C) :**
  ```text
     FAIL  Tests\Feature\Dispatch\CourierLocationFreshnessTest
    ⨯ it Point C1: CourierFactory et CreateCourier laissent current_lat, current_lng et location_updated_at a nul… 4.72s
    ⨯ it Point C2: AssignmentService exclut les livreurs sans GPS ou dont location_updated_at > 2 min (parametrab… 0.50s
  ...
    at tests\Feature\Dispatch\CourierLocationFreshnessTest.php:28 (Failed asserting that 6.877 is null.)
    The unexpected [App\Jobs\NotifierLivreurGoShop] job was pushed.

    Tests:    2 failed (2 assertions)
    Duration: 5.79s
  ```
- **Preuve Verte (après correctifs) :**
  ```text
    Tests:    65 passed (466 assertions)
    Duration: 42.49s
  {"tool":"pint","result":"passed"}
     0/163 [>---------------------------]   0% 163/163 [============================] 100%
   [OK] No errors
  ```
  *(Suites exécutées : `Dispatch/*`, `Api/V1/GoColis/*`, `CashFlowSecurityTest`, `S6DriverAvailabilitySecurityTest`, `S8AssignmentCityIsolationSecurityTest`, `MultiAgentSimulationTest`, `CourierLocationPollingTest`.)*
- **Ce qui reste NON fait :** Néant.

---

## POINT D1 — Inventaire exhaustif du cloisonnement Filament

L'inventaire ci-dessous couvre **l'intégralité des 70 fichiers PHP** détectés sous `backend/app/Filament/Resources`, `backend/app/Filament/Pages` et `backend/app/Filament/Widgets` (énumération réelle via `Get-ChildItem -Recurse`), ainsi que la policy `UserPolicy` utilisée par Filament sur le modèle `User`.  
Constat architectural préalable : `AdminPanelProvider.php` n'applique pas `CityContextMiddleware` sur les requêtes Livewire/web ; l'isolation repose donc entièrement sur chaque Resource, Page, Formulaire/Action, Widget et Policy. Or plusieurs composants :
1. sont totalement dépourvus de scope (`UserResource`, `CityResource`, `NeighborhoodResource`, `ActivityLogResource`, `DisputesPage`, `PendingValidationsPage`, `EmergencyStatsWidget`, `HorizonQueueWidget`, modale `ListRefunds::createRefund`, modale `OrderResource::reassignCourier`, onglets `->badge(...)` des pages `List*`) ;
2. sont **fail-open** si un rôle local (`is_local_admin` / `city_*`) a `city_id === null` (soit condition `&& $user->city_id` qui saute le `where`, soit `$query->where('city_id', null)` qui génère `city_id IS NULL` en SQL, soit comparaison `(int) $record->city_id === (int) $user->city_id` évaluée à `0 === 0` = `true`) ;
3. ne verrouillent ni n'imposent `city_id` dans les pages `Create*` / `Edit*` (`CreateOrder`, `CreateParcel`, `CreateMerchant`, `CreateCourier`, `CreateNeighborhood`, `CreateUser` et leurs `Edit*`).

### Inventaire complet des 70 fichiers Filament (`backend/app/Filament/`)

| # | Fichier (`app/Filament/...`) | Modèle lié | `city_id` présent ? | Scope actuel et failles constatées | Verdict |
|---|---|---|---|---|---|
| 1 | `Resources/ActivityLogResource.php` | `ActivityLog` | Non direct *(via `user.city_id` / `properties->city_id`)* | `canAccess()` via `hasHabilitationTab('activity_logs')` ; aucun `getEloquentQuery()`. | **manquant** |
| 2 | `Resources/ActivityLogResource/Pages/ListActivityLogs.php` | `ActivityLog` | — | Délègue à `ActivityLogResource` (aucun onglet ni création). | **OK (délègue Resource)** |
| 3 | `Resources/AdministratorResource.php` | `User` | Oui *(nullable)* | `getEloquentQuery()` filtre seulement `role === 'city_manager' && $user->city_id` (fail-open si `city_id === null`) ; `canEdit`/`canDelete` font `(int)$record->city_id === (int)$user->city_id` (`0 === 0` si nulls) ; filtre table `city_id` non masqué. | **manquant** |
| 4 | `Resources/AdministratorResource/Pages/CreateAdministrator.php` | `User` | Oui | Impose `city_id` si `role === 'city_manager'`, sans bloquer si `city_id === null` ni couvrir autres rôles `city_*`. | **manquant** |
| 5 | `Resources/AdministratorResource/Pages/EditAdministrator.php` | `User` | Oui | Garde sur `role === 'city_manager'`, sans fail-closed explicite si `city_id === null`. | **manquant** |
| 6 | `Resources/AdministratorResource/Pages/ListAdministrators.php` | `User` | Oui | Les 5 onglets `->badge(User::...->count())` comptent toutes les villes. | **manquant** |
| 7 | `Resources/CityResource.php` | `City` | Oui *(`id` = ville)* | `canAccess()` autorise `city_manager` via onglet `'cities'` ; **aucun `getEloquentQuery()`** ; `canCreate`/`canDelete` ouverts ; actions table Pluie/Urgence non restreintes sur les autres villes. | **manquant** |
| 8 | `Resources/CityResource/Pages/CreateCity.php` | `City` | Oui | Page de création accessible si `canAccess()` vrai sans blocage rôle local. | **manquant** |
| 9 | `Resources/CityResource/Pages/EditCity.php` | `City` | Oui | `DeleteAction` et édition d'autres villes non bloqués pour rôle local. | **manquant** |
| 10 | `Resources/CityResource/Pages/ListCities.php` | `City` | Oui | Les 5 onglets `->badge(City::...->count())` comptent toutes les villes. | **manquant** |
| 11 | `Resources/CityResource/RelationManagers/NeighborhoodsRelationManager.php` | `Neighborhood` | Oui | Scopé sur l'enregistrement `City` parent ; dépend de l'isolation de `CityResource`. | **OK (via `ownerRecord` City)** |
| 12 | `Resources/ClientResource.php` | `User` | Oui *(nullable)* | `getEloquentQuery()` fait `if ($user && $user->is_local_admin && $user->city_id)` -> fail-open si `city_id === null` ; filtre `SelectFilter('city_id')` non masqué pour locaux. | **manquant** |
| 13 | `Resources/ClientResource/Pages/ListClients.php` | `User` | Oui | Délègue à `ClientResource` sans onglets ni création. | **OK (délègue Resource)** |
| 14 | `Resources/CourierResource.php` | `Courier` | Oui | `getEloquentQuery()` fail-open sur `&& $user->city_id` ; champ `city_id` non verrouillé ; `user_id` select & `createOptionUsing` non scopés à la ville. | **manquant** |
| 15 | `Resources/CourierResource/Pages/CreateCourier.php` | `Courier` | Oui | Aucun `mutateFormDataBeforeCreate()` forçant `city_id = $user->city_id` pour un rôle local. | **manquant** |
| 16 | `Resources/CourierResource/Pages/EditCourier.php` | `Courier` | Oui | Aucun `mutateFormDataBeforeSave()` empêchant la modification de `city_id` par un rôle local. | **manquant** |
| 17 | `Resources/CourierResource/Pages/ListCouriers.php` | `Courier` | Oui | Les 4 onglets `->badge(Courier::...->count())` comptent toutes les villes. | **manquant** |
| 18 | `Resources/MerchantCategoryResource.php` | `MerchantCategory` | Non | Référentiel global des catégories commerciales (sans `city_id`). | **N/A (référentiel global)** |
| 19 | `Resources/MerchantCategoryResource/Pages/CreateMerchantCategory.php` | `MerchantCategory` | Non | Page CRUD sur référentiel global. | **N/A (référentiel global)** |
| 20 | `Resources/MerchantCategoryResource/Pages/EditMerchantCategory.php` | `MerchantCategory` | Non | Page CRUD sur référentiel global. | **N/A (référentiel global)** |
| 21 | `Resources/MerchantCategoryResource/Pages/ListMerchantCategories.php` | `MerchantCategory` | Non | Liste et onglets sur référentiel global sans `city_id`. | **N/A (référentiel global)** |
| 22 | `Resources/MerchantResource.php` | `Merchant` | Oui | `getEloquentQuery()` utilise `str_starts_with($user->role, 'city_')` sans fail-closed explicite si `city_id === null` ; formulaire `city_id` non verrouillé ; `user_id` select & `createOptionUsing` non scopés. | **manquant** |
| 23 | `Resources/MerchantResource/Pages/CreateMerchant.php` | `Merchant` | Oui | `mutateFormDataBeforeCreate` n'impose pas `city_id = $user->city_id` côté serveur pour un rôle local. | **manquant** |
| 24 | `Resources/MerchantResource/Pages/EditMerchant.php` | `Merchant` | Oui | Aucun `mutateFormDataBeforeSave()` verrouillant `city_id`. | **manquant** |
| 25 | `Resources/MerchantResource/Pages/ListMerchants.php` | `Merchant` | Oui | Les 4 onglets `->badge(Merchant::...->count())` comptent toutes les villes. | **manquant** |
| 26 | `Resources/NeighborhoodResource.php` | `Neighborhood` | Oui | Accessible à `city_manager` via `'cities'` ; **aucun `getEloquentQuery()`** ; `city_id` formulaire non verrouillé. | **manquant** |
| 27 | `Resources/NeighborhoodResource/Pages/CreateNeighborhood.php` | `Neighborhood` | Oui | Aucun `mutateFormDataBeforeCreate()` imposant `city_id` pour un rôle local. | **manquant** |
| 28 | `Resources/NeighborhoodResource/Pages/EditNeighborhood.php` | `Neighborhood` | Oui | Aucun `mutateFormDataBeforeSave()` verrouillant `city_id`. | **manquant** |
| 29 | `Resources/NeighborhoodResource/Pages/ListNeighborhoods.php` | `Neighborhood` | Oui | Les 3 onglets `->badge(Neighborhood::...->count())` comptent toutes les villes. | **manquant** |
| 30 | `Resources/OrderResource.php` | `Order` | Oui | Pas de fail-closed explicite si local sans `city_id` ; `form()` (`city_id`, `client_id`, `merchant_id`, `courier_id`) non scopés/verrouillés ; action table `reassignCourier` propose `Courier::where('is_active', true)` toutes villes sans valider `$courier->city_id === $record->city_id`. | **manquant** |
| 31 | `Resources/OrderResource/Pages/CreateOrder.php` | `Order` | Oui | Aucun `mutateFormDataBeforeCreate()` imposant `city_id` pour un rôle local. | **manquant** |
| 32 | `Resources/OrderResource/Pages/EditOrder.php` | `Order` | Oui | Aucun `mutateFormDataBeforeSave()` verrouillant `city_id`. | **manquant** |
| 33 | `Resources/OrderResource/Pages/ListOrders.php` | `Order` | Oui | Les 5 onglets `->badge(Order::...->count())` comptent toutes les villes. | **manquant** |
| 34 | `Resources/ParcelResource.php` | `Parcel` | Oui | Pas de fail-closed explicite si local sans `city_id` ; `form()` (`city_id`, `sender_id`, `courier_id`) non scopés/verrouillés. | **manquant** |
| 35 | `Resources/ParcelResource/Pages/CreateParcel.php` | `Parcel` | Oui | Aucun `mutateFormDataBeforeCreate()` imposant `city_id` pour un rôle local. | **manquant** |
| 36 | `Resources/ParcelResource/Pages/EditParcel.php` | `Parcel` | Oui | Aucun `mutateFormDataBeforeSave()` verrouillant `city_id`. | **manquant** |
| 37 | `Resources/ParcelResource/Pages/ListParcels.php` | `Parcel` | Oui | Les 7 onglets `->badge(Parcel::...->count())` comptent toutes les villes. | **manquant** |
| 38 | `Resources/RefundResource.php` | `Refund` | Oui | `getEloquentQuery()` utilise `in_array($user->role, RefundService::LOCAL_ROLES, true) && $user->city_id` -> fail-open si `city_id === null`. | **manquant** |
| 39 | `Resources/RefundResource/Pages/ListRefunds.php` | `Refund` | Oui | Modale `createRefund` charge `Order::latest()->limit(50)` et `Parcel::latest()->limit(50)` **sans filtre `city_id`** (fuite cross-ville dans les options) et appelle `plafondPourRole($role)` sans `$user->city_id`. | **manquant** |
| 40 | `Resources/RoleHabilitationResource.php` | `RoleHabilitation` | Non | `canAccess()` restreint strictement à `$user->role === 'super_admin'`. | **OK** |
| 41 | `Resources/RoleHabilitationResource/Pages/CreateRoleHabilitation.php` | `RoleHabilitation` | Non | Hérite de `RoleHabilitationResource` (`super_admin` uniquement). | **OK** |
| 42 | `Resources/RoleHabilitationResource/Pages/EditRoleHabilitation.php` | `RoleHabilitation` | Non | Hérite de `RoleHabilitationResource` (`super_admin` uniquement). | **OK** |
| 43 | `Resources/RoleHabilitationResource/Pages/ListRoleHabilitations.php` | `RoleHabilitation` | Non | Hérite de `RoleHabilitationResource` (`super_admin` uniquement). | **OK** |
| 44 | `Resources/RoleHabilitationResource/Pages/ViewRoleHabilitation.php` | `RoleHabilitation` | Non | Hérite de `RoleHabilitationResource` (`super_admin` uniquement). | **OK** |
| 45 | `Resources/UserResource.php` | `User` | Oui *(nullable)* | `canAccess()` via `hasHabilitationTab('clients')` (ouvert aux locaux) mais `getEloquentQuery()` ne filtre que `whereNotIn('role', User::ADMIN_ROLES)` **sans aucun filtre `city_id`** ; formulaire `city_id` et `role` non verrouillés. | **manquant** |
| 46 | `Resources/UserResource/Pages/CreateUser.php` | `User` | Oui | Aucun `mutateFormDataBeforeCreate()` imposant `city_id` ni interdisant l'attribution d'un rôle admin. | **manquant** |
| 47 | `Resources/UserResource/Pages/EditUser.php` | `User` | Oui | Aucun `mutateFormDataBeforeSave()` verrouillant `city_id` ni le rôle. | **manquant** |
| 48 | `Resources/UserResource/Pages/ListUsers.php` | `User` | Oui | Les 4 onglets `->badge(User::...->count())` comptent toutes les villes. | **manquant** |
| 49 | `Resources/VehicleTypeResource.php` | `VehicleType` | Non | Référentiel global des types de véhicules (sans `city_id`). | **N/A (référentiel global)** |
| 50 | `Resources/VehicleTypeResource/Pages/CreateVehicleType.php` | `VehicleType` | Non | Page CRUD sur référentiel global. | **N/A (référentiel global)** |
| 51 | `Resources/VehicleTypeResource/Pages/EditVehicleType.php` | `VehicleType` | Non | Page CRUD sur référentiel global. | **N/A (référentiel global)** |
| 52 | `Resources/VehicleTypeResource/Pages/ListVehicleTypes.php` | `VehicleType` | Non | Liste et onglets sur référentiel global sans `city_id`. | **N/A (référentiel global)** |
| 53 | `Resources/WalletTransactionResource.php` | `WalletTransaction` | Indirect *(order / parcel / wallet)* | `getEloquentQuery()` conditionné à `&& $user->city_id` -> fail-open si rôle local avec `city_id === null`. | **manquant** |
| 54 | `Resources/WalletTransactionResource/Pages/ListWalletTransactions.php` | `WalletTransaction` | Indirect | Délègue à `WalletTransactionResource` sans onglets. | **OK (délègue Resource)** |
| 55 | `Resources/WebhookLogResource.php` | `WebhookLog` | Non *(logs globaux agrégateurs)* | `canAccess()` repose uniquement sur `hasHabilitationTab('webhook_logs')` sans garde explicite `! $user->is_local_admin` ni `getEloquentQuery()` fail-closed pour locaux. | **manquant** |
| 56 | `Resources/WebhookLogResource/Pages/ListWebhookLogs.php` | `WebhookLog` | Non | Délègue à `WebhookLogResource`. | **OK (délègue Resource)** |
| 57 | `Pages/DisputesPage.php` | `Order`, `Parcel` | Oui | **Aucun filtre `city_id`** dans `getNavigationBadge()`, `table()` (`Order::query()->where('confirmation_locked', true)`), ni garde ville dans `unlockCode`. | **manquant** |
| 58 | `Pages/EmergencyCenter.php` | `City` | Oui *(`id` = ville)* | `canAccess()` repose uniquement sur `hasHabilitationTab('emergency_center')` sans garde `! $user->is_local_admin` ; actions globales sur toutes les villes dans la même classe. | **manquant** |
| 59 | `Pages/PendingValidationsPage.php` | `Merchant`, `Courier` | Oui | `mount()`, `getNavigationBadge()`, `getPendingCouriersCountProperty()`, `getPendingMerchantsCountProperty()` requêtent `Merchant`/`Courier` **sans filtre `city_id`**. | **manquant** |
| 60 | `Pages/SettingsPage.php` | `Setting` | Oui *(nullable)* | `canAccess()` repose uniquement sur `hasHabilitationTab('settings')` ; `selectedCityId` non verrouillé/bloqué si rôle local. | **manquant** |
| 61 | `Pages/Widgets/PendingCouriersWidget.php` | `Courier` | Oui | `table()` fait `if ($user && $user->is_local_admin && $user->city_id)` -> fail-open si `city_id === null`. | **manquant** |
| 62 | `Pages/Widgets/PendingMerchantsWidget.php` | `Merchant` | Oui | `table()` fait `if ($user && $user->is_local_admin && $user->city_id)` -> fail-open si `city_id === null`. | **manquant** |
| 63 | `Widgets/BlockedCodesAlertWidget.php` | `Order` | Oui | `canView()` et `table()` font `&& $user->city_id` -> fail-open si rôle local avec `city_id === null`. | **manquant** |
| 64 | `Widgets/EmergencyStatsWidget.php` | `City`, `Order`, `Parcel` | Oui | **Aucun filtre `city_id`** sur `City::count()`, `Order::where(...)` et `Parcel::where(...)` (et aucun fail-closed). | **manquant** |
| 65 | `Widgets/HorizonQueueWidget.php` | `WebhookLog`, `jobs` | Non *(queues globales)* | Aucune méthode `canView()` : exposé aux rôles locaux s'ils affichent le Dashboard. | **manquant** |
| 66 | `Widgets/LatestOrdersWidget.php` | `Order` | Oui | `table()` conditionné à `&& $user->city_id` -> fail-open si rôle local avec `city_id === null`. | **manquant** |
| 67 | `Widgets/PaymentMethodsChart.php` | `Order`, `Parcel` | Oui | `getData()` conditionné à `&& $user->city_id` -> fail-open si rôle local avec `city_id === null`. | **manquant** |
| 68 | `Widgets/StatsOverview.php` | `Order`, `Parcel`, `Merchant`, `Courier` | Oui | `getStats()` conditionné à `&& $user->city_id` -> fail-open si rôle local avec `city_id === null`. | **manquant** |
| 69 | `Widgets/WeeklyOrdersChart.php` | `Order` | Oui | `getData()` conditionné à `&& $user->city_id` -> fail-open si rôle local avec `city_id === null`. | **manquant** |
| 70 | `Widgets/WeeklyRevenueChart.php` | `Order`, `Parcel` | Oui | `getData()` conditionné à `&& $user->city_id` -> fail-open si rôle local avec `city_id === null`. | **manquant** |

**Addendum Policy associée (`app/Policies/UserPolicy.php`) :**
- Dans `UserPolicy.php` (lignes 20, 33, 50, 72), les contrôles `view`, `update` et `delete` comparent `(int) $model->city_id === (int) $user->city_id`. Si `$user` local et `$model` ont `city_id === null`, `(int) null === (int) null` (`0 === 0`) vaut `true` (fail-open). De plus `view` n'autorise que `city_manager` alors que `update` autorise aussi `city_support`/`city_agent`/`city_finance`. Verdict : **manquant (fail-closed à appliquer en D2)**.

---

## POINTS D2, E, F, G et Vérification Finale

*(Sections complétées dans les commits suivants.)*
