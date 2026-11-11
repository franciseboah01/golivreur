# DECISIONS.md — Journal des Décisions Techniques
## Projet GoLivreur v5.0

> Chaque décision technique non triviale prise pendant le développement est documentée ici — au moment où elle est prise, pas après coup.
> Objectif : que Francis (non-technique) et tout futur développeur (ex: Lead Dev Bouaké dans 18 mois) comprenne POURQUOI le code est comme il est, sans avoir à redemander.
> Une décision déjà tranchée ici ne se re-débat pas sans raison nouvelle et documentée.

Format de chaque entrée :

```
## [DATE] — Titre court de la décision

**Contexte :** Pourquoi cette question s'est posée.
**Options considérées :** Ce qui était possible.
**Décision retenue :** Ce qu'on fait.
**Raison :** Pourquoi celle-ci et pas une autre.
**Impact :** Ce que ça change dans le code / le planning / le budget.
**Réversible ?** Oui/Non — et à quel coût si on doit revenir dessus.
```

---

## Décisions déjà actées dans le CDC v5.0 (référence — ne pas rouvrir sans validation Francis)

Ces décisions ont été validées par Francis pendant la phase de cadrage. Elles sont listées ici pour mémoire mais ne doivent pas être reproposées comme "nouvelles décisions" — s'y référer directement.

### [2026-06-24] — Architecture Laravel : MVC + Services (pas de DDD)
**Contexte :** Équipe de développement réduite (1 Lead Dev + 1 renfort Flutter ponctuel), planning de 17 semaines très serré.
**Options considérées :** MVC classique / MVC + Services / DDD-Clean Architecture.
**Décision retenue :** MVC + Services.
**Raison :** Le DDD apporte de la rigueur mais demande une cérémonie de code qu'une équipe de 2 personnes ne peut pas maintenir dans les délais. MVC + Services garde une séparation propre sans surcharge.
**Impact :** Structure `app/Http/Controllers` + `app/Services` + `app/Modules/{GoShop,GoColis}`.
**Réversible ?** Oui, à coût moyen — migration progressive possible module par module si l'équipe grandit.

### [2026-06-24] — Authentification API : JWT (pas Sanctum)
**Contexte :** 3 apps mobiles Flutter consommant la même API, aucun besoin de sessions web classiques.
**Décision retenue :** JWT via `tymon/jwt-auth`.
**Raison :** Sanctum est pensé pour SPA/web ou API simple à un seul client. JWT stateless est plus adapté à 3 apps mobiles indépendantes avec refresh token.
**Impact :** Endpoint `/api/auth/refresh` obligatoire. Aucune session serveur à gérer pour les apps mobiles.
**Réversible ?** Non sans réécriture significative de la couche Auth — à ne pas remettre en cause sauf faille de sécurité majeure découverte.

### [2026-06-24] — Multi-tenancy : colonne `city_id` (pas de BDD séparée par ville)
**Contexte :** Expansion prévue vers Bouaké, Yamoussoukro, Korhogo, San Pedro à 18 mois, puis 10 villes à 36 mois.
**Décision retenue :** Une seule base PostgreSQL, filtrage automatique par `city_id` via middleware Laravel (City Context).
**Raison :** Une BDD par ville multiplierait la complexité opérationnelle (migrations ×N, backups ×N, monitoring ×N) sans bénéfice réel à notre échelle (dizaines de milliers de lignes, pas de millions).
**Impact :** Chaque table métier (`orders`, `parcels`, `merchants`...) porte une colonne `city_id`. Ouvrir une ville = ajouter une ligne dans `cities`, zéro changement d'infrastructure.
**Réversible ?** Oui mais coûteux — à ne reconsidérer qu'au-delà de 500 000+ commandes/an cumulées.

### [2026-06-24] — Recherche : SQL `LIKE` PostgreSQL (pas Meilisearch/Scout)
**Contexte :** Recherche de commerces et produits par nom/catégorie/mot-clé.
**Décision retenue :** `LIKE` / `ILIKE` PostgreSQL natif au lancement.
**Raison :** À 150 commerces et quelques milliers de produits (objectif 12 mois), un moteur de recherche dédié est une dépendance supplémentaire sans gain perceptible. Meilisearch devient pertinent au-delà de 500+ commerces.
**Impact :** Pas de service Meilisearch à maintenir sur le VPS au lancement.
**Réversible ?** Oui, migration simple avec Laravel Scout quand le seuil est atteint — prévu en v2.

### [2026-06-24] — Panier GoShop : un seul commerce par commande (pas de multi-commerces)
**Contexte :** Un client pourrait vouloir acheter chez plusieurs commerces en une seule commande/livraison.
**Options considérées :** (A) Mono-commerce strict / (B) Multi-commandes séparées mais session unique / (C) Vrai panier multi-commerces avec 1 paiement et 1 livreur multi-arrêts.
**Décision retenue :** Option A pour le lancement — Option B (multi-commandes séparées, simple) comme amélioration immédiate possible sans complexité additionnelle.
**Raison :** L'option C introduit une complexité logistique et financière majeure (frais de livraison multi-arrêts, refus partiel, remboursement partiel, ordre des ramassages) pour un gain marginal au lancement à Daloa.
**Impact :** Table `orders` = toujours 1 `merchant_id`. Pas de table `basket` séparée.
**Réversible ?** Oui — prévu explicitement comme chantier v2, nécessite un ADR dédié avant implémentation (voir Chapitre du CDC sur ce sujet).

### [2026-06-24] — Remboursements : délégation par plafond (pas centralisé Super Admin)
**Contexte :** Le CDC v4.0 centralisait tous les remboursements sur le Super Admin, créant un goulot d'étranglement opérationnel.
**Décision retenue :** Plafonds par rôle — voir Chapitre 8.5 du CDC (`< 2 000` City Support, `2 000-10 000` City Finance, `10 000-50 000` City Manager, `> 50 000` Super Admin).
**Raison :** Un remboursement de 1 500 F CFA qui attend la disponibilité du Fondateur dégrade l'expérience client sans raison — le risque financier est proportionnel au plafond délégué.
**Impact :** Table `permissions` doit encoder ces plafonds, pas juste un booléen "peut rembourser".
**Réversible ?** Oui, les plafonds sont configurables sans migration de schéma.

### [2026-06-24] — Objectif de livraison affiché : "moins d'une heure" (pas "45 minutes") au lancement
**Contexte :** Contradiction identifiée entre le slogan marketing ("45 minutes") et l'objectif opérationnel réel à 3 mois (< 55 min).
**Décision retenue :** Communication publique "moins d'une heure" au lancement, "45 minutes" devient l'objectif communiqué à partir de 6 mois quand il est atteint.
**Raison :** Une promesse non tenue dès le lancement détruit la confiance plus vite qu'elle ne la construit.
**Impact :** Tous les textes UI, la vitrine, et les supports marketing utilisent "moins d'une heure" jusqu'à nouvel ordre de Francis.
**Réversible ?** Oui, simple changement de string — mais nécessite validation explicite de Francis avant de changer.

---

## Nouvelles décisions prises pendant le développement

*(Claude Code : ajoute ici chaque nouvelle décision technique au fil des sprints, avec le format ci-dessus. Ne supprime jamais une entrée existante — si une décision est remplacée, ajoute une nouvelle entrée qui référence l'ancienne et explique pourquoi elle change.)*

### [2026-11-05] — Environnement local : Services natifs Windows PostgreSQL 16 & Redis 7 (au lieu de Docker Desktop)

**Contexte :** Docker Desktop n'est pas installé dans le PATH de l'environnement de développement local Windows, alors que PostgreSQL 16.15 et Redis 7 tournent déjà en tant que services système Windows natifs.
**Options considérées :** Bloquer le projet en attendant l'installation de Docker Desktop / Utiliser les services natifs Windows existants.
**Décision retenue :** Utiliser directement PostgreSQL 16 natif (port 5432, base `golivreur_dev`) et Redis natif (port 6379, testé `PONG`).
**Raison :** Les versions et fonctionnalités correspondent parfaitement aux spécifications techniques de production du CDC v5.0 (PostgreSQL 16 + Redis 7). Cela permet de développer immédiatement sans blocage ni perte de performance.
**Impact :** Fichier `backend/.env` configuré avec les accès locaux natifs. Aucun impact sur les scripts Docker de déploiement VPS en production (`docker-compose.prod.yml`).
**Réversible ?** Oui, à tout moment en lançant `docker compose up -d` dès que Docker Desktop est installé.

### [2026-11-05] — Compatibilité Laravel 12 & JWT : PHPOpenSourceSaver/JWTAuth avec alias Tymon

**Contexte :** Le package legacy `tymon/jwt-auth` a été forké et modernisé sous `php-open-source-saver/jwt-auth` pour supporter PHP 8.2+ et Laravel 11/12. Certains contrôleurs et tests font référence à `Tymon\JWTAuth\Contracts\JWTSubject` et `Tymon\JWTAuth\Facades\JWTAuth`.
**Options considérées :** Réécrire tous les modèles et tests pour utiliser le nouveau namespace / Créer un `class_alias` dans `AppServiceProvider`.
**Décision retenue :** Enregistrer `class_alias(\PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject::class, 'Tymon\JWTAuth\Contracts\JWTSubject')` et `class_alias(\PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth::class, 'Tymon\JWTAuth\Facades\JWTAuth')`.
**Raison :** Permet la compatibilité binaire immédiate avec tous les tests et codes préexistants du CDC sans refactorisation fragile.
**Impact :** `AppServiceProvider.php` gère l'alias transparentement. Les tests Pest `AuthTest` et `WalletServiceTest` passent à 100%.
**Réversible ?** Oui, par remplacement global du namespace si souhaité.

### [2026-11-05] — Formule Haversine sous PostgreSQL : whereRaw avec cosinus borné

**Contexte :** Dans la recherche de coursiers géographiquement proches, PostgreSQL refuse l'alias `distance` dans la clause `HAVING` sans agrégation explicite ou sous-requête, provoquant une erreur SQL 42703.
**Options considérées :** Réécrire avec une sous-requête SQL complexe / Remplacer `HAVING` par un `whereRaw` avec l'expression trigonométrique haversine et protection `least(1.0, greatest(-1.0, ...))`.
**Décision retenue :** `whereRaw` avec cosinus borné à `[-1, 1]` pour éviter tout débordement de calcul dans `acos()`.
**Raison :** Solution rapide, portable, ne dépendant pas d'extensions tierces (comme PostGIS) et immédiatement compatible avec la base PostgreSQL 16 locale et de production.
**Impact :** `AssignmentService` attribue les coursiers sans aucune erreur SQL.
**Réversible ?** Oui, passage éventuel à PostGIS en v2 si les volumes le justifient.

### [2026-11-05] — Découplage AssignmentService et NotificationService

**Contexte :** Le code initial contenait `AssignmentService` imbriqué dans le même fichier que `CommissionService.php`, en violation du standard PSR-4 et provoquant des conflits d'autoloading.
**Options considérées :** Conserver la structure monolithique / Extraire `AssignmentService` et créer `NotificationService` comme services dédiés.
**Décision retenue :** Extraction de `AssignmentService` dans `app/Modules/GoShop/Services/` et création de `NotificationService` dans `app/Services/`.
**Raison :** Séparation des responsabilités (SRP) et conformité stricte PSR-4.
**Impact :** Couverture de test complète sur les flux de commande et d'attribution.
**Réversible ?** Non nécessaire.

### [2026-11-05] — Flutter : GoLivreurTheme et package golivreur_core

**Contexte :** Le package partagé est nommé `golivreur_core` dans les `pubspec.yaml`, et sa classe centrale de design est `GoLivreurTheme`.
**Options considérées :** Renommer le package en `core` / Utiliser `package:golivreur_core` et `GoLivreurTheme`.
**Décision retenue :** Aligner tous les imports sur `package:golivreur_core` et `GoLivreurTheme.theme`.
**Raison :** Respecte l'architecture du monorepo sans casser les configurations existantes.
**Impact :** Analyse Flutter à 100% propre (0 issue sur les 4 projets).
**Réversible ?** Oui.

### [2026-11-06] — Sécurité Webhooks : Validation HMAC SHA256 stricte et table webhook_logs

**Contexte :** Les webhooks Mobile Money (Hub2/CinetPay) doivent être sécurisés contre les attaques par rejeu et les fausses confirmations de paiement.
**Options considérées :** Vérification par IP uniquement / Validation par signature HMAC SHA256 avec clé secrète partagée et logging systématique avant exécution.
**Décision retenue :** Validation stricte par HMAC SHA256 avec rejet 401 si signature invalide ou absente, enregistrement préalable de chaque requête dans `webhook_logs`, et traitement asynchrone sur file prioritaire `high`.
**Raison :** Exigence de sécurité bancaire et conformité CDC Chapitre 8 & 11.
**Impact :** Zéro risque de faux paiements, traçabilité immuable et capacité de rejeu manuel par un administrateur en cas de panne réseau temporaire.
**Réversible ?** Non (fondement de sécurité requis).

### [2026-11-06] — Base de données dédiée aux tests : golivreur_testing

**Contexte :** `phpunit.xml` pointait par défaut vers `edulink_testing`, risquant des collisions de tables avec un autre projet de la machine hôte.
**Options considérées :** Conserver la base commune / Créer une base PostgreSQL dédiée `golivreur_testing`.
**Décision retenue :** Création et isolation sur la base PostgreSQL dédiée `golivreur_testing`.
**Raison :** Isolation stricte des données et garantie que `RefreshDatabase` ne modifie jamais les tables d'un projet tiers.
**Impact :** Exécution déterministe et isolée de la suite de 54 tests Pest.
**Réversible ?** Oui.

### [2026-11-06] — Notation livreur pondérée et gestion des indemnisations assurance

**Contexte :** Finalisation de GoColis (Sprint 10) nécessitant un mécanisme de notation des coursiers et de gestion des sinistres assurance.
**Options considérées :** Stocker uniquement la dernière note / Recalculer la moyenne pondérée à chaque note sur la table `couriers` et créer un modèle `Rating` lié.
**Décision retenue :** Modèle `Rating` complet et mise à jour atomique de `average_rating` et `total_ratings` sur le livreur ; ajout du point d'entrée `parcelRefund` avec vérification des plafonds de délégation par rôle.
**Raison :** Permet un affichage instantané de la réputation du livreur sans calcul coûteux et garantit le respect des plafonds financiers du CDC.
**Impact :** Sprint 10 validé et testé de bout en bout.
**Réversible ?** Oui.

### [2026-11-11] — D-LIVREUR-1 : Course active unique paramétrable par livreur (`courses.max_actives`)

**Contexte :** Le CDC (sections `7.4.1`, `7.4.2`, `Annexe C`) ne tranchait pas formellement le cumul de courses simultanées, tandis que `DriverController::acceptOrder` laissait un livreur accumuler plusieurs courses actives. Arbitrage produit requis (`D2`).
**Options considérées :** Plafond codé en dur à 1 course / Aucune limite / Plafond piloté dynamiquement par `SettingsService` (`courses.max_actives`, défaut `1`).
**Décision retenue :** Ajout de la clé de configuration `'courses.max_actives' => 1` dans `SettingsService::DEFAULTS` et vérification atomique dans `DriverController::acceptOrder` (sous transaction + `lockForUpdate`) comptant les courses GoShop actives (`accepted`, `ready`, `picked_up`, `in_transit`) et GoColis actives (`accepted`, `at_sender`, `picked_up`, `in_transit`, `at_recipient`) : rejet HTTP `409` avec message français dès que le plafond `courses.max_actives` est atteint.
**Raison :** Protège la ponctualité des livraisons en lancement tout en permettant d'ouvrir le multi-course par configuration admin sans déploiement de code.
**Impact :** `SettingsService`, `DriverController::acceptOrder`, suite `P2aSingleActiveCourseSecurityTest` et `Point3DecisionsLockTest`.
**Réversible ?** Oui (modification de la clé `courses.max_actives` en base via `SettingsService`).

### [2026-11-11] — D-VILLE-1 : Verrouillage de `city_id` sur `/profile` pour les utilisateurs non-admin

**Contexte :** Les endpoints `/api/v1/profile`, `/client/profile`, `/driver/profile`, `/merchant/profile` et `/business/profile` acceptaient auparavant `city_id`, permettant à un client, livreur ou commerce de basculer librement vers une autre ville sans validation opérationnelle.
**Options considérées :** Ignorer silencieusement `city_id` / Rejeter explicitement `city_id` en validation HTTP `422` et réserver le changement de ville aux administrateurs habilités.
**Décision retenue :** Règle `'city_id' => ['prohibited']` avec message français dans `BaseProfileRequest` et suppression de toute écriture sur `city_id` dans `ProfileController` (utilisateurs communs, livreurs, commerces). Tout changement de ville d'un compte passe désormais par le back-office administrateur Filament.
**Raison :** Empêche le contournement d'isolation par ville côté API tout en restant transparent pour les 3 apps mobiles Flutter qui n'envoient pas ce champ dans `/profile`.
**Impact :** `BaseProfileRequest`, `ProfileController`, suite `P2bProfileCityImmutableSecurityTest` et `Point3DecisionsLockTest`.
**Réversible ?** Oui.

### [2026-11-11] — D-ROLE-1 : Exclusion de `city_agent` sur `/admin/orders` et `/admin/dashboard`

**Contexte :** `AdminController::orders()` et `dashboard()` acceptaient auparavant tout rôle de `RoleMiddleware::ADMIN_ROLES` (dont `city_agent`), alors que les chapitres `9.1`, `9.3` et `10.2` (ainsi que `6.4`) du CDC v5.0 (`CDC/GoLivreur_CahierDesCharges_v5.md`) définissent `city_agent` (« Agent de Terrain ») uniquement pour le terrain (recrutement commerces, formation livreurs) sans lui accorder de lecture sur les commandes ou indicateurs d'exploitation.
**Options considérées :** Maintenir une lecture scopée par ville / Exclure strictement `city_agent` en HTTP `403`.
**Décision retenue :** Exclusion stricte (`403`, message français) de `city_agent` dans `AdminController::verifierAccesOperations()` pour `/admin/orders` et `/admin/dashboard` (seuls les rôles support/finance/manager locaux et nationaux autorisés y conservent l'accès scopé par ville).
**Raison :** Application stricte du principe du moindre privilège conformément à la matrice des rôles du CDC v5.0 tant qu'aucune fiche fonctionnelle ne lui attribue la supervision des commandes.
**Impact :** `AdminController`, suite `P2cCityAgentAccessSecurityTest` et `Point3DecisionsLockTest`.
**Réversible ?** Oui (ajout du rôle à `ROLES_LECTURE_OPERATIONS` si le CDC évolue).

### [2026-11-11] — D-MERCHANT-1 : Blocage des routes `merchant/*` lorsque `is_active = false`

**Contexte :** Un commerce suspendu par l'administration (`merchants.is_active = false`) mais détenteur d'un JWT encore valide pouvait encore consulter ou accepter des commandes, modifier ses produits ou basculer son statut d'ouverture (`is_open`).
**Options considérées :** Filtrer uniquement `acceptOrder` / Appliquer un middleware dédié sur toutes les routes commerce hors consultation du profil et du statut.
**Décision retenue :** Création de `ActiveMerchantMiddleware` (alias `active_merchant`) et garde dans `ProfileController::updateBusiness` renvoyant HTTP `403` avec message français pour tout commerce avec `is_active = false` sur toutes les routes `merchant/*` et l'écriture de `business/profile`, en maintenant uniquement `GET /merchant/profile`, `GET /business/profile` et `GET /merchant/status` accessibles en lecture seule (permettant à l'app Business d'afficher l'état de suspension).
**Raison :** Garantit l'effet immédiat d'une suspension administrative sur l'activité commerciale sans casser l'écran de profil/statut côté application Business.
**Impact :** `ActiveMerchantMiddleware`, `bootstrap/app.php`, `routes/api.php`, `ProfileController`, suite `P2dSuspendedMerchantRoutesSecurityTest` et `Point3DecisionsLockTest`.
**Réversible ?** Oui (réactivation via `is_active = true` en base).

---

## Décisions du fondateur — 2026-11-11

### [2026-11-11] — D-TARIF-1 : Non-cumul pluie et heure de pointe (priorité au tarif Pluie)

- **Identifiant :** `D-TARIF-1`
- **Contexte :** Le CDC (`Chapitre 8.3 — Barème des frais de livraison GoShop`) définit trois colonnes tarifaires (`Tarif normal`, `Heure de pointe*`, `Mode Pluie`), mais reste silencieux sur la règle applicable lorsque le mode pluie est actif pendant une heure de pointe (`11:30–14:00` ou `18:00–21:00`) : tarif Pluie exclusif ou cumul des deux majorations.
- **Décision :** Lorsque la pluie et l'heure de pointe sont simultanées, le tarif **PLUIE s'applique seul, sans cumul** (`CommissionService::calculerFraisLivraison()` teste `rain_mode_active` en priorité avant `estHeureDePointe()`).
- **Justification :** Le tableau du Chapitre `8.3` définit 3 niveaux exclusifs (`Normal` / `Pointe` / `Pluie`) ; la colonne `Mode Pluie` correspond déjà au plafond le plus élevé de chaque tranche kilométrique.
- **Exemple chiffré :** Course GoShop de `3 km` (tranche `2 - 4 km` : Normal `800 F CFA`, Pointe `1 000 F CFA`, Pluie `1 300 F CFA`) à `12:30` avec `rain_mode_active = true` = **`1 300 F CFA`** (et non `1 500 F CFA`).
- **Test qui la verrouille :** `backend/tests/Feature/Api/V1/GoShop/CommissionServiceTest.php` — test `'it calcule le barème GoShop CDC 8.3 en tarif normal, pointe seule, pluie seule et pluie+pointe'` (lignes `30` et `36–40`).
- **Comment la modifier plus tard :** Les montants de chaque colonne sont modifiables sans changement de code via la table `settings` (clé `tarifs_goshop.bareme` via Filament `SettingsPage` ou `SettingsService::set()`). Modifier la règle de priorité/non-cumul elle-même nécessiterait de modifier `CommissionService::calculerFraisLivraison()`.

### [2026-11-11] — D-TARIF-2 : Neutralité de GoColis vis-à-vis de la pluie et des heures de pointe

- **Identifiant :** `D-TARIF-2`
- **Contexte :** Les chapitres `8.2` (*Structure financière GoColis*) et `8.4` (*Barème des frais d'expédition GoColis*) ne comportent aucune colonne Pluie ni heure de pointe et ne précisent pas explicitement si les majorations GoShop (`8.3`) s'étendent ou non à GoColis.
- **Décision :** **GoColis n'est affecté ni par la pluie ni par l'heure de pointe** ; `CommissionService::calculerFraisExpedition()` applique dans tous les cas uniquement la formule `base + max(0, distance - 2) * par_km`.
- **Justification :** Les chapitres `8.2` et `8.4` du CDC ne prévoient ni colonne pluie ni colonne pointe et l'exemple officiel du Chapitre `8.2` (`Colis Moyen, 4 km : 1 800 F CFA`) est fixe.
- **Exemple chiffré :** Colis `moyen` sur `4 km` (`1 200 + (4 - 2) × 300`) = **`1 800 F CFA`** en toute circonstance (y compris à `12:30` avec `rain_mode_active = true`, réparti `1 350 F CFA` livreur / `450 F CFA` plateforme).
- **Test qui la verrouille :** `backend/tests/Feature/Api/V1/GoShop/CommissionServiceTest.php` — test `'it calcule le barème GoColis CDC 8.2/8.4 (cas 4 km moyen = 1 800 F CFA et partage 75%/25%)'` (lignes `80–89`).
- **Comment la modifier plus tard :** Modifiable plus tard via `settings` (clé `tarifs_gocolis` : `bareme`, `seuil_assurance`, `taux_assurance`) dans Filament `SettingsPage` ou `SettingsService::set()` sans changement de code. Ajouter une majoration pluie/pointe sur GoColis nécessiterait de modifier `CommissionService::calculerFraisExpedition()`.

### [2026-11-11] — D-TARIF-3 : Bornes horaires de pointe inclusives (`11:30–14:00` et `18:00–21:00`)

- **Identifiant :** `D-TARIF-3`
- **Contexte :** Le Chapitre `8.3` mentionne en note de tableau `*Heures de pointe : 11h30-14h00 et 18h00-21h00*` sans préciser si les minutes limites exactes (`11:30`, `14:00`, `18:00`, `21:00`) sont incluses ou exclues de la majoration.
- **Décision :** Les bornes horaires des heures de pointe sont **inclusives à la minute** (`$heureCourante >= $plage['debut'] && $heureCourante <= $plage['fin']`), soit `11:30–14:00` compris et `18:00–21:00` compris.
- **Justification :** Conforme à l'interprétation courante des créneaux `11h30-14h00` / `18h00-21h00` du CDC `8.3` et au comportement vérifié de `CommissionService::estHeureDePointe()`.
- **Exemple chiffré :** Livraison GoShop de `3 km` (sans pluie) : à **`14:00` = `1 000 F CFA`** (heure de pointe), à **`14:01` = `800 F CFA`** (tarif normal) ; bornes vérifiées de `11:29` (`false`) à `11:30` (`true`), `14:00` (`true`), `14:01` (`false`), `17:59` (`false`), `18:00` (`true`), `21:00` (`true`), `21:01` (`false`).
- **Test qui la verrouille :** `backend/tests/Feature/Api/V1/GoShop/CommissionServiceTest.php` — test `'it vérifie les bornes horaires des heures de pointe GoShop (11:29, 11:30, 14:00, 14:01 et soirée)'` (lignes `43–76`).
- **Comment la modifier plus tard :** Les plages `debut` et `fin` sont configurables dans `settings` via la clé `tarifs_goshop.heures_pointe` (Filament `SettingsPage` ou `SettingsService::set()`). Passer à une borne de fin exclusive (`<`) imposerait une modification de `CommissionService::estHeureDePointe()`.

