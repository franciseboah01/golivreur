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

### [2026-11-05] — Flutter 3.47+ : CardThemeData, withValues(alpha:) et dépendances temps-réel

**Contexte :** Le SDK Flutter installé est Flutter 3.47.9 / Dart 3.10. `pusher_channels_flutter 2.0.3` n'existait pas sur pub.dev, `CardTheme` est devenu `CardThemeData`, et `withOpacity` est obsolète au profit de `withValues(alpha: ...)`.
**Options considérées :** Forcer un downgrade global de Flutter / Adapter les types et dépendances à Flutter 3.47.
**Décision retenue :** Utiliser `pusher_channels_flutter: ^2.2.0`, `laravel_echo: ^1.0.0-beta.1`, `CardThemeData` et `.withValues(alpha: ...)`.
**Raison :** Assure la compatibilité à long terme avec les dernières versions d'Android 14+ et iOS 18+, tout en éliminant tous les avertissements du compilateur Dart.
**Impact :** Les 4 projets Flutter (`mobile/packages/core`, `golivreur_client`, `golivreur_business`, `golivreur_driver`) compilent avec 0 erreur et 0 avertissement (`flutter analyze` 100% propre).
**Réversible ?** Oui, rétrogradation triviale via `pubspec.yaml` si nécessaire.

<!-- Exemple de ce à quoi la prochaine entrée doit ressembler :

## [2026-XX-XX] — Titre court

**Contexte :** ...
**Options considérées :** ...
**Décision retenue :** ...
**Raison :** ...
**Impact :** ...
**Réversible ?** ...

-->
