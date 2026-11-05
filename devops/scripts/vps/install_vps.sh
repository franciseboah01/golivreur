#!/bin/bash
# ═══════════════════════════════════════════════════════════════════════════════
# GoLivreur — Script d'installation VPS
# Ubuntu 24.04 LTS — Part d'un serveur VIERGE
# ═══════════════════════════════════════════════════════════════════════════════
#
# UTILISATION :
#   1. Louer un VPS DigitalOcean (8 Go RAM, 4 CPU, Ubuntu 24.04)
#   2. Se connecter en SSH : ssh root@[IP_DU_VPS]
#   3. Exécuter ce script : bash install_vps.sh
#
# DURÉE ESTIMÉE : 15-20 minutes
#
# CE SCRIPT INSTALLE :
#   - Nginx 1.24
#   - PHP 8.3-FPM + extensions Laravel
#   - PostgreSQL 16
#   - Redis 7
#   - Node.js 20 (pour les assets Filament)
#   - Composer 2
#   - Rclone (pour les backups DO Spaces)
#   - Certbot (SSL Let's Encrypt)
#   - Supervisord (pour Horizon en arrière-plan)
#   - Structure des dossiers de déploiement

set -e  # Arrêter en cas d'erreur

# ── Couleurs ──────────────────────────────────────────────────────────────────
CYAN='\033[36m'
GREEN='\033[32m'
YELLOW='\033[33m'
RED='\033[31m'
RESET='\033[0m'

log()     { echo -e "${CYAN}[$(date +%H:%M:%S)]${RESET} $1"; }
success() { echo -e "${GREEN}✅ $1${RESET}"; }
warning() { echo -e "${YELLOW}⚠️  $1${RESET}"; }
error()   { echo -e "${RED}❌ $1${RESET}"; exit 1; }

# ── Variables de configuration ─────────────────────────────────────────────────
# ⚠️ MODIFIEZ CES VALEURS AVANT D'EXÉCUTER LE SCRIPT
DOMAINE="golivreur.ci"
DOMAINE_STAGING="staging.golivreur.ci"
DB_NAME_PROD="golivreur_prod"
DB_NAME_STAGING="golivreur_staging"
DB_USER="golivreur_user"
DB_PASS="${DB_PASSWORD:-CHANGE_ME}"  # Depuis Bitwarden
EMAIL_ADMIN="francis@golivreur.ci"
GITHUB_REPO="CHANGE_ME"            # Ex: git@github.com:francis-eboah/golivreur.git
DEPLOY_USER="ubuntu"               # Utilisateur non-root pour le déploiement

echo ""
echo "╔══════════════════════════════════════════════════════╗"
echo "║     GoLivreur — Installation VPS Ubuntu 24.04       ║"
echo "╚══════════════════════════════════════════════════════╝"
echo ""

# ── Vérification root ─────────────────────────────────────────────────────────
if [ "$EUID" -ne 0 ]; then
    error "Ce script doit être exécuté en tant que root (sudo bash install_vps.sh)"
fi

# ── 1. Mise à jour système ────────────────────────────────────────────────────
log "Mise à jour du système..."
apt update && apt upgrade -y -q
apt install -y -q \
    curl wget git unzip zip \
    software-properties-common \
    apt-transport-https \
    ca-certificates \
    gnupg \
    supervisor \
    mailutils \
    ufw \
    fail2ban
success "Système mis à jour"

# ── 2. Créer l'utilisateur de déploiement ────────────────────────────────────
log "Création de l'utilisateur de déploiement '$DEPLOY_USER'..."
if ! id "$DEPLOY_USER" &>/dev/null; then
    adduser --disabled-password --gecos "" "$DEPLOY_USER"
    usermod -aG sudo "$DEPLOY_USER"
fi
# Créer le répertoire SSH
mkdir -p /home/$DEPLOY_USER/.ssh
chmod 700 /home/$DEPLOY_USER/.ssh
# Copier la clé SSH autorisée depuis root
cp /root/.ssh/authorized_keys /home/$DEPLOY_USER/.ssh/ 2>/dev/null || true
chown -R $DEPLOY_USER:$DEPLOY_USER /home/$DEPLOY_USER/.ssh
success "Utilisateur '$DEPLOY_USER' créé"

# ── 3. PHP 8.3 ───────────────────────────────────────────────────────────────
log "Installation de PHP 8.3..."
add-apt-repository ppa:ondrej/php -y -q
apt update -q
apt install -y -q \
    php8.3 \
    php8.3-fpm \
    php8.3-pgsql \
    php8.3-redis \
    php8.3-curl \
    php8.3-mbstring \
    php8.3-xml \
    php8.3-zip \
    php8.3-gd \
    php8.3-bcmath \
    php8.3-intl \
    php8.3-pcntl \
    php8.3-cli \
    php8.3-common

# Configuration PHP pour la production
cat > /etc/php/8.3/fpm/conf.d/99-golivreur.ini << 'PHPCONF'
upload_max_filesize = 10M
post_max_size = 12M
max_execution_time = 60
memory_limit = 256M
date.timezone = Africa/Abidjan
display_errors = Off
log_errors = On
error_log = /var/log/php8.3-fpm-error.log
opcache.enable = 1
opcache.memory_consumption = 128
opcache.validate_timestamps = 0
PHPCONF

systemctl restart php8.3-fpm
systemctl enable php8.3-fpm
success "PHP 8.3 installé"

# ── 4. Composer ──────────────────────────────────────────────────────────────
log "Installation de Composer 2..."
curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
composer --version
success "Composer installé"

# ── 5. Nginx ─────────────────────────────────────────────────────────────────
log "Installation de Nginx..."
apt install -y -q nginx
systemctl enable nginx
success "Nginx installé"

# ── 6. PostgreSQL 16 ─────────────────────────────────────────────────────────
log "Installation de PostgreSQL 16..."
echo "deb http://apt.postgresql.org/pub/repos/apt $(lsb_release -cs)-pgdg main" \
    > /etc/apt/sources.list.d/pgdg.list
curl -fsSL https://www.postgresql.org/media/keys/ACCC4CF8.asc | gpg --dearmor -o /etc/apt/trusted.gpg.d/postgresql.gpg
apt update -q
apt install -y -q postgresql-16 postgresql-client-16

# Démarrer PostgreSQL
systemctl start postgresql
systemctl enable postgresql

# Créer les bases de données et l'utilisateur
sudo -u postgres psql << PGSQL
CREATE USER $DB_USER WITH PASSWORD '$DB_PASS';
CREATE DATABASE $DB_NAME_PROD OWNER $DB_USER;
CREATE DATABASE $DB_NAME_STAGING OWNER $DB_USER;
GRANT ALL PRIVILEGES ON DATABASE $DB_NAME_PROD TO $DB_USER;
GRANT ALL PRIVILEGES ON DATABASE $DB_NAME_STAGING TO $DB_USER;
\q
PGSQL

success "PostgreSQL 16 installé et configuré"

# ── 7. Redis 7 ───────────────────────────────────────────────────────────────
log "Installation de Redis 7..."
apt install -y -q redis-server

# Configuration Redis pour la production
sed -i 's/^# maxmemory <bytes>/maxmemory 1gb/' /etc/redis/redis.conf
sed -i 's/^# maxmemory-policy noeviction/maxmemory-policy allkeys-lru/' /etc/redis/redis.conf
# Lier sur localhost uniquement (sécurité)
sed -i 's/^bind 127.0.0.1 ::1/bind 127.0.0.1/' /etc/redis/redis.conf

systemctl restart redis-server
systemctl enable redis-server
success "Redis 7 installé"

# ── 8. Node.js 20 ────────────────────────────────────────────────────────────
log "Installation de Node.js 20..."
curl -fsSL https://deb.nodesource.com/setup_20.x | bash -
apt install -y -q nodejs
success "Node.js $(node --version) installé"

# ── 9. Rclone (backups DigitalOcean Spaces) ──────────────────────────────────
log "Installation de Rclone..."
curl https://rclone.org/install.sh | bash
# La configuration rclone sera ajoutée manuellement (voir GITHUB_SECRETS.md)
success "Rclone installé"

# ── 10. Structure des dossiers ───────────────────────────────────────────────
log "Création de la structure des dossiers..."
mkdir -p /var/www/{production,staging}
mkdir -p /var/www/scripts
mkdir -p /var/log/golivreur
mkdir -p /tmp/backups

# Permissions
chown -R $DEPLOY_USER:www-data /var/www
chmod -R 755 /var/www
chown $DEPLOY_USER:$DEPLOY_USER /var/log/golivreur
success "Structure des dossiers créée"

# ── 11. Cloner le repository ──────────────────────────────────────────────────
if [ "$GITHUB_REPO" != "CHANGE_ME" ]; then
    log "Clonage du repository GoLivreur..."
    sudo -u $DEPLOY_USER git clone "$GITHUB_REPO" /var/www/production
    sudo -u $DEPLOY_USER git clone "$GITHUB_REPO" /var/www/staging
    cd /var/www/staging && sudo -u $DEPLOY_USER git checkout develop
    success "Repository cloné"
fi

# ── 12. Copier les scripts de déploiement ────────────────────────────────────
log "Installation des scripts de déploiement..."
cp /var/www/production/devops/scripts/vps/deploy.sh /var/www/scripts/
cp /var/www/production/devops/scripts/vps/rollback.sh /var/www/scripts/
cp /var/www/production/devops/scripts/backup/backup_bdd.sh /var/www/scripts/
chmod +x /var/www/scripts/*.sh
success "Scripts installés"

# ── 13. Certbot (SSL Let's Encrypt) ─────────────────────────────────────────
log "Installation de Certbot..."
apt install -y -q certbot python3-certbot-nginx
success "Certbot installé (SSL sera configuré après Nginx)"

# ── 14. Supervisord (Horizon en arrière-plan) ────────────────────────────────
log "Configuration de Supervisord pour Horizon..."
cat > /etc/supervisor/conf.d/golivreur-horizon.conf << SUPERVISOR
[program:golivreur-horizon]
process_name=%(program_name)s
command=php /var/www/production/artisan horizon
autostart=true
autorestart=true
user=$DEPLOY_USER
redirect_stderr=true
stdout_logfile=/var/log/golivreur/horizon.log
stdout_logfile_maxbytes=5MB
stdout_logfile_backups=3
stopwaitsecs=3600
SUPERVISOR

supervisorctl reread
supervisorctl update
success "Supervisord + Horizon configurés"

# ── 15. Cron — Backup quotidien ──────────────────────────────────────────────
log "Configuration du cron backup..."
(crontab -u $DEPLOY_USER -l 2>/dev/null; echo "0 3 * * * bash /var/www/scripts/backup_bdd.sh >> /var/log/golivreur/backup.log 2>&1") \
    | crontab -u $DEPLOY_USER -
# Cron Laravel Scheduler (toutes les minutes)
(crontab -u $DEPLOY_USER -l 2>/dev/null; echo "* * * * * cd /var/www/production && php artisan schedule:run >> /dev/null 2>&1") \
    | crontab -u $DEPLOY_USER -
success "Crons configurés (backup 3h + scheduler Laravel)"

# ── 16. Firewall UFW ─────────────────────────────────────────────────────────
log "Configuration du firewall..."
ufw default deny incoming
ufw default allow outgoing
ufw allow 22/tcp comment 'SSH'
ufw allow 80/tcp comment 'HTTP'
ufw allow 443/tcp comment 'HTTPS'
ufw allow 8080/tcp comment 'Reverb WebSocket'
echo "y" | ufw enable
success "Firewall configuré"

# ── 17. Fail2ban ──────────────────────────────────────────────────────────────
log "Configuration de Fail2ban..."
systemctl start fail2ban
systemctl enable fail2ban
success "Fail2ban actif"

# ── Résumé final ──────────────────────────────────────────────────────────────
echo ""
echo "╔══════════════════════════════════════════════════════════════╗"
echo "║         GoLivreur VPS — Installation Terminée ✅            ║"
echo "╠══════════════════════════════════════════════════════════════╣"
echo "║                                                              ║"
echo "║  ÉTAPES SUIVANTES :                                         ║"
echo "║                                                              ║"
echo "║  1. Configurer Nginx :                                       ║"
echo "║     cp devops/nginx/production.conf /etc/nginx/sites-available/$DOMAINE"
echo "║     nginx -t && systemctl reload nginx                       ║"
echo "║                                                              ║"
echo "║  2. Obtenir le certificat SSL :                              ║"
echo "║     certbot --nginx -d $DOMAINE -d $DOMAINE_STAGING --email $EMAIL_ADMIN"
echo "║                                                              ║"
echo "║  3. Configurer le .env production :                          ║"
echo "║     cp /var/www/production/.env.example /var/www/production/.env"
echo "║     nano /var/www/production/.env (remplir depuis Bitwarden) ║"
echo "║                                                              ║"
echo "║  4. Initialiser Laravel :                                    ║"
echo "║     cd /var/www/production                                   ║"
echo "║     composer install --no-dev --optimize-autoloader          ║"
echo "║     php artisan key:generate                                 ║"
echo "║     php artisan jwt:secret                                   ║"
echo "║     php artisan migrate --seed --class=ProductionSeeder      ║"
echo "║                                                              ║"
echo "║  5. Configurer Rclone (DO Spaces) :                          ║"
echo "║     rclone config (voir GITHUB_SECRETS.md)                   ║"
echo "║                                                              ║"
echo "╚══════════════════════════════════════════════════════════════╝"
echo ""
