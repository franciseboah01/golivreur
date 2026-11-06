# NEXT_TASK.md — Prochaine Tâche à Exécuter
## Projet GoLivreur v5.0

> Ce fichier contient TOUJOURS une seule chose : la prochaine action précise et exécutable.
> Mis à jour après chaque tâche terminée. Francis peut lire ce fichier pour savoir exactement "c'est quoi la suite".

---

## 🎯 Tâche actuelle

**Étape :** Validation sur téléphone physique & Démonstration interactive

**Objectif de la tâche :** Connecter le smartphone Android de Francis en USB (ou lancer l'émulateur Android) et tester chaque application GoLivreur en conditions réelles avec le backend en local.

**Actions précises à exécuter dans l'ordre :**

1. **Connecter le smartphone Android en USB :**
   - Activer le mode *Débogage USB* sur le smartphone Android (Paramètres > Options de développement > Débogage USB).
   - Brancher le téléphone au PC avec un câble USB et autoriser la connexion sur l'écran du téléphone.
   - Vérifier la détection avec la commande `flutter devices`.

2. **Lancer et tester chaque application sur le téléphone :**
   - **Application Client :**
     ```powershell
     cd C:\Projets\golivreur\mobile\apps\golivreur_client
     flutter run
     ```
     *Tester l'inscription, la connexion OTP, la consultation des commerces de Daloa, la commande et le suivi GPS.*
   
   - **Application Commerce (Business) :**
     ```powershell
     cd C:\Projets\golivreur\mobile\apps\golivreur_business
     flutter run
     ```
     *Tester la réception de commande avec compte à rebours 45s, l'acceptation et la mise à disposition.*

   - **Application Livreur (Driver) :**
     ```powershell
     cd C:\Projets\golivreur\mobile\apps\golivreur_driver
     flutter run
     ```
     *Tester la mise en ligne, la prise en charge avec photo et double validation par code 4 chiffres, et le portefeuille.*

3. **Administration Web :**
   - Accéder au back-office sur `http://localhost:8080/admin` (ou `http://<IP_PC>:8080/admin` depuis le navigateur mobile).

**Critère de succès (Definition of Done) :**
- Les applications se lancent avec fluidité sur le smartphone sans plantage.
- Le cycle complet commande -> acceptation commerce -> assignation livreur -> livraison par code 4 chiffres fonctionne de bout en bout.

**Bloqué sur quelque chose ?** Non

**Nécessite une action de Francis (non technique) ?**
- Brancher son smartphone Android en USB avec le débogage USB activé.
