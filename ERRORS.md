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

### [2026-11-05] — [Sprint 1] Échec d'assertion sur WalletTransaction (integer vs float)
**Statut :** Résolu
**Symptôme :** `Failed asserting that 1500 is identical to 1500.0` dans `WalletServiceTest`.
**Cause racine :** Le cast Eloquent de `WalletTransaction` forçait `amount` en `integer`, alors que Pest utilise `toBe()` (comparaison stricte `===`) sur un float (`1500.0`).
**Solution appliquée :** Typage en `float` dans `$casts` pour `amount`, `balance_before` et `balance_after`.
**Prévention :** Aligner les casts de modèles financiers sur `float` pour les calculs et comparaisons strictes.
**Fichiers concernés :** `backend/app/Models/WalletTransaction.php`.

<!-- Exemple de ce à quoi la prochaine entrée doit ressembler :

## [2026-XX-XX] — [Sprint X] Titre court

**Statut :** Résolu
**Symptôme :** ...
**Cause racine :** ...
**Solution appliquée :** ...
**Prévention :** ...
**Fichiers concernés :** ...

-->
