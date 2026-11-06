# ERRORS.md — Journal des Erreurs et Blocages
## Projet GoLivreur v5.0

> Chaque erreur significative rencontrée pendant le développement — bug, blocage, comportement inattendu, dépendance cassée — est documentée ici DÈS qu'elle est résolue.
> Objectif : ne jamais perdre 2 heures deux fois sur le même problème. Avant de déboguer quoi que ce soit, cherche d'abord ici.
> Une erreur non résolue et bloquante est signalée avec le statut `[BLOQUÉ]` et remontée immédiatement dans `NEXT_TASK.md` avec une proposition claire pour Francis si une décision business ou un accès externe est nécessaire.

Format de chaque entrée :

```
## [DATE] — [Sprint X] Titre court de l'erreur

**Statut :** Résolu / Bloqué / Contournement temporaire
**Symptôme :** Ce qui s'est passé, message d'erreur exact si pertinent.
**Cause racine :** Pourquoi ça s'est produit.
**Solution appliquée :** Ce qui a été fait pour corriger.
**Prévention :** Ce qu'on change pour que ça ne se reproduise plus (règle ajoutée à CLAUDE.md si nécessaire).
**Fichiers concernés :** Chemins des fichiers touchés.
```

---

## Erreurs anticipées (connues avant même de coder — issues de l'analyse du CDC)

Ces points ont été identifiés comme sources probables de bugs pendant la phase de cadrage. Ils sont listés ici en préventif — à vérifier en priorité si un bug apparaît dans ces zones.

### Zone à risque — Calcul du barème GoColis (multi-km)
**Symptôme attendu si mal codé :** Le calcul "0-2km + par km supplémentaire" peut être mal interprété — confusion entre "2km inclus dans le forfait de base" et "2km comptés en supplément".
**Exemple de référence CDC (Chapitre 8.4) :** Colis Moyen, 4 km = 1 200 F CFA (base 0-2km) + 2 × 300 F CFA (2 km supplémentaires) = 1 800 F CFA. Le calcul supplémentaire porte sur `distance_totale - 2`, jamais sur `distance_totale`.
**Prévention :** Test Pest dédié avec ce cas exact (4km → 1 800 F CFA) avant tout déploiement du `CommissionService`.
**Fichiers concernés :** `app/Services/CommissionService.php`, `tests/Feature/CommissionServiceTest.php`

### Zone à risque — Ordre des codes GoColis
**Symptôme attendu si mal codé :** Confusion entre code expéditeur (à saisir au ramassage) et code destinataire (à saisir à la livraison) — les deux sont des chaînes de 4 chiffres, facilement inversables dans le code si les variables ne sont pas nommées explicitement.
**Prévention :** Toujours nommer explicitement `sender_code` et `recipient_code`, jamais `code_1`/`code_2`. Test Pest qui vérifie que saisir le code destinataire à l'étape ramassage échoue explicitement.
**Fichiers concernés :** `app/Models/Parcel.php`, `app/Services/ParcelService.php`

### Zone à risque — Rate limiting OTP et faux positifs
**Symptôme attendu :** Un client qui teste plusieurs fois son numéro (faute de frappe) se retrouve bloqué 1h après seulement 3 tentatives légitimes.
**Prévention :** Message d'erreur explicite côté app dès la 2ème tentative ratée ("Attention, encore 1 essai avant blocage 1h") pour éviter la frustration silencieuse.
**Fichiers concernés :** `app/Http/Controllers/AuthController.php`, écran OTP des 3 apps Flutter.

### Zone à risque — Webhook Hub2/CinetPay reçu en double
**Symptôme attendu :** Les agrégateurs de paiement Mobile Money renvoient parfois le même webhook plusieurs fois (retry réseau côté opérateur).
**Prévention :** Vérifier l'idempotence — un `reference` de transaction déjà traité dans `webhook_logs` avec statut `processed` ne doit jamais créditer un wallet une deuxième fois.
**Fichiers concernés :** `app/Jobs/ProcessPaymentWebhook.php`, table `webhook_logs`

### Zone à risque — Session unique et reconnexion livreur en zone blanche
**Symptôme attendu :** Un livreur qui perd le réseau puis se reconnecte peut voir sa session invalidée par erreur si le système interprète la reconnexion comme une "nouvelle connexion depuis un autre appareil".
**Prévention :** Distinguer un token JWT expiré/à rafraîchir (même appareil) d'une véritable nouvelle connexion (device_id différent). Ne comparer que sur le `device_id`, jamais sur l'IP seule (qui change en mobilité).
**Fichiers concernés :** `app/Http/Middleware/EnsureSingleSession.php`

---

## Erreurs rencontrées pendant le développement

*(Claude Code : ajoute ici chaque erreur réelle rencontrée et résolue, au fur et à mesure des sprints. Ne supprime jamais une entrée. Si une même erreur revient une 2ème fois, c'est que la prévention n'a pas été appliquée — corrige la prévention, pas seulement le symptôme.)*

### [2026-11-05] — [Sprint 1] Conflit de classe Parcel dupliquée dans Order.php
**Statut :** Résolu
**Symptôme :** `Call to undefined method App\Models\Parcel::factory()` malgré l'existence d'un `Parcel.php` avec `use HasFactory`.
**Cause racine :** Le fichier d'archive initial `backend/app/Models/Order.php` contenait une deuxième classe `Parcel extends Model` en fin de fichier sans trait `HasFactory`. Lorsque `Order` était chargé en premier par Composer/PHP, il définissait la classe `Parcel` en mémoire avant le chargement de `Parcel.php`.
**Solution appliquée :** Suppression de la classe `Parcel` en double dans `Order.php`, consolidation de tous les champs, relations et scopes dans `app/Models/Parcel.php` avec le trait `HasFactory`.
**Prévention :** Respecter strictement la règle PSR-4 : 1 classe par fichier PHP, portant exactement le même nom que le fichier.
**Fichiers concernés :** `backend/app/Models/Order.php`, `backend/app/Models/Parcel.php`.

### [2026-11-05] — [Sprint 1] Incompatibilité CardTheme et withOpacity sous Flutter 3.47
**Statut :** Résolu
**Symptôme :** Erreurs d'analyse Dart dans `app_theme.dart` : `CardTheme can't be assigned to parameter type CardThemeData?` et avertissements de dépréciation sur `withOpacity`.
**Cause racine :** Évolution de l'API Flutter 3.47 / Dart 3.10 : `ThemeData.cardTheme` attend un `CardThemeData`, et `Color.withOpacity` est déprécié au profit de `Color.withValues(alpha: ...)`.
**Solution appliquée :** Remplacement de `CardTheme` par `CardThemeData` et migration de toutes les occurrences `withOpacity(x)` vers `withValues(alpha: x)`.
**Prévention :** Vérification systématique par `flutter analyze` sur chaque package du monorepo.
**Fichiers concernés :** `mobile/packages/core/lib/config/app_theme.dart`.

### [2026-11-05] — [Sprint 3/5] Erreur PostgreSQL 42703 (alias distance dans HAVING)
**Statut :** Résolu
**Symptôme :** `SQLSTATE[42703]: Undefined column: 7 ERREUR: la colonne « distance » n'existe pas ... having "distance" < 10` lors de l'attribution d'un livreur GoColis ou GoShop.
**Cause racine :** PostgreSQL n'autorise pas l'utilisation d'alias de colonnes créés dans le `SELECT` au sein de la clause `HAVING`.
**Solution appliquée :** Utilisation de `whereRaw` avec l'expression complète de la distance haversine et protection `least(1.0, greatest(-1.0, ...))`.
**Prévention :** Ne jamais utiliser d'alias calculé dans un `HAVING` sous PostgreSQL ; utiliser `whereRaw` ou des sous-requêtes.
**Fichiers concernés :** `backend/app/Modules/GoShop/Services/AssignmentService.php`.

### [2026-11-05] — [Sprint 4] Annulation intempestive de commande par le job de timeout en file synchrone
**Statut :** Résolu
**Symptôme :** Une commande fraîchement créée passait instantanément au statut `cancelled` lors de l'exécution des tests.
**Cause racine :** Avec le driver de queue synchrone (`sync`), les jobs dispatchés avec `delay()` sont exécutés immédiatement par le processus PHP. Le job d'annulation à 45s s'exécutait donc immédiatement après la création.
**Solution appliquée :** Ajout d'une vérification de l'âge réel de la commande (`created_at->diffInSeconds(now()) >= 40`) dans la méthode `handle()` de `AnnulerCommandeTimeout`.
**Prévention :** Toujours valider les conditions temporelles au sein du `handle()` des jobs à délai pour immuniser contre l'exécution immédiate en mode synchrone ou lors des tests.
**Fichiers concernés :** `backend/app/Jobs/AnnulerCommandeTimeout.php`.

### [2026-11-06] — [Sprint 6/Web Admin] Erreur SQLSTATE[42703] (colonne remember_token manquante sur users)
**Statut :** Résolu
**Symptôme :** `SQLSTATE[42703]: Undefined column: 7 ERREUR: la colonne « remember_token » de la relation « users » n'existe pas LINE 1: update "users" set "remember_token" = ... where "id" = 1` lors de la connexion web sur Filament.
**Cause racine :** L'authentification par session web de Laravel (avec ou sans l'option "Se souvenir de moi") tente de mettre à jour le jeton `remember_token` sur la table `users`. Cette colonne n'était pas présente dans la migration initiale `create_users_table.php`.
**Solution appliquée :** Création et exécution de la migration `2026_11_06_124333_add_remember_token_to_users_table.php` ajoutant `$table->rememberToken()` sur `users`.
**Prévention :** Toujours inclure `$table->rememberToken()` sur la table `users` dès lors qu'un système d'authentification web/session est utilisé conjointement aux tokens API JWT.
**Fichiers concernés :** `backend/database/migrations/2026_11_06_124333_add_remember_token_to_users_table.php`.


