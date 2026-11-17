# Rapport de Correction — Lot P8 : Passerelle SMS, FCM, Horizon, Sentry, Scheduler, `/health`, Audit Cash & Mémoire PHPUnit

**Date :** 17 novembre 2026  
**Branche :** `develop` (base avant lot : `891c44e`, dernier commit fonctionnel avant rapport : `1c6d234`)  
**Périmètre :** Points 1 à 8 + alignement de test `RefreshTokenAndDevBypassSecurityTest` et fix Pint EOF `SchedulerConfigurationTest` (aucun `git push`).

---

## 1. Synthèse Globale

| Point | Objet | Statut | Commit |
|---|---|---|---|
| Point 1 | Passerelle SMS (`SmsGateway`, `YellikaSmsGateway`, `LogSmsGateway`, table `sms_logs`, alerte quotidienne, masquage `225 XX ** ** YY`) | Vérifié (Rouge → Vert) | `00ef32e` |
| Point 2 | Notifications Push FCM réelles (`kreait/laravel-firebase ^7.2`, canaux Android `orders_new`/`courses_new`/`general`, TTL 60 s haute priorité, `POST /api/v1/devices/token`, nettoyage `404`/`400`) + `composer.lock` | Vérifié (Rouge → Vert) | `0106a28` |
| Point 3 | Laravel Horizon (superviseurs séparés `high`, `default`, `low`, gate strict `super_admin`/`national_tech` actifs sans bypass `local`, `NotifierLivreurGoShop`/`GoColis` sur `high`, `horizon:snapshot` & `queue:prune-failed --hours=168`) | Vérifié (Rouge → Vert) — daemon non lancé sous Windows (`ext-pcntl`/`ext-posix` absentes) | `b9df196` |
| Point 4 | Sentry (`sentry/sentry-laravel ^4.30`, `Integration::handles($exceptions)`, `send_default_pii=false`, `traces_sample_rate=0.1`, filtre `before_send` cacheable `SentryEventScrubber`) + `composer.lock` | Vérifié (Rouge → Vert) | `bbba028` |
| Point 5 | Planificateur (`schedule:list`, 3 tâches avec `withoutOverlapping()` et `onOneServer()`, heartbeat cache `orders:sweep-timeouts`) | Vérifié (Rouge → Vert) | `4ed6cf1` (+ style EOF Pint `2d237fc`) |
| Point 6 | Endpoint `GET /health` (public minimal `{status}`, diagnostics complets via header `HEALTH_TOKEN` / `X-Health-Token`, retrait du secret codé en dur `'golivreur-secret-health-token'`) | Vérifié (Rouge → Vert) | `9f825a5` |
| Point 7 | Audit comptable & flux cash (GoShop 25 000 + 1 500 F CFA, GoColis, incohérences CDC 7.4.1 vs réalité terrain) | Analysé sans modifier le code | — (rapport) |
| Point 8 | Mémoire PHPUnit (`<ini name="memory_limit" value="512M"/>` dans `phpunit.xml`, exécution `./vendor/bin/pest` sans `-d`) + alignement Yellika sur test `production` | Vérifié (Rouge OOM → Vert 468/468) | `ecd9a7c` (+ test prod SMS `1c6d234`) |

---

## 2. Dépendances & Notes Plateforme Windows

1. **Dépendances ajoutées dans `backend/composer.json` + verrouillées dans `backend/composer.lock` :**
   - `kreait/laravel-firebase: ^7.2` (`7.2.1` + `kreait/firebase-php 8.6.0`, commit `0106a28`).
   - `sentry/sentry-laravel: ^4.30` (`4.30.0` + `sentry/sentry 4.37.0`, commit `bbba028`).
   - `laravel/horizon` (`^5.24` / `v5.50.1`) était déjà présent dans `composer.json` / `composer.lock`.
2. **Contournement requis sous Windows lors de `composer require` :**
   - Environnement vérifié : Laravel `v12.70.2`, PHP `8.5.12`.
   - `composer require kreait/laravel-firebase` a initialement échoué car `laravel/horizon v5.50.1` requiert `ext-pcntl *` et `ext-posix *` (extensions Unix absentes de PHP Windows). Les deux installations ont été exécutées avec `--ignore-platform-req=ext-pcntl --ignore-platform-req=ext-posix`. En production/staging Linux (Docker/Ubuntu), ces extensions doivent être actives (`ext-pcntl`, `ext-posix`, `ext-redis` / `phpredis`).
   - Conformément aux instructions, `php artisan horizon` n'a **pas** été démarré sous Windows ; sa configuration, ses routes, sa gate d'accès, son dispatching par file et ses tâches planifiées ont été validés par tests et `schedule:list`.
3. **État `golivreur_dev` :** aucune commande `migrate:fresh`, `db:wipe` ni seeder n'a été exécutée sur `golivreur_dev` (`php artisan migrate:status` montre les migrations P5/P6/P7/P8 toujours `Pending` sur `golivreur_dev`, donc à exécuter par `php artisan migrate` en staging/production lors du déploiement).
4. **Justification écrite sur la modification de test (`1c6d234`) :**
   - Aucun test n'a été affaibli ni supprimé.
   - `tests/Feature/Security/RefreshTokenAndDevBypassSecurityTest.php` (« *it prouve qu en environnement de production AUCUN bypass dev ne fonctionne* ») forçait `app()->detectEnvironment(fn () => 'production')` tout en laissant `sms.driver` à `'log'` (valeur par défaut de `phpunit.xml`), puis appelait `/api/v1/auth/register` et `/api/v1/auth/login` en attendant HTTP `200`. Depuis le Point 1 (`LogSmsGateway` interdit en `production`), cet envoi échoue volontairement en `503`. Le test a donc été aligné (`sms.driver='yellika'` + `Http::fake(['panel.yellikasms.com/*' => ...])`) afin de simuler le pilote de production tout en conservant intactes 100 % de ses assertions anti-bypass et d'absence de `debug_otp`.

---

## 3. Détail Point par Point (Fichiers & Preuves Rouge → Vert)

### Point 1 — Passerelle SMS (`00ef32e`)
- **Fichiers modifiés / créés (19 fichiers) :**
  - `backend/config/sms.php` (créé), `backend/config/services.php`, `backend/.env.example`
  - `backend/app/Support/PhoneHelper.php` (`toE164`, `toYellikaRecipient`, `mask` -> `225 07 ** ** 12`)
  - `backend/app/Services/Sms/SmsResult.php`, `backend/app/Services/Sms/Contracts/SmsGateway.php`, `backend/app/Services/Sms/LogSmsGateway.php` (autorise uniquement `local`, `testing`, `staging` ; refuse `production`), `backend/app/Services/Sms/YellikaSmsGateway.php` (Bearer token, payload `recipient`, `sender_id`, `type='plain'`, `message`, timeout 10 s, max 2 tentatives sur timeout/5xx/429, pas de réessai sur autres 4xx), `backend/app/Services/Sms/InvalidDriverSmsGateway.php`
  - `backend/database/migrations/2026_11_16_100000_create_sms_logs_table.php` (`phone_masked`, `motif`, `driver`, `status`, `provider_message_id`, `error_message`, `sent_at` — aucune colonne `message`), `backend/app/Models/SmsLog.php`
  - `backend/app/Mail/SmsDailyAlertMail.php`, `backend/app/Services/SettingsService.php` (`sms.seuil_alerte_quotidien => 200`), `backend/app/Filament/Pages/SettingsPage.php`
  - `backend/app/Services/SmsService.php`, `backend/app/Providers/AppServiceProvider.php`, `backend/app/Modules/GoColis/Services/ParcelService.php`, `backend/app/Http/Controllers/Api/V1/Auth/AuthController.php` (`503` + suppression `OtpCode` si `envoyerOtp()` échoue)
  - `backend/tests/Feature/Sms/SmsGatewayTest.php`
- **Preuve Rouge :**
  - `./vendor/bin/pest tests/Feature/Sms/SmsGatewayTest.php` → `Tests: 6 failed (6 assertions), Duration: 142.84s` (`Target class [App\Services\Sms\Contracts\SmsGateway] does not exist`, `YellikaSmsGateway` et `LogSmsGateway` absents, validation numéro invalide absente).
- **Preuve Verte :**
  - `./vendor/bin/pint --test` → `{"tool":"pint","result":"passed"}`
  - `php ./vendor/bin/phpstan analyse --level=5 --memory-limit=1G` → `[OK] No errors` (`174/174`)
  - `./vendor/bin/pest tests/Feature/Sms/SmsGatewayTest.php tests/Feature/Auth/AuthTest.php tests/Feature/Security/OtpSecurityP3Test.php tests/Feature/Api/V1/Webhook/PaymentWebhookTest.php tests/Feature/GoColis/ParcelSmsFailureTest.php tests/Feature/Settings/SettingsCdcAndPersistenceTest.php` → `Tests: 36 passed (225 assertions), Duration: 18.44s`.

### Point 2 — Notifications Push FCM (`0106a28`)
- **Fichiers modifiés / créés (14 fichiers) :**
  - `.gitignore`, `backend/.gitignore` (ajout `google-services.json`, `GoogleService-Info.plist`, `*firebase*.json`, `*firebase-adminsdk*.json`, `firebase-service-account*.json`, `service-account*.json`)
  - `backend/composer.json`, `backend/composer.lock` (`kreait/laravel-firebase 7.2.1`, `kreait/firebase-php 8.6.0`)
  - `backend/config/firebase.php`, `backend/config/services.php`, `backend/.env.example`
  - `backend/app/Support/FcmChannels.php` (`ORDERS_NEW='orders_new'`, `COURSES_NEW='courses_new'`, `GENERAL='general'`, `URGENT_TTL_SECONDS=60`)
  - `backend/app/Http/Controllers/Api/V1/DeviceTokenController.php`, `backend/routes/api.php` (`POST /api/v1/devices/token` avec `throttle:device-token` 10 req/min dans `AppServiceProvider.php`)
  - `backend/app/Jobs/SendPushNotification.php` (envoi `CloudMessage` avec config Android/APNs, priorité haute & TTL 60 s, suppression `users.device_token = null` sur `NotFound`/`InvalidMessage`/`InvalidArgument`, aucune injection container directe sur `handle()` pour tolérer l'absence de fichier credentials en local/test sans crash), `backend/app/Services/NotificationService.php` (routage canal/priorité/TTL selon `$type`, file `default`)
  - `backend/tests/Feature/Notifications/FcmPushNotificationTest.php`
- **Preuve Rouge :**
  - `./vendor/bin/pest tests/Feature/Notifications/FcmPushNotificationTest.php` → `Tests: 4 failed (4 assertions), Duration: 3.90s` (`Class "App\Support\FcmChannels" not found`, `Expected 401 received 404` sur `/api/v1/devices/token`, `channel_id` absent, `stale-token-404` non nettoyé).
- **Preuve Verte :**
  - `./vendor/bin/pint --test` → `{"tool":"pint","result":"passed"}`
  - `php ./vendor/bin/phpstan analyse --level=5 --memory-limit=1G` → `[OK] No errors` (`176/176`)
  - `./vendor/bin/pest tests/Feature/Notifications/FcmPushNotificationTest.php tests/Feature/Dispatch/ClientLifecycleNotificationsTest.php tests/Feature/Dispatch/GoShopAssignmentAndTimeoutTest.php tests/Feature/Auth/AuthTest.php tests/Feature/Dispatch/AfterCommitJobDispatchTest.php` → `Tests: 24 passed (150 assertions), Duration: 15.85s` (plus re-vérification `FcmPushNotificationTest` : `5 passed (46 assertions), Duration: 14.39s`).

### Point 3 — Laravel Horizon (`b9df196`)
- **Fichiers modifiés / créés (6 fichiers) :**
  - `backend/config/horizon.php` (`supervisor-high` -> `['high']`, `supervisor-default` -> `['default']`, `supervisor-low` -> `['low']` sur `production`, `staging`, `local`, `memory_limit` via `HORIZON_MEMORY_LIMIT`)
  - `backend/app/Providers/HorizonServiceProvider.php` (surcharge `authorization()` pour exiger strictement `Gate::check('viewHorizon', ...)` même en `local` ; gate autorisant uniquement `super_admin` et `national_tech` actifs)
  - `backend/bootstrap/providers.php` (enregistrement `HorizonServiceProvider`)
  - `backend/app/Modules/GoShop/Services/AssignmentService.php` (`NotifierLivreurGoShop` et `NotifierLivreurGoColis` passés sur `->onQueue('high')`)
  - `backend/routes/console.php` (ajout `horizon:snapshot` `everyFiveMinutes()` et `queue:prune-failed --hours=168` `daily()` avec `withoutOverlapping()` et `onOneServer()`)
  - `backend/tests/Feature/Horizon/HorizonConfigTest.php`
- **Preuve Rouge :**
  - `./vendor/bin/pest tests/Feature/Horizon/HorizonConfigTest.php` → `Tests: 3 failed (7 assertions), Duration: 10.52s` (`Failed asserting that an array has the key 'supervisor-high'`, `viewHorizon` `false`, `horizon:snapshot` `null`).
- **Preuve Verte :**
  - `php ./vendor/bin/phpstan analyse --level=5 --memory-limit=1G` → `[OK] No errors` (`177/177`)
  - `./vendor/bin/pest tests/Feature/Horizon/HorizonConfigTest.php tests/Feature/Dispatch/GoShopAssignmentAndTimeoutTest.php tests/Feature/Dispatch/AfterCommitJobDispatchTest.php tests/Feature/Dispatch/OrderBackupSweepCommandTest.php` → `Tests: 11 passed (112 assertions), Duration: 64.19s`
  - Après correction EOF Pint sur `HorizonConfigTest.php` : `./vendor/bin/pint --test` → `{"tool":"pint","result":"passed"}` et `HorizonConfigTest.php` → `Tests: 4 passed (51 assertions), Duration: 161.51s`.

### Point 4 — Sentry (`bbba028`)
- **Fichiers modifiés / créés (7 fichiers) :**
  - `backend/composer.json`, `backend/composer.lock` (`sentry/sentry-laravel 4.30.0`, `sentry/sentry 4.37.0`)
  - `backend/app/Support/SentryEventScrubber.php` (`beforeSend()` statique cacheable supprimant téléphones, emails, IP, username, OTP, codes secrets, JWT/Bearer, query params sensibles et en-têtes `Authorization`/`X-Webhook-Secret`/`X-Health-Token`)
  - `backend/config/sentry.php` (`environment`, `traces_sample_rate => 0.1` par défaut, `send_default_pii => false`, `before_send => [SentryEventScrubber::class, 'beforeSend']`, `sql_bindings => false`, exclusion `/health`, `/api/v1/health`, `/up`, `horizon`)
  - `backend/bootstrap/app.php` (`Integration::handles($exceptions);` en conservant tous les rendus JSON API)
  - `backend/.env.example`
  - `backend/tests/Feature/Sentry/SentryScrubbingTest.php`
- **Preuve Rouge :**
  - `./vendor/bin/pest tests/Feature/Sentry/SentryScrubbingTest.php` → `Tests: 3 failed (6 assertions), Duration: 11.85s` (`before_send` `null`, `Class "App\Support\SentryEventScrubber" not found`, `Integration::handles($exceptions)` absent de `bootstrap/app.php`).
- **Preuve Verte :**
  - `./vendor/bin/pint --test` → `{"tool":"pint","result":"passed"}`
  - `php ./vendor/bin/phpstan analyse --level=5 --memory-limit=1G` → `[OK] No errors` (`178/178`)
  - `./vendor/bin/pest tests/Feature/Sentry/SentryScrubbingTest.php` → `Tests: 3 passed (41 assertions), Duration: 6.88s` et ensemble `SentryScrubbingTest` + `ApiExceptionHandlerSecurityTest` → `Tests: 7 passed (53 assertions), Duration: 8.34s`.

### Point 5 — Planificateur (`4ed6cf1` + `2d237fc`)
- **Fichiers modifiés / créés :**
  - `backend/app/Console/Commands/SweepOrderTimeoutsCommand.php` (constante `LAST_RUN_CACHE_KEY = 'scheduler:orders_sweep:last_run_at'`, enregistrement horodaté en cache à chaque exécution)
  - `backend/routes/console.php` (déjà complété au Point 3 pour `horizon:snapshot` et `queue:prune-failed --hours=168`)
  - `backend/tests/Feature/Dispatch/SchedulerConfigurationTest.php`
- **Preuve Rouge :**
  - `./vendor/bin/pest tests/Feature/Dispatch/SchedulerConfigurationTest.php` → `Tests: 1 failed, 1 passed (16 assertions), Duration: 7.91s` (`Undefined constant App\Console\Commands\SweepOrderTimeoutsCommand::LAST_RUN_CACHE_KEY`).
- **Preuve Verte :**
  - `./vendor/bin/pint --test` → `{"tool":"pint","result":"passed"}`
  - `php ./vendor/bin/phpstan analyse --level=5 --memory-limit=1G` → `[OK] No errors` (`178/178`)
  - `php artisan schedule:list` :
    - `*   * * * *  php artisan orders:sweep-timeouts`
    - `*/5 * * * *  php artisan horizon:snapshot`
    - `0   0 * * *  php artisan queue:prune-failed --hours=168`
  - `./vendor/bin/pest tests/Feature/Dispatch/SchedulerConfigurationTest.php tests/Feature/Dispatch/OrderBackupSweepCommandTest.php` → `Tests: 5 passed (54 assertions), Duration: 7.77s`.

### Point 6 — Contrôle de santé `/health` (`9f825a5`)
- **Fichiers modifiés / créés (6 fichiers) :**
  - `backend/config/services.php` (retrait de `'golivreur-secret-health-token'`, lecture `env('HEALTH_TOKEN', env('HEALTH_CHECK_TOKEN', ''))`)
  - `backend/app/Services/HealthCheckService.php` (sondes `database`, `redis`, `queues`, `scheduler` > 180 s = `degraded`, `sms` interdit `log` en `production`, `fcm` `credentials_present` sans exposition de chemin ni secret)
  - `backend/app/Http/Controllers/HealthController.php` (`hash_equals` sur header `HEALTH_TOKEN` ou `X-Health-Token` ; sans jeton valide -> strictement `{"status": "ok"|"degraded"}` ; code HTTP `200` ou `503`)
  - `backend/routes/web.php` (`GET /health`) ; `backend/routes/api.php` (`GET /api/v1/health`) conservé intact pour CI / tests existants
  - `backend/.env.example` (`HEALTH_TOKEN=CHANGE_ME`)
  - `backend/tests/Feature/Health/HealthCheckTest.php`
- **Preuve Rouge :**
  - `./vendor/bin/pest tests/Feature/Health/HealthCheckTest.php` → `Tests: 5 failed (5 assertions), Duration: 9.48s` (secret présent dans `config/services.php` et `404` sur `/health`).
- **Preuve Verte :**
  - `./vendor/bin/pint --test` → `{"tool":"pint","result":"passed"}`
  - `php ./vendor/bin/phpstan analyse --level=5 --memory-limit=1G` → `[OK] No errors` (`180/180`)
  - `./vendor/bin/pest tests/Feature/Health/HealthCheckTest.php tests/Feature/Security/ApiExceptionHandlerSecurityTest.php` → `Tests: 9 passed (51 assertions), Duration: 10.63s`.

### Point 8 — Mémoire PHPUnit & Test Production SMS (`ecd9a7c` + `1c6d234`)
- **Fichiers modifiés :**
  - `backend/phpunit.xml` (`<ini name="memory_limit" value="512M"/>` dans `<php>`)
  - `backend/tests/Feature/Security/RefreshTokenAndDevBypassSecurityTest.php` (`sms.driver='yellika'` + `Http::fake()` dans le cas `production`)
- **Preuve Rouge :**
  - `php -r "echo ini_get('memory_limit');"` → `128M`.
  - `./vendor/bin/pest` (sans option `-d`) → arrêt brutal en fatal OOM vers ~65 % de la suite :
    `PHP Fatal error: Allowed memory size of 134217728 bytes exhausted (tried to allocate 8388608 bytes) in C:\Projets\golivreur\backend\vendor\league\mime-type-detection\src\FinfoMimeTypeDetector.php on line 76` (`EXIT_CODE=1`).
- **Preuve Verte :**
  - `./vendor/bin/pest` (sans aucun `-d`) → `Tests: 468 passed (3298 assertions), Duration: 173.21s` (`EXIT_CODE=0`).

---

## 4. Point 7 — Audit Chiffré du Flux Cash et Incohérences CDC 7.4.1 (Sans modification de code)

### 4.1. Exemple chiffré GoShop en espèces : 25 000 F CFA produits + 1 500 F CFA livraison = **26 500 F CFA** client

1. **Calcul serveur des montants (`CommissionService::calculerCommissions`, `app/Modules/GoShop/Services/CommissionService.php:71-89`, défauts CDC 8.1) :**
   - `products_total` : `25 000 F CFA` ; `delivery_fee` : `1 500 F CFA` ; total à encaisser chez le client : `26 500 F CFA`.
   - Commission commerce (`15 %`) : `round(25 000 * 15 / 100) = 3 750 F CFA` → **`merchant_amount` = `21 250 F CFA`**.
   - Commission livreur (`20 %`) : `round(1 500 * 20 / 100) = 300 F CFA` → **`courier_amount` = `1 200 F CFA`** (`80 %`).
   - **`platform_commission`** : `3 750 + 300 = 4 050 F CFA`.
   - Somme : `21 250 + 1 200 + 4 050 = 26 500 F CFA`.
2. **Seuil requis à l'acceptation (`AssignmentService` l.352, `DriverController::acceptOrder` l.184-196, `WalletService::verifierSoldeCashSuffisant` l.209-261) :**
   - `montantAReverser = calculerMontantAReverserOrder($order) = 26 500 - 1 200 = 25 300 F CFA` (`WalletService.php:160-166`).
   - Le contrôle exige **à la fois** :
     1. `solde >= 10 000 F CFA + engagements_cash_en_cours` (règle plancher CDC 7.4.1, `WalletService.php:233-244`), **et**
     2. `disponible (= solde - engagements) >= 25 300 F CFA` (`WalletService.php:246-253`).
   - Conséquence : même sans aucune autre course active (`engagements = 0`), un livreur disposant exactement du minimum CDC de `10 000 F CFA` (ou même `25 000 F CFA`) est **bloqué** (`422 Solde disponible insuffisant pour couvrir le reversement espèces (25300 F CFA requis, ...)`). Il lui faut au moins **`25 300 F CFA`** sur son wallet.
3. **Au ramassage chez le commerce (`confirmPickup`) :**
   - Aucune écriture sur `wallets` / `wallet_transactions` : le code suppose que le livreur récupère le colis sans payer le commerçant comptant sur place.
4. **À la livraison et validation du code client (`OrderService::validerCodeConfirmation`, `OrderService.php:372-411`) :**
   - Le client remet physiquement `26 500 F CFA` en espèces au livreur ; `payment_status` passe à `'paid_cash'`.
   - Le livreur conserve physiquement sa part (`1 200 F CFA`) et garde en main les `25 300 F CFA` dus (`21 250 F` commerce + `4 050 F` plateforme).
   - `WalletService::debiterReversementCashOrder` (`WalletService.php:128-140`, type `'debit_cash_remittance'`) débite **`-25 300 F CFA`** sur le wallet du livreur (vérifie `balance_after >= 0`, appuyé par contrainte DB `wallets_balance_check CHECK (balance >= 0)`).
   - `WalletService::crediterMerchant` (`WalletService.php:46-57`, type `'credit_delivery'`) crédite **`+21 250 F CFA`** sur le wallet du commerçant.
   - **Part plateforme (`4 050 F CFA`) :** il n'existe aucun `Wallet` `'platform'` ; les `4 050 F CFA` restent le différentiel entre le débit livreur (`25 300 F`) et le crédit commerce (`21 250 F`), journalisé dans `activity_logs` (`action = 'cash_remittance_recorded'`, `OrderService.php:397-410`).

### 4.2. Exemple chiffré GoColis en espèces (`CommissionService::calculerCommissionsGoColis` l.143-154, `ParcelService::validerCodeDestinataire` l.364-395)

- **Cas A — Standard CDC 8.2 sans assurance (`shipping_fee = 1 800 F CFA`, `valeur déclarée <= 20 000 F CFA` donc `insurance_amount = 0`) :**
  - `platform_commission` (`25 %`) : `450 F CFA` ; `courier_amount` (`75 %`) : `1 350 F CFA` ; `montantAReverser = 450 F CFA`.
  - Acceptation : `max(10 000, 450) = 10 000 F CFA` exigés (hors engagements existants).
  - Livraison : livreur encaisse `1 800 F CFA` cash, garde `1 350 F CFA`, wallet débité de `-450 F CFA` (`debit_cash_remittance`).
- **Cas B — Avec assurance (`shipping_fee = 2 000 F CFA`, `declared_value = 50 000 F CFA` -> `insurance = 3 % = 1 500 F CFA`, total client = `3 500 F CFA`) :**
  - `platform_commission` (`25 %` de `2 000`) : `500 F CFA` ; `courier_amount` (`75 %`) : `1 500 F CFA` ; `insurance_amount` : `1 500 F CFA`.
  - `calculerMontantAReverserParcel` (`WalletService.php:168-174`) = `(2 000 + 1 500) - 1 500 = 2 000 F CFA` (`500 F` commission + `1 500 F` assurance 100 % reversée à la plateforme).
  - Livraison : livreur garde `1 500 F CFA`, wallet débité de `-2 000 F CFA` (`debit_cash_remittance`).

### 4.3. Incohérence CDC 7.4.1 vs réalité métier & points d'attention exploitation

1. **Affichage CDC « 10 000 F CFA » trompeur pour GoShop :** CDC 7.4.1 (`GoLivreur_CahierDesCharges_v5.md:336`) indique simplement « Solde minimum : 10 000 F CFA pour accepter les commandes en espèces ». En réalité, le code (`WalletService.php:233-253`) bloque toute commande cash dont `montantAReverser > solde - engagements`. Dès qu'un panier GoShop dépasse ~`11 500 F CFA` produits + livraison, `10 000 F CFA` ne suffit plus : pour une commande moyenne à `25 000 F CFA` produits, le livreur doit immobiliser au minimum `25 300 F CFA` (plus s'il cumule des courses).
2. **Absence d'endpoints de recharge et de retrait livreur (CDC 7.4.1 & 11.5) :** CDC 11.5 (`GoLivreur_CahierDesCharges_v5.md:611-612`) spécifie `POST /api/wallet/recharge` et `POST /api/wallet/withdraw` (retrait >= `2 000 F CFA`), mais `routes/api.php:167` ne fournit que `GET /api/v1/driver/wallet` (`DriverController::wallet`). Seul `WalletService::ajusterSolde` existe côté Filament admin (`super_admin` / `national_finance`). En bêta cash-only, les livreurs devront recharger physiquement au bureau (ajustement manuel par `national_finance`/`super_admin`), ce qui augmente la friction dès que le volume augmente.
3. **Asymétrie de trésorerie commerce ↔ livreur au lancement cash-only :** Sur GoShop cash, le livreur encaisse `100 %` du cash client (`26 500 F CFA`) et est débité virtuellement (`-25 300 F CFA`), tandis que le commerce est crédité virtuellement (`+21 250 F CFA`). La plateforme doit gérer manuellement les dépôts cash des livreurs (pour maintenir leurs soldes au-dessus des commandes de 25 000+ F CFA) et les reversements périodiques aux commerçants tant qu'aucun module de payout/retrait n'est branché.

---

## 5. Variables `.env` exactes ajoutées ou modifiées (valeurs secrètes remplacées par `CHANGE_ME`)

Documentées dans `backend/.env.example` :

| Variable | Config cible | Valeur exemple / défaut | Notes prod / staging |
|---|---|---|---|
| `SMS_DRIVER` | `config('sms.driver')` | `log` (`CHANGE_ME` -> `yellika` en prod) | `log` refusé si `APP_ENV=production` |
| `YELLIKA_BASE_URL` | `config('sms.yellika.base_url')` | `https://panel.yellikasms.com/api/v3/sms/send` | Optionnel si défaut conservé |
| `YELLIKA_API_TOKEN` | `config('sms.yellika.api_token')` | `CHANGE_ME` | Requis pour `SMS_DRIVER=yellika` |
| `YELLIKA_SENDER_ID` | `config('sms.yellika.sender_id')` | `GoLivreur` | Sender ID validé chez Yellika |
| `YELLIKA_SMS_TYPE` | `config('sms.yellika.type')` | `plain` | Défaut `plain` dans `config/sms.php` |
| `YELLIKA_TIMEOUT_SECONDS` | `config('sms.yellika.timeout')` | `10` | Timeout HTTP (s) |
| `SMS_MONTHLY_ALERT_THRESHOLD` | `SmsService` | `2500` | Seuil mensuel alerte email |
| `SMS_ALERT_EMAIL` | `SmsService` / `config('services.admin_alert_email')` | `francis@golivreur.ci` | Destinataire alertes |
| `FIREBASE_CREDENTIALS` | `config('firebase.projects.app.credentials')` | `/etc/golivreur/secrets/firebase-service-account.json` | **Hors dépôt Git**, droits lecture `www-data` |
| `HORIZON_MEMORY_LIMIT` | `config('horizon.memory_limit')` | `128` | MB master supervisor Horizon |
| `SENTRY_LARAVEL_DSN` | `config('sentry.dsn')` | `CHANGE_ME` (vide en local) | DSN projet Sentry |
| `SENTRY_ENVIRONMENT` | `config('sentry.environment')` | `staging` (sinon `APP_ENV`) | `local` / `staging` / `production` |
| `SENTRY_TRACES_SAMPLE_RATE` | `config('sentry.traces_sample_rate')` | `0.1` | 10 % traces |
| `SENTRY_SEND_DEFAULT_PII` | `config('sentry.send_default_pii')` | `false` | Obligatoirement `false` |
| `HEALTH_TOKEN` | `config('services.health.token')` | `CHANGE_ME` | Envoyé en header `HEALTH_TOKEN` ou `X-Health-Token` sur `GET /health` |

---

## 6. Checklist des Tests Manuels sur Téléphones Réels (Avant Ouverture Bêta)

1. **SMS réels via Yellika (`APP_ENV=staging` ou `production`, `SMS_DRIVER=yellika`) :**
   - [ ] Tester `POST /api/v1/auth/register` vers **Orange CI** (`+225 07 ...`) : réception < 15 s, `Sender ID = GoLivreur`, vérification ligne `sms_logs` (numéro masqué `225 07 ** ** YY`, **aucun OTP** en clair dans `sms_logs` ni `storage/logs/laravel.log`).
   - [ ] Tester `POST /api/v1/auth/login` vers **MTN CI** (`+225 05 ...`) et **Moov CI** (`+225 01 ...`).
   - [ ] Tester envoi du code destinataire GoColis vers un vrai numéro différent de l'expéditeur et vérifier le déclenchement de l'alerte `SmsDailyAlertMail` en abaissant temporairement `sms.seuil_alerte_quotidien`.
2. **Push FCM réels (Android physique avec `google-services.json` hors Git + serveur avec `FIREBASE_CREDENTIALS` réel) :**
   - [ ] Installer l'APK client/commerce/livreur compilé avec `ci.golivreur.*` et enregistrer le token via `POST /api/v1/devices/token`.
   - [ ] Vérifier l'affichage temps réel (app fermée + arrière-plan) pour :
     - Commerce : nouvelle commande -> canal `orders_new`, priorité haute, TTL 60 s.
     - Livreur : proposition course GoShop / GoColis -> canal `courses_new`, priorité haute, TTL 60 s.
     - Client : acceptation / ramassage / livraison -> canal `general`.
   - [ ] Désinstaller l'app sur un téléphone de test, renvoyer un push -> vérifier que `SendPushNotification` reçoit `NotFound` et remet `users.device_token = null`.
3. **Horizon, Scheduler & `/health` sur serveur Linux :**
   - [ ] Lancer le superviseur Horizon sous Linux (`php artisan horizon`) avec Redis actif, vérifier que `/horizon` retourne `200` uniquement pour `super_admin` et `national_tech` actifs et `403` pour `dg_national`, `city_manager`, comptes suspendus ou non connectés.
   - [ ] Vérifier la tâche cron `* * * * * cd /var/www/app && php artisan schedule:run >> /dev/null 2>&1`.
   - [ ] Vérifier `curl -s https://<domaine>/health` (`{"status":"ok"}` uniquement) vs `curl -s -H "HEALTH_TOKEN: <token>" https://<domaine>/health` (tous les diagnostics `ok`).

---

## 7. Sorties Finales Complètes (`pest`, `phpstan`, `pint`)

Exécutées **après le dernier commit de code/test (`1c6d234`)** sans option `-d` pour PHPUnit :

### 7.1. `./vendor/bin/pest` (complet, sans `-d`)

```text
   PASS  Tests\Feature\Admin\AdministratorHierarchySecurityTest
  ✓ role selector options depend on authenticated user role                                                      4.22s  
  ✓ city manager cannot create superior role even when forging request                                           1.66s  
  ✓ city manager creating local agent forces city id to own city                                                 0.55s  
  ✓ city manager cannot edit admin from another city                                                             0.18s  
  ✓ city manager cannot escalate local agent to super admin via edit                                             0.55s  

   PASS  Tests\Feature\Admin\DisputesAndOrderResourceTest
  ✓ it affiche id, total_client et libelles reels sur DisputesPage sans propriete inexistante                    0.51s  
  ✓ it affiche et enregistre les notifications et cancel_reason reels dans OrderResource                         4.73s  

   PASS  Tests\Feature\Admin\EmergencyModeSecurityTest
  ✓ api activation allowed for super admin and dg national                                                       0.23s  
  ✓ api deactivation is strictly forbidden to dg national and reserved to super admin                            0.19s  
  ✓ api city manager cannot activate or deactivate emergency mode                                                0.17s  
  ✓ api emergency message cannot exceed 255 characters                                                           0.17s  
  ✓ city manager does not have access to emergency center                                                        0.16s  
  ✓ filament emergency center deactivation action forbidden to dg national                                       0.38s  

   PASS  Tests\Feature\Admin\FilamentCityContextIsolationTest
  ✓ city manager daloa sees no orders parcels merchants couriers from bouake                                     1.90s  
  ✓ national roles and super admin see all cities                                                                0.53s  
  ✓ stats overview adapts label and counts to city context                                                       0.29s  
  ✓ city finance sees only wallet transactions from own city                                                     0.35s  
  ✓ latest orders widget scopes by city context                                                                  0.27s  
  ✓ pending widgets scope by city context                                                                        0.36s  

   PASS  Tests\Feature\Admin\FilamentIsolationRattrapageTest
  ✓ it isole UserResource, CityResource, NeighborhoodResource, ActivityLogResource et WebhookLogResource par vi… 0.27s  
  ✓ it isole DisputesPage, PendingValidationsPage, ListRefunds, OrderResource::reassignCourier, widgets et comp… 5.43s  
  ✓ it force city_id cote serveur sur CreateOrder, CreateParcel, CreateMerchant, CreateCourier, CreateNeighborh… 7.36s  
  ✓ it est fail-closed (0 enregistrement, 0 stat, 403 mutation et policy) si un admin local a city_id null       0.80s  

   PASS  Tests\Feature\Admin\FormWorkflowAndMediaTest
  ✓ it masque Categories Activite et Types de Vehicules du menu principal et les rattache aux sections partenai… 1.45s  
  ✓ it permet de créer une catégorie d'activité avec emoji et logo uploadé exposés par l'API                     1.07s  
  ✓ it permet de créer un commerce avec nouveau gestionnaire, photo, logo et emoji puis l'expose à l'app mobile  1.81s  
  ✓ it permet de créer un livreur avec upload photo réel                                                         1.01s  
  ✓ it charge tous les formulaires harmonisés avec listes déroulantes sans erreur                                1.53s  

   PASS  Tests\Feature\Admin\ParcelCodeUnlockSecurityTest
  ✓ unlock sender code requires client called checkbox and calls service                                         3.57s  
  ✓ unlock recipient code requires client called checkbox and calls service                                      3.36s  

   PASS  Tests\Feature\Admin\PaymentMethodsChartTest
  ✓ it compte correctement les commandes et colis en espèces, Orange Money (orange_money) et autre mode dans Pa… 0.38s  

   PASS  Tests\Feature\Admin\PendingValidationsTest
  ✓ it affiche les livreurs en attente de validation sur la page des validations                                 0.30s  
  ✓ it permet de basculer entre les onglets livreurs et commerces sur la page de validation                      0.46s  

   PASS  Tests\Feature\Admin\RefundAndRoleValidationTest
  ✓ it utilise id et total_client sur ListRefunds sans propriete inexistante                                     2.11s  
  ✓ it refuse sur ListRefunds une commande en statut non éligible ou non payée sans créer de refund ni wallet    1.33s  
  ✓ it affiche le message validation francais quand aucun onglet nest coche sur RoleHabilitation                 1.49s  

   PASS  Tests\Feature\Admin\RoleHabilitationFallbackTest
  ✓ user has habilitation tab fallback without db relation                                                       0.38s  
  ✓ role habilitation seeded and migrated matches cdc                                                            0.37s  

   PASS  Tests\Feature\Api\CatalogSyncTest
  ✓ it expose les noms, emojis et logos des catégories via l'API client                                          0.29s  
  ✓ it expose les noms, emojis, logo_url et photo_url des commerces via l'API client                             0.32s  

   PASS  Tests\Feature\Api\ClientExperienceTest
  ✓ it reflète l'état réel du mode pluie de la ville dans city-status                                            0.26s  
  ✓ it permet à un client d'ajouter, modifier, choisir par défaut et supprimer ses adresses de livraison         0.36s  
  ✓ it bloque la modification d'une adresse appartenant à un autre client (403)                                  0.29s  
  ✓ it permet à un client d'ajouter et retirer un commerce de ses favoris                                        0.29s  
  ✓ it retourne une liste de quartiers vide si aucun quartier en base et expose uniquement les quartiers actifs… 0.25s  
  ✓ it expose photo_url et city_name issus de la relation ville dans le profil client                            0.22s  
  ✓ it expose un fallback null propre si photo ou ville est absente du profil client                             0.20s  
  ✓ it permet à un admin de gérer Villes et Quartiers avec formulaires vides sans préremplissage                 0.90s  

   PASS  Tests\Feature\Api\ProfileManagementTest
  ✓ it client : peut lire, mettre à jour son profil, uploader une photo et remplacer l'ancienne                  0.22s  
  ✓ it client : rejette les données invalides (422) et bloque la modification d'un autre profil (403)            0.19s  
  ✓ it client : un driver ne peut pas modifier le profil client (403)                                            0.15s  
  ✓ it driver : peut lire, mettre à jour véhicule/permis/photo et rejette accès non autorisé (403)               0.23s  
  ✓ it driver : un client ne peut pas accéder au profil driver (403)                                             0.17s  
  ✓ it business : peut lire, modifier boutique/catégorie/emoji/logo/horaires et bloque autre rôle (403)          0.22s  
  ✓ it business : un driver ne peut pas modifier le profil business (403)                                        0.17s  
  ✓ it profil : permet de changer son mot de passe après vérification de l'ancien                                0.15s  

   PASS  Tests\Feature\Api\V1\GoColis\GoColisDriverFlowTest
  ✓ it GoColis accept nominal: un livreur actif et en ligne accepte un colis pending ou waiting avec lockForUpd… 0.21s  
  ✓ it GoColis accept nominal: un livreur actif et en ligne accepte un colis pending ou waiting avec lockForUpd… 0.19s  
  ✓ it GoColis accept hors ville: refuse 404 sans fuite de nom de ville                                          0.19s  
  ✓ it GoColis accept déjà pris et concurrence: retourne 409 sans écraser le premier livreur                     0.24s  
  ✓ it GoColis accept disponibilité: refuse 403 si livreur inactif, pénalisé ou hors ligne with dataset "courie… 0.22s  
  ✓ it GoColis accept disponibilité: refuse 403 si livreur inactif, pénalisé ou hors ligne with dataset "user i… 0.17s  
  ✓ it GoColis accept disponibilité: refuse 403 si livreur inactif, pénalisé ou hors ligne with dataset "hors l… 0.18s  
  ✓ it GoColis accept disponibilité: refuse 403 si livreur inactif, pénalisé ou hors ligne with dataset "pénali… 0.18s  
  ✓ it GoColis accept disponibilité: refuse 403 si livreur inactif, pénalisé ou hors ligne with dataset "3 refu… 0.17s  
  ✓ it GoColis accept courses.max_actives: refuse 409 si une course active (GoShop ou GoColis) existe déjà       0.22s  
  ✓ it GoColis accept espèces: refuse 422 sans solde minimum/disponible suffisant et autorise 200 avec solde su… 0.21s  
  ✓ it GoColis refuse: 3 refus consécutifs appliquent la pénalité 15 min hors ligne et relancent le livreur sui… 0.28s  
  ✓ it GoColis notifications réelles: NotifierLivreurGoColis envoie la même notification que GoShop et escalade… 0.25s  
  ✓ it GoColis Filament: l'affectation manuelle d'un livreur sur ParcelResource continue de fonctionner          2.17s  
  ✓ it GoColis refuse après acceptation: un colis jugé interdit ou suspect passe au statut refused (CDC Annexe…  0.43s  

   PASS  Tests\Feature\Api\V1\GoColis\GoColisTest
  ✓ it un client peut créer une expédition GoColis                                                               0.41s  
  ✓ it calcule correctement l'assurance pour une valeur déclarée > 20000 F CFA                                   0.35s  
  ✓ it ne calcule pas d'assurance pour une valeur déclarée <= 20000 F CFA                                        0.48s  
  ✓ it le livreur peut valider le code expéditeur correct après avoir pris la photo                              0.37s  
  ✓ it le livreur ne peut pas valider le code expéditeur sans avoir pris la photo                                0.27s  
  ✓ it bloque l'expédition après 3 codes expéditeur incorrects                                                   0.27s  
  ✓ it valide correctement le code destinataire et crédite le livreur                                            0.30s  
  ✓ it le livreur voit la taille du colis avant d'accepter une course GoColis                                    0.22s  
  ✓ it calcule les frais d'expédition GoColis selon la taille et la distance                                     0.13s  

   PASS  Tests\Feature\Api\V1\GoColis\ParcelCodeSecurityTest
  ✓ it refuse (403) un livreur sur un colis Parcel sans courier_id                                               0.19s  
  ✓ it refuse (403) le livreur A sur un colis Parcel assigné au livreur B                                        0.19s  
  ✓ it autorise le livreur assigné et les rôles super_admin et dg_national sur un colis Parcel                   0.26s  
  ✓ it rejette le code destinataire au stade de ramassage d'un colis Parcel                                      0.19s  
  ✓ it bloque les deux codes d'un colis Parcel après 3 mauvais codes et n'écrit aucun code dans les logs         0.26s  

   PASS  Tests\Feature\Api\V1\GoColis\RatingAndInsuranceTest
  ✓ un client peut noter un livreur post-livraison avec recalcul automatique de la note                          0.20s  
  ✓ un client ne peut pas noter une commande qui ne lui appartient pas                                           0.19s  
  ✓ un admin habilité peut valider le remboursement de l'assurance d'un colis                                    0.32s  
  ✓ un colis assuré simplement in_transit non déclaré perdu ou non payé est refusé 422 au remboursement          0.30s  

   PASS  Tests\Feature\Api\V1\GoShop\CommissionServiceTest
  ✓ it calcule le barème GoShop CDC 8.3 en tarif normal, pointe seule, pluie seule et pluie+pointe               0.26s  
  ✓ it vérifie les bornes horaires des heures de pointe GoShop (11:29, 11:30, 14:00, 14:01 et soirée)            0.69s  
  ✓ it calcule le barème GoColis CDC 8.2/8.4 (cas 4 km moyen = 1 800 F CFA et partage 75%/25%)                   0.46s  

   PASS  Tests\Feature\Api\V1\GoShop\OrderTest
  ✓ it un client peut créer une commande GoShop pour un commerce ouvert                                          0.78s  
  ✓ it le commerce peut accepter une commande et passer au statut accepted                                       0.47s  
  ✓ it le commerce peut refuser une commande avec motif                                                          0.37s  
  ✓ it le livreur peut confirmer la livraison avec le code client et créditer les wallets                        0.36s  

   PASS  Tests\Feature\Api\V1\Wallet\WalletServiceTest
  ✓ it crédite correctement le wallet du livreur après une livraison GoShop                                      0.30s  
  ✓ it accumule correctement plusieurs crédits sur le même wallet                                                0.32s  
  ✓ it crédite correctement le wallet du merchant après une livraison GoShop                                     0.27s  
  ✓ it crédite correctement le wallet du livreur après une livraison GoColis                                     0.26s  
  ✓ it crée exactement une WalletTransaction par opération                                                       0.21s  
  ✓ it chaque transaction a une référence unique                                                                 0.20s  
  ✓ it ne crée pas de wallet pour un client                                                                      0.12s  
  ✓ it un City Support peut valider un remboursement inférieur à 2000 F CFA                                      0.15s  

   PASS  Tests\Feature\Api\V1\Webhook\PaymentWebhookTest
  ✓ un webhook avec signature HMAC invalide est rejeté avec HTTP 401                                             0.17s  
  ✓ un webhook Hub2 valide passe le statut du paiement en paid                                                   0.21s  
  ✓ un paiement échoué passe la commande en cancelled avec motif                                                 0.19s  
  ✓ le traitement des webhooks est idempotent face aux doublons                                                  0.21s  
  ✓ un admin peut rejouer un webhook valide échoué                                                               0.22s  
  ✓ un webhook avec signature invalide ne peut pas être rejoué                                                   0.17s  
  ✓ le compteur mensuel SMS s'incrémente et alerte au seuil de 2500                                              0.18s  

   PASS  Tests\Feature\Auth\AuthTest
  ✓ it un client peut demander un OTP pour s'inscrire                                                            0.24s  
  ✓ it un numéro déjà existant ne peut pas s'inscrire à nouveau                                                  0.19s  
  ✓ it un client peut vérifier son OTP et créer son compte                                                       0.17s  
  ✓ it un OTP incorrect est refusé                                                                               0.17s  
  ✓ it un OTP expiré est refusé                                                                                  0.17s  
  ✓ it le rate limiting bloque après 3 tentatives d'inscription                                                  0.18s  
  ✓ it un utilisateur existant peut demander un OTP de connexion                                                 0.16s  
  ✓ it un numéro inconnu ne peut pas se connecter                                                                0.14s  
  ✓ it la connexion d'un merchant révoque l'ancienne session                                                     0.16s  
  ✓ it un access token peut être renouvelé avec un refresh token valide                                          0.16s  
  ✓ it un utilisateur connecté peut se déconnecter                                                               0.15s  
  ✓ it conserve le nom du client lors de l'inscription même si verify-register ne le renvoie pas                 0.17s  
  ✓ it réinitialise le rate limit de connexion après vérification OTP réussie                                    0.23s  

   PASS  Tests\Feature\Codes\EncryptedConfirmationCodesTest
  ✓ it P7 Point 1 GoShop: stocke confirmation_code_enc, masque dans JSON generiques et autorise seulement le cl… 0.34s  
  ✓ it P7 Point 1 GoColis: stocke sender_code_enc, bloque tiers/apres picked_up et ne permet jamais de lire rec… 0.26s  
  ✓ it P7 Point 1 legacy et regeneration admin: renvoie 409 sur code sans chiffrement et regenere de facon trac… 1.88s  

   PASS  Tests\Feature\Dispatch\AfterCommitJobDispatchTest
  ✓ it P6 Point 8: envoie les jobs apres commit sous Queue::fake() et ne pousse rien en cas de rollback          0.21s  
  ✓ it P6 Point 8 panne Redis: capture exception queue apres commit, log critical et conserve la commande et le… 0.53s  

   PASS  Tests\Feature\Dispatch\AssignmentMaxActivesTest
  ✓ it P6 Point 3: AssignmentService exclut un livreur ayant deja atteint courses.max_actives pour GoShop et Go… 0.76s  
  ✓ it P6 Point 3 settings: AssignmentService autorise jusqu a courses.max_actives personnalise puis exclut ens… 0.65s  

   PASS  Tests\Feature\Dispatch\ClientLifecycleNotificationsTest
  ✓ it P6 Point 5: notifie le client (DB + push) lors de acceptOrder et confirmPickup sans notifier sur conflit  0.46s  
  ✓ it P6 Point 5 GoColis: notifie le client (DB + push) lorsque acceptParcel reussit et pas sur 409             0.35s  

   PASS  Tests\Feature\Dispatch\CourierLocationFreshnessTest
  ✓ it Point C1: CourierFactory et CreateCourier laissent current_lat, current_lng et location_updated_at a nul… 1.67s  
  ✓ it Point C2: AssignmentService exclut les livreurs sans GPS ou dont location_updated_at > 2 min (parametrab… 0.34s  

   PASS  Tests\Feature\Dispatch\DriverRefuseOrderTest
  ✓ it P6 Point 2: POST /driver/orders/{order}/refuse incremente consecutive_refusals, applique la penalite apr… 0.50s  
  ✓ it P6 Point 2 settings: refuseOrder lit refus_avant_penalite et duree_penalite_minutes depuis SettingsServi… 0.27s  
  ✓ it P6 Point 2 securite: refuseOrder rejette autre ville 404, deja assignee 409, mauvais statut 422 et hors…  0.32s  

   PASS  Tests\Feature\Dispatch\GoShopAssignmentAndTimeoutTest
  ✓ it P6 Point 1: NotifierLivreurGoShop exclut le livreur sans reponse apres 30s, passe au suivant puis passe…  0.43s  
  ✓ it P6 Point 1 idempotence: NotifierLivreurGoShop ne relance pas une commande deja acceptee par un livreur    0.28s  

   PASS  Tests\Feature\Dispatch\MerchantOpenHoursTest
  ✓ it P6 Point 6: rejette 422 si commande GoShop hors open_hours configure et autorise dedans ou sans horaires  0.31s  

   PASS  Tests\Feature\Dispatch\OrderBackupSweepCommandTest
  ✓ it planifie orders:sweep-timeouts toutes les minutes avec withoutOverlapping et onOneServer                  0.17s  
  ✓ it balaye les commandes pending et accepted/ready bloquees sans toucher waiting ni doubler a l execution re… 0.31s  
  ✓ it lit dynamiquement les delais et marges depuis SettingsService sans constantes hardcodees                  0.22s  

   PASS  Tests\Feature\Dispatch\SchedulerConfigurationTest
  ✓ it liste les 3 taches planifiees via schedule:list avec withoutOverlapping et onOneServer                    0.31s  
  ✓ it enregistre un heartbeat horodate dans le cache a chaque execution de orders:sweep-timeouts                0.13s  

   PASS  Tests\Feature\Dispatch\SettingsDelaysAndTimeoutTest
  ✓ it P6 Point 7: AnnulerCommandeTimeout annule pending apres 45s, laisse accepted intact et lit acceptation_c… 0.22s  
  ✓ it P6 Point 7: erreurs_code_avant_blocage et acceptation_livreur_secondes lus depuis SettingsService         0.23s  

   PASS  Tests\Feature\Emergency\EmergencyModeBlockingTest
  ✓ it bloque la création de commande GoShop et expédition GoColis si la ville est en mode urgence               0.32s  

   PASS  Tests\Feature\Filament\CityContextFilamentScopingTest
  ✓ it cloisonne strictement les ressources Filament par ville pour un admin local                               0.22s  

   PASS  Tests\Feature\Finance\CashFlowSecurityTest
  ✓ it 1.1a refuse 422 acceptOrder en espèces si le solde wallet est inférieur au seuil paramétré wallet.solde_… 0.22s  
  ✓ it 1.1b refuse 422 acceptOrder en espèces si le solde disponible après engagements cash existants est infér… 0.21s  
  ✓ it 1.1 concurrence: deux acceptations simultanées dépassant le solde ne laissent passer qu'une seule course… 0.21s  
  ✓ it Arbitrage 2 AssignmentService ne propose pas une course ou un colis cash à un livreur sans solde minimum… 0.24s  
  ✓ it 1.2 GoShop espèces 4000 + 800 : débite -4160 livreur, crédite +3400 commerce, aucun +640 livreur, paid_c… 0.73s  
  ✓ it 1.3 GoColis espèces (avec et sans assurance) débite part plateforme + assurance et passe payment_status…  0.64s  
  ✓ it 1.4 paiement non cash (orange_money) conserve le crédit normal livreur + commerce                         0.44s  
  ✓ it 1.5 idempotence : double validation et index unique partiel wallet_transactions empêchent tout double mo… 0.40s  
  ✓ it 1.6 invariant comptable global (part commerce + livreur + plateforme = total payé) et rollback atomique…  0.32s  

   PASS  Tests\Feature\Finance\CashLimitAtAcceptRattrapageTest
  ✓ it refuse acceptOrder, acceptParcel et dispatch auto cash avec 2 courses cash actives et solde juste au-des… 0.48s  
  ✓ it documente le rollback atomique 422 si le debit de reversement cash echoue a la livraison                  0.27s  

   PASS  Tests\Feature\Finance\RefundSecurityTest
  ✓ it 2.1 plafonds depuis SettingsService et frontières exactes 1999/2000/2001/10000/10001/50000/50001 par rôl… 0.28s  
  ✓ it 2.1 plafonds depuis SettingsService et frontières exactes 1999/2000/2001/10000/10001/50000/50001 par rôl… 0.23s  
  ✓ it 2.1 plafonds depuis SettingsService et frontières exactes 1999/2000/2001/10000/10001/50000/50001 par rôl… 0.25s  
  ✓ it 2.1 plafonds depuis SettingsService et frontières exactes 1999/2000/2001/10000/10001/50000/50001 par rôl… 0.27s  
  ✓ it 2.1 plafonds depuis SettingsService et frontières exactes 1999/2000/2001/10000/10001/50000/50001 par rôl… 0.21s  
  ✓ it 2.1 plafonds depuis SettingsService et frontières exactes 1999/2000/2001/10000/10001/50000/50001 par rôl… 0.17s  
  ✓ it 2.1 plafonds depuis SettingsService et frontières exactes 1999/2000/2001/10000/10001/50000/50001 par rôl… 0.16s  
  ✓ it 2.1 plafonds depuis SettingsService et frontières exactes 1999/2000/2001/10000/10001/50000/50001 par rôl… 0.18s  
  ✓ it 2.1 plafonds depuis SettingsService et frontières exactes 1999/2000/2001/10000/10001/50000/50001 par rôl… 0.18s  
  ✓ it 2.1 plafonds depuis SettingsService et frontières exactes 1999/2000/2001/10000/10001/50000/50001 par rôl… 0.18s  
  ✓ it 2.1 plafonds depuis SettingsService et frontières exactes 1999/2000/2001/10000/10001/50000/50001 par rôl… 0.18s  
  ✓ it 2.1 plafonds depuis SettingsService et frontières exactes 1999/2000/2001/10000/10001/50000/50001 par rôl… 0.19s  
  ✓ it 2.1 plafonds depuis SettingsService et frontières exactes 1999/2000/2001/10000/10001/50000/50001 par rôl… 0.19s  
  ✓ it 2.1 plafonds depuis SettingsService et frontières exactes 1999/2000/2001/10000/10001/50000/50001 par rôl… 0.16s  
  ✓ it 2.1 plafonds depuis SettingsService et frontières exactes 1999/2000/2001/10000/10001/50000/50001 par rôl… 0.18s  
  ✓ it 2.1 plafonds depuis SettingsService et frontières exactes 1999/2000/2001/10000/10001/50000/50001 par rôl… 0.19s  
  ✓ it 2.1 plafonds depuis SettingsService et frontières exactes 1999/2000/2001/10000/10001/50000/50001 par rôl… 0.18s  
  ✓ it 2.1 plafonds depuis SettingsService et frontières exactes 1999/2000/2001/10000/10001/50000/50001 par rôl… 0.18s  
  ✓ it 2.1 plafonds depuis SettingsService et frontières exactes 1999/2000/2001/10000/10001/50000/50001 par rôl… 0.19s  
  ✓ it 2.1 plafonds depuis SettingsService et frontières exactes 1999/2000/2001/10000/10001/50000/50001 par rôl… 0.18s  
  ✓ it 2.1 plafonds depuis SettingsService et frontières exactes 1999/2000/2001/10000/10001/50000/50001 par rôl… 0.20s  
  ✓ it 2.1 plafonds depuis SettingsService et frontières exactes 1999/2000/2001/10000/10001/50000/50001 par rôl… 0.19s  
  ✓ it 2.1 plafonds depuis SettingsService et frontières exactes 1999/2000/2001/10000/10001/50000/50001 par rôl… 0.18s  
  ✓ it 2.1 plafonds depuis SettingsService et frontières exactes 1999/2000/2001/10000/10001/50000/50001 par rôl… 0.18s  
  ✓ it 2.1 plafonds depuis SettingsService et frontières exactes 1999/2000/2001/10000/10001/50000/50001 par rôl… 0.18s  
  ✓ it 2.1 plafonds depuis SettingsService et frontières exactes 1999/2000/2001/10000/10001/50000/50001 par rôl… 0.17s  
  ✓ it 2.1 plafonds depuis SettingsService et frontières exactes 1999/2000/2001/10000/10001/50000/50001 par rôl… 0.18s  
  ✓ it 2.1 plafonds depuis SettingsService et frontières exactes 1999/2000/2001/10000/10001/50000/50001 par rôl… 0.19s  
  ✓ it 2.1 plafonds depuis SettingsService et frontières exactes 1999/2000/2001/10000/10001/50000/50001 par rôl… 0.18s  
  ✓ it 2.1 plafonds depuis SettingsService et frontières exactes 1999/2000/2001/10000/10001/50000/50001 par rôl… 0.17s  
  ✓ it 2.1 plafonds depuis SettingsService et frontières exactes 1999/2000/2001/10000/10001/50000/50001 par rôl… 0.18s  
  ✓ it 2.1 plafonds depuis SettingsService et frontières exactes 1999/2000/2001/10000/10001/50000/50001 par rôl… 0.19s  
  ✓ it 2.1 plafonds depuis SettingsService et frontières exactes 1999/2000/2001/10000/10001/50000/50001 par rôl… 0.18s  
  ✓ it 2.1 plafonds depuis SettingsService et frontières exactes 1999/2000/2001/10000/10001/50000/50001 par rôl… 0.20s  
  ✓ it 2.1 plafonds depuis SettingsService et frontières exactes 1999/2000/2001/10000/10001/50000/50001 par rôl… 0.18s  
  ✓ it 2.1 plafonds depuis SettingsService et frontières exactes 1999/2000/2001/10000/10001/50000/50001 par rôl… 0.16s  
  ✓ it 2.1 plafonds depuis SettingsService et frontières exactes 1999/2000/2001/10000/10001/50000/50001 par rôl… 0.16s  
  ✓ it 2.1 source unique settings: modifier le plafond dans settings s'applique immédiatement à RefundService e… 0.19s  
  ✓ it 2.2 table refunds : contraintes CHECK cible unique, client sans wallet sans 500, et double remboursement… 0.23s  
  ✓ it 2.4 éligibilité : refuse 422 commande/colis non payé, statut non éligible, in_transit sans litige/perte…  0.27s  
  ✓ it 2.5 & 2.6 workflow complet requested -> approved -> paid et traçabilité activity_logs sans téléphone ni…  0.38s  
  ✓ it D-REMB-1 plafonds par type de colis/commande : sans assurance perdu = 2x frais, assuré perdu = valeur dé… 0.65s  

   PASS  Tests\Feature\Finance\WalletTransactionImmutabilityTest
  ✓ it bloque la mise à jour Eloquent d'une transaction de portefeuille                                          0.45s  
  ✓ it bloque la suppression Eloquent d'une transaction de portefeuille                                          0.28s  
  ✓ it bloque la mise à jour directe SQL via Query Builder grâce au trigger PostgreSQL                           0.26s  
  ✓ it bloque la suppression directe SQL grâce au trigger PostgreSQL                                             0.25s  
  ✓ it rejette une transaction avec montant nul via contrainte CHECK amount <> 0                                 0.21s  
  ✓ it verifie que WalletTransactionResource est strictement en lecture seule sans actions de modification       0.21s  
  ✓ it permet un ajustement comptable traçable par super_admin ou national_finance avec motif obligatoire        0.26s  
  ✓ it refuse un ajustement comptable si le motif est absent ou si le rôle n'est pas habilité                    0.23s  
  ✓ it applique le garde-fou solde >= 0 sur les wallets conformément au CDC 7.4.1                                0.20s  

   PASS  Tests\Feature\GoColis\ParcelMilestonesAndSmsTest
  ✓ it P7 Point 3: impose ordre at-sender -> photo -> code -> at-recipient -> photo -> code et notifie expedite… 0.58s  
  ✓ it P7 Point 3 compatibilite: /verify-* acceptent toujours les anciens statuts sans jalon at_sender/at_recip… 0.35s  

   PASS  Tests\Feature\GoColis\ParcelPhotoDiskTest
  ✓ it configure un disque parcel_photos prive hors public avec surcharge via PARCEL_PHOTOS_DISK                 0.17s  
  ✓ it stocke la photo en prive sur parcel_photos, sert via temporaryUrl 15 min et rejette URL invalide ou expi… 0.25s  
  ✓ it fonctionne avec Storage::fake parcel_photos pour upload et temporaryUrl                                   0.20s  

   PASS  Tests\Feature\GoColis\ParcelPhotosSecurityTest
  ✓ it P7 Point 2: refuse .exe, fichiers > 5 Mo et tiers, re-encode JPEG/PNG/WebP en prive sur le disque photo   0.29s  
  ✓ it P7 Point 2: impose ordre statut photo et expose URL signees uniquement a expediteur et admin ville        0.28s  

   PASS  Tests\Feature\GoColis\ParcelSmsFailureTest
  ✓ it P7 Point 4: drapeau sms_failed + alerte admin sans code dans les logs lors echec SMS destinataire         0.21s  
  ✓ it P7 Point 4: renvoi admin POST /admin/parcels/{id}/resend-recipient-code isole par ville et reinitialise…  1.09s  

   PASS  Tests\Feature\Health\HealthCheckTest
  ✓ it supprime tout secret code en dur dans config/services.php pour health.token                               0.14s  
  ✓ it renvoie uniquement status sur GET /health public sans exposer les details internes                        0.15s  
  ✓ it renvoie les diagnostics complets sur GET /health avec header HEALTH_TOKEN valide sans fuiter de secrets   0.16s  
  ✓ it passe en degraded si Redis est hors service, scheduler > 3 min ou SMS log en production                   0.16s  
  ✓ it passe en degraded si la base de donnees echoue                                                            2.17s  

   PASS  Tests\Feature\Horizon\HorizonConfigTest
  ✓ it configure Horizon avec 3 files separees high, default et low dans config/horizon.php                      0.13s  
  ✓ it restreint la gate viewHorizon et la route /horizon aux seuls super_admin et national_tech actifs          0.18s  
  ✓ it planifie horizon:snapshot toutes les 5 minutes et queue:prune-failed --hours=168 chaque jour              0.19s  
  ✓ it dispatche NotifierLivreurGoShop sur la file high                                                          0.18s  

   PASS  Tests\Feature\Notifications\FcmPushNotificationTest
  ✓ it definit les constantes de canaux Android et ignore les identifiants Firebase dans .gitignore              0.12s  
  ✓ it enregistre et met a jour le jeton appareil via POST /api/v1/devices/token authentifie et throttle         0.25s  
  ✓ it retourne 429 au-dela de 10 requetes par minute sur POST /api/v1/devices/token                             0.51s  
  ✓ it envoie un push FCM reel via faux Messaging avec priorite haute et ttl 60s sur nouvelle commande et nouve… 0.38s  
  ✓ it remet device_token a null lorsque FCM signale un jeton desinscrit (NotFound) ou invalide (InvalidMessage… 0.29s  

   PASS  Tests\Feature\Payment\MerchantRefusalRefundTest
  ✓ it P5 Point 7: refus commerce d'une commande déjà payée crée automatiquement un remboursement requested ave… 0.42s  
  ✓ it P5 Point 7: refus commerce d'une commande cash non payée ne crée aucun remboursement (inerte tant que ca… 0.31s  

   PASS  Tests\Feature\Payment\PaymentMethodSettingsSecurityTest
  ✓ it P5 D-PAIEMENT-1: paiements.modes_actifs a pour valeur par défaut ["cash"] et utilise l'enum central Paym… 0.22s  
  ✓ it P5 ClientController::createOrder n'accepte que cash par défaut et réagit dynamiquement à paiements.modes… 0.54s  
  ✓ it P5 ParcelController::store n'accepte que cash par défaut et réagit dynamiquement à paiements.modes_actif… 0.36s  

   PASS  Tests\Feature\Payment\PaymentMethodsEndpointTest
  ✓ it P5 GET /client/payment-methods renvoie ["cash"] par défaut avec default="cash"                            0.22s  
  ✓ it P5 GET /client/payment-methods reflète dynamiquement les surcharges globales et par ville de SettingsSer… 0.22s  

   PASS  Tests\Feature\Payment\PaymentRattrapageTest
  ✓ it Point B1: une commande payee en ligne ne notifie pas le commerce et ne demarre pas les 45s avant payment… 0.37s  
  ✓ it Point B2: une commande en ligne restee pending non payee apres 10 minutes (settings) est annulee automat… 0.33s  
  ✓ it Point B3: un echec de paiement GoColis notifie l expediteur (base + push)                                 0.22s  
  ✓ it Point B4: un doublon webhook repond HTTP 200 succes=true message="déjà traité" sans retraiter             0.21s  

   PASS  Tests\Feature\Payment\WebhookSecurityP5Test
  ✓ it P5 Point 3: refuse de passer paid quand payload.amount diffère du total dû (5 800 F dû, webhook 100 F ->… 0.19s  
  ✓ it P5 Point 3: vérifie aussi le total dû sur GoColis (shipping_fee + insurance_amount) et bloque si montant… 0.21s  
  ✓ it P5 Point 4: valide la signature HMAC CinetPay avec services.cinetpay.secret_key et refuse le secret Hub2… 0.20s  
  ✓ it P5 Point 5: refuse un webhook dont l'horodatage a plus de 5 minutes (payload ou header)                   0.18s  
  ✓ it P5 Point 5: ignore un doublon transaction_id avec HTTP 200 "déjà traité" sans retraiter et garantit une…  0.19s  

   PASS  Tests\Feature\Permissions\PermissionsTest
  ✓ it un City Manager ne voit que les commandes de sa ville                                                     0.19s  
  ✓ it un City Agent ne peut pas valider un remboursement de 5000 F CFA                                          0.16s  
  ✓ it un City Finance peut valider un remboursement entre 2000 et 10000 F CFA                                   0.20s  
  ✓ it un City Finance reçoit 422 si la commande est en statut non éligible ou non payée                         0.19s  
  ✓ it un City Finance ne peut PAS valider un remboursement supérieur à 10000 F CFA                              0.17s  
  ✓ it un client ne peut pas accéder aux endpoints merchant                                                      0.13s  
  ✓ it un driver ne peut pas accéder aux endpoints client                                                        0.14s  
  ✓ it un merchant ne peut pas accéder aux endpoints admin                                                       0.14s  
  ✓ it un utilisateur non authentifié est rejeté sur les routes protégées                                        0.13s  
  ✓ it seul le Super Admin ou le DG National peut activer le Mode Urgence                                        0.14s  
  ✓ it le Super Admin peut activer le Mode Urgence                                                               0.15s  
  ✓ it seul un City Support ou supérieur peut débloquer un code bloqué                                           0.16s  
  ✓ it un City Support peut débloquer un code bloqué                                                             0.19s  

   PASS  Tests\Feature\Pricing\PricingServerDistanceSecurityTest
  ✓ it calcule la distance cote serveur par Haversine et ignore la distance envoyee par le client                0.24s  
  ✓ it refuse avec une 422 si les coordonnees sont absentes ou hors ville                                        0.22s  
  ✓ it valide le poids et le telephone destinataire pour GoColis                                                 0.16s  
  ✓ it applique la surcharge de ville specifique sur GoColis                                                     0.20s  
  ✓ it renvoie une 422 propre si le solde du livreur est insuffisant pour la livraison cash                      0.20s  

   PASS  Tests\Feature\Ratings\AttemptsResetAndRatingsTest
  ✓ it P7 Point 6: remet les compteurs confirmation_attempts, sender_code_attempts et recipient_code_attempts a… 0.64s  
  ✓ it P7 Point 6: enregistre type gocolis_courier sur colis et permet la notation du commerce (1-5, livree, un… 0.61s  

   PASS  Tests\Feature\Security\AccountActiveSecurityTest
  ✓ it refuse avec 403 un client desactive disposant dun jeton deja emis                                         0.33s  
  ✓ it refuse avec 403 un livreur desactive disposant dun jeton deja emis                                        0.34s  
  ✓ it refuse le renouvellement de token refresh pour un compte desactive                                        0.28s  

   PASS  Tests\Feature\Security\AdminLoginCredentialsSecurityTest
  ✓ it affiche les identifiants de test sur /admin/login uniquement lorsque APP_ENV est local                    0.36s  
  ✓ it masque totalement login-credentials sur /admin/login et au rendu direct hors environnement local with ('… 0.30s  
  ✓ it masque totalement login-credentials sur /admin/login et au rendu direct hors environnement local with ('… 0.30s  
  ✓ it masque totalement login-credentials sur /admin/login et au rendu direct hors environnement local with ('… 0.29s  

   PASS  Tests\Feature\Security\ApiExceptionHandlerSecurityTest
  ✓ it renvoie un JSON standard 404 pour une route api inexistante                                               0.19s  
  ✓ it renvoie un JSON standard 404 sans fuite de namespace Eloquent pour un ModelNotFound sur commande inexist… 0.22s  
  ✓ it renvoie un JSON standard 500 sans fuite de trace ni chemin en production                                  0.21s  
  ✓ it maintient fonctionnel le endpoint public /health conforme et non perturbe                                 0.18s  

   PASS  Tests\Feature\Security\D1UnlockOrderCodeSecurityTest
  ✓ it D1 nominal: un rôle local débloque une commande de sa ville, remet attempts à 0 et journalise sans le co… 0.32s  
  ✓ it D1 nominal: un rôle local débloque une commande de sa ville, remet attempts à 0 et journalise sans le co… 0.23s  
  ✓ it D1 nominal: un rôle local débloque une commande de sa ville, remet attempts à 0 et journalise sans le co… 0.19s  
  ✓ it D1 national: les rôles nationaux peuvent débloquer une commande de n'importe quelle ville with ('nationa… 0.18s  
  ✓ it D1 national: les rôles nationaux peuvent débloquer une commande de n'importe quelle ville with ('nationa… 0.18s  
  ✓ it D1 national: les rôles nationaux peuvent débloquer une commande de n'importe quelle ville with ('dg_nati… 0.18s  
  ✓ it D1 national: les rôles nationaux peuvent débloquer une commande de n'importe quelle ville with ('super_a… 0.18s  
  ✓ it D1 autre ville: un rôle local ne peut pas débloquer une commande d'une autre ville (404 sans fuite) with… 0.17s  
  ✓ it D1 autre ville: un rôle local ne peut pas débloquer une commande d'une autre ville (404 sans fuite) with… 0.17s  
  ✓ it D1 autre ville: un rôle local ne peut pas débloquer une commande d'une autre ville (404 sans fuite) with… 0.17s  
  ✓ it D1 mauvais statut: refuse de débloquer une commande clôturée (delivered, cancelled, refused) with ('deli… 0.17s  
  ✓ it D1 mauvais statut: refuse de débloquer une commande clôturée (delivered, cancelled, refused) with ('canc… 0.16s  
  ✓ it D1 mauvais statut: refuse de débloquer une commande clôturée (delivered, cancelled, refused) with ('refu… 0.17s  
  ✓ it D1 rôle interdit: refuse 403 aux rôles non habilités with ('city_agent')                                  0.16s  
  ✓ it D1 rôle interdit: refuse 403 aux rôles non habilités with ('national_tech')                               0.16s  
  ✓ it D1 rôle interdit: refuse 403 aux rôles non habilités with ('client')                                      0.16s  
  ✓ it D1 rôle interdit: refuse 403 aux rôles non habilités with ('merchant')                                    0.17s  
  ✓ it D1 rôle interdit: refuse 403 aux rôles non habilités with ('driver')                                      0.16s  
  ✓ it D1 local sans city_id: refuse 404 si le rôle local n'est rattaché à aucune ville                          0.17s  

   PASS  Tests\Feature\Security\D2AcceptOrderSecurityTest
  ✓ it D2 nominal: un livreur actif et en ligne accepte une course proposée (accepted ou ready) de sa ville wit… 0.22s  
  ✓ it D2 nominal: un livreur actif et en ligne accepte une course proposée (accepted ou ready) de sa ville wit… 0.20s  
  ✓ it D2 autre ville: refuse 404 sans fuite si la commande est dans une autre ville                             0.17s  
  ✓ it D2 mauvais statut: refuse 422 si la commande n'est pas au statut accepted ou ready with ('pending')       0.17s  
  ✓ it D2 mauvais statut: refuse 422 si la commande n'est pas au statut accepted ou ready with ('refused')       0.19s  
  ✓ it D2 mauvais statut: refuse 422 si la commande n'est pas au statut accepted ou ready with ('picked_up')     0.18s  
  ✓ it D2 mauvais statut: refuse 422 si la commande n'est pas au statut accepted ou ready with ('in_transit')    0.18s  
  ✓ it D2 mauvais statut: refuse 422 si la commande n'est pas au statut accepted ou ready with ('delivered')     0.17s  
  ✓ it D2 mauvais statut: refuse 422 si la commande n'est pas au statut accepted ou ready with ('cancelled')     0.18s  
  ✓ it D2 disponibilité (hors ligne): refuse 422 si le livreur est hors ligne                                    0.18s  
  ✓ it D2 disponibilité (suspendu): refuse 403 si le livreur ou son utilisateur est inactif with dataset "couri… 0.18s  
  ✓ it D2 disponibilité (suspendu): refuse 403 si le livreur ou son utilisateur est inactif with dataset "user…  0.17s  
  ✓ it D2 disponibilité (pénalité active): refuse 403 si penalty_until est dans le futur                         0.18s  
  ✓ it D2 disponibilité (3 refus): applique la pénalité 15 minutes et met hors ligne sur acceptOrder             0.19s  
  ✓ it D2 disponibilité (pénalité expirée): efface une pénalité passée et autorise acceptOrder                   0.20s  
  ✓ it D2 concurrence et propriétaire: deux livreurs acceptent la même commande -> un seul succès (200) et l'au… 0.21s  
  ✓ it D2 atomicité conditionnelle: si une mise à jour concurrente assigne courier_id avant l'update conditionn… 0.20s  
  ✓ it D2 rôle interdit: refuse 403 aux rôles non livreurs with ('client')                                       0.16s  
  ✓ it D2 rôle interdit: refuse 403 aux rôles non livreurs with ('merchant')                                     0.16s  
  ✓ it D2 rôle interdit: refuse 403 aux rôles non livreurs with ('city_support')                                 0.16s  
  ✓ it D2 rôle interdit: refuse 403 aux rôles non livreurs with ('super_admin')                                  0.16s  

   PASS  Tests\Feature\Security\D3ConfirmPickupSecurityTest
  ✓ it D3 nominal: le livreur assigné de la même ville confirme le ramassage depuis ready -> picked_up (CDC Ann… 0.36s  
  ✓ it D3 autre ville: refuse 404 sans fuite si la commande est dans une autre ville même avec incohérence       0.57s  
  ✓ it D3 mauvais propriétaire: refuse 403 si la commande est non assignée ou assignée à un autre livreur with…  0.39s  
  ✓ it D3 mauvais propriétaire: refuse 403 si la commande est non assignée ou assignée à un autre livreur with…  0.34s  
  ✓ it D3 mauvais statut: refuse 422 pour tout statut hors ready (dont accepted et waiting) with ('pending')     0.32s  
  ✓ it D3 mauvais statut: refuse 422 pour tout statut hors ready (dont accepted et waiting) with ('accepted')    0.28s  
  ✓ it D3 mauvais statut: refuse 422 pour tout statut hors ready (dont accepted et waiting) with ('waiting')     0.28s  
  ✓ it D3 mauvais statut: refuse 422 pour tout statut hors ready (dont accepted et waiting) with ('refused')     0.26s  
  ✓ it D3 mauvais statut: refuse 422 pour tout statut hors ready (dont accepted et waiting) with ('picked_up')   0.25s  
  ✓ it D3 mauvais statut: refuse 422 pour tout statut hors ready (dont accepted et waiting) with ('in_transit')  0.25s  
  ✓ it D3 mauvais statut: refuse 422 pour tout statut hors ready (dont accepted et waiting) with ('delivered')   0.27s  
  ✓ it D3 mauvais statut: refuse 422 pour tout statut hors ready (dont accepted et waiting) with ('cancelled')   0.24s  
  ✓ it D3 rôle interdit: refuse 403 aux rôles non livreurs with ('client')                                       0.23s  
  ✓ it D3 rôle interdit: refuse 403 aux rôles non livreurs with ('merchant')                                     0.23s  
  ✓ it D3 rôle interdit: refuse 403 aux rôles non livreurs with ('city_support')                                 0.22s  
  ✓ it D3 rôle interdit: refuse 403 aux rôles non livreurs with ('super_admin')                                  0.18s  
  ✓ it D3 graphe CDC: OrderService respecte strictement les transitions d'états autorisées                       0.25s  

   PASS  Tests\Feature\Security\D4ConfirmDeliverySecurityTest
  ✓ it D4 nominal: le livreur assigné de la même ville valide une course picked_up/in_transit avec le bon code…  0.26s  
  ✓ it D4 nominal: le livreur assigné de la même ville valide une course picked_up/in_transit avec le bon code…  0.22s  
  ✓ it D4 autre ville: refuse 404 sans consommer aucune tentative sur confirmDelivery hors ville                 0.17s  
  ✓ it D4 non livrable + mauvais code (API): confirmation_attempts reste INCHANGÉ pour pending, accepted, ready… 0.17s  
  ✓ it D4 non livrable + mauvais code (API): confirmation_attempts reste INCHANGÉ pour pending, accepted, ready… 0.18s  
  ✓ it D4 non livrable + mauvais code (API): confirmation_attempts reste INCHANGÉ pour pending, accepted, ready… 0.17s  
  ✓ it D4 non livrable + mauvais code (API): confirmation_attempts reste INCHANGÉ pour pending, accepted, ready… 0.17s  
  ✓ it D4 non livrable + mauvais code (API): confirmation_attempts reste INCHANGÉ pour pending, accepted, ready… 0.18s  
  ✓ it D4 non livrable + mauvais code (API): confirmation_attempts reste INCHANGÉ pour pending, accepted, ready… 0.17s  
  ✓ it D4 service direct non livrable + mauvais code: confirmation_attempts reste INCHANGÉ dans OrderService::v… 0.16s  
  ✓ it D4 service direct non livrable + mauvais code: confirmation_attempts reste INCHANGÉ dans OrderService::v… 0.16s  
  ✓ it D4 service direct non livrable + mauvais code: confirmation_attempts reste INCHANGÉ dans OrderService::v… 0.16s  
  ✓ it D4 service direct non livrable + mauvais code: confirmation_attempts reste INCHANGÉ dans OrderService::v… 0.16s  
  ✓ it D4 service direct non livrable + mauvais code: confirmation_attempts reste INCHANGÉ dans OrderService::v… 0.16s  
  ✓ it D4 service direct non livrable + mauvais code: confirmation_attempts reste INCHANGÉ dans OrderService::v… 0.17s  
  ✓ it D4 mauvais propriétaire: livreur non assigné refusé avant tout incrément dans OrderService et API         0.19s  
  ✓ it D4 3 mauvais codes + verrouillage puis bon code refusé sans fuite dans logs ni réponses                   0.21s  
  ✓ it D4 rôle interdit: refuse 403 aux rôles non livreurs with ('client')                                       0.16s  
  ✓ it D4 rôle interdit: refuse 403 aux rôles non livreurs with ('merchant')                                     0.16s  
  ✓ it D4 rôle interdit: refuse 403 aux rôles non livreurs with ('city_support')                                 0.16s  
  ✓ it D4 rôle interdit: refuse 403 aux rôles non livreurs with ('super_admin')                                  0.18s  

   PASS  Tests\Feature\Security\D5ShowDriverParcelSecurityTest
  ✓ it D5 nominal (assigné): le livreur assigné de la même ville voit les détails complets sans les codes secre… 0.19s  
  ✓ it D5 nominal (non assigné proposé): retourne uniquement taille, distance, frais et zones approximatives SA… 0.17s  
  ✓ it D5 nominal (non assigné proposé): retourne uniquement taille, distance, frais et zones approximatives SA… 0.17s  
  ✓ it D5 autre ville: refuse 404 sans fuite pour un colis d'une autre ville (assigné ou non) with dataset "non… 0.16s  
  ✓ it D5 autre ville: refuse 404 sans fuite pour un colis d'une autre ville (assigné ou non) with dataset "ass… 0.17s  
  ✓ it D5 mauvais propriétaire: refuse 403 pour un colis de la même ville assigné à un autre livreur             0.17s  
  ✓ it D5 mauvais statut: refuse 422 pour un colis non assigné qui n'est plus en pending ou waiting with ('deli… 0.17s  
  ✓ it D5 mauvais statut: refuse 422 pour un colis non assigné qui n'est plus en pending ou waiting with ('canc… 0.17s  
  ✓ it D5 mauvais statut: refuse 422 pour un colis non assigné qui n'est plus en pending ou waiting with ('refu… 0.16s  
  ✓ it D5 rôle interdit: refuse 403 aux rôles non livreurs with ('client')                                       0.16s  
  ✓ it D5 rôle interdit: refuse 403 aux rôles non livreurs with ('merchant')                                     0.16s  
  ✓ it D5 rôle interdit: refuse 403 aux rôles non livreurs with ('city_support')                                 0.15s  
  ✓ it D5 rôle interdit: refuse 403 aux rôles non livreurs with ('city_manager')                                 0.17s  
  ✓ it D5 rôle interdit: refuse 403 aux rôles non livreurs with ('super_admin')                                  0.17s  

   PASS  Tests\Feature\Security\DefensiveSecurityTest
  ✓ it empêche un client d'accéder aux détails du colis d'un autre client (anti-IDOR)                            0.45s  
  ✓ it un city manager ne peut pas consulter une commande d'une autre ville (anti-IDOR)                          0.40s  
  ✓ it empêche la double validation et le double crédit d'une commande GoShop déjà livrée                        0.48s  
  ✓ it empêche la double validation et le double crédit d'un colis GoColis déjà livré                            0.41s  
  ✓ it empêche un solde négatif lors d'un retrait sur wallet                                                     0.27s  

   PASS  Tests\Feature\Security\HiddenFieldsSecurityTest
  ✓ it masque device_token lors de la serialisation du modele User                                               0.24s  
  ✓ it masque code_hash lors de la serialisation du modele OtpCode                                               0.20s  

   PASS  Tests\Feature\Security\OtpSecurityP3Test
  ✓ it bloque et invalide l'OTP apres 5 echecs sur verify-login avec 429 en francais                             0.31s  
  ✓ it bloque et invalide l'OTP apres 5 echecs sur verify-register avec 429 en francais                          0.23s  
  ✓ it supprime l'enregistrement OTP apres validation reussie conformement au CDC                                0.23s  
  ✓ it applique un rate limit de blocage 1h (3600s) sur la demande OTP login                                     0.17s  

   PASS  Tests\Feature\Security\P1ParcelCodeCitySecurityTest
  ✓ it P1b autre ville: refuse 404 sans consommer tentative si un livreur tente verifySenderCode ou verifyRecip… 0.31s  

   PASS  Tests\Feature\Security\P2aSingleActiveCourseSecurityTest
  ✓ it P2a refuse 409 une 2e course GoShop si le livreur a déjà une course active (défaut courses.max_actives =… 0.46s  
  ✓ it P2a refuse 409 acceptOrder si le livreur a déjà un colis GoColis actif en cours                           0.28s  
  ✓ it P2a autorise 2 courses actives lorsque le paramètre courses.max_actives vaut 2 et bloque la 3e            0.24s  

   PASS  Tests\Feature\Security\P2bProfileCityImmutableSecurityTest
  ✓ it P2b interdit (422) le changement de city_id via /profile pour client, driver et merchant with dataset "c… 0.16s  
  ✓ it P2b interdit (422) le changement de city_id via /profile pour client, driver et merchant with dataset "d… 0.16s  
  ✓ it P2b interdit (422) le changement de city_id via /profile pour client, driver et merchant with dataset "m… 0.16s  
  ✓ it P2b interdit (422) le changement de city_id via /profile pour client, driver et merchant with dataset "m… 0.17s  

   PASS  Tests\Feature\Security\P2cCityAgentAccessSecurityTest
  ✓ it P2c refuse 403 au rôle city_agent sur /admin/orders et /admin/dashboard                                   0.14s  
  ✓ it P2c conserve l'accès 200 aux rôles admin habilités (city_support, city_finance, city_manager, super_admi… 0.17s  
  ✓ it P2c conserve l'accès 200 aux rôles admin habilités (city_support, city_finance, city_manager, super_admi… 0.18s  
  ✓ it P2c conserve l'accès 200 aux rôles admin habilités (city_support, city_finance, city_manager, super_admi… 0.16s  
  ✓ it P2c conserve l'accès 200 aux rôles admin habilités (city_support, city_finance, city_manager, super_admi… 0.16s  

   PASS  Tests\Feature\Security\P2dSuspendedMerchantRoutesSecurityTest
  ✓ it P2d autorise uniquement GET profile et GET status pour un commerce suspendu (is_active=false) et renvoie… 0.26s  

   PASS  Tests\Feature\Security\Point3DecisionsLockTest
  ✓ it Point 3 verrouille les 4 décisions D-LIVREUR-1, D-VILLE-1, D-ROLE-1, D-MERCHANT-1 dans DECISIONS.md, aud… 0.30s  

   PASS  Tests\Feature\Security\RefreshTokenAndDevBypassSecurityTest
  ✓ it renouvelle access_token et refresh_token avec un refresh token opaque de 7 jours                          0.17s  
  ✓ it configure le refresh_ttl par defaut a 10080 minutes (7 jours) selon le CDC 11.4                           0.11s  
  ✓ it prouve qu en environnement de production AUCUN bypass dev ne fonctionne                                   0.23s  
  ✓ it prouve qu en environnement local et testing AUCUN bypass dev ne fonctionne with ('testing')               0.18s  
  ✓ it prouve qu en environnement local et testing AUCUN bypass dev ne fonctionne with ('local')                 0.18s  

   PASS  Tests\Feature\Security\S1DgNationalCityContextSecurityTest
  ✓ it S1 modèle: dg_national est reconnu admin national, canAccessCity et timeout 120                           0.13s  
  ✓ it S1 HTTP: dg_national sans city_id accède aux routes admin dashboard et emergency-mode                     0.19s  

   PASS  Tests\Feature\Security\S2AdminRefundCityIsolationSecurityTest
  ✓ it S2 autre ville (order refund): refuse 404 sans fuite si un rôle local tente de rembourser une commande d… 0.19s  
  ✓ it S2 autre ville (order refund): refuse 404 sans fuite si un rôle local tente de rembourser une commande d… 0.19s  
  ✓ it S2 autre ville (order refund): refuse 404 sans fuite si un rôle local tente de rembourser une commande d… 0.18s  
  ✓ it S2 autre ville (parcel refund): refuse 404 sans fuite si un rôle local tente d'indemniser un colis d'une… 0.16s  
  ✓ it S2 autre ville (parcel refund): refuse 404 sans fuite si un rôle local tente d'indemniser un colis d'une… 0.17s  
  ✓ it S2 autre ville (parcel refund): refuse 404 sans fuite si un rôle local tente d'indemniser un colis d'une… 0.17s  
  ✓ it S2 national (order et parcel refund): autorise un rôle financier national sur toute ville                 0.25s  
  ✓ it S2 national refuse 422 si la commande est en statut pending ou non payée                                  0.20s  

   PASS  Tests\Feature\Security\S3WebhookReplaySecurityTest
  ✓ it S3 rôles interdits: refuse 403 aux rôles locaux et non habilités sur webhooks/{id}/replay with ('city_ag… 0.16s  
  ✓ it S3 rôles interdits: refuse 403 aux rôles locaux et non habilités sur webhooks/{id}/replay with ('city_su… 0.40s  
  ✓ it S3 rôles interdits: refuse 403 aux rôles locaux et non habilités sur webhooks/{id}/replay with ('city_fi… 0.41s  
  ✓ it S3 rôles interdits: refuse 403 aux rôles locaux et non habilités sur webhooks/{id}/replay with ('city_ma… 0.35s  
  ✓ it S3 rôles interdits: refuse 403 aux rôles locaux et non habilités sur webhooks/{id}/replay with ('nationa… 0.32s  
  ✓ it S3 rôles interdits: refuse 403 aux rôles locaux et non habilités sur webhooks/{id}/replay with ('dg_nati… 0.34s  
  ✓ it S3 rôles interdits: refuse 403 aux rôles locaux et non habilités sur webhooks/{id}/replay with ('client'… 0.27s  
  ✓ it S3 rôles interdits: refuse 403 aux rôles locaux et non habilités sur webhooks/{id}/replay with ('merchan… 0.26s  
  ✓ it S3 rôles interdits: refuse 403 aux rôles locaux et non habilités sur webhooks/{id}/replay with ('driver'… 0.24s  
  ✓ it S3 rôles autorisés: autorise national_tech et super_admin sur webhooks/{id}/replay with ('national_tech'… 0.30s  
  ✓ it S3 rôles autorisés: autorise national_tech et super_admin sur webhooks/{id}/replay with ('super_admin')   0.32s  
  ✓ it S3 filament replay action: visible uniquement pour super_admin et national_tech                           0.63s  
  ✓ it P5 Point 6: bouton Rejouer Filament dispatche réellement ProcessPaymentWebhook, met à jour la commande e… 1.14s  

   PASS  Tests\Feature\Security\S4RatingSecurityTest
  ✓ it S4 refuse de noter une commande non livrée ou avec un mauvais livreur et bloque les doublons              0.24s  
  ✓ it S4 refuse de noter un colis d'un autre expéditeur, non livré ou avec mauvais livreur                      0.17s  

   PASS  Tests\Feature\Security\S5ClientCatalogAndOrderSecurityTest
  ✓ it S5 refuse 404 le catalogue et l'ajout en favori d'un commerce inactif                                     0.16s  
  ✓ it S5 commande GoShop: refuse 422 un produit indisponible ou d'un autre commerce et aligne city_id sur le c… 0.24s  

   PASS  Tests\Feature\Security\S6DriverAvailabilitySecurityTest
  ✓ it S6 availability: refuse 403 si le livreur est suspendu, pénalisé ou a 3 refus consécutifs                 0.18s  
  ✓ it S6 location et nominal: refuse updateLocation si inactif et autorise mise en ligne si pénalité expirée    0.16s  

   PASS  Tests\Feature\Security\S8AssignmentCityIsolationSecurityTest
  ✓ it S8 isolation ville et statut: attribuerLivreurGoShop n'assigne pas un livreur hors ligne/inactif d'une a… 0.17s  

   PASS  Tests\Feature\Security\SingleSessionSecurityTest
  ✓ it invalide l'ancien JWT lors d'une nouvelle connexion d'un livreur et refuse l'ancien avec 401              0.21s  
  ✓ it invalide l'ancien JWT lors d'une nouvelle connexion d'un commercant et refuse l'ancien avec 401           0.22s  
  ✓ it autorise le multi-appareils pour les clients sans invalider les anciens tokens                            0.18s  

   PASS  Tests\Feature\Security\SweepIdorSecurityTest
  ✓ it balayage IDOR horizontal & vertical famille CLIENT (orders, parcels, addresses, notifications, ratings)   0.26s  
  ✓ it balayage IDOR horizontal & vertical famille MERCHANT (orders/*, products/*, status)                       0.22s  
  ✓ it balayage IDOR horizontal & vertical famille DRIVER (orders/*, parcels/*, verify-codes)                    0.24s  
  ✓ it balayage IDOR horizontal & vertical famille PROFILE et ADMIN                                              0.21s  

   PASS  Tests\Feature\Security\ThrottleAndLocationSecurityTest
  ✓ it valide strictement les coordonnees GPS entre -90/90 et -180/180 sur driver/location                       0.19s  
  ✓ it bloque les requetes de positionnement livreur a plus de 1 requete par 5 secondes                          0.16s  
  ✓ it configure les throttles de groupe auth a 60 par minute et public a 20 par minute                          0.12s  

   PASS  Tests\Feature\Sentry\SentryScrubbingTest
  ✓ it configure sentry avec send_default_pii=false, traces_sample_rate=0.1 et before_send cacheable             0.13s  
  ✓ it supprime telephone, otp, codes secrets, JWT et Authorization dans SentryEventScrubber::beforeSend         0.13s  
  ✓ it branche Sentry Integration::handles dans bootstrap/app.php                                                0.11s  

   PASS  Tests\Feature\Settings\SettingsCdcAndPersistenceTest
  ✓ it 4.1 SettingsService::DEFAULTS correspond exactement aux chapitres 8.1, 8.2, 8.3 et 8.4 du CDC             0.14s  
  ✓ it 4.2 SettingsPage affiche les libellés explicites et l'exemple chiffré pour livreur_goshop et plateforme_… 0.67s  
  ✓ it 4.3 l'enregistrement sur SettingsPage d'un seul groupe ne supprime ni n'écrase aucune clé absente du for… 1.66s  

   PASS  Tests\Feature\Settings\SettingsTest
  ✓ settings service returns cdc defaults                                                                        0.17s  
  ✓ setting modification writes to activity logs and invalidates cache                                           0.41s  
  ✓ city specific override takes precedence                                                                      0.39s  
  ✓ commission service uses dynamic settings                                                                     0.31s  
  ✓ branding returns default emoji when no logo uploaded                                                         0.61s  
  ✓ admin settings page uploads and removes golivreur logo                                                       5.04s  

   PASS  Tests\Feature\Simulation\MultiAgentSimulationTest
  ✓ simulation complete multi agents goshop daloa                                                                0.38s  
  ✓ simulation complete multi agents gocolis daloa                                                               0.26s  

   PASS  Tests\Feature\Sms\SmsGatewayTest
  ✓ it envoie un SMS avec succes via Yellika avec Http::fake, ecrit sms_logs sans message et masque le telephon… 0.17s  
  ✓ it gere une erreur 4xx Yellika sans reessai et alimente sms_failed sur GoColis                               0.19s  
  ✓ it retente au maximum 2 fois en cas de delai depasse (ConnectionException) et echoue proprement              0.13s  
  ✓ it refuse un numero invalide avant tout appel HTTP Yellika et trace echec dans sms_logs                      0.12s  
  ✓ it refuse le pilote log en environnement production et ne journalise jamais le message ni OTP                0.13s  
  ✓ it envoie reellement les OTP inscription et connexion via SmsService -> SmsGateway et declenche alerte seui… 0.17s  
  ✓ it autorise le pilote log en local/testing/staging avec message complet et echoue proprement sur auth/login… 0.16s  

   PASS  Tests\Feature\Tracking\CourierLocationPollingTest
  ✓ it P7 Point 5 GoShop: GET /client/orders/{order}/courier-location autorise seulement proprietaire sur comma… 0.68s  
  ✓ it P7 Point 5 GoColis: GET /client/parcels/{parcel}/courier-location autorise seulement expediteur sur coli… 0.85s  

  Tests:    468 passed (3298 assertions)
  Duration: 173.21s
```

### 7.2. `php ./vendor/bin/phpstan analyse --level=5 --memory-limit=1G`

```text
Note: Using configuration file C:\Projets\golivreur\backend\phpstan.neon.
   0/180 [>---------------------------]   0% 180/180 [============================] 100%


 [OK] No errors
```

### 7.3. `./vendor/bin/pint --test`

```text
{"tool":"pint","result":"passed"}
```

### 7.4. Vérification fichiers sensibles Git (`git ls-files`)

`git ls-files | Select-String -Pattern "\.env|google-services|firebase|GoogleService-Info|service-account"` :
```text
backend/.env.example
backend/config/firebase.php
```
Aucun `.env`, aucun `google-services.json`, aucun `GoogleService-Info.plist` ni fichier de compte de service Firebase suivi par Git.
