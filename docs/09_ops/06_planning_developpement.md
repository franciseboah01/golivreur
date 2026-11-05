# GoLivreur — Planning de Développement

**Durée totale** : 17 semaines  
**Lancement officiel** : Semaine 17  
**Équipe** : Lead Dev (S1→S17) + Renfort Flutter GoColis (S7→S17)

---

## Vue d'ensemble

```
S1  S2  S3  S4  S5  S6  S7  S8  S9  S10 S11 S12 S13 S14 S15 S16 S17
[Phase 0]
         [————————— Phase 1 MVP ——————————]
                                 [— Phase 2 Paiement —]
                                                 [—QA—]
                                                         [Beta][Launch]
```

---

## Phase 0 — Préparation (S1-S2)

### S1 — Tâches Fondateur (PRIORITÉ ABSOLUE)

| Tâche | Responsable | Statut |
|---|---|---|
| ⚠️ Ouvrir compte marchand Hub2/CinetPay | Francis EBOAH | À faire J1 |
| Créer compte Google Play Developer | Francis EBOAH | À faire S1 |
| Créer compte Apple Developer | Francis EBOAH | À faire S1 |
| Acheter domaine golivreur.ci + emails pro | Francis EBOAH | À faire S1 |
| Configurer Bitwarden équipe | Francis EBOAH + Lead Dev | À faire S1 |
| Recruter 5 commerces pilotes à Daloa | Équipe terrain | À faire S1 |
| Recruter 10 livreurs pilotes à Daloa | Équipe terrain | À faire S1 |
| Location et aménagement bureau Daloa | Francis EBOAH | À faire S1 |

> ⚠️ **Le compte marchand Hub2/CinetPay prend 2 à 4 semaines.** C'est la tâche la plus critique du planning. Un retard ici retarde tout le module paiement.

### S1-S2 — Tâches Lead Dev

| Tâche | Livrables |
|---|---|
| Configuration serveur VPS production + staging | VPS opérationnel, Nginx, PHP, PostgreSQL, Redis |
| Création monorepo GitHub (Laravel + Flutter) | Repository avec branches main/develop |
| Setup Docker développement local | docker-compose.yml fonctionnel |
| Configuration GitHub Actions (CI base) | Pipeline tests automatiques sur push |
| Configuration Sentry (Laravel + 3 apps Flutter) | DSN configurés dans .env |
| Initialisation projet Laravel 11 | Structure de base, packages installés |
| Initialisation 3 projets Flutter | GoLivreurClient, GoLivreurBusiness, GoLivreurDriver |
| Mise en place Filament 3 | Interface admin accessible |

---

## Phase 1 — Développement MVP (S3-S10)

### S3-S4 — Authentification et Profils

**⚠️ Contrainte critique fin S5 : Contrats API GoColis figés (Bruno + Scramble)**

**Lead Dev — Backend :**
- Migration users, cities, otp_codes, admin_sessions
- JWT Auth (tymon/jwt-auth) : register, verify-otp, login, refresh, logout
- Middleware CityContextMiddleware
- Middleware SingleSessionMiddleware (merchant/driver)
- 2FA admin (Filament)
- Rate limiting OTP (3/10min, blocage 1h Redis)
- Masquage logs (téléphone, OTP)

**Lead Dev — Flutter (3 apps) :**
- Écrans : Inscription → OTP → Accueil
- Écrans : Connexion → OTP → Dashboard
- ThemeData global (Inter, #F4A623, #1B5E20, #FAFAFA)
- Atomic design : atomes de base (BoutonPrimaire, ChampTexte, BadgeStatut)
- go_router avec guards par rôle

**Livrables S4 :**
- ✅ API Auth complète et testée (Pest)
- ✅ 3 apps Flutter avec auth fonctionnelle
- ✅ Tests Pest : inscription, connexion, OTP, refresh, permissions

---

### S5-S6 — Module Business (GoShop côté commerce)

**⚠️ CONTRAINTE CRITIQUE S5 : En fin de S5, le Lead Dev livre simultanément :**
1. Module Business fonctionnel
2. Contrats API GoColis complets figés dans Bruno et Scramble (pour le renfort S7)

**Lead Dev — Backend :**
- Migrations merchants, products
- CRUD Merchant (inscription, profil, horaires, statut ouvert/fermé)
- CRUD Products (catalogue, disponibilité, photos S3)
- Réception commandes GoShop (accept/refuse dans 45s, bouton "Prêt")
- Statut `refused` (v5)
- Notifications FCM réception commande

**Lead Dev — Flutter Business :**
- Écran tableau de bord commerce
- Écran gestion catalogue produits
- Écran réception commandes (alerte sonore forte)
- Écran commande en cours

**Lead Dev — Contrats API GoColis (à figurer en S5) :**
- Tous les endpoints GoColis documentés dans Scramble
- Collection Bruno GoColis complète avec exemples JSON
- Mock API JSON statique pour tous les endpoints GoColis

**Livrables S6 :**
- ✅ App Business fonctionnelle (réception + gestion catalogue)
- ✅ **Contrats API GoColis figés et mock disponible** ← CRITIQUE
- ✅ Tests Pest Module Business

---

### S7-S8 — Module Client GoShop + Arrivée Renfort Flutter

**Renfort Flutter GoColis arrive en S7.** Première journée : lecture guide de contribution (30 min max), configuration environnement Docker, accès mock API GoColis.

**Lead Dev — Backend :**
- Migrations orders, order_items
- OrderService : create, accept, refuse, ready, pickup, deliver
- AssignmentService : findNearestDriver (proximité géographique)
- CommissionService : calcul 15% merchant, 20% courier
- Endpoint verify-code (GoShop)
- Blocage après 3 erreurs code + alerte Admin

**Lead Dev — Flutter Client GoShop :**
- Écran accueil (liste commerces par catégorie)
- Écran commerce (catalogue produits)
- Écran panier + instructions spéciales
- Écran paiement (Orange Money + espèces)
- Écran suivi GPS temps réel (carte + statuts)
- Centre notifications in-app (30 dernières, lu/non-lu)

**Renfort Flutter — GoColis :**
- Écran création expédition (nom destinataire, téléphone, adresse, taille, valeur)
- Écran récapitulatif + calcul tarif
- Écran paiement GoColis
- Écran suivi expédition (statuts + photos)
- Travail sur mock API → bascule sur vraie API quand disponible

**Livrables S8 :**
- ✅ GoShop Client fonctionnel (commande → suivi)
- ✅ Renfort Flutter opérationnel sur GoColis

---

### S9 — Module Driver (GoShop + début GoColis)

**Lead Dev — Backend + Flutter Driver :**
- Migrations couriers, wallets, wallet_transactions
- Écran disponibilité (En ligne / Hors ligne)
- Réception courses GoShop (notification + acceptation 30s)
- Navigation GPS (Google Maps)
- Prise en charge, livraison, validation code
- Endpoint GPS `/api/v1/courier/location` (filtre 10m)
- WebSocket Reverb (position temps réel)
- WalletService : creditCourier, creditMerchant
- Pénalité anti-filtrage (3 refus = 15min hors ligne)

**Renfort Flutter — GoColis (suite) :**
- Écrans livreur GoColis (si module Driver lancé)
- Intégration photos colis (camera + upload S3)

**Livrables S9 :**
- ✅ GoShop complet de bout en bout (Client → Business → Driver)
- ✅ GPS temps réel fonctionnel

---

### S10 — Administration Filament

**Lead Dev — Admin Filament :**
- Dashboard City Manager (8 métriques)
- Widget Horizon (jobs en attente/échec + lien "Ouvrir Horizon")
- Gestion users (clients, merchants, drivers)
- Validation inscriptions merchants + drivers
- Gestion commandes GoShop (vue, annulation, réassignation)
- Délégation remboursements par plafond (City Support/Finance/Manager/Super Admin)
- Déblocage manuel codes confirmation (City Support)
- Mode Pluie manuel (City Manager)
- Mode Urgence (Super Admin uniquement)
- CityContextMiddleware appliqué à tous les admins locaux

**Livrables S10 :**
- ✅ Interface Admin opérationnelle pour le lancement
- ✅ Tous les rôles configurés avec permissions Spatie

---

## Phase 2 — Paiement et Notifications (S11-S12)

### S11-S12 — Intégrations critiques

> ⚠️ Les tests sandbox Hub2/CinetPay démarrent en S9 pour être prêts ici.

| Tâche | Responsable |
|---|---|
| Intégration Hub2/CinetPay Orange Money (production) | Lead Dev |
| Validation HMAC webhooks + webhook_logs | Lead Dev |
| Queue high/default/low + Horizon | Lead Dev |
| Push notifications FCM (toutes les apps) | Lead Dev |
| SMS OTP Twilio + codes destinataire GoColis | Lead Dev |
| Mode Pluie automatique (OpenWeatherMap) | Lead Dev |
| Alerte SMS > 2 500/mois → email Fondateur | Lead Dev |
| GoColis complet backend (parcels, codes, photos) | Lead Dev |
| Flutter GoColis Driver (renfort) | Renfort Flutter |

**Livrables S12 :**
- ✅ Paiement Orange Money end-to-end testé
- ✅ GoColis complet (expéditeur → livreur → destinataire)
- ✅ Notifications FCM et SMS opérationnelles

---

## Phase 3 — Tests et QA (S13-S14)

| Tâche | Responsable |
|---|---|
| Tests Pest complets (couverture 70% global / 90% critique) | Lead Dev |
| Tests k6 Scénario 1 : 100 commandes simultanées | Lead Dev |
| Tests k6 Scénario 2 : 50 GPS simultanés | Lead Dev |
| Tests k6 Scénario 3 : 20 paiements simultanés | Lead Dev |
| Tests utilisateurs pilotes à Daloa (indemnités prévues budget) | Équipe terrain |
| Testeur QA dédié | QA externe (2 semaines) |
| Correction bugs identifiés | Lead Dev + Renfort |
| Optimisation performances (si > 70% CPU en charge) | Lead Dev |

**Livrables S14 :**
- ✅ Rapport de couverture tests Pest ≥ 70% global
- ✅ Rapport k6 : VPS tient la charge à < 70% CPU
- ✅ Zéro bug critique ouvert

---

## Phase Bêta et Lancement (S15-S17)

### S15-S16 — Bêta fermée

- Déploiement sur App Store + Google Play (version bêta)
- 5 commerces pilotes + 10 livreurs pilotes actifs
- Corrections bugs terrain
- Formation présentielle livreurs et commerces (City Agents)

### S17 — Lancement officiel Daloa

- Déploiement version 1.0 sur les stores
- Campagne marketing (flyers, réseaux sociaux, radio)
- Équipe terrain active (City Support en astreinte)
- Monitoring Sentry + Horizon en temps réel

---

## Definition of Done (DoD) — Critères par sprint

Un sprint est considéré **terminé** uniquement si :

- [ ] Code mergé sur `develop` via Pull Request
- [ ] Tests Pest passants (couverture cible atteinte)
- [ ] `flutter analyze` sans erreur sur les 3 apps concernées
- [ ] Larastan niveau 5 sans erreur
- [ ] Déployé sur staging et testé manuellement
- [ ] Documentation OpenAPI (Scramble) à jour
- [ ] CHANGELOG_API.md mis à jour si breaking change
- [ ] **Démonstration fonctionnelle faite devant Francis EBOAH** (sur son téléphone)
- [ ] **Zéro régression sur les modules déjà livrés**
