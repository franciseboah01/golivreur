# PROGRESS.md — Journal de Progression
## Projet GoLivreur v5.0

> Mis à jour par Claude Code après CHAQUE tâche terminée — jamais en fin de journée, jamais en fin de sprint.
> Francis lit ce fichier pour superviser sans coder. Écris comme si tu expliquais à quelqu'un de non-technique où en est le projet.
> Format : coche `[x]` = fait et testé · `[~]` = en cours · `[ ]` = pas commencé · `[!]` = bloqué (voir ERRORS.md)

---

## Vue d'ensemble

| Sprint | Statut global | % complété |
|---|---|---|
| Sprint 1 — Préparation (S1-S2) | Non commencé | 0% |
| Sprint 2 — Authentification (S3-S4) | Non commencé | 0% |
| Sprint 3 — Business + contrats GoColis (S5-S6) | Non commencé | 0% |
| Sprint 4 — Client GoShop (S7-S8) | Non commencé | 0% |
| Sprint 5 — Driver GoShop (S9) | Non commencé | 0% |
| Sprint 6 — Administration (S10) | Non commencé | 0% |
| Sprint 7 — Paiement + notifications (S11-S12) | Non commencé | 0% |
| Sprint 8 — Tests et QA (S13-S14) | Non commencé | 0% |
| Sprint 9 — Déploiement GoShop (S15-S16) | Non commencé | 0% |
| Sprint 10 — GoColis complet (S17-S22) | Non commencé | 0% |

**Date de dernière mise à jour :** —
**Dernière action effectuée :** —
**Bloqué sur quelque chose ?** Non

---

## Sprint 1 — Préparation (S1-S2)

### Infrastructure
- [ ] Serveur VPS DigitalOcean loué et configuré (`install_vps.sh` exécuté)
- [ ] Nom de domaine `golivreur.ci` acheté et pointé vers le VPS
- [ ] Emails professionnels créés (contact@, support@)
- [ ] Certbot / Let's Encrypt configuré (HTTPS actif)

### Comptes et accès
- [ ] Compte marchand Hub2/CinetPay — **démarches lancées** (délai admin 2-4 semaines, priorité absolue)
- [ ] Compte développeur Google Play créé
- [ ] Compte développeur Apple créé
- [ ] Bitwarden configuré avec Francis (clés partagées)

### Code
- [ ] Monorepo initialisé (structure `packages/core` + 3 apps + `backend/`)
- [ ] `docker-compose.yml` local fonctionnel (`docker compose up -d` démarre tout sans erreur)
- [ ] GitHub repo créé, branches `main` / `develop` en place
- [ ] GitHub Actions — secrets configurés (voir `GITHUB_SECRETS.md`)

### Terrain (hors code, suivi informatif uniquement)
- [ ] 5 commerces pilotes identifiés à Daloa
- [ ] 10 livreurs pilotes identifiés à Daloa
- [ ] Bureau local Daloa loué

**Notes de sprint :** —

---

## Sprint 2 — Authentification (S3-S4)

### Backend
- [ ] Migration `cities` exécutée
- [ ] Migration `users` exécutée
- [ ] Migration `otp_codes` exécutée
- [ ] `AuthController` — inscription par téléphone + OTP SMS
- [ ] `AuthController` — connexion (téléphone / Google ID / Apple ID)
- [ ] JWT configuré (`php artisan jwt:secret` exécuté)
- [ ] Endpoint `/api/auth/refresh` fonctionnel
- [ ] Rate limiting OTP actif (3 tentatives / 10 min → blocage 1h)
- [ ] Session unique pour merchants/couriers/admins — testée (2ème connexion invalide la 1ère)
- [ ] Tests Pest Auth — 9 tests passants

### Flutter — package `core`
- [ ] `pubspec.yaml` versions figées
- [ ] `ApiClient` (Dio) avec timeout 8s + refresh JWT transparent
- [ ] `AppTheme` (Orange `#F4A623` / Vert `#1B5E20` / Inter)
- [ ] Widgets atomiques (Chargement / Erreur / Vide / Bannière connexion lente)

### Flutter — 3 apps
- [ ] Écran inscription — GoLivreur Client
- [ ] Écran connexion — GoLivreur Client
- [ ] Écran inscription — GoLivreur Business
- [ ] Écran inscription — GoLivreur Driver

**Notes de sprint :** —

---

## Sprint 3 — Module Business + Contrats API GoColis (S5-S6)

> ⚠️ **Contrainte critique du CDC :** à la fin de S5, le module Business ET les contrats API GoColis (Bruno + Scramble) doivent être figés simultanément. Le renfort Flutter arrive en S7 et dépend de ce mock.

### Module Business
- [ ] Migration `merchants` exécutée
- [ ] Migration `products` + `product_options` exécutée
- [ ] Gestion catalogue (ajout/modif/suppression produit)
- [ ] Réception commandes — compte à rebours 45s visible
- [ ] Statut `refused` (refus volontaire) implémenté et testé
- [ ] Statut `cancelled` (timeout) implémenté et testé
- [ ] Écran "Commande prête"

### Contrats API GoColis (CRITIQUE — deadline S5)
- [ ] Migration `parcels` exécutée
- [ ] Migration `parcel_photos` exécutée
- [ ] Endpoints GoColis documentés dans Scramble
- [ ] Collection Bruno GoColis à jour
- [ ] Mock JSON GoColis généré dans `lib/mocks/` (codes de test `7291` / `3847`)

**Notes de sprint :** —

---

## Sprint 4 — Module Client GoShop (S7-S8)

- [ ] Renfort Flutter arrivé et onboardé (guide contribution lu, mock GoColis récupéré)
- [ ] Écran liste des commerces (14 catégories, filtres)
- [ ] Écran catalogue / panier (règle : 1 panier = 1 commerce)
- [ ] Écran paiement (Orange Money / Espèces)
- [ ] Écran suivi GPS temps réel (Reverb WebSocket + marqueur moto)
- [ ] Code de confirmation 4 chiffres — saisie et validation
- [ ] Centre de notifications in-app (30 dernières, lu/non lu)
- [ ] Tests Pest Orders

**Notes de sprint :** —

---

## Sprint 5 — Module Driver GoShop (S9)

- [ ] Migration `couriers` exécutée
- [ ] Migration `wallets` + `wallet_transactions` exécutée
- [ ] Endpoint `/api/courier/location` (GPS toutes les 5s si déplacement > 10m)
- [ ] Algorithme d'attribution des courses (proximité, 30s, 3 tentatives → `waiting`)
- [ ] Écran courses GoShop Driver (accepter/naviguer/récupérer/livrer)
- [ ] Solde minimum 10 000 F CFA pour espèces — vérifié
- [ ] Pénalité anti-filtrage (3 refus = hors ligne 15 min)
- [ ] Tests Pest Wallet — 8 tests, seuil 90%

**Notes de sprint :** —

---

## Sprint 6 — Administration de base (S10)

- [ ] Dashboard Filament — 9 métriques City Manager
- [ ] Gestion des 9 rôles admin + permissions
- [ ] Validation commerces / livreurs (Admin Local)
- [ ] Délégation remboursements par plafond (Chapitre 8.5)
- [ ] Widget Horizon dans Filament
- [ ] Audit logs — masquage téléphone, OTP jamais loggé
- [ ] Tests Pest Permissions — 10 tests

**Notes de sprint :** —

---

## Sprint 7 — Paiement et notifications (S11-S12)

- [ ] Intégration Hub2/CinetPay — tests sandbox
- [ ] Webhook paiement — validation HMAC obligatoire
- [ ] Job `ProcessPaymentWebhook` (queue `high`, retry 3x)
- [ ] Table `webhook_logs` fonctionnelle + rejeu depuis Horizon
- [ ] Push notifications FCM configurées
- [ ] SMS OTP — compteur mensuel + alerte email à 2 500 SMS
- [ ] Tests de flux financiers complets

**Notes de sprint :** —

---

## Sprint 8 — Tests et QA (S13-S14)

- [ ] Couverture Pest ≥ 70% globale
- [ ] Couverture Pest ≥ 90% sur modules critiques
- [ ] `flutter analyze` sans erreur — 3 apps
- [ ] Larastan niveau 5 sans erreur
- [ ] Scripts k6 exécutés — 100 commandes simultanées (seuil 95% réussite, p95 < 2s)
- [ ] Scripts k6 exécutés — 50 GPS simultanés (seuil CPU < 70%)
- [ ] Scripts k6 exécutés — 20 paiements simultanés + 5 signatures HMAC frauduleuses (0 acceptée)
- [ ] Tests utilisateurs avec pilotes à Daloa
- [ ] Démo fonctionnelle validée par Francis sur son téléphone

**Notes de sprint :** —

---

## Sprint 9 — Déploiement GoShop (S15-S16)

- [ ] Publication Google Play Store
- [ ] Publication Apple App Store
- [ ] Formation présentielle 5 commerces pilotes
- [ ] Formation présentielle 10 livreurs pilotes
- [ ] **LANCEMENT OFFICIEL GoShop — DALOA**
- [ ] Campagne marketing locale lancée

**Notes de sprint :** —

---

## Sprint 10 — GoColis complet (S17-S22)

- [ ] Module Client GoColis — création expédition, suivi, historique
- [ ] Module Driver GoColis — double code, photos, navigation
- [ ] Notation livreur GoColis (post-livraison)
- [ ] Admin GoColis — litiges, remboursement assurance
- [ ] Tests avec livreurs de confiance
- [ ] **LANCEMENT OFFICIEL GoColis — DALOA**

**Notes de sprint :** —

---

## Journal chronologique (log brut, une ligne par action significative)

```
[Format attendu]
YYYY-MM-DD HH:MM — Sprint X — Action réalisée — Résultat (OK / bloqué / à valider par Francis)

[Exemple]
2026-06-25 09:15 — Sprint 1 — docker-compose.yml créé et testé — OK, make install fonctionne
2026-06-25 14:30 — Sprint 1 — Ouverture compte Hub2/CinetPay — En attente de validation Hub2 (délai externe)
```

*(à compléter au fil du projet)*
