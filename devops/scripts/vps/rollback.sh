#!/bin/bash
# GoLivreur — Script de rollback production
# Utilisé par : make rollback OU GitHub Actions (smoke test échoué)
#
# Ce script :
#   1. Revient au commit précédent
#   2. Réinstalle les dépendances
#   3. Remet en ligne l'application
#   4. Envoie un email de notification

set -e

CYAN='\033[36m'
GREEN='\033[32m'
RED='\033[31m'
RESET='\033[0m'

log()   { echo -e "${CYAN}[$(date +%H:%M:%S)]${RESET} $1"; }
ok()    { echo -e "${GREEN}✅ $1${RESET}"; }
fail()  { echo -e "${RED}❌ $1${RESET}"; }

PROD_DIR="/var/www/production"
COMMIT_FILE="/tmp/golivreur_last_deploy_commit"
ADMIN_EMAIL="${ADMIN_EMAIL:-francis@golivreur.ci}"

echo ""
echo "╔══════════════════════════════════════════════════════╗"
echo "║       GoLivreur — ROLLBACK PRODUCTION               ║"
echo "║       $(date '+%d/%m/%Y %H:%M:%S')                  ║"
echo "╚══════════════════════════════════════════════════════╝"
echo ""

cd "$PROD_DIR"

# ── 1. Récupérer le commit précédent ─────────────────────────────────────────
COMMIT_ACTUEL=$(git rev-parse HEAD)

if [ -f "$COMMIT_FILE" ]; then
    COMMIT_AVANT=$(cat "$COMMIT_FILE")
    log "Commit actuel   : $COMMIT_ACTUEL"
    log "Rollback vers   : $COMMIT_AVANT"
else
    # Si pas de fichier, utiliser HEAD~1 (commit précédent dans l'historique Git)
    COMMIT_AVANT=$(git rev-parse HEAD~1)
    log "Fichier commit introuvable — rollback vers HEAD~1 : $COMMIT_AVANT"
fi

# ── 2. Activer le mode maintenance ───────────────────────────────────────────
log "Activation du mode maintenance..."
php artisan down --message="Maintenance corrective en cours — retour dans 5 minutes" --retry=60
ok "Mode maintenance activé"

# ── 3. Revenir au commit précédent ───────────────────────────────────────────
log "Rollback Git vers $COMMIT_AVANT..."
git fetch origin
git checkout "$COMMIT_AVANT"
ok "Code rollback effectué"

# ── 4. Réinstaller les dépendances ───────────────────────────────────────────
log "Réinstallation des dépendances..."
composer install --no-interaction --no-dev --prefer-dist --optimize-autoloader
ok "Dépendances réinstallées"

# ── 5. Pas de rollback des migrations
# Les migrations Laravel ne se rollback pas automatiquement en production
# (risque de perte de données). Le schema reste tel quel.
log "⚠️  Les migrations ne sont PAS rollbackées (protection données)"
log "   Si nécessaire, restaurer depuis le backup pre-deploy"

# ── 6. Reconstruire les caches ────────────────────────────────────────────────
log "Reconstruction des caches..."
php artisan optimize:clear
php artisan optimize
ok "Caches reconstruits"

# ── 7. Redémarrer les workers ────────────────────────────────────────────────
log "Redémarrage des workers Horizon..."
php artisan queue:restart
ok "Workers redémarrés"

# ── 8. Remettre en ligne ──────────────────────────────────────────────────────
log "Remise en ligne..."
php artisan up
ok "Application remise en ligne"

# ── 9. Smoke test rapide ─────────────────────────────────────────────────────
log "Vérification de l'API après rollback..."
sleep 5
if curl -sf --max-time 10 https://golivreur.ci/api/v1/health | grep -q '"status":"ok"'; then
    ok "API opérationnelle après rollback"
    STATUT="✅ ROLLBACK RÉUSSI"
else
    fail "⚠️  API ne répond pas après rollback — intervention manuelle requise"
    STATUT="⚠️  ROLLBACK — API NE RÉPOND PAS"
fi

# ── 10. Notification email ────────────────────────────────────────────────────
echo "$STATUT

Date            : $(date '+%d/%m/%Y %H:%M:%S')
Commit avant    : $COMMIT_ACTUEL
Commit rollback : $COMMIT_AVANT
Serveur         : $(hostname)

Actions effectuées :
  - Rollback Git vers $COMMIT_AVANT
  - Dépendances réinstallées
  - Caches reconstruits
  - Workers redémarrés
  - Application remise en ligne

⚠️  Vérifiez le dashboard Horizon : https://admin.golivreur.ci/horizon
⚠️  Vérifiez les logs : /var/log/golivreur/
" | mail -s "🔄 GoLivreur Production — $STATUT" "$ADMIN_EMAIL" || true

echo ""
echo "═══════════════════════════════════════════════════"
echo "  Rollback terminé — $STATUT"
echo "  Commit actuel : $(git rev-parse HEAD)"
echo "═══════════════════════════════════════════════════"
