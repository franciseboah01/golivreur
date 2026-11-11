# Classement & Traitement des Erreurs Larastan (Niveau 5) — GoLivreur

> **Outil :** `php vendor/bin/phpstan analyse` (`backend/phpstan.neon`, `level: 5` sur `app/` — 149 fichiers, `reportUnmatchedIgnoredErrors: true`, **0** règle `ignoreErrors` restante).  
> **Passe 1 (99 erreurs initiales) :** `95` corrigées + `4` faux positifs mis en [`phpstan-baseline.neon`](../backend/phpstan-baseline.neon).  
> **Passe 2 (levée des 8 règles génériques `ignoreErrors` de `phpstan.neon`) :** `15` erreurs cachées révélées → `15` corrigées dans le code (`9` bugs réels PHPStan + `3` colonnes/champs compagnon inexistants, `6` dettes de typage), **0** nouvel ajout dans la baseline → **`[OK] No errors`**.

---

## Partie A — Audit des 8 règles `ignoreErrors` supprimées de `backend/phpstan.neon`

| # | Règle d'exclusion retirée | Ce qu'elle masquait | Erreurs cachées | Raison d'origine | Classification des erreurs | Action effectuée |
|---|---|---|---|---|---|---|
| 1 | `Access to an undefined property Illuminate\Database\Eloquent\Model::\$...` | Attributs lus sur des relations `BelongsTo` sans génériques (`Model` inféré) | **3** | Relations non typées sur `Order` (`ListRefunds.php:43`, `OrderService.php:233`, `NotificationService.php:53`) | **DETTE DE TYPAGE** (3) | Règle supprimée ; ajout de `@return BelongsTo<User\|Merchant\|Courier\|City, $this>` sur `Order.php`. |
| 2 | `Access to an undefined property App\Models\...::\$...` | Tout attribut inexistant ou dynamique sur n'importe quel modèle Eloquent | **9** | Propriété dynamique `Parcel::$sender_code_plain` et masquage involontaire de `Order::$order_number` | **7 BUGS RÉELS** + **2 DETTE DE TYPAGE** | Règle supprimée ; correction de `DisputesPage.php` & `OrderResource.php` + `@property string\|null $sender_code_plain` sur `Parcel.php`. |
| 3 | `Call to an undefined method App\Models\...::...()` | Appels de méthodes sur les modèles | **0** | Règle préventive obsolète | *(Aucune)* | Règle supprimée. |
| 4 | `Call to an undefined method Illuminate\Database\Eloquent\Model::...()` | Appels de méthodes sur relations Eloquent sans génériques | **1** | Absence de `@return BelongsTo<RoleHabilitation, $this>` sur `User::roleHabilitation()` (`User.php:108`) | **DETTE DE TYPAGE** (1) | Règle supprimée ; ajout du générique `@return BelongsTo<RoleHabilitation, $this>` sur `User.php`. |
| 5 | `Property App\Filament\Resources\...::\$(navigationGroup\|...) is never read` | Propriétés statiques Filament | **0** | Règle préventive obsolète | *(Aucune)* | Règle supprimée. |
| 6 | `Parameter #1 $forceForever of static method JWT::(refresh\|invalidate)() expects bool` | Passage de `Token\|null` au paramètre booléen `$forceForever` | **2** | Appel `JWTAuth::refresh(JWTAuth::getToken())` / `invalidate(JWTAuth::getToken())` (`AuthController.php:357,381`) | **2 BUGS RÉELS** | Règle supprimée ; corrigé en `JWTAuth::parseToken()->refresh()` et `JWTAuth::parseToken()->invalidate()` + assertion `AuthTest.php`. |
| 7 | `Static method Redis::set() invoked with 5 parameters` | Appel `Redis::set` à 5 paramètres | **0** | Règle héritée obsolète | *(Aucune)* | Règle supprimée. |
| 8 | `Parameter #3 $options of static method Redis::set() expects array` | Type du 3e paramètre de `Redis::set` | **0** | Règle héritée obsolète | *(Aucune)* | Règle supprimée. |
| — | **TOTAL passe 2** | **8 règles `ignoreErrors` supprimées** (`reportUnmatchedIgnoredErrors: true`) | **15** | — | **9 Bugs réels + 6 Dette de typage** | **`15` corrigées dans le code, `0` exclusion générique conservée.** |

---

## Partie B — Récapitulatif global des bugs réels corrigés (Passes 1 & 2)

| Fichier | Erreur / Bug réel constaté | Correction appliquée | Test associé |
|---|---|---|---|
| `Filament/Resources/RefundResource/Pages/ListRefunds.php` | Lecture de `$order->order_number`, `$order->total`, `$parcel->tracking_number` (inexistants ; modal et `wallet_transactions.description` vides) | Utilisation de `$o->id`, `$o->total_client`, `$p->id` avec `@var Order` / `@var Parcel` | `RefundAndRoleValidationTest.php` |
| `Filament/Resources/RoleHabilitationResource.php` | `minItems(1, 'Veuillez cocher...')` ignorait le 2e argument | `->minItems(1)->validationMessages(['min' => ..., 'required' => ...])` | `RefundAndRoleValidationTest.php` |
| `Filament/Pages/DisputesPage.php` | Colonne `order_number` (vide + erreur SQL en recherche), colonne `total` (vide), modal/log/notif sur `$record->order_number` (`3` erreurs démasquées) | Colonne `id` formatée `#id` + `total_client` + `#{$record->id}` + `'order_id' => $record->id` | `DisputesAndOrderResourceTest.php` |
| `Filament/Resources/OrderResource.php` | Notifications/modals `#{$record->order_number}` (`4` erreurs démasquées) + champ inexistant `cancellation_reason` (motif d'annulation perdu) | `#{$record->id}`, `'cancel_reason' => $data['cancellation_reason']`, `'cancelled_at' => now()` | `DisputesAndOrderResourceTest.php` |
| `Http/Controllers/Api/V1/Auth/AuthController.php` | `JWTAuth::refresh(JWTAuth::getToken())` et `invalidate(JWTAuth::getToken())` passaient `Token\|null` à `$forceForever` (`bool`) (`2` erreurs démasquées) | `JWTAuth::parseToken()->refresh()` et `JWTAuth::parseToken()->invalidate()` | `AuthTest.php` |

---

## Partie C — Tableau synthétique des groupes (Passe 1 + Passe 2)

| Groupe | Nb | Classe | Action |
|---|---|---|---|
| `RefundResource\Pages\ListRefunds` (`order_number`, `tracking_number`) | **2** | **BUG RÉEL** | Corrigé + test Pest Livewire |
| `RoleHabilitationResource` (`minItems` 2 args) | **1** | **BUG RÉEL** | Corrigé + test Pest Livewire |
| `DisputesPage` & `OrderResource` (`Order::$order_number` démasqué) | **7** *(+3 UI/champ compagnon)* | **BUG RÉEL** | Corrigé (`id`, `total_client`, `cancel_reason`) + tests Pest Livewire |
| `AuthController` (`JWT::refresh` / `invalidate` `$forceForever` démasqué) | **2** | **BUG RÉEL** | Corrigé (`parseToken()->refresh()/invalidate()`) + test Pest JWT |
| `JsonResource` `@mixin` (`Business`, `Driver`, `Client`, `Merchant`, `MerchantCategory`) | **57** | **DETTE DE TYPAGE** | `@mixin` + `@property-read` sur modèles |
| `nullsafe.neverNull` (`?->` avant `??`) | **33** | **DETTE DE TYPAGE** | Remplacé par `->` devant `??` |
| Relations `Order` / `User` sans génériques + `ClientAddress` + `Parcel::$sender_code_plain` | **8** *(2 + 3 + 1 + 2)* | **DETTE DE TYPAGE** | Génériques `BelongsTo`/`HasMany`/`HasOne` + `@property` |
| `SettingsPage::$form` (magie Filament Livewire `InteractsWithForms`) | **2** | **FAUX POSITIF** | Conservé en `phpstan-baseline.neon` |
| `Courier` & `Merchant` `$appends = ['photo_url']` (colonne + accesseur legacy) | **2** | **FAUX POSITIF** | Conservé en `phpstan-baseline.neon` |
| **TOTAL cumulé traité (99 + 15)** | **114** | **12 Bugs réels / 98 Dette / 4 Faux positifs** | **110 corrigées dans le code + 4 ciblées dans `phpstan-baseline.neon` → `[OK] No errors`.** |
