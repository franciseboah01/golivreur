# GoLivreur — Stratégie de Sauvegarde

---

## Plan de sauvegarde

| Type | Fréquence | Heure | Rétention | Stockage |
|---|---|---|---|---|
| Backup quotidien BDD | Tous les jours | 03h00 | 30 jours | DO Spaces /backups/daily/ |
| Backup hebdomadaire BDD | Dimanche | 03h30 | 6 mois | DO Spaces /backups/weekly/ |
| Backup pré-déploiement | Avant chaque déploiement prod | À la volée | 30 jours | DO Spaces /backups/pre-deploy/ |
| Photos colis/livreurs | Temps réel (upload) | — | Permanent | DO Spaces /photos/ |

---

## Script de backup quotidien

Ce script est exécuté automatiquement par le cron Laravel Scheduler à 3h00.

```bash
#!/bin/bash
# /var/www/production/scripts/backup_bdd.sh
# Ne pas exécuter manuellement sauf urgence

set -e

DATE=$(date +%Y%m%d_%H%M%S)
BACKUP_FILE="/tmp/golivreur_prod_${DATE}.sql.gz"
SPACES_PATH="golivreur/backups/daily/"

echo "[$(date)] Démarrage backup PostgreSQL..."

# Dump compressé de la base de données
PGPASSWORD="${DB_PASSWORD}" pg_dump \
    -h 127.0.0.1 \
    -U golivreur_user \
    -d golivreur_prod \
    --no-password \
    --clean \
    --if-exists \
    | gzip > "${BACKUP_FILE}"

echo "[$(date)] Dump créé : ${BACKUP_FILE} ($(du -h ${BACKUP_FILE} | cut -f1))"

# Upload vers DigitalOcean Spaces via rclone
rclone copy "${BACKUP_FILE}" "spaces:${SPACES_PATH}"

echo "[$(date)] Upload terminé vers spaces:${SPACES_PATH}"

# Nettoyage fichier temporaire local
rm -f "${BACKUP_FILE}"

# Purge des backups de plus de 30 jours dans Spaces
rclone delete "spaces:golivreur/backups/daily/" \
    --min-age 30d \
    --include "*.sql.gz"

echo "[$(date)] Backup quotidien terminé avec succès ✅"
```

---

## Procédure de restauration

> ⚠️ **Lisez cette procédure AVANT d'en avoir besoin.** Une restauration en urgence sans l'avoir testée prend 3× plus de temps.

### Étape 1 — Identifier le bon backup

```bash
# Lister les backups disponibles
rclone ls spaces:golivreur/backups/daily/ | sort -r | head -20

# Exemple de sortie :
# 45231234 backups/daily/golivreur_prod_20260624_030001.sql.gz  ← le plus récent
# 44980123 backups/daily/golivreur_prod_20260623_030001.sql.gz
```

### Étape 2 — Télécharger le backup

```bash
# Télécharger le backup choisi
rclone copy "spaces:golivreur/backups/daily/golivreur_prod_20260624_030001.sql.gz" /tmp/

# Vérifier l'intégrité du fichier
gzip -t /tmp/golivreur_prod_20260624_030001.sql.gz && echo "Fichier OK" || echo "FICHIER CORROMPU"
```

### Étape 3 — Mettre l'application en mode maintenance

```bash
cd /var/www/production
php artisan down --message="Maintenance en cours — retour dans 15 minutes"
```

### Étape 4 — Restaurer la base de données

```bash
# Décompresser
gunzip /tmp/golivreur_prod_20260624_030001.sql.gz

# Restaurer (ATTENTION : ceci ÉCRASE toutes les données actuelles)
PGPASSWORD="${DB_PASSWORD}" psql \
    -h 127.0.0.1 \
    -U golivreur_user \
    -d golivreur_prod \
    -f /tmp/golivreur_prod_20260624_030001.sql

echo "Restauration terminée"
```

### Étape 5 — Vérifier et relancer

```bash
# Vérifier que les tables sont bien là
PGPASSWORD="${DB_PASSWORD}" psql -h 127.0.0.1 -U golivreur_user -d golivreur_prod \
    -c "SELECT COUNT(*) FROM orders;"

# Vider les caches Laravel
php artisan optimize:clear

# Relancer l'application
php artisan up

# Vérifier le health check
curl https://golivreur.ci/api/v1/health
```

### Étape 6 — Notifier

```bash
# Envoyer un email de notification
echo "Restauration PostgreSQL effectuée le $(date). Backup utilisé : golivreur_prod_20260624_030001.sql" \
    | mail -s "GoLivreur — Restauration BDD effectuée" francis@golivreur.ci
```

---

## Test mensuel de restauration

À effectuer le **premier lundi de chaque mois** sur l'environnement **staging**.

```bash
# Sur le VPS, restaurer le dernier backup quotidien sur la BDD staging
PGPASSWORD="${DB_PASSWORD}" psql -h 127.0.0.1 -U golivreur_user -d golivreur_staging \
    -f /tmp/dernier_backup.sql

# Vérifier que staging répond correctement
curl https://staging.golivreur.ci/api/v1/health

# Remettre les données de staging propres
cd /var/www/staging
php artisan migrate:fresh --seed --env=staging

echo "Test restauration mensuel OK — $(date)" >> /var/log/golivreur/backup_tests.log
```
