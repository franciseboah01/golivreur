#!/bin/bash
# GoLivreur — Script de backup PostgreSQL
# Exécuté automatiquement par cron à 3h00 chaque nuit
# Également disponible manuellement : bash backup_bdd.sh
#
# CE SCRIPT :
#   1. Crée un dump PostgreSQL compressé (gzip)
#   2. L'envoie sur DigitalOcean Spaces (dossier /backups/daily/)
#   3. Purge les backups de plus de 30 jours
#   4. Envoie un email de confirmation à Francis EBOAH
#
# PRÉREQUIS SUR LE VPS :
#   - rclone configuré avec accès DO Spaces (rclone config)
#   - mailutils installé (apt install mailutils)
#   - Variables d'environnement DB_* dans /etc/environment ou chargées

set -e

# ── Configuration ─────────────────────────────────────────────────────────────
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-5432}"
DB_NAME="${DB_DATABASE:-golivreur_prod}"
DB_USER="${DB_USERNAME:-golivreur_user}"
ADMIN_EMAIL="${ADMIN_EMAIL:-francis@golivreur.ci}"

# Dossiers DigitalOcean Spaces
SPACES_DAILY="spaces:golivreur/backups/daily"
SPACES_WEEKLY="spaces:golivreur/backups/weekly"
SPACES_PRE_DEPLOY="spaces:golivreur/backups/pre-deploy"

# Rétention
RETENTION_DAILY_JOURS=30
RETENTION_WEEKLY_MOIS=6

# Répertoire temporaire local
TMP_DIR="/tmp/backups"
LOG_FILE="/var/log/golivreur/backup.log"

# ── Fonctions ─────────────────────────────────────────────────────────────────
log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1" | tee -a "$LOG_FILE"; }

DEBUT=$(date +%s)
DATE_FICHIER=$(date +%Y%m%d_%H%M%S)
JOUR_SEMAINE=$(date +%u)  # 1=Lundi ... 7=Dimanche
FICHIER_BACKUP="golivreur_prod_${DATE_FICHIER}.sql.gz"
CHEMIN_COMPLET="$TMP_DIR/$FICHIER_BACKUP"

mkdir -p "$TMP_DIR"

log "════════════════════════════════════════════════"
log "  GoLivreur — Backup PostgreSQL démarré"
log "  Base     : $DB_NAME"
log "  Fichier  : $FICHIER_BACKUP"
log "════════════════════════════════════════════════"

# ── 1. Créer le dump PostgreSQL compressé ────────────────────────────────────
log "Création du dump..."

PGPASSWORD="$DB_PASSWORD" pg_dump \
    -h "$DB_HOST" \
    -p "$DB_PORT" \
    -U "$DB_USER" \
    -d "$DB_NAME" \
    --no-password \
    --clean \
    --if-exists \
    --verbose \
    2>> "$LOG_FILE" \
    | gzip -9 > "$CHEMIN_COMPLET"

# Vérifier que le fichier n'est pas vide
TAILLE=$(du -sh "$CHEMIN_COMPLET" | cut -f1)
if [ ! -s "$CHEMIN_COMPLET" ]; then
    log "ERREUR : Le fichier backup est vide !"
    echo "ERREUR CRITIQUE : Backup PostgreSQL vide le $(date)" \
        | mail -s "🚨 GoLivreur — BACKUP ÉCHOUÉ" "$ADMIN_EMAIL"
    exit 1
fi

log "Dump créé : $FICHIER_BACKUP ($TAILLE)"

# ── 2. Vérifier l'intégrité du fichier ───────────────────────────────────────
log "Vérification de l'intégrité..."
if gzip -t "$CHEMIN_COMPLET" 2>/dev/null; then
    log "Intégrité OK"
else
    log "ERREUR : Fichier corrompu !"
    echo "ERREUR : Backup corrompu le $(date)" \
        | mail -s "🚨 GoLivreur — BACKUP CORROMPU" "$ADMIN_EMAIL"
    exit 1
fi

# ── 3. Upload vers DigitalOcean Spaces (backup quotidien) ────────────────────
log "Upload vers DO Spaces (quotidien)..."
rclone copy "$CHEMIN_COMPLET" "$SPACES_DAILY/" \
    --progress \
    2>> "$LOG_FILE"
log "Upload quotidien terminé"

# ── 4. Backup hebdomadaire (chaque dimanche) ──────────────────────────────────
if [ "$JOUR_SEMAINE" = "7" ]; then
    log "Dimanche détecté — Backup hebdomadaire..."
    FICHIER_WEEKLY="golivreur_weekly_$(date +%Y_W%V).sql.gz"
    rclone copy "$CHEMIN_COMPLET" "$SPACES_WEEKLY/" \
        --progress \
        2>> "$LOG_FILE"
    log "Backup hebdomadaire uploadé : $FICHIER_WEEKLY"
fi

# ── 5. Purge des anciens backups ──────────────────────────────────────────────
log "Purge des backups quotidiens > $RETENTION_DAILY_JOURS jours..."
rclone delete "$SPACES_DAILY/" \
    --min-age "${RETENTION_DAILY_JOURS}d" \
    --include "*.sql.gz" \
    2>> "$LOG_FILE"

log "Purge des backups hebdomadaires > $RETENTION_WEEKLY_MOIS mois..."
rclone delete "$SPACES_WEEKLY/" \
    --min-age "${RETENTION_WEEKLY_MOIS}M" \
    --include "*.sql.gz" \
    2>> "$LOG_FILE"

# ── 6. Nettoyage du fichier temporaire local ──────────────────────────────────
rm -f "$CHEMIN_COMPLET"
log "Fichier temporaire supprimé"

# ── 7. Calculer la durée ──────────────────────────────────────────────────────
FIN=$(date +%s)
DUREE=$((FIN - DEBUT))

# ── 8. Lister les derniers backups disponibles ────────────────────────────────
LISTE_BACKUPS=$(rclone ls "$SPACES_DAILY/" | sort -r | head -5 | awk '{print "  - "$2" ("$1" bytes)"}')

# ── 9. Email de confirmation ──────────────────────────────────────────────────
EMAIL_CORPS="✅ Backup GoLivreur réussi

Date           : $(date '+%d/%m/%Y %H:%M:%S')
Base           : $DB_NAME
Taille         : $TAILLE
Durée          : ${DUREE}s
Destination    : $SPACES_DAILY/$FICHIER_BACKUP
Hebdomadaire   : $([ "$JOUR_SEMAINE" = "7" ] && echo "Oui" || echo "Non")

Derniers backups disponibles :
$LISTE_BACKUPS

Espace DO Spaces utilisé :
$(rclone about spaces:golivreur 2>/dev/null | grep -E "Used|Free" || echo "  (rclone about non disponible)")

Pour restaurer un backup :
  1. rclone ls $SPACES_DAILY/ | sort -r | head -5
  2. rclone copy $SPACES_DAILY/[NOM_FICHIER] /tmp/
  3. Voir documentation/09_ops/02_strategie_backup.md
"

echo "$EMAIL_CORPS" \
    | mail -s "✅ GoLivreur — Backup BDD $(date '+%d/%m/%Y') OK ($TAILLE)" \
    "$ADMIN_EMAIL" || log "Avertissement : email non envoyé"

log "════════════════════════════════════════════════"
log "  Backup terminé avec succès en ${DUREE}s"
log "════════════════════════════════════════════════"
