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

## POINT D2 — Isolation Filament complète + fail-closed (`city_id` nul)

- **Commit :** `e257206` (`fix(filament): isolation stricte par ville et fail-closed sur toutes les ressources, pages et widgets (Point D2)`)
- **Cause racine critique identifiée et corrigée :**
  - [AppServiceProvider.php](file:///c:/Projets/golivreur/backend/app/Providers/AppServiceProvider.php#L48) enregistrait `Gate::policy(User::class, ProfilePolicy::class);`, éclipsant [`UserPolicy`](file:///c:/Projets/golivreur/backend/app/Policies/UserPolicy.php) et permettant l'accès multi-villes aux enregistrements `User` dans Filament.
  - Bascule vers `Gate::policy(User::class, UserPolicy::class);` avec ajout des capacités API dédiées `viewSelfProfile()` / `updateSelfProfile()` dans [`UserPolicy`](file:///c:/Projets/golivreur/backend/app/Policies/UserPolicy.php#L92-L106) et durcissement fail-closed (`$user->city_id === null || $model->city_id === null => false`) pour `city_manager`.
- **Correctifs implémentés (56 fichiers) :**
  - Helper centralisé [`FilamentCityScope`](file:///c:/Projets/golivreur/backend/app/Filament/Support/FilamentCityScope.php) : `scopeByCity()` (`whereRaw('1 = 0')` si admin local sans `city_id`), `canAccessCityId()`, `authorizeCityId()`, `authorizeRecord()`, `enforceCityOnCreate()`, `enforceCityOnSave()`, `configureCitySelect()`, `scopedCityName()`, `scopedTabCount()`.
  - Isolation appliquée sur toutes les ressources (`UserResource`, `AdministratorResource`, `CityResource` + `NeighborhoodsRelationManager`, `NeighborhoodResource`, `ClientResource`, `MerchantResource`, `CourierResource`, `OrderResource`, `ParcelResource`, `RefundResource`, `WalletTransactionResource`, `ActivityLogResource`, `WebhookLogResource`) + pages `Create*` / `Edit*` / `List*`, pages personnalisées (`DisputesPage`, `PendingValidationsPage`, `EmergencyCenter`, `SettingsPage`), et widgets (`StatsOverview`, `LatestOrdersWidget`, `WeeklyOrdersChart`, `WeeklyRevenueChart`, `PaymentMethodsChart`, `BlockedCodesAlertWidget`, `EmergencyStatsWidget`, `PendingCouriersWidget`, `PendingMerchantsWidget`, `HorizonQueueWidget`).
  - Génération automatique côté serveur des codes de confirmation/sécurité chiffrés et hashés dans [`CreateOrder`](file:///c:/Projets/golivreur/backend/app/Filament/Resources/OrderResource/Pages/CreateOrder.php) et [`CreateParcel`](file:///c:/Projets/golivreur/backend/app/Filament/Resources/ParcelResource/Pages/CreateParcel.php) (+ valeurs par défaut `0` pour `declared_value` / `insurance_amount`).
- **Preuve Rouge -> Vert :**
  - **ROUGE** (`FilamentIsolationRattrapageTest.php`, avant D2) :
    - Résultat : `Tests: 4 failed (12 assertions), Duration: 8.58s` (`UserResource` fuite Bouaké : `7` visibles ; `DisputesPage::getNavigationBadge()` `'2'` au lieu de `'1'` ; `CreateNeighborhood` créait en Bouaké `6` au lieu de Daloa `5` ; `CourierResource` admin local `city_id=null` voyait `1` enregistrement au lieu de `0`).
  - **VERT** (après D2) :
    - `./vendor/bin/pest tests/Feature/Admin/FilamentIsolationRattrapageTest.php` -> `Tests: 4 passed (125 assertions), Duration: 33.35s`.
    - Régression complète Admin / Filament / Security / Client / GoColis : `Tests: 83 passed (693 assertions), Duration: 177.91s`.
    - `ProfileManagementTest` + `P2bProfileCityImmutableSecurityTest` : `Tests: 12 passed (88 assertions), Duration: 13.13s`.
    - `./vendor/bin/pint --test` -> `{"tool":"pint","result":"passed"}`.
    - `php ./vendor/bin/phpstan analyse --level=5 --memory-limit=1G` -> `[OK] No errors` (`164/164`).
- **Tests créés / modifiés (transparence) :**
  - Création de [`FilamentIsolationRattrapageTest.php`](file:///c:/Projets/golivreur/backend/tests/Feature/Admin/FilamentIsolationRattrapageTest.php) uniquement ; aucun test existant modifié ni affaibli.
  - Sur `DisputesPage::callTableAction('unlockCode', $bouakeLockedOrder)`, la requête scopée de la table Filament ignore l'enregistrement hors périmètre (no-op côté table), et le test vérifie explicitement les invariants base (`confirmation_locked === true`, `confirmation_unlocked_by === null`, absence de log `order_code_unlocked`).

---

## POINT E — Disque privé `parcel_photos` hors `public/`, URL signées 15 min et audit Nginx / `.env`

- **Commit :** `bea591f` (`fix(storage): disque prive parcel_photos hors public, URL signees 15 min et var PARCEL_PHOTOS_DISK (Point E)`)
- **Constat initial :**
  - `ParcelPhoto` et `ParcelPhotoStorageService` utilisaient en dur le disque `s3` alors que `league/flysystem-aws-s3-v3` n'est pas installé dans [`composer.json`](file:///c:/Projets/golivreur/backend/composer.json).
  - [`backend/public/`](file:///c:/Projets/golivreur/backend/public) contient uniquement `css`, `images`, `js`, `storage` (jonction vers `storage/app/public`), `.htaccess`, `favicon.ico`, `index.php`, `robots.txt`.
- **Correctifs implémentés (9 fichiers) :**
  - [`config/filesystems.php`](file:///c:/Projets/golivreur/backend/config/filesystems.php) : ajout de `'parcel_photos_disk' => env('PARCEL_PHOTOS_DISK', 'parcel_photos')` et du disque privé `'parcel_photos'` (`driver => 'local'`, `root => storage_path('app/private/parcel_photos')` hors `public/`, `url => rtrim(env('APP_URL', 'http://localhost'), '/').'/media/parcel-photos'`, `serve => true`, `visibility => 'private'`, `throw => true`, `report => false`).
  - [`ParcelPhoto::storageDisk()`](file:///c:/Projets/golivreur/backend/app/Models/ParcelPhoto.php#L20-L27) et `getSignedUrlAttribute()` (`temporaryUrl(..., now()->addMinutes(15))`) + [`ParcelPhotoStorageService`](file:///c:/Projets/golivreur/backend/app/Services/ParcelPhotoStorageService.php) ciblant `ParcelPhoto::storageDisk()`.
  - **Audit Nginx :** [`devops/nginx/production.conf`](file:///c:/Projets/golivreur/devops/nginx/production.conf) (blocs production et staging, avec `root /var/www/production/public;` ligne 31 et `location ~ ^/storage/ { deny all; return 404; }` lignes 101-104) et [`devops/docker/nginx/default.conf`](file:///c:/Projets/golivreur/devops/docker/nginx/default.conf) interceptaient `.jpg/.png` avant PHP via `location ~* \.(js|css|png|jpg|jpeg|...)`. Ajout de `location ^~ /media/parcel-photos/ { try_files /_force_laravel_signed /index.php?$query_string; }` avant la règle statique pour déléguer le flux signé à Laravel `ServeFile`.
  - **Dépendance S3/Spaces & `.env.example` :** `league/flysystem-aws-s3-v3` n'a **pas** été installé. [`backend/.env.example`](file:///c:/Projets/golivreur/backend/.env.example) documente `PARCEL_PHOTOS_DISK=parcel_photos`, les variables `DO_SPACES_KEY`, `DO_SPACES_SECRET`, `DO_SPACES_REGION`, `DO_SPACES_BUCKET`, `DO_SPACES_ENDPOINT`, `DO_SPACES_CDN_ENDPOINT` ainsi que le mappage `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, `AWS_BUCKET`, `AWS_URL`, `AWS_ENDPOINT`, `AWS_USE_PATH_STYLE_ENDPOINT`.
- **Preuve Rouge -> Vert :**
  - **ROUGE** (`./vendor/bin/pest tests/Feature/GoColis/ParcelPhotoDiskTest.php`) :
    - `Tests: 3 failed (2 assertions), Duration: 7.32s` (`Failed asserting that null is identical to 'parcel_photos'` à `:33`, `Disk [parcel_photos] does not have a configured driver` à `:83`, `Class "League\Flysystem\AwsS3V3\PortableVisibilityConverter" not found` à `FilesystemManager.php:250`).
  - **VERT** :
    - `./vendor/bin/pest tests/Feature/GoColis/ParcelPhotoDiskTest.php` -> `Tests: 3 passed (34 assertions), Duration: 7.47s`.
    - Suite GoColis photos + médias : `Tests: 9 passed (120 assertions), Duration: 31.33s`.
    - `./vendor/bin/pint --test` -> `{"tool":"pint","result":"passed"}`.
    - `php ./vendor/bin/phpstan analyse --level=5 --memory-limit=1G` -> `[OK] No errors` (`164/164`).
- **Justification des tests existants adaptés (2 fichiers) :**
  - [`ParcelPhotosSecurityTest.php`](file:///c:/Projets/golivreur/backend/tests/Feature/GoColis/ParcelPhotosSecurityTest.php) et [`ParcelMilestonesAndSmsTest.php`](file:///c:/Projets/golivreur/backend/tests/Feature/GoColis/ParcelMilestonesAndSmsTest.php) : remplacement de `'s3'` en dur par `ParcelPhoto::storageDisk()` dans `Storage::fake(...)` et `Storage::disk(...)`, car le stockage cible désormais le disque configuré (`parcel_photos` par défaut) tout en conservant exactement les mêmes assertions de sécurité (visibilité privée, ré-encodage GD, rejet `.exe` / > 5 Mo, URL signées).

---

## POINT F — Plafond solde cash minimum + engagements en cours à l'acceptation

- **Commit :** `61d6edd` (`fix(wallet): exige solde >= minimum + engagements cash en cours a l'acceptation (Point F)`)
- **Correctif implémenté ([`WalletService.php`](file:///c:/Projets/golivreur/backend/app/Services/WalletService.php)) :**
  - Calcul de `$engagements = $this->calculerEngagementsCashEnCours($courier->id)`, `$soldeRequis = $soldeMinimum + $engagements` et `$disponible = $solde - $engagements`.
  - Refus dès que `$solde < $soldeRequis` (et si `$disponible < $montantAReverser`), protégeant `DriverController::acceptOrder`, `DriverController::acceptParcel` et `AssignmentService`.
- **Comportement observé sans modification si le débit de reversement échoue à la livraison :**
  - À la confirmation de livraison cash (`OrderService::validerCodeLivraison()` / `ParcelService` via `DriverController::confirmDelivery` / `ParcelController`), l'opération s'exécute dans `DB::transaction()`.
  - `WalletService::effectuerTransaction()` lève `\InvalidArgumentException('Solde portefeuille livreur insuffisant pour effectuer le reversement en espèces.')` lorsque `$balanceApres < 0`.
  - Toute la transaction PostgreSQL est annulée atomiquement (rollback : statut inchangé, `payment_status` inchangé, solde wallet inchangé, `0` `WalletTransaction` créée) et l'API renvoie HTTP `422` `{"succes": false, "message": "..."}`.
- **Preuve Rouge -> Vert :**
  - **ROUGE** (`./vendor/bin/pest tests/Feature/Finance/CashLimitAtAcceptRattrapageTest.php`) :
    - `Tests: 1 failed, 1 passed (7 assertions), Duration: 6.89s` (`The unexpected [App\Jobs\NotifierLivreurGoShop] job was pushed` à `QueueFake.php:300` avec 2 courses cash actives et solde juste au-dessus du minimum brut).
  - **VERT** :
    - `./vendor/bin/pest tests/Feature/Finance/CashLimitAtAcceptRattrapageTest.php` -> `Tests: 2 passed (17 assertions), Duration: 6.93s`.
    - Suite combinée (`CashLimitAtAcceptRattrapageTest`, `CashFlowSecurityTest`, `GoColisDriverFlowTest`, `P2aSingleActiveCourseSecurityTest`, `D2AcceptOrderSecurityTest`) -> `Tests: 48 passed (249 assertions), Duration: 38.67s`.
    - `./vendor/bin/pint --test` -> `{"tool":"pint","result":"passed"}`.
    - `php ./vendor/bin/phpstan analyse --level=5 --memory-limit=1G` -> `[OK] No errors` (`164/164`).

---

## POINT G — Commande de balayage secours `orders:sweep-timeouts`

- **Commits :** `bc7f2cc` (`fix(dispatch): commande orders:sweep-timeouts en filet de secours toutes les minutes (Point G)`) + `5c19c02` (`style(dispatch): corrige fin de fichier Point G pour pint`)
- **Correctifs implémentés (10 fichiers) :**
  - Migration [`2026_11_15_110000_add_last_assignment_attempt_at_to_orders_table.php`](file:///c:/Projets/golivreur/backend/database/migrations/2026_11_15_110000_add_last_assignment_attempt_at_to_orders_table.php) ajoutant `last_assignment_attempt_at` nullable sur `orders` (renseigné et casté dans [`Order`](file:///c:/Projets/golivreur/backend/app/Models/Order.php) et mis à jour dans [`AssignmentService::attribuerLivreurGoShop()`](file:///c:/Projets/golivreur/backend/app/Modules/GoShop/Services/AssignmentService.php)).
  - Extraction réutilisable `AnnulerCommandeTimeout::annulerSiExpiree(..., int $margeSecondes = 0)` dans [`AnnulerCommandeTimeout`](file:///c:/Projets/golivreur/backend/app/Jobs/AnnulerCommandeTimeout.php) conservant la garde paiement en ligne non payé et `lockForUpdate()`.
  - Nouveaux paramètres configurables dans [`SettingsService`](file:///c:/Projets/golivreur/backend/app/Services/SettingsService.php) (`marge_balayage_commerce_secondes => 15`, `marge_balayage_livreur_secondes => 15` sous `delais_regles`, lus dynamiquement via `getDelaiRegle(..., minimum: 0)`) et exposés dans [`SettingsPage`](file:///c:/Projets/golivreur/backend/app/Filament/Pages/SettingsPage.php).
  - Service [`OrderSweepService::balayer()`](file:///c:/Projets/golivreur/backend/app/Services/OrderSweepService.php) exécuté sous `Order::lockForUpdate()` par commande, ne retouchant jamais le statut `waiting`, respectant `AssignmentService::MAX_TENTATIVES` (`3`), et journalisant dans `activity_logs` (`order_sweep_pending_timeout_cancelled`, `order_sweep_assignment_retried`).
  - Commande Artisan [`orders:sweep-timeouts`](file:///c:/Projets/golivreur/backend/app/Console/Commands/SweepOrderTimeoutsCommand.php) planifiée dans [`routes/console.php`](file:///c:/Projets/golivreur/backend/routes/console.php) via `->everyMinute()->withoutOverlapping()->onOneServer()`, confirmée par `php artisan schedule:list` (`* * * * *  php artisan orders:sweep-timeouts`).
  - **Note périmètre cron système :** le cron OS/systemd/Docker appelant `php artisan schedule:run` n'est **pas** configuré dans ce lot (réservé au Prompt 11).
- **Preuve Rouge -> Vert :**
  - **ROUGE** (`./vendor/bin/pest tests/Feature/Dispatch/OrderBackupSweepCommandTest.php`) :
    - `Tests: 3 failed (2 assertions), Duration: 7.78s` (`Expecting null not to be null` à `:31` et `:77`, `SQLSTATE[42703]: Undefined column last_assignment_attempt_at`).
  - **VERT** :
    - `./vendor/bin/pest tests/Feature/Dispatch/OrderBackupSweepCommandTest.php` -> `Tests: 3 passed (34 assertions), Duration: 9.14s`.
    - Suite combinée (`tests/Feature/Dispatch`, `SettingsTest`, `PaymentRattrapageTest`) -> `Tests: 29 passed (234 assertions), Duration: 57.64s`.
    - `php ./vendor/bin/phpstan analyse --level=5 --memory-limit=1G` -> `[OK] No errors` (`166/166`).
    - Correction de 4 sauts de ligne finaux EOF détectés par `pint --test` sur les 4 nouveaux fichiers (`5c19c02`) -> `./vendor/bin/pint --test` : `{"tool":"pint","result":"passed"}`.

---

## Ce qui reste NON fait (périmètre exclu ou actions ultérieures)

1. **Cron système / scheduler serveur (Prompt 11) :** la planification Laravel `orders:sweep-timeouts` est en place dans `routes/console.php`, mais l'entrée `cron` / superviseur système exécutant `* * * * * php artisan schedule:run` reste à déployer au Prompt 11.
2. **Dépendance S3/DigitalOcean Spaces (`league/flysystem-aws-s3-v3`) :** volontairement non ajoutée sans accord préalable ; requise avant d'activer `PARCEL_PHOTOS_DISK=s3` en production.
3. **Application incrémentale des migrations sur `golivreur_dev` :** conformément à la règle interdisant toute réinitialisation/seeding destructif de `golivreur_dev`, 8 migrations récentes restent `Pending` selon `php artisan migrate:status` sur l'environnement local (dont `2026_11_15_100000_add_paid_at_to_orders_table` et `2026_11_15_110000_add_last_assignment_attempt_at_to_orders_table`) ; `php artisan migrate` incrémental devra être exécuté au déploiement.
4. **Initiation PSP sortante (Hub2 / CinetPay) :** hors périmètre du présent lot (D-PAIEMENT-1 lance uniquement en `cash`).

---

## Vérification finale complète

> [!NOTE]
> **`memory_limit` PHP CLI sous Windows :** lors de l'exécution de `./vendor/bin/pest` avec la limite par défaut de PHP CLI (`memory_limit=128M`), tous les tests passent jusqu'à `Tests\Feature\Security\ThrottleAndLocationSecurityTest`, puis Livewire épuise les 128 Mo lors du chargement des vues (`Allowed memory size of 134217728 bytes exhausted in vendor/livewire/livewire/src/Mechanisms/ExtendBlade/ExtendedCompilerEngine.php:43`). L'exécution complète de la suite sans aucune modification ni exclusion est effectuée avec `php -d memory_limit=512M ./vendor/bin/pest`. Aucune exclusion PHPStan ajoutée ; aucun fichier mobile modifié dans ce lot (vérifié tout de même sur les 3 apps).

### Résumé exécutif final

| Vérification | Commande | Résultat | Durée |
|---|---|---|---|
| **Pest complet (128M défaut)** | `./vendor/bin/pest` | OOM après `ThrottleAndLocationSecurityTest` (`134217728 bytes`) | Interrompu (`EXIT_PEST=1`) |
| **Pest complet (512M)** | `php -d memory_limit=512M ./vendor/bin/pest` | **442 passed (3047 assertions)** (`EXIT_PEST=0`) | `378.33s` |
| **PHPStan niveau 5** | `php ./vendor/bin/phpstan analyse --level=5 --memory-limit=1G` | **[OK] No errors** (`166/166`, `EXIT_PHPSTAN=0`) | — |
| **Pint** | `./vendor/bin/pint --test` | **`{"tool":"pint","result":"passed"}`** (`EXIT_PINT=0`) | — |
| **Client (`flutter analyze` / `test`)** | `flutter analyze` / `flutter test` | **No issues found!** / **+3: All tests passed!** (`0` / `0`) | `151.3s` / `5s` |
| **Business (`flutter analyze` / `test`)** | `flutter analyze` / `flutter test` | **No issues found!** / **+3: All tests passed!** (`0` / `0`) | `7.0s` / `4s` |
| **Driver (`flutter analyze` / `test`)** | `flutter analyze` / `flutter test` | **No issues found!** / **+3: All tests passed!** (`0` / `0`) | `38.4s` / `4s` |

### 1. Sortie `./vendor/bin/pint --test`

```text
{"tool":"pint","result":"passed"}
EXIT_PINT=0
```

### 2. Sortie `php ./vendor/bin/phpstan analyse --level=5 --memory-limit=1G`

```text
Note: Using configuration file C:\Projets\golivreur\backend\phpstan.neon.
   0/166 [>---------------------------]   0% 166/166 [============================] 100%


 [OK] No errors

EXIT_PHPSTAN=0
```

### 3. Sortie `./vendor/bin/pest` avec défaut `128M` (incident mémoire CLI)

```text
   PASS  Tests\Feature\Security\ThrottleAndLocationSecurityTest
  ✓ it valide strictement les coordonnees GPS entre -90/90 et -180/180 sur driver/location                       0.50s
  ✓ it bloque les requetes de positionnement livreur a plus de 1 requete par 5 secondes                          0.63s
  ✓ it configure les throttles de groupe auth a 60 par minute et public a 20 par minute                          0.38s
PHP Fatal error:  Allowed memory size of 134217728 bytes exhausted (tried to allocate 2097152 bytes) in C:\Projets\golivreur\backend\vendor\livewire\livewire\src\Mechanisms\ExtendBlade\ExtendedCompilerEngine.php on line 43
Stack trace:
#0 C:\Projets\golivreur\backend\vendor\livewire\livewire\src\Mechanisms\ExtendBlade\ExtendedCompilerEngine.php(43): ob_get_clean()
#1 {main}

   Pest\Exceptions\FatalException

  Allowed memory size of 134217728 bytes exhausted (tried to allocate 2097152 bytes)

  at vendor\livewire\livewire\src\Mechanisms\ExtendBlade\ExtendedCompilerEngine.php:43
EXIT_PEST=1
```

### 4. Sortie complète `php -d memory_limit=512M ./vendor/bin/pest`

```text
   PASS  Tests\Feature\Admin\AdministratorHierarchySecurityTest
  ✓ role selector options depend on authenticated user role                                                      6.03s
  ✓ city manager cannot create superior role even when forging request                                           4.67s
  ✓ city manager creating local agent forces city id to own city                                                 1.94s
  ✓ city manager cannot edit admin from another city                                                             0.51s
  ✓ city manager cannot escalate local agent to super admin via edit                                             2.16s

   PASS  Tests\Feature\Admin\DisputesAndOrderResourceTest
  ✓ it affiche id, total_client et libelles reels sur DisputesPage sans propriete inexistante                    1.90s
  ✓ it affiche et enregistre les notifications et cancel_reason reels dans OrderResource                         8.17s

   PASS  Tests\Feature\Admin\EmergencyModeSecurityTest
  ✓ api activation allowed for super admin and dg national                                                       0.60s
  ✓ api deactivation is strictly forbidden to dg national and reserved to super admin                            0.51s
  ✓ api city manager cannot activate or deactivate emergency mode                                                0.50s
  ✓ api emergency message cannot exceed 255 characters                                                           0.73s
  ✓ city manager does not have access to emergency center                                                        0.73s
  ✓ filament emergency center deactivation action forbidden to dg national                                       1.52s

   PASS  Tests\Feature\Admin\FilamentCityContextIsolationTest
  ✓ city manager daloa sees no orders parcels merchants couriers from bouake                                     3.64s
  ✓ national roles and super admin see all cities                                                                1.60s
  ✓ stats overview adapts label and counts to city context                                                       0.73s
  ✓ city finance sees only wallet transactions from own city                                                     1.05s
  ✓ latest orders widget scopes by city context                                                                  0.77s
  ✓ pending widgets scope by city context                                                                        1.18s

   PASS  Tests\Feature\Admin\FilamentIsolationRattrapageTest
  ✓ it isole UserResource, CityResource, NeighborhoodResource, ActivityLogResource et WebhookLogResource par vi… 0.68s
  ✓ it isole DisputesPage, PendingValidationsPage, ListRefunds, OrderResource::reassignCourier, widgets et comp… 9.46s
  ✓ it force city_id cote serveur sur CreateOrder, CreateParcel, CreateMerchant, CreateCourier, CreateNeighbor… 17.39s
  ✓ it est fail-closed (0 enregistrement, 0 stat, 403 mutation et policy) si un admin local a city_id null       1.69s

   PASS  Tests\Feature\Admin\FormWorkflowAndMediaTest
  ✓ it masque Categories Activite et Types de Vehicules du menu principal et les rattache aux sections partenai… 4.01s
  ✓ it permet de créer une catégorie d'activité avec emoji et logo uploadé exposés par l'API                     2.88s
  ✓ it permet de créer un commerce avec nouveau gestionnaire, photo, logo et emoji puis l'expose à l'app mobile  5.27s
  ✓ it permet de créer un livreur avec upload photo réel                                                         3.86s
  ✓ it charge tous les formulaires harmonisés avec listes déroulantes sans erreur                                5.50s

   PASS  Tests\Feature\Admin\ParcelCodeUnlockSecurityTest
  ✓ unlock sender code requires client called checkbox and calls service                                         7.45s
  ✓ unlock recipient code requires client called checkbox and calls service                                      7.21s

   PASS  Tests\Feature\Admin\PaymentMethodsChartTest
  ✓ it compte correctement les commandes et colis en espèces, Orange Money (orange_money) et autre mode dans Pa… 0.91s

   PASS  Tests\Feature\Admin\PendingValidationsTest
  ✓ it affiche les livreurs en attente de validation sur la page des validations                                 0.81s
  ✓ it permet de basculer entre les onglets livreurs et commerces sur la page de validation                      1.47s

   PASS  Tests\Feature\Admin\RefundAndRoleValidationTest
  ✓ it utilise id et total_client sur ListRefunds sans propriete inexistante                                     5.61s
  ✓ it refuse sur ListRefunds une commande en statut non éligible ou non payée sans créer de refund ni wallet    4.88s
  ✓ it affiche le message validation francais quand aucun onglet nest coche sur RoleHabilitation                 2.84s

   PASS  Tests\Feature\Admin\RoleHabilitationFallbackTest
  ✓ user has habilitation tab fallback without db relation                                                       0.36s
  ✓ role habilitation seeded and migrated matches cdc                                                            0.61s

   PASS  Tests\Feature\Api\CatalogSyncTest
  ✓ it expose les noms, emojis et logos des catégories via l'API client                                          0.54s
  ✓ it expose les noms, emojis, logo_url et photo_url des commerces via l'API client                             0.55s

   PASS  Tests\Feature\Api\ClientExperienceTest
  ✓ it reflète l'état réel du mode pluie de la ville dans city-status                                            0.51s
  ✓ it permet à un client d'ajouter, modifier, choisir par défaut et supprimer ses adresses de livraison         0.64s
  ✓ it bloque la modification d'une adresse appartenant à un autre client (403)                                  0.51s
  ✓ it permet à un client d'ajouter et retirer un commerce de ses favoris                                        0.87s
  ✓ it retourne une liste de quartiers vide si aucun quartier en base et expose uniquement les quartiers actifs… 0.61s
  ✓ it expose photo_url et city_name issus de la relation ville dans le profil client                            0.78s
  ✓ it expose un fallback null propre si photo ou ville est absente du profil client                             0.55s
  ✓ it permet à un admin de gérer Villes et Quartiers avec formulaires vides sans préremplissage                 4.04s

   PASS  Tests\Feature\Api\ProfileManagementTest
  ✓ it client : peut lire, mettre à jour son profil, uploader une photo et remplacer l'ancienne                  0.85s
  ✓ it client : rejette les données invalides (422) et bloque la modification d'un autre profil (403)            0.69s
  ✓ it client : un driver ne peut pas modifier le profil client (403)                                            0.65s
  ✓ it driver : peut lire, mettre à jour véhicule/permis/photo et rejette accès non autorisé (403)               0.69s
  ✓ it driver : un client ne peut pas accéder au profil driver (403)                                             0.47s
  ✓ it business : peut lire, modifier boutique/catégorie/emoji/logo/horaires et bloque autre rôle (403)          0.77s
  ✓ it business : un driver ne peut pas modifier le profil business (403)                                        0.75s
  ✓ it profil : permet de changer son mot de passe après vérification de l'ancien                                0.71s

   PASS  Tests\Feature\Api\V1\GoColis\GoColisDriverFlowTest
  ✓ it GoColis accept nominal: un livreur actif et en ligne accepte un colis pending ou waiting avec lockForUpd… 0.56s
  ✓ it GoColis accept nominal: un livreur actif et en ligne accepte un colis pending ou waiting avec lockForUpd… 0.60s
  ✓ it GoColis accept hors ville: refuse 404 sans fuite de nom de ville                                          0.48s
  ✓ it GoColis accept déjà pris et concurrence: retourne 409 sans écraser le premier livreur                     0.62s
  ✓ it GoColis accept disponibilité: refuse 403 si livreur inactif, pénalisé ou hors ligne with dataset "courie… 0.45s
  ✓ it GoColis accept disponibilité: refuse 403 si livreur inactif, pénalisé ou hors ligne with dataset "user i… 0.77s
  ✓ it GoColis accept disponibilité: refuse 403 si livreur inactif, pénalisé ou hors ligne with dataset "hors l… 0.62s
  ✓ it GoColis accept disponibilité: refuse 403 si livreur inactif, pénalisé ou hors ligne with dataset "pénali… 0.93s
  ✓ it GoColis accept disponibilité: refuse 403 si livreur inactif, pénalisé ou hors ligne with dataset "3 refu… 0.81s
  ✓ it GoColis accept courses.max_actives: refuse 409 si une course active (GoShop ou GoColis) existe déjà       0.92s
  ✓ it GoColis accept espèces: refuse 422 sans solde minimum/disponible suffisant et autorise 200 avec solde su… 1.01s
  ✓ it GoColis refuse: 3 refus consécutifs appliquent la pénalité 15 min hors ligne et relancent le livreur sui… 1.27s
  ✓ it GoColis notifications réelles: NotifierLivreurGoColis envoie la même notification que GoShop et escalade… 0.63s
  ✓ it GoColis Filament: l'affectation manuelle d'un livreur sur ParcelResource continue de fonctionner          2.88s
  ✓ it GoColis refuse après acceptation: un colis jugé interdit ou suspect passe au statut refused (CDC Annexe…  0.52s

   PASS  Tests\Feature\Api\V1\GoColis\GoColisTest
  ✓ it un client peut créer une expédition GoColis                                                               0.94s
  ✓ it calcule correctement l'assurance pour une valeur déclarée > 20000 F CFA                                   0.63s
  ✓ it ne calcule pas d'assurance pour une valeur déclarée <= 20000 F CFA                                        0.80s
  ✓ it le livreur peut valider le code expéditeur correct après avoir pris la photo                              0.81s
  ✓ it le livreur ne peut pas valider le code expéditeur sans avoir pris la photo                                0.91s
  ✓ it bloque l'expédition après 3 codes expéditeur incorrects                                                   0.59s
  ✓ it valide correctement le code destinataire et crédite le livreur                                            0.68s
  ✓ it le livreur voit la taille du colis avant d'accepter une course GoColis                                    0.76s
  ✓ it calcule les frais d'expédition GoColis selon la taille et la distance                                     0.52s

   PASS  Tests\Feature\Api\V1\GoColis\ParcelCodeSecurityTest
  ✓ it refuse (403) un livreur sur un colis Parcel sans courier_id                                               0.54s
  ✓ it refuse (403) le livreur A sur un colis Parcel assigné au livreur B                                        0.71s
  ✓ it autorise le livreur assigné et les rôles super_admin et dg_national sur un colis Parcel                   1.01s
  ✓ it rejette le code destinataire au stade de ramassage d'un colis Parcel                                      0.52s
  ✓ it bloque les deux codes d'un colis Parcel après 3 mauvais codes et n'écrit aucun code dans les logs         0.78s

   PASS  Tests\Feature\Api\V1\GoColis\RatingAndInsuranceTest
  ✓ un client peut noter un livreur post-livraison avec recalcul automatique de la note                          0.52s
  ✓ un client ne peut pas noter une commande qui ne lui appartient pas                                           0.67s
  ✓ un admin habilité peut valider le remboursement de l'assurance d'un colis                                    0.49s
  ✓ un colis assuré simplement in_transit non déclaré perdu ou non payé est refusé 422 au remboursement          0.44s

   PASS  Tests\Feature\Api\V1\GoShop\CommissionServiceTest
  ✓ it calcule le barème GoShop CDC 8.3 en tarif normal, pointe seule, pluie seule et pluie+pointe               0.32s
  ✓ it vérifie les bornes horaires des heures de pointe GoShop (11:29, 11:30, 14:00, 14:01 et soirée)            0.29s
  ✓ it calcule le barème GoColis CDC 8.2/8.4 (cas 4 km moyen = 1 800 F CFA et partage 75%/25%)                   0.28s

   PASS  Tests\Feature\Api\V1\GoShop\OrderTest
  ✓ it un client peut créer une commande GoShop pour un commerce ouvert                                          0.57s
  ✓ it le commerce peut accepter une commande et passer au statut accepted                                       0.41s
  ✓ it le commerce peut refuser une commande avec motif                                                          0.65s
  ✓ it le livreur peut confirmer la livraison avec le code client et créditer les wallets                        0.61s

   PASS  Tests\Feature\Api\V1\Wallet\WalletServiceTest
  ✓ it crédite correctement le wallet du livreur après une livraison GoShop                                      0.43s
  ✓ it accumule correctement plusieurs crédits sur le même wallet                                                0.46s
  ✓ it crédite correctement le wallet du merchant après une livraison GoShop                                     0.58s
  ✓ it crédite correctement le wallet du livreur après une livraison GoColis                                     0.36s
  ✓ it crée exactement une WalletTransaction par opération                                                       0.39s
  ✓ it chaque transaction a une référence unique                                                                 0.43s
  ✓ it ne crée pas de wallet pour un client                                                                      0.26s
  ✓ it un City Support peut valider un remboursement inférieur à 2000 F CFA                                      0.30s

   PASS  Tests\Feature\Api\V1\Webhook\PaymentWebhookTest
  ✓ un webhook avec signature HMAC invalide est rejeté avec HTTP 401                                             0.37s
  ✓ un webhook Hub2 valide passe le statut du paiement en paid                                                   0.43s
  ✓ un paiement échoué passe la commande en cancelled avec motif                                                 0.59s
  ✓ le traitement des webhooks est idempotent face aux doublons                                                  0.54s
  ✓ un admin peut rejouer un webhook valide échoué                                                               0.47s
  ✓ un webhook avec signature invalide ne peut pas être rejoué                                                   0.33s
  ✓ le compteur mensuel SMS s'incrémente et alerte au seuil de 2500                                              0.28s

   PASS  Tests\Feature\Auth\AuthTest
  ✓ it un client peut demander un OTP pour s'inscrire                                                            0.34s
  ✓ it un numéro déjà existant ne peut pas s'inscrire à nouveau                                                  0.42s
  ✓ it un client peut vérifier son OTP et créer son compte                                                       0.36s
  ✓ it un OTP incorrect est refusé                                                                               0.36s
  ✓ it un OTP expiré est refusé                                                                                  0.38s
  ✓ it le rate limiting bloque après 3 tentatives d'inscription                                                  0.47s
  ✓ it un utilisateur existant peut demander un OTP de connexion                                                 0.28s
  ✓ it un numéro inconnu ne peut pas se connecter                                                                0.49s
  ✓ it la connexion d'un merchant révoque l'ancienne session                                                     0.55s
  ✓ it un access token peut être renouvelé avec un refresh token valide                                          0.37s
  ✓ it un utilisateur connecté peut se déconnecter                                                               0.44s
  ✓ it conserve le nom du client lors de l'inscription même si verify-register ne le renvoie pas                 0.46s
  ✓ it réinitialise le rate limit de connexion après vérification OTP réussie                                    0.65s

   PASS  Tests\Feature\Codes\EncryptedConfirmationCodesTest
  ✓ it P7 Point 1 GoShop: stocke confirmation_code_enc, masque dans JSON generiques et autorise seulement le cl… 0.79s
  ✓ it P7 Point 1 GoColis: stocke sender_code_enc, bloque tiers/apres picked_up et ne permet jamais de lire rec… 0.79s
  ✓ it P7 Point 1 legacy et regeneration admin: renvoie 409 sur code sans chiffrement et regenere de facon trac… 5.74s

   PASS  Tests\Feature\Dispatch\AfterCommitJobDispatchTest
  ✓ it P6 Point 8: envoie les jobs apres commit sous Queue::fake() et ne pousse rien en cas de rollback          0.85s
  ✓ it P6 Point 8 panne Redis: capture exception queue apres commit, log critical et conserve la commande et le… 0.66s

   PASS  Tests\Feature\Dispatch\AssignmentMaxActivesTest
  ✓ it P6 Point 3: AssignmentService exclut un livreur ayant deja atteint courses.max_actives pour GoShop et Go… 0.60s
  ✓ it P6 Point 3 settings: AssignmentService autorise jusqu a courses.max_actives personnalise puis exclut ens… 0.69s

   PASS  Tests\Feature\Dispatch\ClientLifecycleNotificationsTest
  ✓ it P6 Point 5: notifie le client (DB + push) lors de acceptOrder et confirmPickup sans notifier sur conflit  0.84s
  ✓ it P6 Point 5 GoColis: notifie le client (DB + push) lorsque acceptParcel reussit et pas sur 409             0.72s

   PASS  Tests\Feature\Dispatch\CourierLocationFreshnessTest
  ✓ it Point C1: CourierFactory et CreateCourier laissent current_lat, current_lng et location_updated_at a nul… 3.16s
  ✓ it Point C2: AssignmentService exclut les livreurs sans GPS ou dont location_updated_at > 2 min (parametrab… 0.78s

   PASS  Tests\Feature\Dispatch\DriverRefuseOrderTest
  ✓ it P6 Point 2: POST /driver/orders/{order}/refuse incremente consecutive_refusals, applique la penalite apr… 0.82s
  ✓ it P6 Point 2 settings: refuseOrder lit refus_avant_penalite et duree_penalite_minutes depuis SettingsServi… 0.80s
  ✓ it P6 Point 2 securite: refuseOrder rejette autre ville 404, deja assignee 409, mauvais statut 422 et hors…  0.67s

   PASS  Tests\Feature\Dispatch\GoShopAssignmentAndTimeoutTest
  ✓ it P6 Point 1: NotifierLivreurGoShop exclut le livreur sans reponse apres 30s, passe au suivant puis passe…  0.95s
  ✓ it P6 Point 1 idempotence: NotifierLivreurGoShop ne relance pas une commande deja acceptee par un livreur    0.50s

   PASS  Tests\Feature\Dispatch\MerchantOpenHoursTest
  ✓ it P6 Point 6: rejette 422 si commande GoShop hors open_hours configure et autorise dedans ou sans horaires  0.63s

   PASS  Tests\Feature\Dispatch\OrderBackupSweepCommandTest
  ✓ it planifie orders:sweep-timeouts toutes les minutes avec withoutOverlapping et onOneServer                  0.45s
  ✓ it balaye les commandes pending et accepted/ready bloquees sans toucher waiting ni doubler a l execution re… 1.11s
  ✓ it lit dynamiquement les delais et marges depuis SettingsService sans constantes hardcodees                  0.89s

   PASS  Tests\Feature\Dispatch\SettingsDelaysAndTimeoutTest
  ✓ it P6 Point 7: AnnulerCommandeTimeout annule pending apres 45s, laisse accepted intact et lit acceptation_c… 0.90s
  ✓ it P6 Point 7: erreurs_code_avant_blocage et acceptation_livreur_secondes lus depuis SettingsService         0.85s

   PASS  Tests\Feature\Emergency\EmergencyModeBlockingTest
  ✓ it bloque la création de commande GoShop et expédition GoColis si la ville est en mode urgence               1.12s

   PASS  Tests\Feature\Filament\CityContextFilamentScopingTest
  ✓ it cloisonne strictement les ressources Filament par ville pour un admin local                               0.52s

   PASS  Tests\Feature\Finance\CashFlowSecurityTest
  ✓ it 1.1a refuse 422 acceptOrder en espèces si le solde wallet est inférieur au seuil paramétré wallet.solde_… 0.64s
  ✓ it 1.1b refuse 422 acceptOrder en espèces si le solde disponible après engagements cash existants est infér… 0.48s
  ✓ it 1.1 concurrence: deux acceptations simultanées dépassant le solde ne laissent passer qu'une seule course… 0.52s
  ✓ it Arbitrage 2 AssignmentService ne propose pas une course ou un colis cash à un livreur sans solde minimum… 0.53s
  ✓ it 1.2 GoShop espèces 4000 + 800 : débite -4160 livreur, crédite +3400 commerce, aucun +640 livreur, paid_c… 0.56s
  ✓ it 1.3 GoColis espèces (avec et sans assurance) débite part plateforme + assurance et passe payment_status…  0.57s
  ✓ it 1.4 paiement non cash (orange_money) conserve le crédit normal livreur + commerce                         0.50s
  ✓ it 1.5 idempotence : double validation et index unique partiel wallet_transactions empêchent tout double mo… 0.58s
  ✓ it 1.6 invariant comptable global (part commerce + livreur + plateforme = total payé) et rollback atomique…  0.61s

   PASS  Tests\Feature\Finance\CashLimitAtAcceptRattrapageTest
  ✓ it refuse acceptOrder, acceptParcel et dispatch auto cash avec 2 courses cash actives et solde juste au-des… 1.03s
  ✓ it documente le rollback atomique 422 si le debit de reversement cash echoue a la livraison                  0.86s

   PASS  Tests\Feature\Finance\RefundSecurityTest
  ✓ it 2.1 plafonds depuis SettingsService et frontières exactes 1999/2000/2001/10000/10001/50000/50001 par rôl… 0.47s
  ✓ it 2.1 plafonds depuis SettingsService et frontières exactes 1999/2000/2001/10000/10001/50000/50001 par rôl… 0.41s
  ✓ it 2.1 plafonds depuis SettingsService et frontières exactes 1999/2000/2001/10000/10001/50000/50001 par rôl… 0.39s
  ✓ it 2.1 plafonds depuis SettingsService et frontières exactes 1999/2000/2001/10000/10001/50000/50001 par rôl… 0.38s
  ✓ it 2.1 plafonds depuis SettingsService et frontières exactes 1999/2000/2001/10000/10001/50000/50001 par rôl… 0.42s
  ✓ it 2.1 plafonds depuis SettingsService et frontières exactes 1999/2000/2001/10000/10001/50000/50001 par rôl… 0.40s
  ✓ it 2.1 plafonds depuis SettingsService et frontières exactes 1999/2000/2001/10000/10001/50000/50001 par rôl… 0.40s
  ✓ it 2.1 plafonds depuis SettingsService et frontières exactes 1999/2000/2001/10000/10001/50000/50001 par rôl… 0.56s
  ✓ it 2.1 plafonds depuis SettingsService et frontières exactes 1999/2000/2001/10000/10001/50000/50001 par rôl… 0.59s
  ✓ it 2.1 plafonds depuis SettingsService et frontières exactes 1999/2000/2001/10000/10001/50000/50001 par rôl… 0.69s
  ✓ it 2.1 plafonds depuis SettingsService et frontières exactes 1999/2000/2001/10000/10001/50000/50001 par rôl… 0.69s
  ✓ it 2.1 plafonds depuis SettingsService et frontières exactes 1999/2000/2001/10000/10001/50000/50001 par rôl… 0.76s
  ✓ it 2.1 plafonds depuis SettingsService et frontières exactes 1999/2000/2001/10000/10001/50000/50001 par rôl… 0.67s
  ✓ it 2.1 plafonds depuis SettingsService et frontières exactes 1999/2000/2001/10000/10001/50000/50001 par rôl… 0.53s
  ✓ it 2.1 plafonds depuis SettingsService et frontières exactes 1999/2000/2001/10000/10001/50000/50001 par rôl… 0.60s
  ✓ it 2.1 plafonds depuis SettingsService et frontières exactes 1999/2000/2001/10000/10001/50000/50001 par rôl… 0.59s
  ✓ it 2.1 plafonds depuis SettingsService et frontières exactes 1999/2000/2001/10000/10001/50000/50001 par rôl… 0.42s
  ✓ it 2.1 plafonds depuis SettingsService et frontières exactes 1999/2000/2001/10000/10001/50000/50001 par rôl… 0.48s
  ✓ it 2.1 plafonds depuis SettingsService et frontières exactes 1999/2000/2001/10000/10001/50000/50001 par rôl… 0.46s
  ✓ it 2.1 plafonds depuis SettingsService et frontières exactes 1999/2000/2001/10000/10001/50000/50001 par rôl… 0.45s
  ✓ it 2.1 plafonds depuis SettingsService et frontières exactes 1999/2000/2001/10000/10001/50000/50001 par rôl… 0.43s
  ✓ it 2.1 plafonds depuis SettingsService et frontières exactes 1999/2000/2001/10000/10001/50000/50001 par rôl… 0.49s
  ✓ it 2.1 plafonds depuis SettingsService et frontières exactes 1999/2000/2001/10000/10001/50000/50001 par rôl… 0.50s
  ✓ it 2.1 plafonds depuis SettingsService et frontières exactes 1999/2000/2001/10000/10001/50000/50001 par rôl… 0.54s
  ✓ it 2.1 plafonds depuis SettingsService et frontières exactes 1999/2000/2001/10000/10001/50000/50001 par rôl… 0.50s
  ✓ it 2.1 plafonds depuis SettingsService et frontières exactes 1999/2000/2001/10000/10001/50000/50001 par rôl… 0.53s
  ✓ it 2.1 plafonds depuis SettingsService et frontières exactes 1999/2000/2001/10000/10001/50000/50001 par rôl… 0.43s
  ✓ it 2.1 plafonds depuis SettingsService et frontières exactes 1999/2000/2001/10000/10001/50000/50001 par rôl… 0.54s
  ✓ it 2.1 plafonds depuis SettingsService et frontières exactes 1999/2000/2001/10000/10001/50000/50001 par rôl… 0.65s
  ✓ it 2.1 plafonds depuis SettingsService et frontières exactes 1999/2000/2001/10000/10001/50000/50001 par rôl… 0.66s
  ✓ it 2.1 plafonds depuis SettingsService et frontières exactes 1999/2000/2001/10000/10001/50000/50001 par rôl… 0.38s
  ✓ it 2.1 plafonds depuis SettingsService et frontières exactes 1999/2000/2001/10000/10001/50000/50001 par rôl… 0.71s
  ✓ it 2.1 plafonds depuis SettingsService et frontières exactes 1999/2000/2001/10000/10001/50000/50001 par rôl… 0.74s
  ✓ it 2.1 plafonds depuis SettingsService et frontières exactes 1999/2000/2001/10000/10001/50000/50001 par rôl… 0.67s
  ✓ it 2.1 plafonds depuis SettingsService et frontières exactes 1999/2000/2001/10000/10001/50000/50001 par rôl… 0.68s
  ✓ it 2.1 plafonds depuis SettingsService et frontières exactes 1999/2000/2001/10000/10001/50000/50001 par rôl… 0.70s
  ✓ it 2.1 plafonds depuis SettingsService et frontières exactes 1999/2000/2001/10000/10001/50000/50001 par rôl… 0.39s
  ✓ it 2.1 source unique settings: modifier le plafond dans settings s'applique immédiatement à RefundService e… 0.79s
  ✓ it 2.2 table refunds : contraintes CHECK cible unique, client sans wallet sans 500, et double remboursement… 1.04s
  ✓ it 2.4 éligibilité : refuse 422 commande/colis non payé, statut non éligible, in_transit sans litige/perte…  1.22s
  ✓ it 2.5 & 2.6 workflow complet requested -> approved -> paid et traçabilité activity_logs sans téléphone ni…  0.72s
  ✓ it D-REMB-1 plafonds par type de colis/commande : sans assurance perdu = 2x frais, assuré perdu = valeur dé… 0.69s
   PASS  Tests\Feature\Finance\WalletTransactionImmutabilityTest
  ✓ it bloque la mise à jour Eloquent d'une transaction de portefeuille                                          0.49s
  ✓ it bloque la suppression Eloquent d'une transaction de portefeuille                                          0.30s
  ✓ it bloque la mise à jour directe SQL via Query Builder grâce au trigger PostgreSQL                           0.63s
  ✓ it bloque la suppression directe SQL grâce au trigger PostgreSQL                                             0.35s
  ✓ it rejette une transaction avec montant nul via contrainte CHECK amount <> 0                                 0.45s
  ✓ it verifie que WalletTransactionResource est strictement en lecture seule sans actions de modification       0.35s
  ✓ it permet un ajustement comptable traçable par super_admin ou national_finance avec motif obligatoire        0.45s
  ✓ it refuse un ajustement comptable si le motif est absent ou si le rôle n'est pas habilité                    0.33s
  ✓ it applique le garde-fou solde >= 0 sur les wallets conformément au CDC 7.4.1                                0.36s

   PASS  Tests\Feature\GoColis\ParcelMilestonesAndSmsTest
  ✓ it P7 Point 3: impose ordre at-sender -> photo -> code -> at-recipient -> photo -> code et notifie expedite… 0.86s
  ✓ it P7 Point 3 compatibilite: /verify-* acceptent toujours les anciens statuts sans jalon at_sender/at_recip… 0.62s

   PASS  Tests\Feature\GoColis\ParcelPhotoDiskTest
  ✓ it configure un disque parcel_photos prive hors public avec surcharge via PARCEL_PHOTOS_DISK                 0.43s
  ✓ it stocke la photo en prive sur parcel_photos, sert via temporaryUrl 15 min et rejette URL invalide ou expi… 0.59s
  ✓ it fonctionne avec Storage::fake parcel_photos pour upload et temporaryUrl                                   0.45s

   PASS  Tests\Feature\GoColis\ParcelPhotosSecurityTest
  ✓ it P7 Point 2: refuse .exe, fichiers > 5 Mo et tiers, re-encode JPEG/PNG/WebP en prive sur le disque photo   0.84s
  ✓ it P7 Point 2: impose ordre statut photo et expose URL signees uniquement a expediteur et admin ville        0.66s

   PASS  Tests\Feature\GoColis\ParcelSmsFailureTest
  ✓ it P7 Point 4: drapeau sms_failed + alerte admin sans code dans les logs lors echec SMS destinataire         0.50s
  ✓ it P7 Point 4: renvoi admin POST /admin/parcels/{id}/resend-recipient-code isole par ville et reinitialise…  2.68s

   PASS  Tests\Feature\Payment\MerchantRefusalRefundTest
  ✓ it P5 Point 7: refus commerce d'une commande déjà payée crée automatiquement un remboursement requested ave… 0.49s
  ✓ it P5 Point 7: refus commerce d'une commande cash non payée ne crée aucun remboursement (inerte tant que ca… 0.72s

   PASS  Tests\Feature\Payment\PaymentMethodSettingsSecurityTest
  ✓ it P5 D-PAIEMENT-1: paiements.modes_actifs a pour valeur par défaut ["cash"] et utilise l'enum central Paym… 0.29s
  ✓ it P5 ClientController::createOrder n'accepte que cash par défaut et réagit dynamiquement à paiements.modes… 0.82s
  ✓ it P5 ParcelController::store n'accepte que cash par défaut et réagit dynamiquement à paiements.modes_actif… 0.85s

   PASS  Tests\Feature\Payment\PaymentMethodsEndpointTest
  ✓ it P5 GET /client/payment-methods renvoie ["cash"] par défaut avec default="cash"                            0.38s
  ✓ it P5 GET /client/payment-methods reflète dynamiquement les surcharges globales et par ville de SettingsSer… 0.36s

   PASS  Tests\Feature\Payment\PaymentRattrapageTest
  ✓ it Point B1: une commande payee en ligne ne notifie pas le commerce et ne demarre pas les 45s avant payment… 0.88s
  ✓ it Point B2: une commande en ligne restee pending non payee apres 10 minutes (settings) est annulee automat… 0.53s
  ✓ it Point B3: un echec de paiement GoColis notifie l expediteur (base + push)                                 0.77s
  ✓ it Point B4: un doublon webhook repond HTTP 200 succes=true message="déjà traité" sans retraiter             0.67s

   PASS  Tests\Feature\Payment\WebhookSecurityP5Test
  ✓ it P5 Point 3: refuse de passer paid quand payload.amount diffère du total dû (5 800 F dû, webhook 100 F ->… 0.72s
  ✓ it P5 Point 3: vérifie aussi le total dû sur GoColis (shipping_fee + insurance_amount) et bloque si montant… 0.89s
  ✓ it P5 Point 4: valide la signature HMAC CinetPay avec services.cinetpay.secret_key et refuse le secret Hub2… 0.60s
  ✓ it P5 Point 5: refuse un webhook dont l'horodatage a plus de 5 minutes (payload ou header)                   0.39s
  ✓ it P5 Point 5: ignore un doublon transaction_id avec HTTP 200 "déjà traité" sans retraiter et garantit une…  0.47s

   PASS  Tests\Feature\Permissions\PermissionsTest
  ✓ it un City Manager ne voit que les commandes de sa ville                                                     0.52s
  ✓ it un City Agent ne peut pas valider un remboursement de 5000 F CFA                                          0.45s
  ✓ it un City Finance peut valider un remboursement entre 2000 et 10000 F CFA                                   0.67s
  ✓ it un City Finance reçoit 422 si la commande est en statut non éligible ou non payée                         0.44s
  ✓ it un City Finance ne peut PAS valider un remboursement supérieur à 10000 F CFA                              0.47s
  ✓ it un client ne peut pas accéder aux endpoints merchant                                                      0.51s
  ✓ it un driver ne peut pas accéder aux endpoints client                                                        0.36s
  ✓ it un merchant ne peut pas accéder aux endpoints admin                                                       0.55s
  ✓ it un utilisateur non authentifié est rejeté sur les routes protégées                                        0.62s
  ✓ it seul le Super Admin ou le DG National peut activer le Mode Urgence                                        0.47s
  ✓ it le Super Admin peut activer le Mode Urgence                                                               0.48s
  ✓ it seul un City Support ou supérieur peut débloquer un code bloqué                                           0.39s
  ✓ it un City Support peut débloquer un code bloqué                                                             0.34s

   PASS  Tests\Feature\Pricing\PricingServerDistanceSecurityTest
  ✓ it calcule la distance cote serveur par Haversine et ignore la distance envoyee par le client                0.86s
  ✓ it refuse avec une 422 si les coordonnees sont absentes ou hors ville                                        0.45s
  ✓ it valide le poids et le telephone destinataire pour GoColis                                                 0.48s
  ✓ it applique la surcharge de ville specifique sur GoColis                                                     0.47s
  ✓ it renvoie une 422 propre si le solde du livreur est insuffisant pour la livraison cash                      0.46s

   PASS  Tests\Feature\Ratings\AttemptsResetAndRatingsTest
  ✓ it P7 Point 6: remet les compteurs confirmation_attempts, sender_code_attempts et recipient_code_attempts a… 0.82s
  ✓ it P7 Point 6: enregistre type gocolis_courier sur colis et permet la notation du commerce (1-5, livree, un… 0.64s

   PASS  Tests\Feature\Security\AccountActiveSecurityTest
  ✓ it refuse avec 403 un client desactive disposant dun jeton deja emis                                         0.40s
  ✓ it refuse avec 403 un livreur desactive disposant dun jeton deja emis                                        0.44s
  ✓ it refuse le renouvellement de token refresh pour un compte desactive                                        0.33s

   PASS  Tests\Feature\Security\AdminLoginCredentialsSecurityTest
  ✓ it affiche les identifiants de test sur /admin/login uniquement lorsque APP_ENV est local                    0.50s
  ✓ it masque totalement login-credentials sur /admin/login et au rendu direct hors environnement local with ('… 0.56s
  ✓ it masque totalement login-credentials sur /admin/login et au rendu direct hors environnement local with ('… 0.51s
  ✓ it masque totalement login-credentials sur /admin/login et au rendu direct hors environnement local with ('… 0.38s

   PASS  Tests\Feature\Security\ApiExceptionHandlerSecurityTest
  ✓ it renvoie un JSON standard 404 pour une route api inexistante                                               0.27s
  ✓ it renvoie un JSON standard 404 sans fuite de namespace Eloquent pour un ModelNotFound sur commande inexist… 0.44s
  ✓ it renvoie un JSON standard 500 sans fuite de trace ni chemin en production                                  0.47s
  ✓ it maintient fonctionnel le endpoint public /health conforme et non perturbe                                 0.31s

   PASS  Tests\Feature\Security\D1UnlockOrderCodeSecurityTest
  ✓ it D1 nominal: un rôle local débloque une commande de sa ville, remet attempts à 0 et journalise sans le co… 0.42s
  ✓ it D1 nominal: un rôle local débloque une commande de sa ville, remet attempts à 0 et journalise sans le co… 0.42s
  ✓ it D1 nominal: un rôle local débloque une commande de sa ville, remet attempts à 0 et journalise sans le co… 0.45s
  ✓ it D1 national: les rôles nationaux peuvent débloquer une commande de n'importe quelle ville with ('nationa… 0.54s
  ✓ it D1 national: les rôles nationaux peuvent débloquer une commande de n'importe quelle ville with ('nationa… 0.42s
  ✓ it D1 national: les rôles nationaux peuvent débloquer une commande de n'importe quelle ville with ('dg_nati… 0.65s
  ✓ it D1 national: les rôles nationaux peuvent débloquer une commande de n'importe quelle ville with ('super_a… 0.45s
  ✓ it D1 autre ville: un rôle local ne peut pas débloquer une commande d'une autre ville (404 sans fuite) with… 0.39s
  ✓ it D1 autre ville: un rôle local ne peut pas débloquer une commande d'une autre ville (404 sans fuite) with… 0.54s
  ✓ it D1 autre ville: un rôle local ne peut pas débloquer une commande d'une autre ville (404 sans fuite) with… 0.44s
  ✓ it D1 mauvais statut: refuse de débloquer une commande clôturée (delivered, cancelled, refused) with ('deli… 0.43s
  ✓ it D1 mauvais statut: refuse de débloquer une commande clôturée (delivered, cancelled, refused) with ('canc… 0.36s
  ✓ it D1 mauvais statut: refuse de débloquer une commande clôturée (delivered, cancelled, refused) with ('refu… 0.41s
  ✓ it D1 rôle interdit: refuse 403 aux rôles non habilités with ('city_agent')                                  0.35s
  ✓ it D1 rôle interdit: refuse 403 aux rôles non habilités with ('national_tech')                               0.41s
  ✓ it D1 rôle interdit: refuse 403 aux rôles non habilités with ('client')                                      0.70s
  ✓ it D1 rôle interdit: refuse 403 aux rôles non habilités with ('merchant')                                    0.53s
  ✓ it D1 rôle interdit: refuse 403 aux rôles non habilités with ('driver')                                      0.37s
  ✓ it D1 local sans city_id: refuse 404 si le rôle local n'est rattaché à aucune ville                          0.43s

   PASS  Tests\Feature\Security\D2AcceptOrderSecurityTest
  ✓ it D2 nominal: un livreur actif et en ligne accepte une course proposée (accepted ou ready) de sa ville wit… 0.68s
  ✓ it D2 nominal: un livreur actif et en ligne accepte une course proposée (accepted ou ready) de sa ville wit… 0.56s
  ✓ it D2 autre ville: refuse 404 sans fuite si la commande est dans une autre ville                             0.41s
  ✓ it D2 mauvais statut: refuse 422 si la commande n'est pas au statut accepted ou ready with ('pending')       0.73s
  ✓ it D2 mauvais statut: refuse 422 si la commande n'est pas au statut accepted ou ready with ('refused')       0.49s
  ✓ it D2 mauvais statut: refuse 422 si la commande n'est pas au statut accepted ou ready with ('picked_up')     0.42s
  ✓ it D2 mauvais statut: refuse 422 si la commande n'est pas au statut accepted ou ready with ('in_transit')    0.49s
  ✓ it D2 mauvais statut: refuse 422 si la commande n'est pas au statut accepted ou ready with ('delivered')     0.45s
  ✓ it D2 mauvais statut: refuse 422 si la commande n'est pas au statut accepted ou ready with ('cancelled')     0.47s
  ✓ it D2 disponibilité (hors ligne): refuse 422 si le livreur est hors ligne                                    0.71s
  ✓ it D2 disponibilité (suspendu): refuse 403 si le livreur ou son utilisateur est inactif with dataset "couri… 0.45s
  ✓ it D2 disponibilité (suspendu): refuse 403 si le livreur ou son utilisateur est inactif with dataset "user…  0.41s
  ✓ it D2 disponibilité (pénalité active): refuse 403 si penalty_until est dans le futur                         0.51s
  ✓ it D2 disponibilité (3 refus): applique la pénalité 15 minutes et met hors ligne sur acceptOrder             0.41s
  ✓ it D2 disponibilité (pénalité expirée): efface une pénalité passée et autorise acceptOrder                   0.62s
  ✓ it D2 concurrence et propriétaire: deux livreurs acceptent la même commande -> un seul succès (200) et l'au… 0.59s
  ✓ it D2 atomicité conditionnelle: si une mise à jour concurrente assigne courier_id avant l'update conditionn… 0.46s
  ✓ it D2 rôle interdit: refuse 403 aux rôles non livreurs with ('client')                                       0.52s
  ✓ it D2 rôle interdit: refuse 403 aux rôles non livreurs with ('merchant')                                     0.42s
  ✓ it D2 rôle interdit: refuse 403 aux rôles non livreurs with ('city_support')                                 0.41s
  ✓ it D2 rôle interdit: refuse 403 aux rôles non livreurs with ('super_admin')                                  0.53s

   PASS  Tests\Feature\Security\D3ConfirmPickupSecurityTest
  ✓ it D3 nominal: le livreur assigné de la même ville confirme le ramassage depuis ready -> picked_up (CDC Ann… 0.49s
  ✓ it D3 autre ville: refuse 404 sans fuite si la commande est dans une autre ville même avec incohérence       0.42s
  ✓ it D3 mauvais propriétaire: refuse 403 si la commande est non assignée ou assignée à un autre livreur with…  0.42s
  ✓ it D3 mauvais propriétaire: refuse 403 si la commande est non assignée ou assignée à un autre livreur with…  0.74s
  ✓ it D3 mauvais statut: refuse 422 pour tout statut hors ready (dont accepted et waiting) with ('pending')     0.62s
  ✓ it D3 mauvais statut: refuse 422 pour tout statut hors ready (dont accepted et waiting) with ('accepted')    0.39s
  ✓ it D3 mauvais statut: refuse 422 pour tout statut hors ready (dont accepted et waiting) with ('waiting')     0.49s
  ✓ it D3 mauvais statut: refuse 422 pour tout statut hors ready (dont accepted et waiting) with ('refused')     0.37s
  ✓ it D3 mauvais statut: refuse 422 pour tout statut hors ready (dont accepted et waiting) with ('picked_up')   0.36s
  ✓ it D3 mauvais statut: refuse 422 pour tout statut hors ready (dont accepted et waiting) with ('in_transit')  0.41s
  ✓ it D3 mauvais statut: refuse 422 pour tout statut hors ready (dont accepted et waiting) with ('delivered')   0.48s
  ✓ it D3 mauvais statut: refuse 422 pour tout statut hors ready (dont accepted et waiting) with ('cancelled')   0.59s
  ✓ it D3 rôle interdit: refuse 403 aux rôles non livreurs with ('client')                                       0.44s
  ✓ it D3 rôle interdit: refuse 403 aux rôles non livreurs with ('merchant')                                     0.42s
  ✓ it D3 rôle interdit: refuse 403 aux rôles non livreurs with ('city_support')                                 0.45s
  ✓ it D3 rôle interdit: refuse 403 aux rôles non livreurs with ('super_admin')                                  0.64s
  ✓ it D3 graphe CDC: OrderService respecte strictement les transitions d'états autorisées                       0.60s

   PASS  Tests\Feature\Security\D4ConfirmDeliverySecurityTest
  ✓ it D4 nominal: le livreur assigné de la même ville valide une course picked_up/in_transit avec le bon code…  0.71s
  ✓ it D4 nominal: le livreur assigné de la même ville valide une course picked_up/in_transit avec le bon code…  0.53s
  ✓ it D4 autre ville: refuse 404 sans consommer aucune tentative sur confirmDelivery hors ville                 0.42s
  ✓ it D4 non livrable + mauvais code (API): confirmation_attempts reste INCHANGÉ pour pending, accepted, ready… 0.65s
  ✓ it D4 non livrable + mauvais code (API): confirmation_attempts reste INCHANGÉ pour pending, accepted, ready… 0.43s
  ✓ it D4 non livrable + mauvais code (API): confirmation_attempts reste INCHANGÉ pour pending, accepted, ready… 0.74s
  ✓ it D4 non livrable + mauvais code (API): confirmation_attempts reste INCHANGÉ pour pending, accepted, ready… 0.71s
  ✓ it D4 non livrable + mauvais code (API): confirmation_attempts reste INCHANGÉ pour pending, accepted, ready… 0.38s
  ✓ it D4 non livrable + mauvais code (API): confirmation_attempts reste INCHANGÉ pour pending, accepted, ready… 0.67s
  ✓ it D4 service direct non livrable + mauvais code: confirmation_attempts reste INCHANGÉ dans OrderService::v… 0.57s
  ✓ it D4 service direct non livrable + mauvais code: confirmation_attempts reste INCHANGÉ dans OrderService::v… 0.35s
  ✓ it D4 service direct non livrable + mauvais code: confirmation_attempts reste INCHANGÉ dans OrderService::v… 0.66s
  ✓ it D4 service direct non livrable + mauvais code: confirmation_attempts reste INCHANGÉ dans OrderService::v… 0.59s
  ✓ it D4 service direct non livrable + mauvais code: confirmation_attempts reste INCHANGÉ dans OrderService::v… 0.37s
  ✓ it D4 service direct non livrable + mauvais code: confirmation_attempts reste INCHANGÉ dans OrderService::v… 0.35s
  ✓ it D4 mauvais propriétaire: livreur non assigné refusé avant tout incrément dans OrderService et API         0.71s
  ✓ it D4 3 mauvais codes + verrouillage puis bon code refusé sans fuite dans logs ni réponses                   0.54s
  ✓ it D4 rôle interdit: refuse 403 aux rôles non livreurs with ('client')                                       0.59s
  ✓ it D4 rôle interdit: refuse 403 aux rôles non livreurs with ('merchant')                                     0.43s
  ✓ it D4 rôle interdit: refuse 403 aux rôles non livreurs with ('city_support')                                 0.43s
  ✓ it D4 rôle interdit: refuse 403 aux rôles non livreurs with ('super_admin')                                  0.41s

   PASS  Tests\Feature\Security\D5ShowDriverParcelSecurityTest
  ✓ it D5 nominal (assigné): le livreur assigné de la même ville voit les détails complets sans les codes secre… 0.46s
  ✓ it D5 nominal (non assigné proposé): retourne uniquement taille, distance, frais et zones approximatives SA… 0.52s
  ✓ it D5 nominal (non assigné proposé): retourne uniquement taille, distance, frais et zones approximatives SA… 0.43s
  ✓ it D5 autre ville: refuse 404 sans fuite pour un colis d'une autre ville (assigné ou non) with dataset "non… 0.44s
  ✓ it D5 autre ville: refuse 404 sans fuite pour un colis d'une autre ville (assigné ou non) with dataset "ass… 0.38s
  ✓ it D5 mauvais propriétaire: refuse 403 pour un colis de la même ville assigné à un autre livreur             0.34s
  ✓ it D5 mauvais statut: refuse 422 pour un colis non assigné qui n'est plus en pending ou waiting with ('deli… 0.47s
  ✓ it D5 mauvais statut: refuse 422 pour un colis non assigné qui n'est plus en pending ou waiting with ('canc… 0.55s
  ✓ it D5 mauvais statut: refuse 422 pour un colis non assigné qui n'est plus en pending ou waiting with ('refu… 0.44s
  ✓ it D5 rôle interdit: refuse 403 aux rôles non livreurs with ('client')                                       0.31s
  ✓ it D5 rôle interdit: refuse 403 aux rôles non livreurs with ('merchant')                                     0.35s
  ✓ it D5 rôle interdit: refuse 403 aux rôles non livreurs with ('city_support')                                 0.39s
  ✓ it D5 rôle interdit: refuse 403 aux rôles non livreurs with ('city_manager')                                 0.43s
  ✓ it D5 rôle interdit: refuse 403 aux rôles non livreurs with ('super_admin')                                  0.47s

   PASS  Tests\Feature\Security\DefensiveSecurityTest
  ✓ it empêche un client d'accéder aux détails du colis d'un autre client (anti-IDOR)                            0.41s
  ✓ it un city manager ne peut pas consulter une commande d'une autre ville (anti-IDOR)                          0.38s
  ✓ it empêche la double validation et le double crédit d'une commande GoShop déjà livrée                        0.55s
  ✓ it empêche la double validation et le double crédit d'un colis GoColis déjà livré                            0.46s
  ✓ it empêche un solde négatif lors d'un retrait sur wallet                                                     0.31s

   PASS  Tests\Feature\Security\HiddenFieldsSecurityTest
  ✓ it masque device_token lors de la serialisation du modele User                                               0.31s
  ✓ it masque code_hash lors de la serialisation du modele OtpCode                                               0.26s

   PASS  Tests\Feature\Security\OtpSecurityP3Test
  ✓ it bloque et invalide l'OTP apres 5 echecs sur verify-login avec 429 en francais                             0.35s
  ✓ it bloque et invalide l'OTP apres 5 echecs sur verify-register avec 429 en francais                          0.44s
  ✓ it supprime l'enregistrement OTP apres validation reussie conformement au CDC                                0.37s
  ✓ it applique un rate limit de blocage 1h (3600s) sur la demande OTP login                                     0.22s

   PASS  Tests\Feature\Security\P1ParcelCodeCitySecurityTest
  ✓ it P1b autre ville: refuse 404 sans consommer tentative si un livreur tente verifySenderCode ou verifyRecip… 0.37s

   PASS  Tests\Feature\Security\P2aSingleActiveCourseSecurityTest
  ✓ it P2a refuse 409 une 2e course GoShop si le livreur a déjà une course active (défaut courses.max_actives =… 0.51s
  ✓ it P2a refuse 409 acceptOrder si le livreur a déjà un colis GoColis actif en cours                           0.49s
  ✓ it P2a autorise 2 courses actives lorsque le paramètre courses.max_actives vaut 2 et bloque la 3e            0.56s

   PASS  Tests\Feature\Security\P2bProfileCityImmutableSecurityTest
  ✓ it P2b interdit (422) le changement de city_id via /profile pour client, driver et merchant with dataset "c… 0.42s
  ✓ it P2b interdit (422) le changement de city_id via /profile pour client, driver et merchant with dataset "d… 0.39s
  ✓ it P2b interdit (422) le changement de city_id via /profile pour client, driver et merchant with dataset "m… 0.34s
  ✓ it P2b interdit (422) le changement de city_id via /profile pour client, driver et merchant with dataset "m… 0.35s

   PASS  Tests\Feature\Security\P2cCityAgentAccessSecurityTest
  ✓ it P2c refuse 403 au rôle city_agent sur /admin/orders et /admin/dashboard                                   0.40s
  ✓ it P2c conserve l'accès 200 aux rôles admin habilités (city_support, city_finance, city_manager, super_admi… 0.45s
  ✓ it P2c conserve l'accès 200 aux rôles admin habilités (city_support, city_finance, city_manager, super_admi… 0.36s
  ✓ it P2c conserve l'accès 200 aux rôles admin habilités (city_support, city_finance, city_manager, super_admi… 0.52s
  ✓ it P2c conserve l'accès 200 aux rôles admin habilités (city_support, city_finance, city_manager, super_admi… 0.74s

   PASS  Tests\Feature\Security\P2dSuspendedMerchantRoutesSecurityTest
  ✓ it P2d autorise uniquement GET profile et GET status pour un commerce suspendu (is_active=false) et renvoie… 0.82s

   PASS  Tests\Feature\Security\Point3DecisionsLockTest
  ✓ it Point 3 verrouille les 4 décisions D-LIVREUR-1, D-VILLE-1, D-ROLE-1, D-MERCHANT-1 dans DECISIONS.md, aud… 0.62s

   PASS  Tests\Feature\Security\RefreshTokenAndDevBypassSecurityTest
  ✓ it renouvelle access_token et refresh_token avec un refresh token opaque de 7 jours                          0.40s
  ✓ it configure le refresh_ttl par defaut a 10080 minutes (7 jours) selon le CDC 11.4                           0.40s
  ✓ it prouve qu en environnement de production AUCUN bypass dev ne fonctionne                                   0.39s
  ✓ it prouve qu en environnement local et testing AUCUN bypass dev ne fonctionne with ('testing')               0.42s
  ✓ it prouve qu en environnement local et testing AUCUN bypass dev ne fonctionne with ('local')                 0.35s

   PASS  Tests\Feature\Security\S1DgNationalCityContextSecurityTest
  ✓ it S1 modèle: dg_national est reconnu admin national, canAccessCity et timeout 120                           0.38s
  ✓ it S1 HTTP: dg_national sans city_id accède aux routes admin dashboard et emergency-mode                     0.48s

   PASS  Tests\Feature\Security\S2AdminRefundCityIsolationSecurityTest
  ✓ it S2 autre ville (order refund): refuse 404 sans fuite si un rôle local tente de rembourser une commande d… 0.53s
  ✓ it S2 autre ville (order refund): refuse 404 sans fuite si un rôle local tente de rembourser une commande d… 0.43s
  ✓ it S2 autre ville (order refund): refuse 404 sans fuite si un rôle local tente de rembourser une commande d… 0.67s
  ✓ it S2 autre ville (parcel refund): refuse 404 sans fuite si un rôle local tente d'indemniser un colis d'une… 0.68s
  ✓ it S2 autre ville (parcel refund): refuse 404 sans fuite si un rôle local tente d'indemniser un colis d'une… 0.44s
  ✓ it S2 autre ville (parcel refund): refuse 404 sans fuite si un rôle local tente d'indemniser un colis d'une… 0.42s
  ✓ it S2 national (order et parcel refund): autorise un rôle financier national sur toute ville                 0.61s
  ✓ it S2 national refuse 422 si la commande est en statut pending ou non payée                                  0.47s

   PASS  Tests\Feature\Security\S3WebhookReplaySecurityTest
  ✓ it S3 rôles interdits: refuse 403 aux rôles locaux et non habilités sur webhooks/{id}/replay with ('city_ag… 0.40s
  ✓ it S3 rôles interdits: refuse 403 aux rôles locaux et non habilités sur webhooks/{id}/replay with ('city_su… 0.40s
  ✓ it S3 rôles interdits: refuse 403 aux rôles locaux et non habilités sur webhooks/{id}/replay with ('city_fi… 0.33s
  ✓ it S3 rôles interdits: refuse 403 aux rôles locaux et non habilités sur webhooks/{id}/replay with ('city_ma… 0.37s
  ✓ it S3 rôles interdits: refuse 403 aux rôles locaux et non habilités sur webhooks/{id}/replay with ('nationa… 0.39s
  ✓ it S3 rôles interdits: refuse 403 aux rôles locaux et non habilités sur webhooks/{id}/replay with ('dg_nati… 0.37s
  ✓ it S3 rôles interdits: refuse 403 aux rôles locaux et non habilités sur webhooks/{id}/replay with ('client'… 0.40s
  ✓ it S3 rôles interdits: refuse 403 aux rôles locaux et non habilités sur webhooks/{id}/replay with ('merchan… 0.54s
  ✓ it S3 rôles interdits: refuse 403 aux rôles locaux et non habilités sur webhooks/{id}/replay with ('driver'… 0.37s
  ✓ it S3 rôles autorisés: autorise national_tech et super_admin sur webhooks/{id}/replay with ('national_tech'… 0.57s
  ✓ it S3 rôles autorisés: autorise national_tech et super_admin sur webhooks/{id}/replay with ('super_admin')   0.51s
  ✓ it S3 filament replay action: visible uniquement pour super_admin et national_tech                           1.43s
  ✓ it P5 Point 6: bouton Rejouer Filament dispatche réellement ProcessPaymentWebhook, met à jour la commande e… 2.44s

   PASS  Tests\Feature\Security\S4RatingSecurityTest
  ✓ it S4 refuse de noter une commande non livrée ou avec un mauvais livreur et bloque les doublons              0.66s
  ✓ it S4 refuse de noter un colis d'un autre expéditeur, non livré ou avec mauvais livreur                      0.61s

   PASS  Tests\Feature\Security\S5ClientCatalogAndOrderSecurityTest
  ✓ it S5 refuse 404 le catalogue et l'ajout en favori d'un commerce inactif                                     0.63s
  ✓ it S5 commande GoShop: refuse 422 un produit indisponible ou d'un autre commerce et aligne city_id sur le c… 0.57s

   PASS  Tests\Feature\Security\S6DriverAvailabilitySecurityTest
  ✓ it S6 availability: refuse 403 si le livreur est suspendu, pénalisé ou a 3 refus consécutifs                 0.45s
  ✓ it S6 location et nominal: refuse updateLocation si inactif et autorise mise en ligne si pénalité expirée    0.36s

   PASS  Tests\Feature\Security\S8AssignmentCityIsolationSecurityTest
  ✓ it S8 isolation ville et statut: attribuerLivreurGoShop n'assigne pas un livreur hors ligne/inactif d'une a… 0.37s

   PASS  Tests\Feature\Security\SingleSessionSecurityTest
  ✓ it invalide l'ancien JWT lors d'une nouvelle connexion d'un livreur et refuse l'ancien avec 401              0.58s
  ✓ it invalide l'ancien JWT lors d'une nouvelle connexion d'un commercant et refuse l'ancien avec 401           0.76s
  ✓ it autorise le multi-appareils pour les clients sans invalider les anciens tokens                            0.65s

   PASS  Tests\Feature\Security\SweepIdorSecurityTest
  ✓ it balayage IDOR horizontal & vertical famille CLIENT (orders, parcels, addresses, notifications, ratings)   0.59s
  ✓ it balayage IDOR horizontal & vertical famille MERCHANT (orders/*, products/*, status)                       0.61s
  ✓ it balayage IDOR horizontal & vertical famille DRIVER (orders/*, parcels/*, verify-codes)                    0.56s
  ✓ it balayage IDOR horizontal & vertical famille PROFILE et ADMIN                                              0.83s

   PASS  Tests\Feature\Security\ThrottleAndLocationSecurityTest
  ✓ it valide strictement les coordonnees GPS entre -90/90 et -180/180 sur driver/location                       0.56s
  ✓ it bloque les requetes de positionnement livreur a plus de 1 requete par 5 secondes                          0.63s
  ✓ it configure les throttles de groupe auth a 60 par minute et public a 20 par minute                          0.43s

   PASS  Tests\Feature\Settings\SettingsCdcAndPersistenceTest
  ✓ it 4.1 SettingsService::DEFAULTS correspond exactement aux chapitres 8.1, 8.2, 8.3 et 8.4 du CDC             0.23s
  ✓ it 4.2 SettingsPage affiche les libellés explicites et l'exemple chiffré pour livreur_goshop et plateforme_… 1.86s
  ✓ it 4.3 l'enregistrement sur SettingsPage d'un seul groupe ne supprime ni n'écrase aucune clé absente du for… 4.48s

   PASS  Tests\Feature\Settings\SettingsTest
  ✓ settings service returns cdc defaults                                                                        0.32s
  ✓ setting modification writes to activity logs and invalidates cache                                           0.37s
  ✓ city specific override takes precedence                                                                      0.45s
  ✓ commission service uses dynamic settings                                                                     0.33s
  ✓ branding returns default emoji when no logo uploaded                                                         0.78s
  ✓ admin settings page uploads and removes golivreur logo                                                      11.26s

   PASS  Tests\Feature\Simulation\MultiAgentSimulationTest
  ✓ simulation complete multi agents goshop daloa                                                                1.03s
  ✓ simulation complete multi agents gocolis daloa                                                               0.85s

   PASS  Tests\Feature\Tracking\CourierLocationPollingTest
  ✓ it P7 Point 5 GoShop: GET /client/orders/{order}/courier-location autorise seulement proprietaire sur comma… 0.54s
  ✓ it P7 Point 5 GoColis: GET /client/parcels/{parcel}/courier-location autorise seulement expediteur sur coli… 0.51s

  Tests:    442 passed (3047 assertions)
  Duration: 378.33s

EXIT_PEST=0
```

### 5. Sorties `flutter analyze` et `flutter test` (3 apps mobiles non modifiées)

```text
=== FLUTTER ANALYZE golivreur_client ===
Analyzing golivreur_client...
No issues found! (ran in 151.3s)
EXIT_ANALYZE_golivreur_client=0

=== FLUTTER TEST golivreur_client ===
00:00 +0: loading C:/Projets/golivreur/mobile/apps/golivreur_client/test/widget_test.dart
00:00 +0: GoLivreur Client - affiche AnimatedSplashScreen au démarrage
00:02 +1: GoLivreur Client - affiche LoginScreen par défaut
00:03 +2: MainNavigationScreen - affiche la barre avec 4 onglets
00:05 +3: All tests passed!
EXIT_TEST_golivreur_client=0

=== FLUTTER ANALYZE golivreur_business ===
Analyzing golivreur_business...
No issues found! (ran in 7.0s)
EXIT_ANALYZE_golivreur_business=0

=== FLUTTER TEST golivreur_business ===
00:00 +0: loading C:/Projets/golivreur/mobile/apps/golivreur_business/test/widget_test.dart
00:00 +0: GoLivreur Business - affiche AnimatedSplashScreen au démarrage
00:01 +1: GoLivreur Business smoke test - démarrage application
00:02 +2: BusinessMainNavigationScreen - affiche les 4 onglets avec Profil
00:04 +3: All tests passed!
EXIT_TEST_golivreur_business=0

=== FLUTTER ANALYZE golivreur_driver ===
Analyzing golivreur_driver...
No issues found! (ran in 38.4s)
EXIT_ANALYZE_golivreur_driver=0

=== FLUTTER TEST golivreur_driver ===
00:00 +0: loading C:/Projets/golivreur/mobile/apps/golivreur_driver/test/widget_test.dart
00:00 +0: GoLivreur Driver - affiche AnimatedSplashScreen au démarrage
00:02 +1: GoLivreur Driver smoke test - démarrage application
00:03 +2: DriverMainNavigationScreen - affiche les 4 onglets avec Profil
00:04 +3: All tests passed!
EXIT_TEST_golivreur_driver=0
```

