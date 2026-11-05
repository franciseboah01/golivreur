# GoLivreur — Secrets GitHub Actions

**À configurer UNE SEULE FOIS dans :**  
GitHub → Settings → Secrets and variables → Actions → New repository secret

---

## Comment configurer un secret GitHub

1. Aller sur votre repository GitHub
2. Cliquer sur **Settings** (en haut à droite)
3. Dans le menu gauche : **Secrets and variables → Actions**
4. Cliquer **New repository secret**
5. Saisir le nom EXACT (copier-coller depuis ce fichier) et la valeur
6. Cliquer **Add secret**

---

## Liste complète des secrets requis

### Secrets d'infrastructure VPS

| Nom du secret | Description | Où trouver la valeur |
|---|---|---|
| `VPS_HOST` | Adresse IP de votre VPS DigitalOcean | Dashboard DigitalOcean → Droplets → votre VPS → IPv4 |
| `VPS_USER` | Utilisateur SSH de déploiement | `ubuntu` (créé par le script install_vps.sh) |
| `VPS_SSH_PRIVATE_KEY` | Clé SSH privée pour accéder au VPS | Voir section "Générer une clé SSH" ci-dessous |

### Secrets base de données

| Nom du secret | Description | Valeur |
|---|---|---|
| `DB_PASSWORD` | Mot de passe PostgreSQL golivreur_user | Depuis Bitwarden > GoLivreur > PostgreSQL |

### Secrets services externes

| Nom du secret | Description | Où trouver |
|---|---|---|
| `HUB2_WEBHOOK_SECRET` | Secret HMAC validation webhooks Hub2 | Dashboard Hub2 → API Keys → Webhook secret |
| `TWILIO_SID` | Account SID Twilio | console.twilio.com → Account Info |
| `TWILIO_TOKEN` | Auth Token Twilio | console.twilio.com → Account Info |

### Secrets notifications

| Nom du secret | Description | Valeur |
|---|---|---|
| `ADMIN_EMAIL` | Email pour les alertes et notifications | `francis@golivreur.ci` |

### Secrets Sentry

| Nom du secret | Description | Où trouver |
|---|---|---|
| `SENTRY_DSN` | DSN Sentry pour le monitoring erreurs | sentry.io → Project → Settings → DSN |

---

## Configurer l'environnement "production" (approbation manuelle)

C'est ce qui déclenche la **notification sur votre téléphone** avant chaque déploiement.

1. GitHub → Settings → **Environments** → New environment
2. Nommer l'environnement : `production`
3. Activer **Required reviewers**
4. Ajouter votre compte GitHub comme reviewer
5. Cliquer **Save protection rules**

Désormais, chaque déploiement production vous enverra une notification GitHub.  
Vous cliquez **"Approve and deploy"** depuis votre téléphone.

---

## Générer une clé SSH pour le déploiement

```bash
# Sur votre machine locale (pas sur le VPS)
ssh-keygen -t ed25519 -C "golivreur-github-actions" -f ~/.ssh/golivreur_deploy

# Vous obtenez deux fichiers :
#   ~/.ssh/golivreur_deploy       ← CLÉ PRIVÉE (à mettre dans GitHub Secrets)
#   ~/.ssh/golivreur_deploy.pub   ← Clé publique (à mettre sur le VPS)
```

**Mettre la clé publique sur le VPS :**
```bash
# Copier la clé publique sur le VPS
ssh-copy-id -i ~/.ssh/golivreur_deploy.pub ubuntu@[IP_DU_VPS]

# Ou manuellement :
cat ~/.ssh/golivreur_deploy.pub | ssh root@[IP_DU_VPS] \
    "mkdir -p /home/ubuntu/.ssh && cat >> /home/ubuntu/.ssh/authorized_keys"
```

**Mettre la clé privée dans GitHub Secrets :**
```bash
# Afficher le contenu de la clé privée
cat ~/.ssh/golivreur_deploy

# Copier TOUT le contenu (de -----BEGIN... jusqu'à ...END-----)
# et le coller dans le secret GitHub VPS_SSH_PRIVATE_KEY
```

---

## Configurer Rclone sur le VPS (DO Spaces)

À exécuter une seule fois sur le VPS après l'installation :

```bash
rclone config

# Répondre aux questions :
# n) New remote
# name> spaces
# Type> s3 (numéro correspondant)
# provider> DigitalOcean
# env_auth> false
# access_key_id> [VOTRE_DO_SPACES_KEY depuis Bitwarden]
# secret_access_key> [VOTRE_DO_SPACES_SECRET depuis Bitwarden]
# region> fra1
# endpoint> fra1.digitaloceanspaces.com
# location_constraint> (laisser vide)
# acl> private
# (confirmer avec q) quit config)

# Tester la configuration :
rclone ls spaces:golivreur/
# Doit lister les fichiers dans votre bucket
```

---

## Vérification finale

Après avoir configuré tous les secrets, vérifiez que le pipeline fonctionne :

```bash
# 1. Faire un commit sur la branche develop
git add .
git commit -m "chore: test pipeline CI"
git push origin develop

# 2. Aller sur GitHub → Actions
# Le pipeline "CI + Déploiement Staging" doit démarrer automatiquement

# 3. Vérifier que staging est déployé
curl https://staging.golivreur.ci/api/v1/health
# Doit retourner : {"status":"ok","version":"5.0",...}
```

---

## Checklist de configuration complète

```
[ ] Secret VPS_HOST configuré (IP du VPS)
[ ] Secret VPS_USER configuré (ubuntu)
[ ] Secret VPS_SSH_PRIVATE_KEY configuré (clé privée)
[ ] Clé publique SSH ajoutée sur le VPS (/home/ubuntu/.ssh/authorized_keys)
[ ] Secret DB_PASSWORD configuré
[ ] Secret HUB2_WEBHOOK_SECRET configuré
[ ] Secret TWILIO_SID configuré
[ ] Secret TWILIO_TOKEN configuré
[ ] Secret ADMIN_EMAIL configuré (francis@golivreur.ci)
[ ] Secret SENTRY_DSN configuré
[ ] Environnement "production" créé avec required reviewer
[ ] Rclone configuré sur le VPS
[ ] Test pipeline develop → staging réussi
```
