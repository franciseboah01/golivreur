# GoLivreur — Authentification et Sécurité

---

## 1. Mécanisme JWT

### Durées de validité

| Token | Durée | Stockage côté app |
|---|---|---|
| Access token | 1 heure | Mémoire (Riverpod state) |
| Refresh token | 7 jours | Secure Storage (flutter_secure_storage) |

### Flux de renouvellement automatique (intercepteur Dio)

Quand l'access token expire, l'intercepteur Dio dans Flutter détecte automatiquement le code HTTP `401` et tente un refresh **avant** de notifier l'utilisateur :

```
Requête API → 401 Unauthorized
    ↓
Intercepteur Dio : access token expiré ?
    ↓ Oui
POST /api/v1/auth/refresh avec refresh token
    ↓ 200 OK
Nouveau access_token + refresh_token stockés
    ↓
Requête originale rejouée avec nouveau token
    ↓
Utilisateur ne voit rien (transparent)

Si refresh token expiré → déconnexion + écran de connexion
```

---

## 2. Masquage des données sensibles dans les logs

### Règles de masquage (configurées dans `config/logging.php`)

| Donnée | Règle | Exemple |
|---|---|---|
| Numéro de téléphone | Masquer chiffres 3 à 6 | `0700000001` → `07****001` |
| Code OTP | Jamais loggé | — |
| Code de confirmation GoShop/GoColis | Jamais loggé | — |
| Token JWT | Jamais loggé | — |
| Clé HMAC Hub2/CinetPay | Jamais loggée | — |
| Montants financiers | Logs audit uniquement (pas logs système) | Visible dans `activity_logs` |

### Implémentation — Middleware de masquage

```php
// app/Http/Middleware/SanitizeLogsMiddleware.php
// Ce middleware est appliqué globalement dans bootstrap/app.php

class SanitizeLogsMiddleware
{
    private array $sensitiveFields = [
        'otp', 'code', 'confirmation_code', 'sender_code',
        'recipient_code', 'password', 'token', 'secret',
        'hmac', 'signature',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        // Les champs sensibles sont retirés des logs de requête
        // mais la requête elle-même n'est pas modifiée
        return $next($request);
    }
}
```

---

## 3. Rate Limiting

### Configuration dans `routes/api.php`

| Endpoint | Limite | Fenêtre | Action si dépassé |
|---|---|---|---|
| POST /auth/register | 3 req | 10 min par IP | Blocage 1h |
| POST /auth/login | 3 req | 10 min par numéro | Blocage 1h |
| POST /auth/verify-otp | 3 req | 10 min par numéro | Blocage 1h |
| POST /courier/location | 1 req | 5 secondes par livreur | 429 silencieux |
| GET /api/v1/* (auth) | 60 req | 1 minute par user | 429 |
| GET /* (vitrine) | 20 req | 1 minute par IP | 429 |

### Comportement après blocage OTP (1 heure)

```
Tentative 1 → Code incorrect → attempts: 1
Tentative 2 → Code incorrect → attempts: 2
Tentative 3 → Code incorrect → attempts: 3
    → Numéro bloqué 1 heure dans Redis
    → Réponse : 429 {"message": "Trop de tentatives. Réessayez dans 60 minutes."}
    → Log dans activity_logs (action: otp_brute_force_detected)
```

---

## 4. Session unique — Merchant et Driver

Quand un Merchant ou Driver se connecte sur un nouvel appareil :

1. L'ancien `session_token` est invalidé en base
2. Une notification FCM est envoyée à l'ancien appareil
3. Toutes les requêtes suivantes de l'ancien appareil retournent `401`
4. Un log `activity_logs` est créé (action: `forced_logout_new_device`)

---

## 5. Tracking IP — Admins

Chaque connexion admin est enregistrée dans `admin_sessions` :
- IP address
- User agent (navigateur)
- Horodatage
- Statut (active/révoquée)

Si la connexion vient d'une IP différente de la dernière session :
- Log `activity_logs` (sévérité: `warning`, action: `admin_new_ip_login`)
- Email automatique à l'admin concerné : "Nouvelle connexion depuis [IP] — [ville] — [date]"

---

## 6. Validation HMAC — Webhooks Hub2/CinetPay

```
Webhook reçu → POST /api/v1/webhooks/payment
    ↓
1. Log immédiat dans webhook_logs (payload + signature_received)
    ↓
2. Calcul HMAC-SHA256 du payload avec HUB2_WEBHOOK_SECRET
    ↓
3. Comparaison hash_equals(calculé, reçu)
    ↓
Invalide → webhook_logs.signature_valid = false
         → activity_logs (sévérité: critical, action: invalid_webhook_signature)
         → Email immédiat à francis@golivreur.ci
         → HTTP 401 retourné à Hub2
    ↓
Valide → webhook_logs.signature_valid = true
       → Job ProcessPaymentWebhook dispatché (queue: high)
       → HTTP 200 retourné immédiatement à Hub2
```

---

## 7. Checklist OWASP Top 10 — Application GoLivreur

| # | Vulnérabilité | Statut | Mesure implémentée |
|---|---|---|---|
| A01 | Broken Access Control | ✅ Couvert | Spatie Permissions + CityContextMiddleware + tests permissions exhaustifs |
| A02 | Cryptographic Failures | ✅ Couvert | OTP et codes hashés bcrypt, JWT stateless, HTTPS obligatoire, secrets Bitwarden |
| A03 | Injection | ✅ Couvert | Eloquent ORM (requêtes préparées), FormRequests pour toutes les entrées |
| A04 | Insecure Design | ✅ Couvert | Architecture modulaire, séparation des rôles, City Context, délégation remboursements |
| A05 | Security Misconfiguration | ✅ Couvert | APP_DEBUG=false en prod, headers sécurité Nginx, ports fermés sauf 80/443/SSH |
| A06 | Vulnerable Components | ⚠️ À surveiller | composer.lock figé, `composer audit` dans CI/CD, Dependabot activé |
| A07 | Auth Failures | ✅ Couvert | Rate limiting OTP, JWT rotation, session unique merchant/driver, 2FA admins |
| A08 | Software Integrity Failures | ✅ Couvert | HMAC validation webhooks, signatures Git, GitHub Actions secrets |
| A09 | Logging Failures | ✅ Couvert | Audit logs complets, Sentry, masquage données sensibles, webhook_logs |
| A10 | SSRF | ✅ Couvert | Aucune URL utilisateur exécutée côté serveur, whitelist domaines externes |

---

## 8. Headers de sécurité Nginx (production)

```nginx
# À ajouter dans la configuration Nginx de production
add_header X-Frame-Options "SAMEORIGIN" always;
add_header X-Content-Type-Options "nosniff" always;
add_header X-XSS-Protection "1; mode=block" always;
add_header Referrer-Policy "strict-origin-when-cross-origin" always;
add_header Content-Security-Policy "default-src 'self'; script-src 'self' 'unsafe-inline' https://maps.googleapis.com; img-src 'self' data: https://*.digitaloceanspaces.com; connect-src 'self' wss://golivreur.ci;" always;
add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
```
