#!/bin/bash
# GoLivreur — Test de restauration mensuel
# À exécuter le premier lundi de chaque mois sur staging
#
# PRINCIPE : Un backup qu'on n'a jamais testé ne vaut rien.
# Ce script restaure le dernier backup quotidien sur la BDD staging
# et vérifie que l'application répond correctement.
#
# EXÉCUTION : bash test_restauration.sh
# DURÉE ESTIMÉE : 5-10 minutes

set -e

CYAN='\033[36m'
GREEN='\033[32m'
RED='\033[31m'
RESET='\033[0m'

log()  { echo -e "${CYAN}[$(date +%H:%M:%S)]${RESET} $1"; }
ok()   { echo -e "${GREEN}✅ $1${RESET}"; }
fail() { echo -e "${RED}❌ $1${RESET}"; }

ADMIN_EMAIL="${ADMIN_EMAIL:-francis@golivreur.ci}"
SPACES_DAILY="spaces:golivreur/backups/daily"
DB_STAGING="golivreur_staging"
DB_USER="${DB_USERNAME:-golivreur_user}"
LOG_FILE="/var/log/golivreur/restauration_test.log"
TMP_DIR="/tmp/restauration_test"

mkdir -p "$TMP_DIR"

DEBUT=$(date +%s)

echo "" | tee -a "$LOG_FILE"
log "═══════════════════════════════════════════════════" | tee -a "$LOG_FILE"
log "  GoLivreur — Test de restauration mensuel" | tee -a "$LOG_FILE"
log "  $(date '+%d/%m/%Y %H:%M:%S')" | tee -a "$LOG_FILE"
log "═══════════════════════════════════════════════════" | tee -a "$LOG_FILE"

# ── 1. Identifier le dernier backup disponible ────────────────────────────────
log "Recherche du dernier backup..."
DERNIER_BACKUP=$(rclone ls "$SPACES_DAILY/" \
    | sort -r \
    | grep "\.sql\.gz" \
    | head -1 \
    | awk '{print $2}')

if [ -z "$DERNIER_BACKUP" ]; then
    fail "Aucun backup trouvé dans $SPACES_DAILY/"
    echo "ÉCHEC : Aucun backup disponible le $(date)" \
        | mail -s "🚨 GoLivreur — Test restauration ÉCHOUÉ" "$ADMIN_EMAIL"
    exit 1
fi

log "Backup sélectionné : $DERNIER_BACKUP"

# ── 2. Télécharger le backup ──────────────────────────────────────────────────
log "Téléchargement du backup..."
rclone copy "$SPACES_DAILY/$DERNIER_BACKUP" "$TMP_DIR/" --progress
FICHIER_LOCAL="$TMP_DIR/$DERNIER_BACKUP"
TAILLE=$(du -sh "$FICHIER_LOCAL" | cut -f1)
ok "Backup téléchargé ($TAILLE)"

# ── 3. Vérifier l'intégrité ───────────────────────────────────────────────────
log "Vérification de l'intégrité du fichier..."
if gzip -t "$FICHIER_LOCAL"; then
    ok "Intégrité du fichier vérifiée"
else
    fail "Fichier corrompu !"
    exit 1
fi

# ── 4. Décompresser ───────────────────────────────────────────────────────────
log "Décompression..."
FICHIER_SQL="${FICHIER_LOCAL%.gz}"
gunzip -f "$FICHIER_LOCAL"
ok "Décompressé : $(du -sh "$FICHIER_SQL" | cut -f1)"

# ── 5. Restaurer sur la BDD staging ──────────────────────────────────────────
log "Restauration sur la BDD staging ($DB_STAGING)..."
log "⚠️  Ceci ÉCRASE les données staging existantes"

# Vider la BDD staging proprement
PGPASSWORD="$DB_PASSWORD" psql \
    -h 127.0.0.1 -U "$DB_USER" -d postgres \
    -c "DROP DATABASE IF EXISTS ${DB_STAGING}_restore_test;" 2>/dev/null || true

PGPASSWORD="$DB_PASSWORD" psql \
    -h 127.0.0.1 -U "$DB_USER" -d postgres \
    -c "CREATE DATABASE ${DB_STAGING}_restore_test OWNER $DB_USER;"

# Restaurer
PGPASSWORD="$DB_PASSWORD" psql \
    -h 127.0.0.1 \
    -U "$DB_USER" \
    -d "${DB_STAGING}_restore_test" \
    -f "$FICHIER_SQL" \
    --quiet 2>> "$LOG_FILE"

ok "Restauration terminée"

# ── 6. Vérifier les données restaurées ───────────────────────────────────────
log "Vérification des données restaurées..."

TABLES=$(PGPASSWORD="$DB_PASSWORD" psql \
    -h 127.0.0.1 -U "$DB_USER" -d "${DB_STAGING}_restore_test" \
    -t -c "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'public';")

NB_USERS=$(PGPASSWORD="$DB_PASSWORD" psql \
    -h 127.0.0.1 -U "$DB_USER" -d "${DB_STAGING}_restore_test" \
    -t -c "SELECT COUNT(*) FROM users;" 2>/dev/null || echo "0")

NB_ORDERS=$(PGPASSWORD="$DB_PASSWORD" psql \
    -h 127.0.0.1 -U "$DB_USER" -d "${DB_STAGING}_restore_test" \
    -t -c "SELECT COUNT(*) FROM orders;" 2>/dev/null || echo "0")

log "Tables restaurées : $TABLES"
log "Utilisateurs : $NB_USERS"
log "Commandes : $NB_ORDERS"

if [ "$TABLES" -gt "10" ] && [ "$NB_USERS" -gt "0" ]; then
    ok "Données vérifiées — restauration cohérente"
    VERIFICATION_OK=true
else
    fail "Données insuffisantes après restauration"
    VERIFICATION_OK=false
fi

# ── 7. Nettoyage ──────────────────────────────────────────────────────────────
log "Nettoyage..."
PGPASSWORD="$DB_PASSWORD" psql \
    -h 127.0.0.1 -U "$DB_USER" -d postgres \
    -c "DROP DATABASE IF EXISTS ${DB_STAGING}_restore_test;" 2>/dev/null || true
rm -f "$FICHIER_SQL"
ok "Nettoyage effectué"

# ── 8. Calculer la durée ──────────────────────────────────────────────────────
FIN=$(date +%s)
DUREE=$((FIN - DEBUT))

# ── 9. Rapport email ──────────────────────────────────────────────────────────
if [ "$VERIFICATION_OK" = true ]; then
    STATUT="✅ TEST DE RESTAURATION RÉUSSI"
    SUJET="✅ GoLivreur — Test restauration mensuel OK"
else
    STATUT="❌ TEST DE RESTAURATION ÉCHOUÉ"
    SUJET="🚨 GoLivreur — Test restauration ÉCHOUÉ"
fi

EMAIL_CORPS="$STATUT

Date             : $(date '+%d/%m/%Y %H:%M:%S')
Backup testé     : $DERNIER_BACKUP
Taille           : $TAILLE
Durée du test    : ${DUREE}s
BDD test         : ${DB_STAGING}_restore_test (supprimée après test)

Résultats :
  Tables restaurées  : $TABLES
  Utilisateurs       : $NB_USERS
  Commandes          : $NB_ORDERS

$([ "$VERIFICATION_OK" = true ] && echo "Le backup est valide et peut être utilisé en cas d'incident." || echo "ACTION REQUISE : Vérifiez le processus de backup immédiatement.")

Log complet : /var/log/golivreur/restauration_test.log
"

echo "$EMAIL_CORPS" | mail -s "$SUJET" "$ADMIN_EMAIL" || true
echo "$EMAIL_CORPS" >> "$LOG_FILE"

log "═══════════════════════════════════════════════════"
log "  $STATUT"
log "  Durée : ${DUREE}s"
log "═══════════════════════════════════════════════════"
