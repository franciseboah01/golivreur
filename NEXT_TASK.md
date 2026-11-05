# NEXT_TASK.md — Prochaine Tâche à Exécuter
## Projet GoLivreur v5.0

> Ce fichier contient TOUJOURS une seule chose : la prochaine action précise et exécutable.
> Mis à jour après chaque tâche terminée. Francis peut lire ce fichier pour savoir exactement "c'est quoi la suite".

---

## 🎯 Tâche actuelle

**Sprint :** 2 — Authentification (S3-S4)

**Objectif de la tâche :** Implémenter et connecter les écrans d'authentification (Inscription téléphone + OTP SMS + Connexion) sur les 3 applications mobiles Flutter (`golivreur_client`, `golivreur_business`, `golivreur_driver`) avec le backend API (`/api/v1/auth/*`).

**Actions précises à exécuter dans l'ordre :**

1. **GoLivreur Client :**
   - Implémenter l'écran d'inscription avec saisie numéro de téléphone ivoirien (`+225` / format 10 chiffres).
   - Implémenter l'écran de validation du code OTP (4 chiffres) avec compte à rebours 60s et bouton de renvoi.
   - Connecter avec `ApiClient` vers `POST /api/v1/auth/register` et `POST /api/v1/auth/verify-register`.
   - Stocker les tokens JWT (`access_token`, `refresh_token`) via `flutter_secure_storage`.
2. **GoLivreur Business :**
   - Implémenter l'écran de connexion / inscription partenaire (nom du commerce, catégorie, adresse, contact).
   - Valider la gestion de session unique (une nouvelle connexion invalide la précédente).
3. **GoLivreur Driver :**
   - Implémenter l'écran de connexion / inscription livreur (type de véhicule moto, numéro de pièce).
   - Gérer l'état de validation en attente d'activation par l'agent de ville.
4. **Validation de bout en bout :**
   - Tester l'inscription et la connexion d'un client de bout en bout contre l'API backend locale.
   - Valider `flutter analyze` sans aucune régression (0 erreur).

**Critère de succès (Definition of Done) :**
- Les écrans de connexion et d'inscription sont fluides, conformes à la charte graphique Orange `#F4A623` / Vert `#1B5E20`.
- Un nouvel utilisateur peut s'inscrire, recevoir son OTP simulé/réel, valider son compte et obtenir son JWT sécurisé.
- `flutter analyze` rapporte 0 avertissement sur les 3 apps.

**Bloqué sur quelque chose ?** Non.

**Nécessite une action de Francis (non technique) ?**
- ⚠️ Suivi de l'ouverture du compte marchand Hub2/CinetPay (démarche externe administrative).
- Réservation du nom de domaine `golivreur.ci`.
