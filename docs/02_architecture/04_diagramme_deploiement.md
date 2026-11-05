# GoLivreur — Diagramme de Déploiement

---

## Infrastructure complète

```mermaid
graph TB
    subgraph DEV["Poste développeur (Docker)"]
        DC["docker-compose.yml\nPHP 8.3-FPM\nPostgreSQL 16\nRedis 7\nNginx"]
    end

    subgraph GITHUB["GitHub"]
        REPO["Repository\nmonorepo\nLaravel + Flutter"]
        GHA["GitHub Actions\nCI/CD Pipeline"]
    end

    subgraph VPS["VPS Production (8Go RAM / 4CPU)"]
        subgraph PROD["Production — golivreur.ci"]
            NGINX_P["Nginx\n:443 (SSL Let's Encrypt)"]
            PHP_P["PHP 8.3-FPM\nLaravel 11\n/var/www/production"]
            HORIZON_P["Laravel Horizon\nWorkers Redis\nhigh/default/low"]
            SCHED_P["Laravel Scheduler\ncrontab * * * * *"]
        end

        subgraph STAGING["Staging — staging.golivreur.ci"]
            NGINX_S["Nginx\n:443 (SSL)"]
            PHP_S["PHP 8.3-FPM\nLaravel 11\n/var/www/staging"]
        end

        subgraph DATA_VPS["Données partagées VPS"]
            PG["PostgreSQL 16\nPort 5432\nDB: golivreur_prod\nDB: golivreur_staging"]
            REDIS["Redis 7\nPort 6379\nDB 0: prod\nDB 1: staging"]
            REVERB["Laravel Reverb\nWebSocket :8080"]
        end
    end

    subgraph DO_SPACES["DigitalOcean Spaces"]
        S3["/photos/ — Photos colis + livreurs\n/backups/ — Dumps PostgreSQL\n (quotidien + hebdo)"]
    end

    subgraph EXTERNAL2["Services externes"]
        HUB2_E["Hub2/CinetPay\nPaiement Mobile Money"]
        FCM_E["Firebase FCM\nPush + Crashlytics"]
        TWILIO_E["Twilio / Orange API\nSMS"]
        MAPS_E["Google Maps API"]
        WEATHER_E["OpenWeatherMap API"]
        SENTRY_E["Sentry\nMonitoring erreurs"]
    end

    subgraph STORES["App Stores"]
        PLAY["Google Play Store\nGoLivreur Client\nGoLivreur Business\nGoLivreur Driver"]
        APPLE["Apple App Store\nGoLivreur Client\nGoLivreur Business\nGoLivreur Driver"]
    end

    DEV -->|git push feature/xxx| GITHUB
    REPO --> GHA

    GHA -->|push develop:\nPest + Larastan + flutter analyze\n→ déploiement auto staging| STAGING
    GHA -->|merge main (approbation Fondateur)\n→ déploiement prod 3h du matin| PROD

    NGINX_P --- PHP_P
    PHP_P --- HORIZON_P
    PHP_P --- SCHED_P
    PHP_P --- PG
    PHP_P --- REDIS
    PHP_P --- REVERB
    PHP_S --- PG
    PHP_S --- REDIS

    PHP_P -->|Photos upload| DO_SPACES
    SCHED_P -->|Backup 3h quotidien| DO_SPACES

    PHP_P -->|Webhooks paiement| HUB2_E
    HUB2_E -->|Webhooks HMAC| NGINX_P
    HORIZON_P -->|Push notifications| FCM_E
    HORIZON_P -->|SMS OTP| TWILIO_E
    PHP_P -->|Géocodage| MAPS_E
    SCHED_P -->|Météo /15min| WEATHER_E
    PHP_P -->|Erreurs| SENTRY_E
```

---

## Processus de déploiement pas à pas

### Déploiement sur Staging (automatique)

Déclenché par chaque push sur la branche `develop`.

```
1. GitHub Actions démarre
2. Installation des dépendances (composer install, flutter pub get)
3. Larastan niveau 5 → échec = pipeline bloqué
4. flutter analyze (3 apps) → échec = pipeline bloqué
5. Tests Pest → échec = pipeline bloqué
6. Si tout passe :
   - SSH vers VPS
   - cd /var/www/staging
   - git pull origin develop
   - composer install --no-dev
   - php artisan migrate --force
   - php artisan config:cache && route:cache && view:cache
   - php artisan queue:restart
   - Notification Slack/email "Staging déployé ✅"
```

### Déploiement en Production (manuel + automatique)

Déclenché par un merge sur `main` **après approbation de Francis EBOAH**.

```
1. Pull Request de develop → main créée
2. Pipeline CI identique au staging (tests + analyse)
3. Francis EBOAH reçoit notification GitHub pour review
4. Francis approuve le merge
5. Merge effectué → GitHub Actions déclenché
6. Pipeline vérifie l'heure : attente 3h00 si nécessaire
   (ou déploiement immédiat si urgence hotfix)
7. SSH vers VPS production
8. Backup PostgreSQL manuel immédiat (avant toute migration)
   pg_dump golivreur_prod > /tmp/backup_pre_deploy_$(date +%Y%m%d_%H%M).sql
   rclone copy /tmp/backup_pre_deploy_*.sql spaces:golivreur/backups/pre-deploy/
9. cd /var/www/production
10. git pull origin main
11. composer install --no-dev --optimize-autoloader
12. php artisan down --message="Mise à jour en cours (2-3 min)" --retry=60
13. php artisan migrate --force
14. php artisan config:cache && route:cache && view:cache
15. php artisan queue:restart
16. php artisan up
17. Health check : curl https://golivreur.ci/api/v1/health → 200 OK
18. Notification email Francis "Production déployée ✅ — $(date)"
```

---

## Ports et services VPS

| Service | Port | Accès |
|---|---|---|
| Nginx (HTTP) | 80 | Public → redirige vers 443 |
| Nginx (HTTPS) | 443 | Public |
| PostgreSQL | 5432 | Localhost uniquement |
| Redis | 6379 | Localhost uniquement |
| Laravel Reverb (WebSocket) | 8080 | Via Nginx proxy |
| Laravel Horizon | /horizon | Admin authentifié uniquement |
| SSH | 22 | IP whitelist (Fondateur + Lead Dev) |

---

## Variables d'environnement production (.env)

> ⚠️ Ce fichier est sur le VPS uniquement — jamais dans Git.  
> Les valeurs sont partagées via **Bitwarden** entre le Fondateur et le Lead Dev.

```env
APP_NAME=GoLivreur
APP_ENV=production
APP_DEBUG=false
APP_URL=https://golivreur.ci

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=golivreur_prod
DB_USERNAME=golivreur_user
DB_PASSWORD=*** (dans Bitwarden)

REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_DB=0

JWT_SECRET=*** (dans Bitwarden — généré via: php artisan jwt:secret)
JWT_TTL=60
JWT_REFRESH_TTL=10080

REVERB_APP_ID=golivreur
REVERB_APP_KEY=*** (dans Bitwarden)
REVERB_APP_SECRET=*** (dans Bitwarden)
REVERB_HOST=golivreur.ci
REVERB_PORT=8080
REVERB_SCHEME=https

FILESYSTEM_DISK=spaces
DO_SPACES_KEY=*** (dans Bitwarden)
DO_SPACES_SECRET=*** (dans Bitwarden)
DO_SPACES_REGION=fra1
DO_SPACES_BUCKET=golivreur
DO_SPACES_ENDPOINT=https://fra1.digitaloceanspaces.com

HUB2_API_KEY=*** (dans Bitwarden)
HUB2_SECRET=*** (dans Bitwarden)
HUB2_WEBHOOK_SECRET=*** (dans Bitwarden — pour validation HMAC)
HUB2_ENV=production

TWILIO_SID=*** (dans Bitwarden)
TWILIO_TOKEN=*** (dans Bitwarden)
TWILIO_FROM=+*** (dans Bitwarden)

GOOGLE_MAPS_KEY=*** (dans Bitwarden)
OPENWEATHERMAP_KEY=*** (dans Bitwarden)

FIREBASE_CREDENTIALS=*** (chemin vers fichier JSON service account)

SENTRY_LARAVEL_DSN=https://***@sentry.io/***

MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=noreply@golivreur.ci
MAIL_PASSWORD=*** (dans Bitwarden)
MAIL_FROM_ADDRESS=noreply@golivreur.ci
MAIL_FROM_NAME=GoLivreur

SMS_MONTHLY_ALERT_THRESHOLD=2500
SMS_ALERT_EMAIL=francis@golivreur.ci

QUEUE_CONNECTION=redis
HORIZON_ENABLED=true
```
