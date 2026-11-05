# NEXT_TASK.md — Prochaine Tâche à Exécuter
## Projet GoLivreur v5.0

> Ce fichier contient TOUJOURS une seule chose : la prochaine action précise et exécutable.
> Claude Code le réécrit intégralement (pas d'ajout, remplacement complet) à la fin de chaque tâche terminée.
> Francis peut lire ce fichier pour savoir exactement "c'est quoi la suite" sans avoir à comprendre le code.
> S'il est vide ou périmé, Claude Code déduit la suite logique depuis PROGRESS.md et le planning (Chapitre 13 du CDC) — il ne doit jamais rester bloqué faute d'instruction ici.

---

## 🎯 Tâche actuelle

**Sprint :** 1 — Préparation (S1-S2)

**Objectif de la tâche :** Initialiser le monorepo et l'environnement de développement local.

**Actions précises à exécuter dans l'ordre :**

1. Décompresser les 5 ZIP livrables dans la structure suivante :
   ```
   golivreur/
   ├── CDC/GoLivreur_CahierDesCharges_v5.md
   ├── docs/        (ZIP 01)
   ├── backend/     (ZIP 02)
   ├── mobile/      (ZIP 03)
   ├── devops/      (ZIP 04)
   └── api-tools/   (ZIP 05)
   ```
2. Copier `.env.example` vers `.env` dans `backend/`
3. Demander à Francis les clés réelles (Bitwarden) pour : Hub2/CinetPay sandbox, Google Maps API, Twilio/Orange API, DigitalOcean Spaces
4. Exécuter `make install` depuis la racine du projet
5. Vérifier que les 3 services Docker (PHP, PostgreSQL, Redis) démarrent sans erreur
6. Exécuter les migrations dans l'ordre exact listé dans `backend/database/migrations/`
7. Exécuter le seeder Daloa (`php artisan db:seed`)
8. Vérifier que `flutter pub get` fonctionne dans `packages/core/` puis dans chacune des 3 apps
9. Mettre à jour `PROGRESS.md` — Sprint 1, section Code

**Critère de succès (Definition of Done) :**
- `make install` s'exécute sans erreur de bout en bout
- Les 3 apps Flutter se lancent sur un émulateur sans crash à l'écran d'accueil
- Un compte de test du seeder (client, commerce, livreur) est fonctionnel en connexion

**Bloqué sur quelque chose ?** Non — si `make install` échoue, consulter `ERRORS.md` avant de modifier quoi que ce soit.

**Nécessite une action de Francis (non technique) ?**
- ⚠️ Ouverture du compte marchand Hub2/CinetPay (démarche externe, délai 2-4 semaines — à lancer aujourd'hui si pas encore fait)
- Achat du nom de domaine `golivreur.ci`
- Création des comptes développeur Google Play et Apple Developer

---

## Rappel de la règle de mise à jour

Une fois cette tâche terminée et validée (tests passants, DoD atteinte) :

1. Coche les cases correspondantes dans `PROGRESS.md`
2. Ajoute une entrée dans `DECISIONS.md` si un choix technique non trivial a été fait
3. Ajoute une entrée dans `ERRORS.md` si un blocage a été rencontré et résolu
4. **Réécris entièrement ce fichier** avec la tâche suivante (voir planning Chapitre 13 du CDC pour déduire la suite si besoin)

Ne jamais laisser ce fichier vide en fin de session — la dernière action avant de s'arrêter est toujours d'écrire ici quelle est la prochaine étape.
