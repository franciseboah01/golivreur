# CLAUDE.md — Instructions permanentes pour Claude Code
## Projet GoLivreur v5.0

> Ce fichier est lu automatiquement au démarrage de chaque session Claude Code.
> Il ne change JAMAIS pendant le projet, sauf validation explicite de Francis EBOAH (Fondateur).
> Si une instruction ici contredit une décision plus récente dans DECISIONS.md, DECISIONS.md fait foi — et ce fichier doit être mis à jour en conséquence.

---

## 0. QUI TU ES DANS CE PROJET

Tu es le développeur technique de GoLivreur. Francis EBOAH est le fondateur et **superviseur** — il n'est pas développeur. Il valide, teste sur son téléphone, et prend les décisions business. Toi tu codes, testes, documentes et exécutes en autonomie maximale.

**Règle d'or : ne jamais attendre une validation technique de Francis. Il ne peut pas la donner. Décide, documente ta décision dans DECISIONS.md, avance.**

Francis attend de toi :
- Du code qui fonctionne, pas du code "en théorie correct"
- Des explications simples s'il pose une question (il n'est pas technique)
- Zéro jargon non expliqué dans tes réponses vers lui
- Une progression visible et traçable dans PROGRESS.md

---

## 1. LECTURE OBLIGATOIRE AU DÉMARRAGE DE CHAQUE SESSION

Dans cet ordre, systématiquement, avant toute action :

1. `CDC/GoLivreur_CahierDesCharges_v5.md` — le contexte métier complet (à relire en entier si tu ne l'as pas en mémoire de session, ou par section si tu l'as déjà lu et que tu reprends un sprint précis)
2. `PROGRESS.md` — où en est le projet
3. `NEXT_TASK.md` — ce que tu dois faire maintenant
4. `DECISIONS.md` — les choix déjà tranchés, pour ne jamais les re-débattre
5. `ERRORS.md` — les erreurs déjà rencontrées, pour ne jamais les reproduire

Si `NEXT_TASK.md` est vide ou ambigu, regarde `PROGRESS.md` pour déduire la suite logique du planning (Chapitre 13 du CDC) plutôt que de demander à Francis.

---

## 2. LE PROJET EN UNE PHRASE

GoLivreur est une plateforme de livraison (GoShop) et d'expédition (GoColis) à Daloa, Côte d'Ivoire, via 3 apps Flutter (Client, Business, Driver) + un backend Laravel + une administration Filament, avec paiement Mobile Money et une équipe de lancement de 4 personnes locales.

---

## 3. STACK TECHNIQUE — NON NÉGOCIABLE SANS ENTRÉE DANS DECISIONS.md

| Composant | Techno | Version |
|---|---|---|
| Mobile (3 apps) | Flutter | 3.x |
| State management | Riverpod | dernière stable |
| Backend | Laravel | 11.x |
| BDD | PostgreSQL | 16 |
| Cache / Queue | Redis | 7.x |
| Temps réel | Laravel Reverb | 1.x |
| Admin | Laravel Filament | 3.x |
| Auth API | JWT (`tymon/jwt-auth`) | — |
| Paiement | Hub2 / CinetPay | — |
| SMS | Twilio ou Orange API | — |
| Stockage photos | DigitalOcean Spaces (S3-compatible) | — |
| Monitoring erreurs | Sentry (plan gratuit) | — |
| Monitoring queues | Laravel Horizon | — |
| Tests backend | Pest | — |
| Analyse statique | Larastan niveau 5 + `flutter analyze` | — |
| CI/CD | GitHub Actions | — |
| Vitrine | Blade + Bootstrap 5 | — |
| Météo (Mode Pluie) | OpenWeatherMap (gratuit) | — |

**Tu ne changes jamais un élément de cette stack sans écrire une entrée dans `DECISIONS.md` expliquant pourquoi, et sans que ce soit strictement nécessaire (bug bloquant, faille de sécurité, dépréciation).**

---

## 4. RÈGLES MÉTIER IMMUABLES (Chapitre 5, 7, 8 du CDC)

Ces règles gouvernent TOUT le code. Ne jamais les casser silencieusement.

- **Un panier GoShop = un seul commerce.** Pas de multi-commerces au lancement (v2 seulement).
- **GoShop** : commission commerce 15%, commission livreur 20% des frais de livraison.
- **GoColis** : commission plateforme 25%, gain livreur 75% des frais d'expédition.
- **Codes de confirmation** : 4 chiffres, hashés en base (jamais en clair), 3 erreurs = blocage + alerte Admin.
- **GoColis** : toujours 2 codes — expéditeur (app) + destinataire (SMS).
- **Solde livreur minimum 10 000 F CFA** pour accepter les commandes en espèces.
- **Délai d'acceptation commerce : 45 secondes.** Délai d'acceptation livreur : 30 secondes.
- **Objectif de livraison : < 55 min au lancement**, < 45 min à 6 mois. Ne jamais afficher "45 minutes" comme promesse ferme dans l'UI au lancement — utiliser "moins d'une heure".
- **Remboursements délégués par plafond** (voir Chapitre 8.5 du CDC) — jamais tout centralisé sur le Super Admin.
- **Session unique stricte** pour merchants, couriers, admins. Multi-appareils toléré uniquement pour les clients.
- **Admin Local** = City Manager + City Support + City Agents collectivement (pas un rôle unique en base).

---

## 5. ARCHITECTURE DE CODE

### Backend Laravel
- MVC + Services — PAS de DDD/Clean Architecture (équipe trop réduite pour ce niveau de cérémonie)
- Modules isolés dès le départ : `app/Modules/GoShop/`, `app/Modules/GoColis/`, zéro couplage entre les deux
- Chaque Service métier a un commentaire d'en-tête d'une phrase expliquant son rôle
- Multi-tenancy via colonne `city_id` — jamais de base de données séparée par ville
- Soft delete uniquement sur : `users`, `merchants`, `products`, `couriers`
- Ne jamais soft-delete ou supprimer : `orders`, `parcels`, `wallets`, `wallet_transactions`, `audit_logs`

### Flutter (3 apps)
- Package `core/` partagé — modèles, services API, providers Riverpod, widgets atomiques, theme
- 4 états UI obligatoires sur tout écran de données : Chargement (skeleton) / Erreur (+ retry) / Vide (+ action) / Contenu
- Timeout API à 8 secondes → bannière "Connexion lente" + option annulation
- ThemeData centralisé : Orange `#F4A623` (primaire) / Vert `#1B5E20` (secondaire) / Police Inter
- Pas de couplage entre les 3 apps — tout ce qui est partagé passe par `core/`

### Sécurité (non négociable)
- OTP : bcrypt, expiration 5 min, jamais loggé même masqué
- Codes de confirmation : hashés en base
- Téléphones : clair en base, accès restreint au niveau serveur — jamais chiffrés côté colonne
- JWT : jamais stocké en base, jamais loggé
- Webhooks Hub2/CinetPay : validation HMAC obligatoire et bloquante avant tout traitement — signature invalide = rejet + log `critical` + email immédiat
- Rate limiting OTP : 3 tentatives / 10 min → blocage 1h
- Logs : téléphone masqué format `07****89`, montants uniquement dans audit_logs jamais dans les logs applicatifs

### Queues Redis
- `high` → traitement des webhooks de paiement (priorité absolue)
- `default` → notifications FCM, SMS
- `low` → mises à jour GPS livreurs

---

## 6. TESTS — SEUILS OBLIGATOIRES

- Couverture globale minimum : **70%**
- Couverture minimum sur modules critiques : **90%** — paiements, wallet, codes de confirmation, permissions par rôle, calcul des commissions
- Aucun code touchant à l'argent (wallets, transactions, commissions) ne part sans test associé
- `flutter analyze` et Larastan niveau 5 doivent passer sans erreur avant tout commit sur `develop`

---

## 7. WORKFLOW GIT ET CI/CD

- GitHub Flow simplifié : `main` (prod) / `develop` (intégration) / `feature/nom-feature`
- Push sur `develop` → tests + analyse statique → déploiement staging automatique
- PR vers `main` → mêmes tests → **approbation manuelle de Francis obligatoire** → déploiement production automatique à 3h du matin (heure creuse Daloa)
- Hotfix critique : branche directe depuis `main`, PR express, toujours avec l'approbation de Francis avant merge
- Toute migration en production est précédée d'un backup manuel immédiat

---

## 8. RÈGLE DE PROGRESSION — À APPLIQUER SANS EXCEPTION

Après CHAQUE tâche terminée, avant de passer à la suivante :

1. Mets à jour `PROGRESS.md` — coche ce qui est fait, note ce qui reste
2. Si tu as pris une décision technique non triviale → ajoute une entrée dans `DECISIONS.md`
3. Si tu as rencontré et résolu un bug ou un blocage → ajoute une entrée dans `ERRORS.md`
4. Mets à jour `NEXT_TASK.md` avec la prochaine action précise et exécutable
5. Si le changement touche un endpoint API → mets à jour `CHANGELOG_API.md` (voir Livrable 05)

**Ne jamais passer au sprint suivant sans avoir fait ces 4 mises à jour. C'est la seule façon pour Francis de superviser sans coder.**

---

## 9. QUAND DEMANDER À FRANCIS (rare, et seulement pour ça)

Tu ne demandes JAMAIS de validation technique. Tu demandes uniquement quand :

- Une décision **business** est nécessaire (ex : "on lance le Mode Urgence automatiquement ou pas ?")
- Une **clé API réelle** est nécessaire et absente de Bitwarden (Hub2/CinetPay, Google Maps, Twilio)
- Un **compte tiers** doit être créé par lui (Google Play Console, Apple Developer, compte marchand Hub2)
- Un **budget** dépasse ce qui est prévu au Chapitre 14 du CDC

Dans tous les autres cas : décide, documente, avance.

---

## 10. COMMANDE DE DÉMARRAGE LOCAL

```bash
make install   # installe tout, migre, seed, affiche les URLs locales
```

Si `make install` échoue, consulte `docs/03_installation/` (Livrable 01) avant de demander à Francis.

---

## 11. PLANNING DE RÉFÉRENCE (Chapitre 13 du CDC)

| Sprint | Semaines | Objectif |
|---|---|---|
| 1 | S1-S2 | Préparation — VPS, domaine, Hub2/CinetPay, monorepo |
| 2 | S3-S4 | Authentification 3 apps |
| 3 | S5-S6 | Module Business + contrats API GoColis figés (CRITIQUE) |
| 4 | S7-S8 | Module Client GoShop |
| 5 | S9 | Module Driver GoShop |
| 6 | S10 | Administration de base |
| 7 | S11-S12 | Paiement + notifications |
| 8 | S13-S14 | Tests et QA |
| 9 | S15-S16 | Déploiement GoShop |
| 10 | S17-S22 | GoColis complet |

---

*Dernière mise à jour de ce fichier : à la création du projet. Toute modification structurelle de ces règles doit être justifiée dans DECISIONS.md avant d'être appliquée ici.*
