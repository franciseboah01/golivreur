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
| Points classés en **« DÉCISION REQUISE »** (non modifiés sans arbitrage métier/tests existants) | **6** |

> Note : Le défaut potentiel sur `verify-sender-code` / `verify-recipient-code` (`city_id` pour livreurs) a été détecté lors du balayage puis basculé en **DÉCISION REQUISE** après avoir constaté que 2 tests existants non modifiables (`GoColisTest.php:139,159`) créent des colis avec des `city_id` distincts du livreur assigné.

---

## Tableau exhaustif des 71 endpoints (`api/v1/*`)

| # | Endpoint | Rôles autorisés (middleware) | Vérification de propriété (`client_id` / `merchant_id` / `courier_id`) | Vérification `city_id` | Vérification de statut | Verdict | Action |
|---|---|---|---|---|---|---|---|
| 1 | `POST api/v1/admin/cities/{city}/emergency-mode` | `auth:api`, `role:admin`, `city_context` + contrôleur (`super_admin`, `dg_national`) | N/A (action exécutive nationale) | Toutes villes (`CityContextMiddleware` échouait à tort pour `dg_national` sans ville) | N/A | **DÉFAUT (S1)** | Corrigé dans `b6e2b57` (`'dg_national'` dans `CityContextMiddleware` & `User`) |
| 2 | `GET\|HEAD api/v1/admin/dashboard` | `auth:api`, `role:admin`, `city_context` | N/A | Filtré via `city_context_id` pour locaux ; `dg_national` réparé (S1) | N/A | **DÉFAUT (S1) + DÉCISION REQUISE** | `dg_national` corrigé (`b6e2b57`) ; accès `city_agent` à arbitrer |
| 3 | `GET\|HEAD api/v1/admin/orders` | `auth:api`, `role:admin`, `city_context` | N/A | Filtré `city_id` pour non-nationaux ; `dg_national` réparé (S1) | N/A | **DÉFAUT (S1) + DÉCISION REQUISE** | `dg_national` corrigé (`b6e2b57`) ; accès `city_agent` à arbitrer |
| 4 | `POST api/v1/admin/orders/{order}/refund` | `auth:api`, `role:admin`, `city_context` + plafonds rôle | Paramètres `owner_type`/`owner_id` (voir Bloc C) | **Absente** avant S2 pour rôles locaux | Non vérifié avant Bloc C | **DÉFAUT (S2) + DÉCISION REQUISE** | Isolation `city_id` (404 hors ville) corrigée (`46072f2`) ; logique financière → Bloc C |
| 5 | `POST api/v1/admin/parcels/{parcel}/refund` | `auth:api`, `role:admin`, `city_context` + plafonds rôle | Paramètres `owner_type`/`owner_id` (voir Bloc C) | **Absente** avant S2 pour rôles locaux | Passe `refunded` sans garde statut | **DÉFAUT (S2) + DÉCISION REQUISE** | Isolation `city_id` (404 hors ville) corrigée (`46072f2`) ; statut/idempotence → Bloc C |
| 6 | `POST api/v1/admin/webhooks/{id}/replay` | `auth:api`, `role:admin`, `city_context` + contrôleur `super_admin, dg_national, national_tech` | N/A | N/A (table technique globale) | `signature_valid=true` (422), non `processed` (409) | **DÉFAUT (S3)** | Restreint aux 3 rôles nationaux techniques (`73d1c25`, 403 sinon) |
| 7 | `POST api/v1/auth/login` | Public (`api`) + rate limit | Compte actif non supprimé par téléphone | N/A | `is_active = true` | **OK** | Rate limit 3/10min en prod (`10` en dev) |
| 8 | `POST api/v1/auth/login-password` | Public (`api`) | Compte actif + Hash password | N/A | `is_active = true` | **OK** | Invariant `APP_ENV=production` noté en Décision |
| 9 | `POST api/v1/auth/logout` | `auth:api` | Token courant uniquement | N/A | N/A | **OK** | RAS |
| 10 | `POST api/v1/auth/refresh` | `auth:api` | Token courant uniquement | N/A | N/A | **OK** | RAS |
| 11 | `POST api/v1/auth/register` | Public (`api`) + rate limit | Vérifie unicité `phone` (409) | `city_id` validé optionnel | N/A | **OK** | RAS |
| 12 | `POST api/v1/auth/verify-login` | Public (`api`) | OTP `purpose=login` + `is_active` | N/A | `is_used=false`, non expiré | **OK** | Invariant `APP_ENV=production` noté en Décision |
| 13 | `POST api/v1/auth/verify-register` | Public (`api`) | Crée rôle fixé `'client'` | `city_id` vérifié en BDD | OTP non utilisé & non expiré | **OK** | Invariant `APP_ENV=production` noté en Décision |
| 14 | `GET\|HEAD api/v1/branding` | Public (`api`) | N/A | N/A | N/A | **OK** | Lecture seule branding |
| 15 | `GET\|HEAD api/v1/business/profile` | `auth:api`, `role:merchant` | Self-only (`$request->user()`) | N/A | N/A | **OK** | RAS |
| 16 | `PUT\|POST api/v1/business/profile` | `auth:api`, `role:merchant` | Self-only (`BaseProfileRequest` anti-usurpation `user_id`) | `city_id` modifiable par le commerce | N/A | **OK** | Alias `merchant/profile` |
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
| 32 | `POST api/v1/client/orders` | `auth:api`, `role:client` | `client_id = auth()->id()`, produits du `$merchant` | Aligné sur `$merchant->city_id` (S5) | Commerce `is_open & is_active`, produit `is_available` | **DÉFAUT (S5) + DÉCISION REQUISE** | Validation produits & `city_id` corrigée (`9dfb48d`) ; restriction client/merchant même ville en Décision |
| 33 | `GET\|HEAD api/v1/client/orders` | `auth:api`, `role:client` | `where('client_id', auth()->id())` | N/A | N/A | **OK** | RAS |
| 34 | `GET\|HEAD api/v1/client/orders/{order}` | `auth:api`, `role:client` | `$order->client_id === $user->id` (403) | N/A | `Order::$hidden` cache `confirmation_code` (D1) | **OK** | Test IDOR horizontal OK |
| 35 | `POST api/v1/client/parcels` | `auth:api`, `role:client` | `sender_id = $expediteur->id` | `$expediteur->city_id` (`City::findOrFail`) | `STATUS_PENDING` à la création | **OK** | RAS |
| 36 | `GET\|HEAD api/v1/client/parcels/{parcel}` | `auth:api`, `role:client` | `$parcel->sender_id === $user->id` (403) | N/A | `Parcel::$hidden` cache les codes | **OK** | Test IDOR horizontal OK |
| 37 | `GET\|HEAD api/v1/client/profile` | `auth:api`, `role:client` | Self-only (`$request->user()`) | N/A | N/A | **OK** | RAS |
| 38 | `PUT\|POST api/v1/client/profile` | `auth:api`, `role:client` | Self-only (`BaseProfileRequest`) | `city_id` modifiable par le client | N/A | **OK** | Auto-changement de `city_id` noté en Décision |
| 39 | `POST api/v1/client/ratings` | `auth:api`, `role:client` | `order.client_id` / `parcel.sender_id` (403) + `courier_id` exact (422) + anti-doublon (409) | N/A | `status === delivered` exigé (422) | **DÉFAUT (S4)** | Corrigé dans `e5f5dae` |
| 40 | `POST api/v1/driver/availability` | `auth:api`, `role:driver,courier` | `$this->getCourier()` | N/A | **Manquait** vérif `is_active`, `penalty_until`, `3 refus` | **DÉFAUT (S6)** | Bloque mise en ligne si suspendu/pénalisé (`4920676`) |
| 41 | `GET\|HEAD api/v1/driver/courses` | `auth:api`, `role:driver,courier` | `where('courier_id', $courier->id)` | N/A | Statuts actifs uniquement | **OK** | RAS |
| 42 | `POST api/v1/driver/location` | `auth:api`, `role:driver,courier` | `$this->getCourier()` | N/A | **Manquait** vérif `is_active` | **DÉFAUT (S6)** | Bloque 403 si compte livreur suspendu (`4920676`) |
| 43 | `POST api/v1/driver/orders/{order}/accept` | `auth:api`, `role:driver,courier` | `courier_id === null` atomique (`lockForUpdate` + update conditionnel), 409 sinon | `order.city_id === courier.city_id` (404 sinon) | `[accepted, ready]` (422 sinon) + éligibilité/3 refus→15 min (403/422) | **DÉFAUT D2 + DÉCISION REQUISE** | Corrigé (`36cdf76`) ; cumul courses actives non interdit par CDC |
| 44 | `POST api/v1/driver/orders/{order}/confirm-delivery` | `auth:api`, `role:driver,courier` | `order.courier_id === courier.id` (403) + vérif acteur dans `OrderService` | `order.city_id === courier.city_id` (404 sinon) | API exige `[picked_up, in_transit]` (422) ; `OrderService` bloque `pending/refused/cancelled/delivered` + `locked` avant incrément | **DÉFAUT D4 + DÉCISION REQUISE** | Corrigé (`9ce8497`) ; conflit `DefensiveSecurityTest.php:64` (`STATUS_ACCEPTED`) en Décision |
| 45 | `POST api/v1/driver/orders/{order}/confirm-pickup` | `auth:api`, `role:driver,courier` | `order.courier_id === courier.id` (403) | `order.city_id === courier.city_id` (404 sinon) | Graphe CDC `[accepted, ready] -> picked_up` (422) + `Order::TRANSITIONS_AUTORISEES` | **DÉFAUT D3** | Corrigé (`2e2e43d`) |
| 46 | `GET\|HEAD api/v1/driver/parcels/{parcel}` | `auth:api`, `role:driver,courier` | Assigné : complet sans codes ; Autre livreur : 403 ; Non assigné : DTO restreint sans PII/adresse/codes | `parcel.city_id === courier.city_id` (404 sinon) | Non assigné limité à `[pending, waiting]` (422 sinon) | **DÉFAUT D5** | Corrigé (`9072897`) |
| 47 | `GET\|HEAD api/v1/driver/profile` | `auth:api`, `role:driver,courier` | Self-only (`$request->user()`) | N/A | N/A | **OK** | RAS |
| 48 | `PUT\|POST api/v1/driver/profile` | `auth:api`, `role:driver,courier` | Self-only (`BaseProfileRequest`) | `city_id` dans `UpdateDriverProfile` | N/A | **OK** | Modification `city_id` par livreur notée en Décision |
| 49 | `GET\|HEAD api/v1/driver/wallet` | `auth:api`, `role:driver,courier` | Self-only (`owner_id = $courier->id`) | N/A | N/A | **OK** | RAS |
| 50 | `GET\|HEAD api/v1/health` | Public (`api`) | N/A | N/A | N/A | **OK** | Health check sans donnée sensible |
| 51 | `GET\|HEAD api/v1/merchant/orders` | `auth:api`, `role:merchant` | `where('merchant_id', $merchant->id)` | N/A | Statuts actifs uniquement | **OK** | RAS |
| 52 | `POST api/v1/merchant/orders/{order}/accept` | `auth:api`, `role:merchant` | `order.merchant_id === $merchant->id` (403) + `OrderService` | N/A | Graphe CDC `pending -> accepted` (`lockForUpdate`) | **OK** | Durci dans D3 (`2e2e43d`) |
| 53 | `POST api/v1/merchant/orders/{order}/ready` | `auth:api`, `role:merchant` | `order.merchant_id === $merchant->id` (403) | N/A | Graphe CDC `accepted -> ready` (`lockForUpdate`) | **OK** | Durci dans D3 (`2e2e43d`) |
| 54 | `POST api/v1/merchant/orders/{order}/refuse` | `auth:api`, `role:merchant` | `order.merchant_id === $merchant->id` (403) | N/A | Graphe CDC `pending -> refused` (`lockForUpdate`) | **OK** | Durci dans D3 (`2e2e43d`) |
| 55 | `GET\|HEAD api/v1/merchant/products` | `auth:api`, `role:merchant` | `where('merchant_id', $merchant->id)` | N/A | N/A | **OK** | RAS |
| 56 | `POST api/v1/merchant/products` | `auth:api`, `role:merchant` | `merchant_id = $merchant->id` | N/A | N/A | **OK** | RAS |
| 57 | `PUT api/v1/merchant/products/{product}` | `auth:api`, `role:merchant` | `$product->merchant_id === $merchant->id` (403) | N/A | N/A | **OK** | Test IDOR horizontal OK |
| 58 | `DELETE api/v1/merchant/products/{product}` | `auth:api`, `role:merchant` | `$product->merchant_id === $merchant->id` (403) | N/A | N/A | **OK** | Test IDOR horizontal OK |
| 59 | `GET\|HEAD api/v1/merchant/profile` | `auth:api`, `role:merchant` | Self-only (`$request->user()`) | N/A | N/A | **OK** | Alias `business/profile` |
| 60 | `PUT\|POST api/v1/merchant/profile` | `auth:api`, `role:merchant` | Self-only (`BaseProfileRequest`) | N/A | N/A | **OK** | Alias `business/profile` |
| 61 | `GET\|HEAD api/v1/merchant/status` | `auth:api`, `role:merchant` | Self (`$this->getMerchant()`) | N/A | N/A | **OK** | RAS |
| 62 | `POST api/v1/merchant/status` | `auth:api`, `role:merchant` | Self (`$this->getMerchant()`) | N/A | N/A | **OK** | Restriction si `! $merchant->is_active` en Décision |
| 63 | `POST api/v1/orders/{order}/unlock-code` | `auth:api` + contrôleur (`city_support/finance/manager`, `national_support/finance`, `dg_national`, `super_admin`) | N/A (support/admin) | Locaux limités à `order.city_id === user.city_id` (404 sinon) | Refuse `delivered/cancelled/refused` (422) ; remet `attempts=0` + `activity_logs` sans code | **DÉFAUT D1** | Corrigé dans `55b2383` |
| 64 | `POST api/v1/parcels/{parcel}/verify-recipient-code` | `auth:api` + `authorizeCodeVerification` | `parcel.courier_id === $courier->id` (403), ou `super_admin`/`dg_national` | **Manque** vérif `city_id` pour livreur (en Décision) | `[picked_up, in_transit, at_recipient]` + photo + verrouillage | **DÉCISION REQUISE** | Conflit `GoColisTest.php:139,159` → voir Décisions ci-dessous |
| 65 | `POST api/v1/parcels/{parcel}/verify-sender-code` | `auth:api` + `authorizeCodeVerification` | `parcel.courier_id === $courier->id` (403), ou `super_admin`/`dg_national` | **Manque** vérif `city_id` pour livreur (en Décision) | `[accepted, at_sender]` + photo + verrouillage | **DÉCISION REQUISE** | Conflit `GoColisTest.php:139,159` → voir Décisions ci-dessous |
| 66 | `GET\|HEAD api/v1/profile` | `auth:api` | Self-only (`$request->user()`) | N/A | N/A | **OK** | RAS |
| 67 | `PUT api/v1/profile/password` | `auth:api` | Self-only + vérif `current_password` (422) | N/A | N/A | **OK** | RAS |
| 68 | `GET\|HEAD api/v1/profile/{user}` | `auth:api` | `Gate::authorize('viewSelfProfile', $user)` (403) | N/A | N/A | **OK** | Test IDOR horizontal OK |
| 69 | `PUT\|POST api/v1/profile/{user}` | `auth:api` | `Gate::authorize('updateSelfProfile', $user)` (403) | N/A | N/A | **OK** | Test IDOR horizontal OK |
| 70 | `GET\|HEAD api/v1/vehicle-types` | Public (`api`) | N/A | N/A | `is_active = true` | **OK** | RAS |
| 71 | `POST api/v1/webhooks/payment` | Public (`api`) + `throttle:60,1` | HMAC-SHA256 `hash_equals` obligatoire (401 sinon) | N/A | Idempotence dans `ProcessPaymentWebhook` | **OK** | RAS |

### Service transverse vérifié lors du balayage (`S8`)

| Composant | Défaut trouvé | Action | Commit |
|---|---|---|---|
| `AssignmentService::trouverLivreurProche` | Requête sans GPS utilisait `->whereNull('penalty_until')->orWhere('penalty_until', '<', now())` sans parenthèses ni groupement SQL, contournant `city_id`, `is_online = true` et `is_active = true` (fuite inter-villes et assignation de livreurs suspendus/hors ligne). | Requête factorisée avec `where(fn ($q) => ...)` groupant les conditions sur `penalty_until`. | `d28bc78` |

---

## DÉCISION REQUISE (arbitrages métier / conflits avec tests existants non modifiables)

1. **`OrderService::validerCodeConfirmation` vs test hérité `DefensiveSecurityTest.php:64` (`D4`)** :
   - Le test existant non modifiable `DefensiveSecurityTest.php:64` appelle directement `OrderService::validerCodeConfirmation($order, '1234')` sans utilisateur connecté avec `status => Order::STATUS_ACCEPTED` et attend `Order::STATUS_DELIVERED`.
   - Selon la machine d'états CDC v5 (Annexe A), `accepted` désigne « Commerce a accepté, préparation en cours » et doit passer par `confirmPickup` → `picked_up` avant livraison.
   - **État implémenté sans casser les tests existants** : `DriverController::confirmDelivery` (API `/api/v1/driver/orders/{order}/confirm-delivery`) exige strictement `['picked_up', 'in_transit']` (422 sinon, y compris sur `accepted` et `ready`), tandis que `OrderService::validerCodeConfirmation` rejette `['pending', 'refused', 'cancelled', 'delivered']` avant incrément et conserve temporairement la tolérance `STATUS_ACCEPTED` pour ce test hérité.
   - **Décision demandée** : autoriser la mise à jour de `DefensiveSecurityTest.php:64` vers `Order::STATUS_PICKED_UP` et restreindre `OrderService::validerCodeConfirmation` uniquement à `['picked_up', 'in_transit']`.

2. **`ParcelController::verifySenderCode / verifyRecipientCode` vs fixtures `GoColisTest.php:139,159`** :
   - Les méthodes `verifySenderCode` et `verifyRecipientCode` vérifient `courier_id` (403), mais pas la cohérence `parcel.city_id === courier.city_id`.
   - Deux tests existants non modifiables (`GoColisTest.php:139` et `159`) instancient `Parcel::factory()->create(['courier_id' => $courier->id, ...])` sans passer `city_id`, ce qui crée des colis rattachés à une deuxième ville par la factory.
   - **Décision demandée** : autoriser l'ajout de `'city_id' => $city->id` dans ces deux fixtures de `GoColisTest.php` afin d'activer le contrôle `404` hors ville dans `ParcelController::authorizeCodeVerification`.

3. **Cumul de courses actives par livreur (`D2` — `DriverController::acceptOrder`)** :
   - Vérification effectuée dans `CDC/GoLivreur_CahierDesCharges_v5.md` (sections `7.4.1`, `7.4.2`, `Annexe C`) : le CDC n'interdit pas explicitement à un livreur d'avoir plusieurs courses actives simultanées du même type, et `DriverController::courses()` retourne d'ailleurs une collection d'ordres et de colis actifs.
   - De même, le seuil wallet minimum de `10 000 F CFA` pour courses cash (`CDC 7.4.1`) n'est pas appliqué sur `acceptOrder`.
   - **Décision demandée** : confirmer si un livreur doit être bloqué dès qu'il possède une course GoShop (ou GoColis) active, et à quel niveau appliquer le seuil cash de `10 000 F CFA`.

4. **Remboursements Admin (`admin/orders/{order}/refund` et `admin/parcels/{parcel}/refund`)** :
   - L'isolation inter-villes (404 hors ville pour rôles locaux) a été corrigée (`46072f2`).
   - Les règles financières (validation `owner_type`/`owner_id`, vérification qu'une commande/colis est éligible/non déjà remboursée, blocage `parcelRefund` à `declared_value` / `has_insurance`) relèvent des décisions financières (`audit/decisions-argent.md`, Bloc C).

5. **Client commandant dans une autre ville (`client/orders`) & modification libre de `city_id` dans `client/profile` / `driver/profile`** :
   - `OrderService::creerCommande` aligne désormais `order.city_id` sur `merchant.city_id`, mais n'empêche pas un client inscrit dans une ville A de commander chez un commerce d'une ville B (cas d'un client en déplacement), ni un utilisateur de changer son `city_id` via `PUT /profile`.
   - **Décision demandée** : confirmer si un client ou un livreur peut changer de ville librement ou uniquement sur validation Admin.

6. **Accès lecture `admin/dashboard`, `admin/orders`, actions `merchant/*` si `! is_active`, et invariant `APP_ENV=production`** :
   - `admin/dashboard` et `admin/orders` acceptent tout rôle `ADMIN_ROLES` (dont `city_agent`), scopé par ville pour les locaux ; arbitrer si `city_agent` doit en être exclu.
   - `MerchantController` n'interdit pas explicitement `toggleStatus` / `acceptOrder` si `$merchant->is_active === false` (cas d'un commerce suspendu conservant un JWT actif).
   - `AuthController` autorise OTP `'1234'` et mots de passe dev uniquement lorsque `app()->environment('local', 'testing')` — vérifier systématiquement `APP_ENV=production` au déploiement.
