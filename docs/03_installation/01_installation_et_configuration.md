# GoLivreur — Installation et Configuration

**Public cible** : Francis EBOAH (Fondateur) et Lead Developer  
**Prérequis** : Aucun logiciel pré-installé supposé — tout est expliqué étape par étape.

---

## Prérequis — Poste de développement

### Vérifier si Docker est installé
```bash
docker --version
docker compose version
```

Si ces commandes échouent, installez Docker Desktop :
- **Mac** : https://docs.docker.com/desktop/install/mac-install/
- **Windows** : https://docs.docker.com/desktop/install/windows-install/
- **Linux Ubuntu** : https://docs.docker.com/engine/install/ubuntu/

### Vérifier si Git est installé
```bash
git --version
```

Si la commande échoue :
- **Mac** : `xcode-select --install`
- **Windows** : https://git-scm.com/download/win
- **Linux** : `sudo apt install git`

### Vérifier si Flutter est installé (pour le renfort Flutter)
```bash
flutter --version
```

Si Flutter n'est pas installé : https://docs.flutter.dev/get-started/install

---

## Installation locale (développement)

### Étape 1 — Cloner le repository

```bash
# Remplacez [URL_REPO] par l'URL GitHub fournie par le Lead Dev
git clone [URL_REPO] golivreur
cd golivreur
```

### Étape 2 — Copier le fichier d'environnement

```bash
cp .env.example .env
```

> ⚠️ **Action manuelle requise** : Ouvrez le fichier `.env` dans un éditeur de texte et renseignez les valeurs depuis **Bitwarden** (partagé par le Fondateur). Les valeurs à remplir sont marquées `CHANGE_ME`.

### Étape 3 — Démarrer les conteneurs Docker

```bash
# Construire et démarrer tous les services
docker compose up -d

# Vérifier que tous les conteneurs sont bien lancés
docker compose ps
```

Vous devez voir 4 conteneurs avec le statut `running` :
- `golivreur_app` (PHP 8.3-FPM + Laravel)
- `golivreur_db` (PostgreSQL 16)
- `golivreur_redis` (Redis 7)
- `golivreur_nginx` (Nginx)

**Si un conteneur est en erreur :**
```bash
# Voir les logs du conteneur en erreur
docker compose logs golivreur_app
```

### Étape 4 — Initialiser le projet Laravel

```bash
# Entrer dans le conteneur PHP
docker compose exec golivreur_app bash

# Dans le conteneur — installer les dépendances PHP
composer install

# Générer la clé d'application Laravel
php artisan key:generate

# Générer la clé JWT
php artisan jwt:secret

# Créer les tables de la base de données
php artisan migrate

# Peupler la base avec les données de démonstration
php artisan db:seed

# Créer le lien symbolique pour le stockage
php artisan storage:link

# Quitter le conteneur
exit
```

### Étape 5 — Vérifier l'installation

Ouvrez votre navigateur et accédez à :
- **API** : http://localhost:8000/api/v1/health → doit retourner `{"status":"ok"}`
- **Admin** : http://localhost:8000/admin → page de connexion Filament

**Compte administrateur créé par le seeder :**
```
Email    : admin@golivreur.ci
Password : GoLivreur2026!
Rôle     : super_admin
```

> ⚠️ **Changez ce mot de passe immédiatement** après votre première connexion.

---

## Commandes courantes (développement)

Toutes ces commandes s'exécutent depuis le dossier `golivreur/` :

```bash
# Démarrer les conteneurs
docker compose up -d

# Arrêter les conteneurs
docker compose down

# Voir les logs en temps réel
docker compose logs -f golivreur_app

# Ouvrir un terminal dans le conteneur PHP
docker compose exec golivreur_app bash

# Lancer les tests Pest
docker compose exec golivreur_app php artisan test

# Lancer les tests avec rapport de couverture
docker compose exec golivreur_app php artisan test --coverage --min=70

# Lancer Larastan (analyse statique)
docker compose exec golivreur_app ./vendor/bin/phpstan analyse --level=5

# Vider tous les caches
docker compose exec golivreur_app php artisan optimize:clear

# Créer une nouvelle migration
docker compose exec golivreur_app php artisan make:migration nom_de_la_migration

# Exécuter les migrations
docker compose exec golivreur_app php artisan migrate

# Annuler la dernière migration (développement uniquement)
docker compose exec golivreur_app php artisan migrate:rollback

# Relancer le worker Horizon (après modification du code)
docker compose exec golivreur_app php artisan horizon:terminate
docker compose exec golivreur_app php artisan horizon
```

---

## Structure des branches Git

```bash
# Créer une nouvelle branche de fonctionnalité
git checkout develop
git pull origin develop
git checkout -b feature/nom-de-la-fonctionnalite

# Travailler, commiter...
git add .
git commit -m "feat(goshop): ajoute validation du code de confirmation"

# Pousser et créer une Pull Request vers develop
git push origin feature/nom-de-la-fonctionnalite
```

**Format des messages de commit :**
```
type(scope): description courte en français

Types : feat, fix, refactor, test, docs, chore
Scopes : auth, goshop, gocolis, wallet, admin, driver, client, merchant
```

---

## Installation sur le VPS (production)

> Cette section est exécutée une seule fois lors de la mise en production initiale.  
> Les déploiements suivants se font via GitHub Actions.

### Prérequis VPS Ubuntu 22.04

```bash
# Connexion SSH au VPS
ssh root@[IP_DU_VPS]

# Mise à jour du système
apt update && apt upgrade -y

# Installation des dépendances
apt install -y nginx postgresql postgresql-contrib redis-server \
    php8.3-fpm php8.3-pgsql php8.3-redis php8.3-curl php8.3-mbstring \
    php8.3-xml php8.3-zip php8.3-gd php8.3-bcmath \
    git curl unzip supervisor

# Installation de Composer
curl -sS https://getcomposer.org/installer | php
mv composer.phar /usr/local/bin/composer

# Installation de Node.js (pour les assets Filament)
curl -fsSL https://deb.nodesource.com/setup_20.x | bash -
apt install -y nodejs
```

### Configuration PostgreSQL

```bash
# Se connecter à PostgreSQL
sudo -u postgres psql

-- Dans PostgreSQL :
CREATE DATABASE golivreur_prod;
CREATE DATABASE golivreur_staging;
CREATE USER golivreur_user WITH PASSWORD 'CHANGER_CE_MOT_DE_PASSE';
GRANT ALL PRIVILEGES ON DATABASE golivreur_prod TO golivreur_user;
GRANT ALL PRIVILEGES ON DATABASE golivreur_staging TO golivreur_user;
\q
```

### Déploiement initial

```bash
# Créer les dossiers
mkdir -p /var/www/production /var/www/staging
cd /var/www/production

# Cloner le repository (branche main)
git clone [URL_REPO] .

# Copier et configurer le .env
cp .env.example .env
nano .env  # ← Remplir toutes les valeurs depuis Bitwarden

# Installer les dépendances
composer install --no-dev --optimize-autoloader

# Initialiser Laravel
php artisan key:generate
php artisan jwt:secret
php artisan migrate --force
php artisan db:seed --class=ProductionSeeder
php artisan storage:link
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Configurer les permissions
chown -R www-data:www-data /var/www/production
chmod -R 755 /var/www/production/storage
```

---

## Résolution des problèmes courants

### "Connection refused" sur localhost:8000
```bash
# Vérifier que les conteneurs sont bien démarrés
docker compose ps

# Si golivreur_nginx est arrêté
docker compose restart golivreur_nginx
```

### "SQLSTATE: Connection refused" (PostgreSQL)
```bash
# Vérifier que le conteneur PostgreSQL est démarré
docker compose ps golivreur_db

# Vérifier les logs PostgreSQL
docker compose logs golivreur_db

# Redémarrer uniquement la BDD
docker compose restart golivreur_db

# Attendre 10 secondes puis relancer la migration
sleep 10 && docker compose exec golivreur_app php artisan migrate
```

### "JWT Secret not set"
```bash
docker compose exec golivreur_app php artisan jwt:secret
```

### Les migrations ont échoué partiellement
```bash
# Voir le statut des migrations
docker compose exec golivreur_app php artisan migrate:status

# Annuler la dernière migration (développement uniquement — JAMAIS en production)
docker compose exec golivreur_app php artisan migrate:rollback

# En production : restaurer depuis le backup créé avant le déploiement
# Voir 02_strategie_backup.md pour la procédure de restauration
```
