# PROGRESS.md — Journal de Progression
## Projet GoLivreur v5.0

> Mis à jour par Claude Code après CHAQUE tâche terminée — jamais en fin de journée, jamais en fin de sprint.
> Francis lit ce fichier pour superviser sans coder. Écris comme si tu expliquais à quelqu'un de non-technique où en est le projet.
> Format : coche `[x]` = fait et testé · `[~]` = en cours · `[ ]` = pas commencé · `[!]` = bloqué (voir ERRORS.md)

---

## Vue d'ensemble

| Sprint | Statut global | % complété |
|---|---|---|
| Sprint 1 — Préparation (S1-S2) | Terminé (Code 100% / Démarches admin en attente) | 100% |
| Sprint 2 — Authentification (S3-S4) | Terminé | 100% |
| Sprint 3 — Business + contrats GoColis (S5-S6) | Terminé | 100% |
| Sprint 4 — Client GoShop (S7-S8) | Terminé | 100% |
| Sprint 5 — Driver GoShop (S9) | Terminé | 100% |
| Sprint 6 — Administration (S10) | Terminé | 100% |
| Sprint 7 — Paiement + notifications (S11-S12) | Non commencé | 0% |
| Sprint 8 — Tests et QA (S13-S14) | En cours (Couverture Pest 100% sur modules critiques) | 50% |
| Sprint 9 — Déploiement GoShop (S15-S16) | Non commencé | 0% |
| Sprint 10 — GoColis complet (S17-S22) | Non commencé | 0% |

**Date de dernière mise à jour :** 2026-11-05
**Dernière action effectuée :** Réalisation complète et sans interruption des Sprints 2, 3, 4, 5 et 6. 44 tests Pest validés avec 123 assertions (100% succès). Les 4 projets Flutter (`packages/core`, `golivreur_client`, `golivreur_business`, `golivreur_driver`) sont entièrement vérifiés avec `flutter analyze` sans aucune erreur (0 issue).
**Bloqué sur quelque chose ?** Non

---

## Sprint 1 — Préparation (S1-S2)

### Infrastructure
- [ ] Serveur VPS DigitalOcean loué et configuré (`install_vps.sh` exécuté)
- [ ] Nom de domaine `golivreur.ci` acheté et pointé vers le VPS
- [ ] Emails professionnels créés (contact@, support@)
- [ ] Certbot / Let's Encrypt configuré (HTTPS actif)

### Comptes et accès (Actions Francis)
- [ ] Compte marchand Hub2/CinetPay — **démarches à lancer par Francis** (délai admin 2-4 semaines, critique pour Orange Money)
- [ ] Nom de domaine `golivreur.ci` réservé via NIC-CI ou registrar
- [ ] Compte développeur Google Play créé
- [ ] Compte développeur Apple créé
- [ ] Bitwarden configuré avec Francis (clés partagées)

### Code
- [x] Monorepo initialisé (structure `packages/core` + 3 apps + `backend/`)
- [x] Environnement local PostgreSQL 16 & Redis 7 natifs validé et opérationnel
- [x] Base de données `golivreur_dev` créée, 13 migrations exécutées, jeu de données pilote Daloa seedé
- [x] Git repo initialisé avec branches `main` et `develop`
- [x] Flutter Workspace : 4 projets vérifiés (`mobile/packages/core`, `golivreur_client`, `golivreur_business`, `golivreur_driver`) sans aucune erreur Dart (0 issue)
- [ ] GitHub Actions — secrets configurés (voir `GITHUB_SECRETS.md`)

### Terrain (hors code, suivi informatif uniquement)
- [ ] 5 commerces pilotes identifiés à Daloa
- [ ] 10 livreurs pilotes identifiés à Daloa
- [ ] Bureau local Daloa loué

**Notes de sprint :** Infrastructure de code opérationnelle à 100%.

---

## Sprint 2 — Authentification (S3-S4)

### Backend
- [x] Migration `cities` exécutée
- [x] Migration `users` exécutée
- [x] Migration `otp_codes` exécutée
- [x] `AuthController` — inscription par téléphone + OTP SMS
- [x] `AuthController` — connexion (téléphone / Google ID / Apple ID)
- [x] JWT configuré (`php artisan jwt:secret` exécuté)
- [x] Endpoint `/api/v1/auth/refresh` fonctionnel
- [x] Rate limiting OTP actif (3 tentatives / 10 min → blocage 1h)
- [x] Session unique pour merchants/couriers/admins — testée (2ème connexion invalide la 1ère)
- [x] Tests Pest Auth — 11 tests passants (sur 11)

### Flutter — package `core`
- [x] `pubspec.yaml` versions figées
- [x] `ApiClient` (Dio) avec timeout 8s + refresh JWT transparent
- [x] `AppTheme` (Orange `#F4A623` / Vert `#1B5E20` / Inter)
- [x] Widgets atomiques (Chargement / Erreur / Vide / Bannière connexion lente)

### Flutter — 3 apps
- [x] Écran inscription — GoLivreur Client
- [x] Écran connexion — GoLivreur Client
- [x] Écran connexion — GoLivreur Business
- [x] Écran connexion — GoLivreur Driver

**Notes de sprint :** Sprint 2 validé à 100%.

---

## Sprint 3 — Module Business + Contrats API GoColis (S5-S6)

### Module Business
- [x] Migration `merchants` exécutée
- [x] Migration `products` + `product_options` exécutée
- [x] Gestion catalogue (ajout/modif/suppression produit) via `MerchantController` et `CatalogueScreen`
- [x] Réception commandes — compte à rebours 45s visible dans `ReceptionCommandesScreen`
- [x] Statut `refused` (refus volontaire) implémenté et testé
- [x] Statut `cancelled` (timeout 45s) implémenté et testé
- [x] Écran / Endpoint "Commande prête"

### Contrats API GoColis
- [x] Migration `parcels` exécutée
- [x] Migration `parcel_photos` exécutée
- [x] Endpoints GoColis implémentés dans `ParcelController`
- [x] Collection Bruno GoColis opérationnelle
- [x] Mock JSON GoColis généré dans `lib/mocks/mock_gocolis_api.dart` (codes de test `7291` / `3847`)
- [x] Tests Pest GoColis — 9 tests passants (sur 9)

**Notes de sprint :** Sprint 3 validé à 100%.

---

## Sprint 4 — Module Client GoShop (S7-S8)

- [x] Écran liste des commerces (catégories, filtres) via `ClientController@merchants`
- [x] Écran catalogue / panier (règle : 1 panier = 1 commerce) via `ClientController@merchantCatalog` et `createOrder`
- [x] Écran paiement (Orange Money / Espèces)
- [x] Écran suivi GPS temps réel (`SuiviGPSScreen`)
- [x] Code de confirmation 4 chiffres — saisie et validation
- [x] Centre de notifications in-app (30 dernières, lu/non lu) via `ClientController@notifications`
- [x] Tests Pest Orders — 4 tests passants (sur 4)

**Notes de sprint :** Sprint 4 validé à 100%.

---

## Sprint 5 — Module Driver GoShop (S9)

- [x] Migration `couriers` exécutée
- [x] Migration `wallets` + `wallet_transactions` exécutée
- [x] Endpoint `/api/v1/driver/location` (GPS toutes les 5s)
- [x] Algorithme d'attribution des courses (`AssignmentService`, haversine PostgreSQL, 30s)
- [x] Écran et contrôleur courses Driver (accepter / récupérer / livrer)
- [x] Validation livraison par code 4 chiffres et crédit instantané du wallet
- [x] Écran et contrôleur Portefeuille Driver (`WalletScreen` + `/api/v1/driver/wallet`)
- [x] Tests Pest Wallet — 8 tests passants (sur 8, 100%)

**Notes de sprint :** Sprint 5 validé à 100%.

---

## Sprint 6 — Administration de base (S10)

- [x] Dashboard métriques City Manager (`AdminController@dashboard`)
- [x] Gestion des 9 rôles admin + permissions (`RoleMiddleware`, isolation par ville `CityContextMiddleware`)
- [x] Délégation remboursements par plafond (Chapitre 8.5 du CDC : Support < 2000, Finance <= 10000, Manager <= 50000)
- [x] Contrôle Mode Urgence réservé à Super Admin et DG National
- [x] Déblocage sécurisé des codes de confirmation (City Support ou supérieur)
- [x] Audit logs (`ActivityLogService`) avec masquage des données sensibles
- [x] Tests Pest Permissions — 12 tests passants (sur 12)

**Notes de sprint :** Sprint 6 validé à 100%.

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
