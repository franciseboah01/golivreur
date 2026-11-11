# Balayage Systématique des Autorisations API — GoLivreur v5.0

- **Source** : `php artisan route:list --path=api --json` (**71 endpoints applicatifs** sous `api/v1/*`, plus 23 routes internes Horizon `horizon/api/*` protégées par le middleware `horizon` + `Laravel\Horizon\Http\Middleware\Authenticate`).
- **Date / Référence** : Branche `develop` (CDC v5.0 — `CDC/GoLivreur_CahierDesCharges_v5.md`).
- **Convention cross-city (anti-énumération)** : Pour toute ressource appartenant à une autre ville manipulée par un acteur local (`city_*`, `driver`, `courier`), la réponse est **404** (et non 403) afin de ne jamais confirmer l'existence d'un identifiant hors de sa ville. Pour les ressources **de la même ville** appartenant à un autre utilisateur (client, commerce, livreur), la réponse reste **403** (conforme aux tests existants `DefensiveSecurityTest.php` et `ClientExperienceTest.php`).

---

## Synthèse quantitative

| Indicateur | Valeur |
|---|---|
| Endpoints `api/v1/*` audités (`routes/api.php`) | **71** |
| Défauts ciblés **D1–D5** corrigés (1 commit chacun + preuve rouge→vert) | **5** (D1, D2, D3, D4, D5) |
| Défauts **supplémentaires** trouvés & corrigés lors du balayage (1 commit chacun + preuve rouge→vert) | **7** (S1, S2, S3, S4, S5, S6, S8) |
| Arbitrages Point 1 & Point 2 appliqués (`DECISIONS.md`) | **6 / 6 résolus** (Points 1 & 2 traités ; volets financiers basculés vers Prompt 3 (argent)) |
| Points restant en **« DÉCISION REQUISE »** | **0** |

> Note : Le contrôle `city_id` livreur sur `verify-sender-code` / `verify-recipient-code` ainsi que la suppression de la tolérance `STATUS_ACCEPTED` dans `OrderService::validerCodeConfirmation` ont été appliqués dans le Point 1 (`52b1454`) après correction autorisée et documentée de `DefensiveSecurityTest.php:64` et `GoColisTest.php:139,159`. Les 4 décisions produit (`D-LIVREUR-1`, `D-VILLE-1`, `D-ROLE-1`, `D-MERCHANT-1`) ont été implémentées dans le Point 2 (`13d4014`) et consignées dans `DECISIONS.md` (Point 3).

---

## Tableau exhaustif des 71 endpoints (`api/v1/*`)

| # | Endpoint | Rôles autorisés (middleware) | Vérification de propriété (`client_id` / `merchant_id` / `courier_id`) | Vérification `city_id` | Vérification de statut | Verdict | Action |
|---|---|---|---|---|---|---|---|
| 1 | `POST api/v1/admin/cities/{city}/emergency-mode` | `auth:api`, `role:admin`, `city_context` + contrôleur (`super_admin`, `dg_national`) | N/A (action exécutive nationale) | Toutes villes (`CityContextMiddleware` échouait à tort pour `dg_national` sans ville) | N/A | **DÉFAUT (S1)** | Corrigé dans `b6e2b57` (`'dg_national'` dans `CityContextMiddleware` & `User`) |
| 2 | `GET\|HEAD api/v1/admin/dashboard` | `auth:api`, `role:admin`, `city_context` + `AdminController::ROLES_LECTURE_OPERATIONS` | N/A | Filtré via `city_context_id` pour locaux ; `dg_national` réparé (S1) | Exclusion `city_agent` (`403`, CDC `9.1/9.3/10.2`) | **DÉFAUT (S1) + P2c** | `dg_national` corrigé (`b6e2b57`) ; `city_agent` bloqué `403` (`13d4014`, `D-ROLE-1`) |
| 3 | `GET\|HEAD api/v1/admin/orders` | `auth:api`, `role:admin`, `city_context` + `AdminController::ROLES_LECTURE_OPERATIONS` | N/A | Filtré `city_id` pour non-nationaux ; `dg_national` réparé (S1) | Exclusion `city_agent` (`403`, CDC `9.1/9.3/10.2`) | **DÉFAUT (S1) + P2c** | `dg_national` corrigé (`b6e2b57`) ; `city_agent` bloqué `403` (`13d4014`, `D-ROLE-1`) |
| 4 | `POST api/v1/admin/orders/{order}/refund` | `auth:api`, `role:admin`, `city_context` + plafonds rôle | Paramètres `owner_type`/`owner_id` (Prompt 3 argent) | Isolation `city_id` (404 hors ville, S2) | Traitée par Prompt 3 (argent) | **DÉFAUT (S2)** | Isolation `city_id` corrigée (`46072f2`) ; règles financières traitées par le Prompt 3 (argent) |
| 5 | `POST api/v1/admin/parcels/{parcel}/refund` | `auth:api`, `role:admin`, `city_context` + plafonds rôle | Paramètres `owner_type`/`owner_id` (Prompt 3 argent) | Isolation `city_id` (404 hors ville, S2) | Traitée par Prompt 3 (argent) | **DÉFAUT (S2)** | Isolation `city_id` corrigée (`46072f2`) ; statut/idempotence traités par le Prompt 3 (argent) |
| 6 | `POST api/v1/admin/webhooks/{id}/replay` | `auth:api`, `role:admin`, `city_context` + contrôleur `super_admin, dg_national, national_tech` | N/A | N/A (table technique globale) | `signature_valid=true` (422), non `processed` (409) | **DÉFAUT (S3)** | Restreint aux 3 rôles nationaux techniques (`73d1c25`, 403 sinon) |
| 7 | `POST api/v1/auth/login` | Public (`api`) + rate limit | Compte actif non supprimé par téléphone | N/A | `is_active = true` | **OK** | Rate limit 3/10min en prod (`10` en dev) |
| 8 | `POST api/v1/auth/login-password` | Public (`api`) | Compte actif + Hash password | N/A | `is_active = true` | **OK** | Invariant `APP_ENV=production` noté en Décision |
| 9 | `POST api/v1/auth/logout` | `auth:api` | Token courant uniquement | N/A | N/A | **OK** | RAS |
| 10 | `POST api/v1/auth/refresh` | `auth:api` | Token courant uniquement | N/A | N/A | **OK** | RAS |
| 11 | `POST api/v1/auth/register` | Public (`api`) + rate limit | Vérifie unicité `phone` (409) | `city_id` validé optionnel | N/A | **OK** | RAS |
| 12 | `POST api/v1/auth/verify-login` | Public (`api`) | OTP `purpose=login` + `is_active` | N/A | `is_used=false`, non expiré | **OK** | Invariant `APP_ENV=production` noté en Décision |
| 13 | `POST api/v1/auth/verify-register` | Public (`api`) | Crée rôle fixé `'client'` | `city_id` vérifié en BDD | OTP non utilisé & non expiré | **OK** | Invariant `APP_ENV=production` noté en Décision |
| 14 | `GET\|HEAD api/v1/branding` | Public (`api`) | N/A | N/A | N/A | **OK** | Lecture seule branding |
| 15 | `GET\|HEAD api/v1/business/profile` | `auth:api`, `role:merchant` | Self-only (`$request->user()`) | N/A | Lecture seule conservée même si `!is_active` (`D-MERCHANT-1`) | **OK** | Alias `GET merchant/profile` ; lecture seule autorisée si suspendu (`13d4014`) |
| 16 | `PUT\|POST api/v1/business/profile` | `auth:api`, `role:merchant`, `active_merchant` | Self-only (`BaseProfileRequest` anti-usurpation `user_id`) | `city_id` `prohibited` (`422`, `D-VILLE-1`) | `active_merchant` bloque `403` si `!is_active` (`D-MERCHANT-1`) | **OK (P2b+P2d)** | `city_id` verrouillé & écriture bloquée si suspendu (`13d4014`) |
| 17 | `GET\|HEAD api/v1/categories` | Public (`api`) | N/A | N/A | `is_active = true` | **OK** | Catalogue public actif |
| 18 | `GET\|HEAD api/v1/cities` | Public (`api`) | N/A | N/A | `is_active = true` | **OK** | Villes actives uniquement |
| 19 | `GET\|HEAD api/v1/client/addresses` | `auth:api`, `role:client` | `$user->addresses()` | N/A | N/A | **OK** | Scopé utilisateur |
| 20 | `POST api/v1/client/addresses` | `auth:api`, `role:client` | `$user->addresses()->create(...)` | `city_id` validé `exists:cities,id` | N/A | **OK** | RAS |
| 21 | `PUT api/v1/client/addresses/{address}` | `auth:api`, `role:client` | `$address->user_id === $user->id` (403) | `city_id` validé | N/A | **OK** | Test IDOR horizontal OK |
| 22 | `DELETE api/v1/client/addresses/{address}` | `auth:api`, `role:client` | `$address->user_id === $user->id` (403) | N/A | N/A | **OK** | Test IDOR horizontal OK |
| 23 | `POST api/v1/client/addresses/{address}/default` | `auth:api`, `role:client` | `$address->user_id === $user->id` (403) | N/A | N/A | **OK** | Test IDOR horizontal OK |
| 24 | `GET\|HEAD api/v1/client/categories` | Public (`api`) | N/A | N/A | `is_active = true` | **OK** | Alias public |
| 25 | `GET\|HEAD api/v1/client/city-status` | Public (`api`) | N/A | Query `city_id` ou ville utilisateur/défaut | Quartiers `is_active = true` | **OK** | Données publiques ville |
| 26 | `GET\|HEAD api/v1/client/favorites` | `auth:api`, `role:client` | `$user->favoriteMerchants()` | N/A | `is_active = true` | **OK** | RAS |
| 27 | `POST api/v1/client/favorites/{merchant}/toggle` | `auth:api`, `role:client` | Self (`$user->favoriteMerchants()`) | N/A | **Manquait** `is_active` | **DÉFAUT (S5)** | Retourne 404 si `! $merchant->is_active` (`9dfb48d`) |
| 28 | `GET\|HEAD api/v1/client/merchants` | Public (`api`) | N/A | Filtré par `city_id` (query ou user) | `is_active = true` | **OK** | RAS |
| 29 | `GET\|HEAD api/v1/client/merchants/{merchant}/catalog` | Public (`api`) | N/A | N/A (public) | **Manquait** `$merchant->is_active` | **DÉFAUT (S5)** | Retourne 404 si commerce inactif (`9dfb48d`) |
| 30 | `GET\|HEAD api/v1/client/notifications` | `auth:api`, `role:client` | `where('user_id', auth()->id())` | N/A | N/A | **OK** | RAS |
| 31 | `PUT api/v1/client/notifications/{notification}/read` | `auth:api`, `role:client` | `$notification->user_id === auth()->id()` (403) | N/A | N/A | **OK** | Test IDOR horizontal OK |
| 32 | `POST api/v1/client/orders` | `auth:api`, `role:client` | `client_id = auth()->id()`, produits du `$merchant` | Aligné sur `$merchant->city_id` (S5) ; profil client verrouillé (`D-VILLE-1`) | Commerce `is_open & is_active`, produit `is_available` | **DÉFAUT (S5)** | Validation produits & `city_id` corrigée (`9dfb48d`) ; profil `city_id` verrouillé (`D-VILLE-1`, `13d4014`) |
| 33 | `GET\|HEAD api/v1/client/orders` | `auth:api`, `role:client` | `where('client_id', auth()->id())` | N/A | N/A | **OK** | RAS |
| 34 | `GET\|HEAD api/v1/client/orders/{order}` | `auth:api`, `role:client` | `$order->client_id === $user->id` (403) | N/A | `Order::$hidden` cache `confirmation_code` (D1) | **OK** | Test IDOR horizontal OK |
| 35 | `POST api/v1/client/parcels` | `auth:api`, `role:client` | `sender_id = $expediteur->id` | `$expediteur->city_id` (`City::findOrFail`) | `STATUS_PENDING` à la création | **OK** | RAS |
| 36 | `GET\|HEAD api/v1/client/parcels/{parcel}` | `auth:api`, `role:client` | `$parcel->sender_id === $user->id` (403) | N/A | `Parcel::$hidden` cache les codes | **OK** | Test IDOR horizontal OK |
| 37 | `GET\|HEAD api/v1/client/profile` | `auth:api`, `role:client` | Self-only (`$request->user()`) | N/A | N/A | **OK** | RAS |
| 38 | `PUT\|POST api/v1/client/profile` | `auth:api`, `role:client` | Self-only (`BaseProfileRequest`) | `city_id` `prohibited` (`422`, `D-VILLE-1`) | N/A | **OK (P2b)** | Modification `city_id` interdite (`422`) sur `/profile` (`13d4014`, `D-VILLE-1`) |
| 39 | `POST api/v1/client/ratings` | `auth:api`, `role:client` | `order.client_id` / `parcel.sender_id` (403) + `courier_id` exact (422) + anti-doublon (409) | N/A | `status === delivered` exigé (422) | **DÉFAUT (S4)** | Corrigé dans `e5f5dae` |
| 40 | `POST api/v1/driver/availability` | `auth:api`, `role:driver,courier` | `$this->getCourier()` | N/A | **Manquait** vérif `is_active`, `penalty_until`, `3 refus` | **DÉFAUT (S6)** | Bloque mise en ligne si suspendu/pénalisé (`4920676`) |
| 41 | `GET\|HEAD api/v1/driver/courses` | `auth:api`, `role:driver,courier` | `where('courier_id', $courier->id)` | N/A | Statuts actifs uniquement | **OK** | RAS |
| 42 | `POST api/v1/driver/location` | `auth:api`, `role:driver,courier` | `$this->getCourier()` | N/A | **Manquait** vérif `is_active` | **DÉFAUT (S6)** | Bloque 403 si compte livreur suspendu (`4920676`) |
| 43 | `POST api/v1/driver/orders/{order}/accept` | `auth:api`, `role:driver,courier` | `courier_id === null` atomique (`lockForUpdate` + update conditionnel), 409 sinon | `order.city_id === courier.city_id` (404 sinon) | `[accepted, ready]` (422) + éligibilité/3 refus→15 min (403/422) + plafond `courses.max_actives` (`409`, `D-LIVREUR-1`) | **DÉFAUT (D2) + P2a** | Corrigé (`36cdf76`) ; course active unique paramétrable (`13d4014`, `D-LIVREUR-1`) ; seuil cash traité par le Prompt 3 (argent) |
| 44 | `POST api/v1/driver/orders/{order}/confirm-delivery` | `auth:api`, `role:driver,courier` | `order.courier_id === courier.id` (403) + vérif acteur dans `OrderService` | `order.city_id === courier.city_id` (404 sinon) | API et `OrderService` exigent strictement `[picked_up, in_transit]` (422) + anti-verrouillage (423) | **DÉFAUT (D4) + P1a** | Corrigé (`9ce8497`) ; tolérance `STATUS_ACCEPTED` retirée & `DefensiveSecurityTest:64` corrigé (`52b1454`) |
| 45 | `POST api/v1/driver/orders/{order}/confirm-pickup` | `auth:api`, `role:driver,courier` | `order.courier_id === courier.id` (403) | `order.city_id === courier.city_id` (404 sinon) | Graphe CDC `[accepted, ready] -> picked_up` (422) + `Order::TRANSITIONS_AUTORISEES` | **DÉFAUT D3** | Corrigé (`2e2e43d`) |
| 46 | `GET\|HEAD api/v1/driver/parcels/{parcel}` | `auth:api`, `role:driver,courier` | Assigné : complet sans codes ; Autre livreur : 403 ; Non assigné : DTO restreint sans PII/adresse/codes | `parcel.city_id === courier.city_id` (404 sinon) | Non assigné limité à `[pending, waiting]` (422 sinon) | **DÉFAUT D5** | Corrigé (`9072897`) |
| 47 | `GET\|HEAD api/v1/driver/profile` | `auth:api`, `role:driver,courier` | Self-only (`$request->user()`) | N/A | N/A | **OK** | RAS |
| 48 | `PUT\|POST api/v1/driver/profile` | `auth:api`, `role:driver,courier` | Self-only (`BaseProfileRequest`) | `city_id` `prohibited` (`422`, `D-VILLE-1`) | N/A | **OK (P2b)** | Modification `city_id` interdite (`422`) sur `/profile` (`13d4014`, `D-VILLE-1`) |
| 49 | `GET\|HEAD api/v1/driver/wallet` | `auth:api`, `role:driver,courier` | Self-only (`owner_id = $courier->id`) | N/A | N/A | **OK** | RAS |
| 50 | `GET\|HEAD api/v1/health` | Public (`api`) | N/A | N/A | N/A | **OK** | Health check sans donnée sensible |
| 51 | `GET\|HEAD api/v1/merchant/orders` | `auth:api`, `role:merchant`, `active_merchant` | `where('merchant_id', $merchant->id)` | N/A | `active_merchant` (`403` si `!is_active`) + statuts actifs | **OK (P2d)** | Commerce suspendu bloqué `403` (`13d4014`, `D-MERCHANT-1`) |
| 52 | `POST api/v1/merchant/orders/{order}/accept` | `auth:api`, `role:merchant`, `active_merchant` | `order.merchant_id === $merchant->id` (403) + `OrderService` | N/A | `active_merchant` (`403`) + graphe CDC `pending -> accepted` (`lockForUpdate`) | **OK (P2d)** | Durci dans D3 (`2e2e43d`) et `D-MERCHANT-1` (`13d4014`) |
| 53 | `POST api/v1/merchant/orders/{order}/ready` | `auth:api`, `role:merchant`, `active_merchant` | `order.merchant_id === $merchant->id` (403) | N/A | `active_merchant` (`403`) + graphe CDC `accepted -> ready` (`lockForUpdate`) | **OK (P2d)** | Durci dans D3 (`2e2e43d`) et `D-MERCHANT-1` (`13d4014`) |
| 54 | `POST api/v1/merchant/orders/{order}/refuse` | `auth:api`, `role:merchant`, `active_merchant` | `order.merchant_id === $merchant->id` (403) | N/A | `active_merchant` (`403`) + graphe CDC `pending -> refused` (`lockForUpdate`) | **OK (P2d)** | Durci dans D3 (`2e2e43d`) et `D-MERCHANT-1` (`13d4014`) |
| 55 | `GET\|HEAD api/v1/merchant/products` | `auth:api`, `role:merchant`, `active_merchant` | `where('merchant_id', $merchant->id)` | N/A | `active_merchant` (`403` si `!is_active`) | **OK (P2d)** | Commerce suspendu bloqué `403` (`13d4014`, `D-MERCHANT-1`) |
| 56 | `POST api/v1/merchant/products` | `auth:api`, `role:merchant`, `active_merchant` | `merchant_id = $merchant->id` | N/A | `active_merchant` (`403` si `!is_active`) | **OK (P2d)** | Commerce suspendu bloqué `403` (`13d4014`, `D-MERCHANT-1`) |
| 57 | `PUT api/v1/merchant/products/{product}` | `auth:api`, `role:merchant`, `active_merchant` | `$product->merchant_id === $merchant->id` (403) | N/A | `active_merchant` (`403` si `!is_active`) | **OK (P2d)** | Test IDOR horizontal OK + `D-MERCHANT-1` (`13d4014`) |
| 58 | `DELETE api/v1/merchant/products/{product}` | `auth:api`, `role:merchant`, `active_merchant` | `$product->merchant_id === $merchant->id` (403) | N/A | `active_merchant` (`403` si `!is_active`) | **OK (P2d)** | Test IDOR horizontal OK + `D-MERCHANT-1` (`13d4014`) |
| 59 | `GET\|HEAD api/v1/merchant/profile` | `auth:api`, `role:merchant` | Self-only (`$request->user()`) | N/A | Lecture seule conservée même si `!is_active` (`D-MERCHANT-1`) | **OK** | Alias `GET business/profile` ; lecture seule autorisée si suspendu (`13d4014`) |
| 60 | `PUT\|POST api/v1/merchant/profile` | `auth:api`, `role:merchant`, `active_merchant` | Self-only (`BaseProfileRequest`) | `city_id` `prohibited` (`422`, `D-VILLE-1`) | `active_merchant` (`403` si `!is_active`, `D-MERCHANT-1`) | **OK (P2b+P2d)** | `city_id` verrouillé & écriture bloquée si suspendu (`13d4014`) |
| 61 | `GET\|HEAD api/v1/merchant/status` | `auth:api`, `role:merchant` | Self (`$this->getMerchant()`) | N/A | Lecture seule conservée même si `!is_active` (`D-MERCHANT-1`) | **OK** | Expose `is_active` même si commerce suspendu (`13d4014`) |
| 62 | `POST api/v1/merchant/status` | `auth:api`, `role:merchant`, `active_merchant` | Self (`$this->getMerchant()`) | N/A | `active_merchant` (`403` si `!is_active`, `D-MERCHANT-1`) | **OK (P2d)** | Bascule ouverture bloquée `403` si suspendu (`13d4014`) |
| 63 | `POST api/v1/orders/{order}/unlock-code` | `auth:api` + contrôleur (`city_support/finance/manager`, `national_support/finance`, `dg_national`, `super_admin`) | N/A (support/admin) | Locaux limités à `order.city_id === user.city_id` (404 sinon) | Refuse `delivered/cancelled/refused` (422) ; remet `attempts=0` + `activity_logs` sans code | **DÉFAUT D1** | Corrigé dans `55b2383` |
| 64 | `POST api/v1/parcels/{parcel}/verify-recipient-code` | `auth:api` + `authorizeCodeVerification` | `parcel.courier_id === $courier->id` (403), ou `super_admin`/`dg_national` | `parcel.city_id === $courier->city_id` (`404` hors ville, P1b) | `[picked_up, in_transit, at_recipient]` + photo + verrouillage | **DÉFAUT (P1b)** | Contrôle `city_id` (`404` hors ville) ajouté & `GoColisTest:159` corrigé (`52b1454`) |
| 65 | `POST api/v1/parcels/{parcel}/verify-sender-code` | `auth:api` + `authorizeCodeVerification` | `parcel.courier_id === $courier->id` (403), ou `super_admin`/`dg_national` | `parcel.city_id === $courier->city_id` (`404` hors ville, P1b) | `[accepted, at_sender]` + photo + verrouillage | **DÉFAUT (P1b)** | Contrôle `city_id` (`404` hors ville) ajouté & `GoColisTest:139` corrigé (`52b1454`) |
| 66 | `GET\|HEAD api/v1/profile` | `auth:api` | Self-only (`$request->user()`) | N/A | N/A | **OK** | RAS |
| 67 | `PUT api/v1/profile/password` | `auth:api` | Self-only + vérif `current_password` (422) | N/A | N/A | **OK** | RAS |
| 68 | `GET\|HEAD api/v1/profile/{user}` | `auth:api` | `Gate::authorize('viewSelfProfile', $user)` (403) | N/A | N/A | **OK** | Test IDOR horizontal OK |
| 69 | `PUT\|POST api/v1/profile/{user}` | `auth:api` | `Gate::authorize('updateSelfProfile', $user)` (403) | `city_id` `prohibited` (`422`) | Commerce suspendu bloqué `403` via `updateBusiness` (`13d4014`) | **OK** | Test IDOR horizontal OK |
| 70 | `GET\|HEAD api/v1/vehicle-types` | Public (`api`) | N/A | N/A | `is_active = true` | **OK** | RAS |
| 71 | `POST api/v1/webhooks/payment` | Public (`api`) + `throttle:60,1` | HMAC-SHA256 `hash_equals` obligatoire (401 sinon) | N/A | Idempotence dans `ProcessPaymentWebhook` | **OK** | RAS |

### Service transverse vérifié lors du balayage (`S8`)

| Composant | Défaut trouvé | Action | Commit |
|---|---|---|---|
| `AssignmentService::trouverLivreurProche` | Requête sans GPS utilisait `->whereNull('penalty_until')->orWhere('penalty_until', '<', now())` sans parenthèses ni groupement SQL, contournant `city_id`, `is_online = true` et `is_active = true` (fuite inter-villes et assignation de livreurs suspendus/hors ligne). | Requête factorisée avec `where(fn ($q) => ...)` groupant les conditions sur `penalty_until`. | `d28bc78` |

---

## Arbitrages résolus (ex-« DÉCISION REQUISE » — Points 1, 2 & 3)

1. **`OrderService::validerCodeConfirmation` vs test hérité `DefensiveSecurityTest.php:64` (`D4`)** — **Statut : `traitée`** (`52b1454`) :
   - `DefensiveSecurityTest.php:64` a été corrigé vers `Order::STATUS_PICKED_UP` avec livreur authentifié (`$this->actingAs($driverUser, 'api')`), tout en conservant les assertions d'origine sur le non-double-crédit des wallets et le refus de double validation.
   - La tolérance sur `Order::STATUS_ACCEPTED` a été supprimée dans `OrderService::validerCodeConfirmation`, qui exige désormais strictement `['picked_up', 'in_transit']`.

2. **`ParcelController::verifySenderCode / verifyRecipientCode` vs fixtures `GoColisTest.php:139,159`** — **Statut : `traitée`** (`52b1454`) :
   - Les fixtures `GoColisTest.php:139,159` renseignent désormais `'city_id' => $courier->city_id`.
   - `ParcelController::authorizeCodeVerification` bloque désormais en HTTP `404` (`Expédition introuvable.`) toute tentative par un livreur d'une autre ville, sans consommer de tentative (`P1ParcelCodeCitySecurityTest`).

3. **Cumul de courses actives par livreur (`D2` — `DriverController::acceptOrder`) & seuil cash `10 000 F CFA`** — **Statut : `traitée` (`D-LIVREUR-1`, `13d4014`) + `traitée par le Prompt 3 (argent)` (seuil cash)** :
   - **Cumul de courses (`traitée`)** : ajout de `courses.max_actives` (défaut `1`) dans `SettingsService` ; `DriverController::acceptOrder` refuse (`409`) toute nouvelle acceptation si le livreur possède déjà `>= max_actives` courses actives GoShop ou GoColis (`D-LIVREUR-1`, `P2aSingleActiveCourseSecurityTest`).
   - **Seuil wallet cash `10 000 F CFA`** : **`traitée par le Prompt 3 (argent)`**.

4. **Remboursements Admin (`admin/orders/{order}/refund` et `admin/parcels/{parcel}/refund`)** — **Statut : `traitée par le Prompt 3 (argent)`** :
   - L'isolation inter-villes (`404` hors ville pour rôles locaux) reste acquise (`S2`, commit `46072f2`).
   - Les règles financières (validation `owner_type`/`owner_id`, éligibilité/idempotence du remboursement, plafonnement `parcelRefund` à `declared_value` / `has_insurance`) sont **traitées par le Prompt 3 (argent)** (`audit/decisions-argent.md`).

5. **Client commandant dans une autre ville (`client/orders`) & modification libre de `city_id` dans `/profile`** — **Statut : `traitée` (`D-VILLE-1`, `13d4014`)** :
   - Le champ `city_id` est désormais `prohibited` (`422`) sur `/profile` pour `client`, `driver` et `merchant` (`BaseProfileRequest` + `ProfileController`, `D-VILLE-1`, `P2bProfileCityImmutableSecurityTest`), la réaffectation de ville étant réservée aux administrateurs habilités.
   - `OrderService::creerCommande` continue d'aligner `order.city_id` sur `merchant.city_id` (`S5`, commit `9dfb48d`) afin qu'un client en déplacement puisse commander chez un commerce actif local.

6. **Accès lecture `admin/dashboard`, `admin/orders`, actions `merchant/*` si `! is_active`, et invariant `APP_ENV=production`** — **Statut : `traitée` (`D-ROLE-1`, `D-MERCHANT-1`, `13d4014`)** :
   - `city_agent` reçoit `403` sur `/admin/orders` et `/admin/dashboard` conformément aux chapitres `9.1`, `9.3`, `10.2` (et `6.4`) du CDC v5.0 (`D-ROLE-1`, `P2cCityAgentAccessSecurityTest`).
   - Un commerce suspendu (`is_active = false`) reçoit `403` sur toutes les routes `merchant/*` et écritures profil (`ActiveMerchantMiddleware` + `ProfileController::updateBusiness`), à l'exception de la lecture seule sur `profile` et `status` (`D-MERCHANT-1`, `P2dSuspendedMerchantRoutesSecurityTest`).
   - Invariant d'exploitation maintenu : `APP_ENV=production` obligatoire au déploiement afin de désactiver OTP `'1234'` et mots de passe de développement dans `AuthController`.
