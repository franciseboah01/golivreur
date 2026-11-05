# FICHIER 2 — INTERCONNEXIONS ENTRE LES 3 APPLICATIONS

## Vue d'ensemble

Les trois applications (Client, Business, Driver) ne communiquent jamais directement entre elles au niveau de l'interface — tous les échanges passent par le backend GoLivreur (API + WebSocket temps réel). Ce document cartographie les points où une action dans une app produit un effet visible dans une autre.

---

## 1. Points de contact — GoShop

| Déclencheur | App source | Effet | App destinataire |
|---|---|---|---|
| Client valide une commande | CLIENT-GOSHOP-Paiement | Alerte sonore + notification push | BUSINESS-COMMANDES-AlerteNouvelleCommande |
| Commerce accepte la commande | BUSINESS-COMMANDES-AlerteNouvelleCommande | Statut "En préparation" affiché en temps réel | CLIENT-GOSHOP-SuiviCommande |
| Commerce refuse / timeout 45s | BUSINESS-COMMANDES-AlerteNouvelleCommande | Commande annulée, notification client | CLIENT-GOSHOP-SuiviCommande |
| Commerce marque "Commande prête" | BUSINESS-COMMANDES-CommandePrete | Course proposée au livreur le plus proche | DRIVER-COURSESGOSHOP-AlerteCourse |
| Livreur accepte la course | DRIVER-COURSESGOSHOP-AlerteCourse | Statut "Livreur en route", position GPS affichée | CLIENT-GOSHOP-SuiviCommande |
| Livreur arrive au commerce | DRIVER-COURSESGOSHOP-NavigationCommerce | — (information interne livreur) | — |
| Livreur saisit le code client | DRIVER-COURSESGOSHOP-LivraisonCodeClient | Commande marquée "Livrée", invitation à évaluer | CLIENT-GOSHOP-Evaluation |
| Client évalue | CLIENT-GOSHOP-Evaluation | Note mise à jour sur le profil | BUSINESS-REVENUS-DashboardRevenus (note commerce) + DRIVER-GAINS-DashboardGains (note livreur) |

---

## 2. Points de contact — GoColis

| Déclencheur | App source | Effet | App destinataire |
|---|---|---|---|
| Client crée une expédition et paie | CLIENT-GOCOLIS-PaiementColis | Course proposée au livreur le plus proche (taille du colis visible) | DRIVER-COURSESGOCOLIS-AlerteCourse |
| Livreur accepte | DRIVER-COURSESGOCOLIS-AlerteCourse | Statut "Livreur en route" | CLIENT-GOCOLIS-SuiviExpedition |
| Livreur arrive chez l'expéditeur, prend la photo et saisit le code expéditeur | DRIVER-COURSESGOCOLIS-RamassagePhotoCode | Photo visible dans le suivi, statut "En transit" | CLIENT-GOCOLIS-SuiviExpedition |
| Système envoie le code destinataire par SMS | Backend (déclenché à l'acceptation du livreur) | SMS reçu directement sur le téléphone du destinataire — hors app | Destinataire (pas d'app) |
| Livreur saisit le code destinataire et prend la photo de remise | DRIVER-COURSESGOCOLIS-LivraisonCodeDestinatairePhoto | Notification "Colis livré à [Destinataire]" | CLIENT-GOCOLIS-ConfirmationLivraison |
| 3 erreurs de code | DRIVER (GoShop ou GoColis) | Alerte envoyée à l'administration (hors périmètre des 3 apps) | Interface Admin Filament |

---

## 3. Données partagées entre les apps

| Donnée | Source de vérité | Consommée par |
|---|---|---|
| Profil utilisateur (nom, téléphone, photo) | Backend `users` | Les 3 apps affichent leur propre variante (Client, Business, Driver) |
| Statut de commande / expédition | Backend `orders` / `parcels` (mis à jour via WebSocket Reverb) | CLIENT (suivi), BUSINESS (commandes), DRIVER (courses) |
| Position GPS du livreur | DRIVER (émise toutes les 5s si déplacement > 10m) | CLIENT-GOSHOP-SuiviCommande, CLIENT-GOCOLIS-SuiviExpedition |
| Solde du portefeuille | Backend `wallets` | DRIVER-PORTEFEUILLE uniquement (Business a son propre solde, non partagé visuellement) |
| Notifications | Backend `notifications` | CLIENT-PROFIL-NotificationsCentre (centre dédié) ; BUSINESS et DRIVER reçoivent uniquement des alertes système temps réel, pas de centre dédié |
| Note et évaluation | Backend `ratings` | Visible sur BUSINESS-REVENUS-DashboardRevenus et DRIVER-GAINS-DashboardGains |

---

## 4. Ce qui n'est PAS partagé (cloisonnement volontaire)

- Le Client ne voit jamais l'identité complète du livreur (uniquement prénom + photo + note)
- Le Destinataire GoColis n'a accès à aucune app — uniquement des SMS
- Le Business ne voit jamais les autres commerces ni leurs performances
- Le Driver ne voit jamais le détail financier d'un commerce, uniquement le montant de son propre gain

---

## 5. Deep links (liens profonds) à prévoir dans la structure technique

| Lien profond | Ouvre | Usage |
|---|---|---|
| `golivreur://order/{id}` | CLIENT-GOSHOP-SuiviCommande | Depuis une notification push commande |
| `golivreur://parcel/{id}` | CLIENT-GOCOLIS-SuiviExpedition | Depuis une notification push expédition |
| `golivreur-business://order/{id}` | BUSINESS-COMMANDES-DetailCommande | Depuis l'alerte sonore nouvelle commande |
| `golivreur-driver://job/{id}` | DRIVER-COURSESGOSHOP-AlerteCourse ou DRIVER-COURSESGOCOLIS-AlerteCourse | Depuis la notification de course proposée |

*(Note pour Claude Code : ces schémas d'URL sont indicatifs — à harmoniser avec la configuration réelle Flutter au moment de l'implémentation.)*
