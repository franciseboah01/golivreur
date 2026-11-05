# GoLivreur — Guide Bruno + k6 en 10 minutes

**Public** : Francis EBOAH (non-développeur) et Lead Dev  
**Objectif** : Être opérationnel sur les deux outils en 10 minutes

---

## Partie 1 — Bruno (5 minutes)

### Installation

```bash
# Mac
brew install bruno

# Windows / Linux : télécharger depuis usebruno.com
```

Ou utilisez l'application desktop : https://www.usebruno.com/downloads

### Ouvrir la collection GoLivreur

1. Lancer Bruno
2. **Open Collection**
3. Sélectionner le dossier `bruno/` de ce livrable
4. La collection "GoLivreur API v5.0" apparaît dans le panneau gauche

### Sélectionner l'environnement

En haut à droite de Bruno, un menu déroulant affiche les environnements :

```
☐ local       ← Pour tester contre votre backend Docker local
☐ staging     ← Pour tester contre staging.golivreur.ci
☐ production  ← Lecture seule, health check uniquement
```

Sélectionnez **local** pour commencer (votre backend doit tourner via `make up`).

### Premier test — Health Check

1. Dans le panneau gauche, naviguez vers un dossier (ou créez une requête health check à la racine)
2. Cliquez sur **Send**
3. Vous devez voir : `{"status":"ok","version":"5.0",...}`

### Flux d'authentification complet

```
1. Exécuter : auth/1_register.bru (ou 3_login.bru pour un compte existant)
2. Récupérer le code OTP :
   docker compose exec golivreur_app php artisan tinker
   >>> App\Models\OtpCode::latest()->first()
3. Copier le code dans : auth/2_verify_register.bru (ou 4_verify_login.bru)
4. Exécuter — le token est sauvegardé AUTOMATIQUEMENT dans l'environnement
5. Toutes les requêtes suivantes utilisent {{jwt_token_client}} sans rien faire
```

### Comprendre les résultats des tests automatiques

Après chaque requête, l'onglet **Tests** (en bas) affiche :

```
✅ Statut 201                          ← Vert = test réussi
✅ Token JWT reçu
❌ Le rôle créé est 'client'           ← Rouge = problème à corriger
```

**Vous n'avez rien à vérifier manuellement** — si tout est vert, ça fonctionne.

### Tester le flux GoColis complet (codes 7291 / 3847)

Ces codes sont **synchronisés avec le mock Flutter** :

```
1. client/gocolis/1_creer_expedition.bru     → crée l'expédition
2. driver/gocolis/1_accepter_course.bru      → livreur accepte
3. driver/gocolis/2_arrivee_expediteur.bru
4. driver/gocolis/3_photo_ramassage.bru      → ⚠️ nécessite un fichier image
5. driver/gocolis/4_valider_code_expediteur.bru  → utilise {{sender_code_test}}
6. driver/gocolis/5_arrivee_destinataire.bru
7. driver/gocolis/6_photo_livraison.bru
8. driver/gocolis/7_valider_code_destinataire.bru → utilise {{recipient_code_test}}
```

> ⚠️ Sur des données réelles (pas le mock), les codes sont générés
> aléatoirement à chaque création. Utilisez le `sender_code_plain`
> retourné par l'étape 1, pas la variable `{{sender_code_test}}`.

### Tester la sécurité (webhook signature invalide)

```
webhooks/2_paiement_signature_invalide.bru
```

Doit retourner **401** avec `succes: false`. Si ce n'est pas le cas,
c'est une faille de sécurité critique à signaler immédiatement.

---

## Partie 2 — k6 (5 minutes)

### Installation

```bash
# Mac
brew install k6

# Windows
choco install k6

# Linux
sudo apt install k6
```

Vérifier : `k6 version`

### Lancer le premier scénario

```bash
cd api_tools/k6

# Test 1 : 100 commandes simultanées (contre local)
k6 run scenarios/commandes_simultanees.js

# Contre staging :
k6 run -e BASE_URL=https://staging.golivreur.ci/api/v1 scenarios/commandes_simultanees.js
```

### Lire le résultat dans le terminal

À la fin du test, vous verrez un encadré comme celui-ci :

```
╔═══════════════════════════════════════════════════════════════╗
║   GoLivreur — Rapport Scénario 1 : 100 Commandes Simultanées  ║
╠═══════════════════════════════════════════════════════════════╣
║  Commandes réussies   : 98/100                                ║
║  Taux de réussite     : 98.0%                                 ║
║  Temps réponse (p95)  : 1240ms                                ║
║  Taux d'échec HTTP    : 2.00%                                 ║
║  ✅ TEST RÉUSSI — Seuil requis : 95% de réussite               ║
╚═══════════════════════════════════════════════════════════════╝
```

**C'est tout ce que vous avez besoin de lire.** ✅ = bon, ❌ = problème.

### Lancer les 3 scénarios dans l'ordre

```bash
# 1. Commandes simultanées
k6 run scenarios/commandes_simultanees.js

# 2. GPS simultanés (surveillez le CPU pendant ce test)
k6 run scenarios/gps_simultanes.js

# 3. Paiements simultanés (avec votre vrai secret HMAC)
k6 run -e WEBHOOK_SECRET=$(grep HUB2_WEBHOOK_SECRET ../../backend/.env | cut -d= -f2) \
  scenarios/paiements_simultanes.js
```

### Surveiller le CPU pendant les tests

Ouvrez un second terminal **pendant** que k6 tourne :

```bash
# Si VPS DigitalOcean
ssh ubuntu@[IP_VPS] "htop"

# Si local Docker
docker stats golivreur_app golivreur_db golivreur_redis
```

Le CPU ne doit jamais dépasser **70%**. Si c'est le cas, le terminal k6
affichera une alerte automatique pour le scénario GPS.

### Remplir le rapport final

Après avoir exécuté les 3 scénarios, ouvrez `RAPPORT_TEMPLATE.md` et
copiez les résultats de chaque terminal dans les sections correspondantes.

Ce document signé devient votre preuve de capacité avant le lancement.

---

## Résumé — Checklist de validation avant lancement

```
[ ] Bruno : flux Auth complet testé (register + login)
[ ] Bruno : flux GoShop complet testé (commande → livraison)
[ ] Bruno : flux GoColis complet testé (double code)
[ ] Bruno : webhook signature invalide correctement rejeté (401)
[ ] k6 : Scénario 1 (commandes) ✅ réussi
[ ] k6 : Scénario 2 (GPS) ✅ réussi, CPU < 70%
[ ] k6 : Scénario 3 (paiements) ✅ réussi, 0 faille sécurité
[ ] RAPPORT_TEMPLATE.md rempli et signé
```

Si toutes les cases sont cochées : **GO pour le lancement à Daloa.**
