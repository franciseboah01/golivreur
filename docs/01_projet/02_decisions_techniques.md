# GoLivreur — Décisions Techniques (ADR)

**Architecture Decision Records — Version 5.0**

---

## ADR-001 — JWT plutôt que Sanctum pour l'API mobile

**Date** : Juin 2026  
**Statut** : Accepté

**Contexte**  
Trois applications mobiles Flutter (Client, Business, Driver) consomment la même API Laravel. Les tokens doivent être sans état (stateless) pour fonctionner correctement avec les apps mobiles, y compris en mode hors-ligne avec resynchronisation.

**Options considérées**  
- Laravel Sanctum (tokens opaques, sessions serveur)
- JWT via `tymon/jwt-auth` (tokens auto-porteurs, stateless)

**Décision**  
JWT. L'endpoint `/api/auth/refresh` est explicitement prévu dans le CDC. Les tokens JWT permettent une validation sans appel BDD à chaque requête, ce qui réduit la charge sur le VPS à 8Go/4CPU.

**Conséquences**  
- Pas de révocation immédiate de token (liste noire Redis si besoin urgent)
- Expiration courte recommandée : access token 1h, refresh token 7 jours
- Durées de session admin configurées par rôle (2h Super Admin, 8h City Agent terrain)

---

## ADR-002 — Multi-tenancy par colonne city_id plutôt que bases distinctes

**Date** : Juin 2026  
**Statut** : Accepté

**Contexte**  
GoLivreur s'étendra à plusieurs villes (Bouaké, Abidjan, etc.). Les données de chaque ville doivent être isolées pour les admins locaux mais accessibles de façon centralisée aux admins nationaux.

**Options considérées**  
- Une base de données PostgreSQL par ville
- Une seule base avec colonne `city_id` sur toutes les entités locales

**Décision**  
Colonne `city_id` dans une base unique. Un middleware `CityContextMiddleware` filtre automatiquement toutes les requêtes des admins locaux. Ouvrir une nouvelle ville = insérer une ligne dans `cities` et créer les admins. Zéro modification d'infrastructure.

**Conséquences**  
- Risque de fuite de données entre villes si le middleware est mal appliqué → tests de permissions exhaustifs obligatoires sur chaque endpoint
- Migration vers bases distinctes possible à > 50 villes si les performances l'exigent
- Le Super Admin et les admins nationaux voient toutes les villes sans filtre

---

## ADR-003 — MVC + Services plutôt que DDD

**Date** : Juin 2026  
**Statut** : Accepté

**Contexte**  
Équipe de 2 développeurs maximum, planning de 17 semaines, budget fixe. Le Lead Dev est le référent technique unique.

**Options considérées**  
- Domain-Driven Design (DDD) avec Bounded Contexts
- MVC classique Laravel
- MVC + couche Services (contrôleurs fins, logique métier dans des Services)

**Décision**  
MVC + Services. Les contrôleurs délèguent toute logique métier à des classes Service dédiées (`OrderService`, `ParcelService`, `WalletService`). Structure lisible pour un développeur rejoignant le projet (renfort S7, Lead Dev Bouaké à 18 mois).

**Conséquences**  
- `app/Services/` contient toute la logique métier
- Les contrôleurs font uniquement : validation → appel Service → réponse HTTP
- Pas de Repositories (overhead inutile à ce stade)
- Migration vers DDD naturelle depuis cette base si l'équipe dépasse 5 développeurs

---

## ADR-004 — Modules isolés dès S3 pour l'extensibilité

**Date** : Juin 2026  
**Statut** : Accepté

**Contexte**  
GoShop et GoColis sont deux services distincts avec leurs propres flux, tables et règles métier. Des modules v2 sont anticipés (GoExpress, Loyalty, Referral).

**Options considérées**  
- Tout dans `app/` sans séparation modulaire
- Modules Laravel (`nwidart/laravel-modules`)
- Dossiers `Modules/` organisés manuellement avec autoloading

**Décision**  
Dossiers `Modules/GoShop/` et `Modules/GoColis/` sous `app/Modules/` avec leurs propres Controllers, Services, Models et Routes. Pas de package externe pour éviter la dépendance. L'autoloading est configuré dans `composer.json`.

**Conséquences**  
- Ajouter GoExpress en v2 = créer `app/Modules/GoExpress/` sans toucher GoShop ou GoColis
- Le Lead Dev Bouaké peut travailler sur un module sans impacter les autres
- Les routes de chaque module sont enregistrées dans `RouteServiceProvider`

---

## ADR-005 — Docker local uniquement, production sans Docker

**Date** : Juin 2026  
**Statut** : Accepté

**Contexte**  
VPS de production à 8Go RAM / 4CPU. Le Lead Dev travaille depuis un poste de développement (OS inconnu).

**Options considérées**  
- Docker partout (local + staging + production)
- Docker local uniquement, production native

**Décision**  
Docker uniquement en développement local. En production, les services (PHP-FPM, PostgreSQL, Redis, Nginx) sont installés directement sur le VPS. Moins de couche d'abstraction, débogage plus direct en cas d'incident à 22h.

**Conséquences**  
- `docker-compose.yml` fourni pour le développement local
- Le staging (`staging.golivreur.ci`) utilise la même configuration native que la production
- Les déploiements via GitHub Actions utilisent SSH + scripts bash

---

## ADR-006 — Queue haute priorité pour les webhooks de paiement

**Date** : Juin 2026  
**Statut** : Accepté

**Contexte**  
Les webhooks Hub2/CinetPay confirment les paiements Orange Money. Un retard de traitement = client qui voit son écran tourner après avoir saisi son code Mobile Money.

**Options considérées**  
- Queue unique pour tous les jobs
- Queues séparées par priorité

**Décision**  
Trois queues Redis distinctes :
- `high` : confirmation paiements, webhooks Hub2/CinetPay
- `default` : notifications FCM, SMS OTP
- `low` : mises à jour GPS, logs asynchrones

Laravel Horizon supervise les trois queues avec workers dédiés.

**Conséquences**  
- Un pic de trafic sur les notifications FCM ne retarde jamais les confirmations de paiement
- Configuration Horizon dans `config/horizon.php` avec ratios de workers par queue
- Supervision via widget Filament + dashboard Horizon sur `/horizon`

---

## ADR-007 — Monolithe longtemps, microservices différés

**Date** : Juin 2026  
**Statut** : Accepté

**Contexte**  
Ambition d'expansion en Afrique de l'Ouest, mais équipe réduite à court terme.

**Décision**  
Monolithe Laravel bien structuré jusqu'à 10 villes ivoiriennes minimum. Les modules isolés (ADR-004) et les services découplés (ADR-003) facilitent une migration future si nécessaire. La décision de migrer vers des microservices sera réévaluée quand l'équipe dépasse 10 développeurs ou que les contraintes de performance l'imposent.

**Conséquences**  
- Zéro overhead opérationnel de microservices pour une équipe de 2
- Architecture testée, documentée et lisible dès le premier jour
- Pas de communication inter-services à gérer (message brokers, service discovery, etc.)
