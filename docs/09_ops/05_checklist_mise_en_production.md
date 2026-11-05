# GoLivreur — Checklist de Mise en Production

À exécuter **dans l'ordre** avant chaque déploiement sur `production`.  
Cochez chaque case manuellement — ne passez pas à l'étape suivante si une case est vide.

---

## Avant le déploiement

### Code et tests
- [ ] Toutes les Pull Requests du sprint sont mergées sur `develop`
- [ ] Pipeline GitHub Actions sur `develop` : VERT (Pest + Larastan + flutter analyze)
- [ ] Couverture Pest ≥ 70% globale (rapport visible dans GitHub Actions)
- [ ] Modules critiques ≥ 90% (paiements, codes, permissions, commissions)
- [ ] Zéro TODO ni placeholder dans le code
- [ ] CHANGELOG_API.md mis à jour si des endpoints ont changé
- [ ] Démonstration validée par Francis EBOAH sur staging

### Infrastructure
- [ ] Backup PostgreSQL manuel déclenché et vérifié dans DO Spaces
  ```bash
  # Exécuter sur le VPS
  bash /var/www/production/scripts/backup_bdd.sh
  # Vérifier dans DO Spaces que le fichier est bien présent
  rclone ls spaces:golivreur/backups/pre-deploy/ | tail -3
  ```
- [ ] Espace disque VPS suffisant (minimum 2 Go libres)
  ```bash
  df -h /
  ```
- [ ] Redis opérationnel
  ```bash
  redis-cli ping  # Doit retourner PONG
  ```
- [ ] Horizon en cours d'exécution
  ```bash
  php artisan horizon:status
  ```

### Services externes
- [ ] Hub2/CinetPay : tableau de bord vérifié (pas d'alerte)
- [ ] DO Spaces : espace suffisant (vérifier quota)
- [ ] Sentry : pas d'erreurs critiques ouvertes non résolues

---

## Pendant le déploiement

- [ ] Mode maintenance activé
  ```bash
  php artisan down --message="Mise à jour en cours — retour dans 5 minutes" --retry=60
  ```
- [ ] Déploiement GitHub Actions déclenché (merge sur `main`)
- [ ] Migrations exécutées sans erreur (vérifier logs GitHub Actions)
- [ ] Workers Horizon redémarrés
  ```bash
  php artisan queue:restart
  ```

---

## Après le déploiement

### Vérifications techniques
- [ ] Mode maintenance désactivé
  ```bash
  php artisan up
  ```
- [ ] Health check API
  ```bash
  curl -s https://golivreur.ci/api/v1/health
  # Doit retourner : {"status":"ok","timestamp":"..."}
  ```
- [ ] Page vitrine accessible
  ```bash
  curl -s -o /dev/null -w "%{http_code}" https://golivreur.ci
  # Doit retourner : 200
  ```
- [ ] Admin accessible
  ```bash
  curl -s -o /dev/null -w "%{http_code}" https://admin.golivreur.ci/admin/login
  # Doit retourner : 200
  ```
- [ ] WebSocket Reverb opérationnel (tester dans l'app Driver)
- [ ] Horizon dashboard accessible et workers actifs : https://admin.golivreur.ci/horizon

### Vérifications fonctionnelles (tester sur téléphone)
- [ ] Connexion client avec OTP SMS reçu
- [ ] Connexion commerce fonctionnelle
- [ ] Connexion livreur fonctionnelle
- [ ] Connexion admin avec 2FA fonctionnelle
- [ ] Créer une commande GoShop test (paiement espèces)
- [ ] Créer une expédition GoColis test

### Monitoring
- [ ] Sentry : vérifier qu'aucune nouvelle erreur critique n'est apparue dans les 10 minutes post-déploiement
- [ ] Horizon : vérifier que les queues `high`, `default`, `low` traitent des jobs normalement

---

## En cas de problème

### Rollback immédiat

```bash
# Sur le VPS
cd /var/www/production

# Revenir au commit précédent
git log --oneline -5  # Identifier le commit précédent
git checkout [HASH_COMMIT_PRECEDENT]

# Restaurer le backup pré-déploiement
# (voir documentation/09_ops/02_strategie_backup.md — Procédure de restauration)

# Vider les caches
php artisan optimize:clear

# Relancer
php artisan up
```

### Contacts d'urgence

| Situation | Action |
|---|---|
| Bug paiement en production | Email immédiat à francis@golivreur.ci + Lead Dev |
| Panne serveur | Lead Dev SSH + redémarrage services |
| Erreur Sentry critique (> 5 users) | Notification automatique email Fondateur |
| Jobs Horizon en échec | Ouvrir Horizon dashboard + rejouer les jobs |
