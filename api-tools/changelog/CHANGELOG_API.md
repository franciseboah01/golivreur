# GoLivreur — Changelog API

Tous les changements notables de l'API GoLivreur sont documentés ici.

**Format** : Chaque entrée précise la version, la date, et catégorise les
changements en `Ajouté`, `Modifié`, `Déprécié`, `Supprimé`, `Corrigé`,
`Sécurité`. Les **breaking changes** (qui cassent la compatibilité avec
les apps Flutter existantes) sont marqués `⚠️ BREAKING`.

**Règle d'équipe** : Tout changement d'endpoint, nouveau champ obligatoire,
ou suppression de champ doit être documenté ICI avant le merge sur `develop`.

---

## [1.0.0] — 2026-06-30 (Lancement initial)

### Ajouté

**Authentification**
- `POST /auth/register` — Inscription par OTP SMS
- `POST /auth/verify-register` — Validation OTP et création de compte
- `POST /auth/login` — Demande de connexion par OTP
- `POST /auth/verify-login` — Validation OTP et émission JWT
- `POST /auth/refresh` — Renouvellement du token JWT
- `POST /auth/logout` — Déconnexion

**GoShop — Client**
- `GET /client/merchants` — Liste des commerces (filtré par ville)
- `GET /client/merchants/{id}` — Détail d'un commerce avec catalogue
- `POST /client/orders` — Création d'une commande
- `GET /client/orders` — Historique des commandes du client
- `GET /client/orders/{id}` — Détail d'une commande
- `POST /client/orders/{id}/rate` — Notation commerce + livreur
- `POST /orders/{id}/verify-code` — Validation code de confirmation

**GoShop — Merchant**
- `GET /merchant/orders` — Commandes reçues
- `POST /merchant/orders/{id}/accept` — Acceptation (délai 45s)
- `POST /merchant/orders/{id}/refuse` — Refus volontaire (v5)
- `POST /merchant/orders/{id}/ready` — Signalement commande prête
- `GET/POST/PUT/DELETE /merchant/products` — Gestion catalogue

**GoShop — Driver**
- `GET /driver/orders` — Courses disponibles/en cours
- `POST /driver/orders/{id}/accept` — Acceptation course (délai 30s)
- `POST /driver/orders/{id}/pickup` — Ramassage commande
- `POST /driver/orders/{id}/arrive` — Arrivée chez le client
- `POST /courier/location` — Mise à jour GPS (throttle 5s, filtre 10m)

**GoColis — Client**
- `POST /client/parcels` — Création d'une expédition
- `GET /client/parcels` — Historique des expéditions
- `GET /client/parcels/{id}` — Détail d'une expédition
- `POST /client/parcels/{id}/rate` — Notation livreur

**GoColis — Driver**
- `POST /driver/parcels/{id}/accept` — Acceptation (taille visible avant accept — v5)
- `POST /driver/parcels/{id}/at-sender` — Arrivée chez l'expéditeur
- `POST /parcels/{id}/photo` — Upload photo (pickup/delivery)
- `POST /parcels/{id}/verify-sender-code` — Validation code expéditeur
- `POST /driver/parcels/{id}/at-recipient` — Arrivée chez le destinataire
- `POST /parcels/{id}/verify-recipient-code` — Validation code destinataire

**Notifications in-app (Client uniquement)**
- `GET /client/notifications` — 30 dernières notifications
- `POST /client/notifications/{id}/read` — Marquer comme lue
- `POST /client/notifications/read-all` — Tout marquer comme lu
- `GET /client/notifications/unread-count` — Compteur non lues

**Webhooks**
- `POST /webhooks/payment` — Réception confirmation Hub2/CinetPay (HMAC obligatoire)

**Admin**
- `GET /admin/dashboard` — Métriques City Manager (8 indicateurs)
- `GET /admin/orders` `/admin/parcels` — Vue admin (filtrée City Context)
- `POST /admin/orders/{id}/refund` — Remboursement par plafond de délégation
- `POST /admin/orders/{id}/unlock-code` — Déblocage code après 3 erreurs
- `POST /admin/cities/{id}/rain-mode` — Mode Pluie manuel
- `POST /admin/cities/{id}/emergency-mode` — Mode Urgence (Super Admin/DG uniquement)
- `GET /admin/webhooks` — Historique des webhooks reçus
- `POST /admin/webhooks/{id}/replay` — Rejeu d'un webhook depuis Horizon

### Sécurité

- JWT avec rotation automatique (access 1h / refresh 7j)
- Session unique forcée pour merchants et drivers
- Rate limiting OTP : 3 tentatives / 10 min, blocage 1h
- Validation HMAC obligatoire sur tous les webhooks Hub2/CinetPay
- Masquage des numéros de téléphone dans tous les logs système
- Codes de confirmation (GoShop + GoColis) hashés bcrypt, jamais en clair en base

---

## [1.1.0] — 2026-11-10 (Cohérence Admin ↔ Mobile : Emojis, Logos & Catégories dynamiques)

### Ajouté

- **`GET /api/v1/client/categories`** (et alias public `GET /api/v1/categories`) — Retourne la liste des catégories d'activité actives triées par nom (`MerchantCategoryResource`) :
  - `id` *(int)* : Identifiant unique en base
  - `name` *(string)* : Libellé officiel configuré dans l'admin
  - `slug` *(string)* : Identifiant technique utilisé pour filtrer `/client/merchants?category={slug}`
  - `description` *(string|null)* : Description courte
  - `icon` *(string|null)* : Icône fallback optionnelle
  - `emoji` *(string)* : Emoji choisi dans l'admin (fallback `"🏪"`)
  - `logo_url` *(string|null)* : URL publique du logo uploadé (`storage/categories/logos/...`), ou `null`
  - `is_active` *(bool)* : Statut d'activation

### Modifié

- **`GET /api/v1/client/merchants`** et **`GET /api/v1/client/merchants/{id}/catalog`** (`MerchantResource`) :
  - Ajout de `category_name` *(string)* : Nom lisible de la catégorie depuis `merchant_categories`
  - Ajout de `category_emoji` *(string|null)* : Emoji de la catégorie associée
  - Ajout de `emoji` *(string)* : Emoji de la boutique (ou celui de sa catégorie en fallback)
  - Ajout de `logo_url` *(string|null)* : URL publique du logo de la boutique (`storage/commerces/logos/...`)
  - `photo_url` *(string|null)* : URL publique de la photo vitrine (`storage/commerces/...`) ou placeholder
  - Inclusion automatique des `products` disponibles dans `/client/merchants` pour affichage immédiat côté mobile
- **`GET /api/v1/merchant/status`** (`MerchantController::status`) :
  - Ajout de `id`, `category_name`, `emoji`, `logo_url` et `photo_url` pour synchroniser l'application `GoLivreur Business`.

---

## [Non publié] — En préparation

### Prévu pour v1.1.0 (Phase 2 — S11-S12)

- `POST /client/parcels/{id}` — Endpoints assurance détaillés
- Intégration MTN Mobile Money (en complément d'Orange Money)
- Intégration Wave
- `GET /admin/system-metrics` — Endpoint CPU/RAM pour les tests k6 (admin uniquement)

---

## Format des futures entrées

```markdown
## [X.Y.Z] — AAAA-MM-JJ

### ⚠️ BREAKING
- `POST /client/orders` : le champ `items[].product_id` devient obligatoire
  (était optionnel en v1.0.0). Migration requise pour les apps < v1.1.0.

### Ajouté
- Nouvel endpoint ou nouveau champ optionnel

### Modifié
- Changement de comportement sans casser la compatibilité

### Déprécié
- Endpoint qui sera supprimé dans une prochaine version majeure

### Supprimé
- Endpoint retiré (toujours précédé d'une dépréciation)

### Corrigé
- Bug fix sur un endpoint existant

### Sécurité
- Changement lié à la sécurité (rate limiting, validation, etc.)
```
