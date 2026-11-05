# GoLivreur — Guide d'utilisation par rôle

---

## Rôles applicatifs (apps mobiles)

### Client — GoLivreur Client
**Ce que le client peut faire :**
- S'inscrire avec son numéro de téléphone (OTP SMS)
- Commander via GoShop : parcourir les commerces, ajouter au panier, payer (Orange Money ou espèces), suivre la livraison GPS en temps réel, donner le code de confirmation au livreur, noter le commerce et le livreur
- Expédier via GoColis : créer une expédition, décrire le colis (taille, valeur), payer, suivre le statut, voir les photos de preuve
- Gérer ses adresses favorites
- Activer/désactiver les notifications par type (commandes, promotions)
- Consulter l'historique de ses 30 dernières notifications in-app

**Ce que le client ne peut pas faire :**
- Voir les données des autres clients
- Accéder aux informations des commerces ou livreurs hors livraison active
- Modifier une commande déjà acceptée par le commerce

---

### Merchant (Commerce) — GoLivreur Business
**Ce que le commerce peut faire :**
- Recevoir les commandes GoShop avec alerte sonore forte
- Accepter une commande dans les 45 secondes ou la refuser explicitement
- Signaler qu'une commande est prête (bouton "Commande prête")
- Gérer son catalogue produits (nom, description, prix, photo, disponibilité)
- Configurer ses horaires d'ouverture et son temps de préparation estimé
- Activer/désactiver le statut ouvert/fermé manuellement
- Consulter l'historique de ses commandes et son chiffre d'affaires

**Ce que le commerce ne peut pas faire :**
- Modifier les frais de livraison (fixés par la plateforme)
- Voir les données des autres commerces
- Contacter directement le client sans passer par la plateforme

---

### Driver (Livreur) — GoLivreur Driver
**Ce que le livreur peut faire :**
- Passer en ligne/hors ligne
- Recevoir les propositions de courses GoShop et GoColis
- Voir la taille du colis avant d'accepter une course GoColis
- Naviguer vers le commerce/expéditeur puis vers le client/destinataire
- Valider les codes de confirmation (GoShop : 1 code, GoColis : 2 codes)
- Prendre les photos obligatoires (colis au ramassage + remise au destinataire)
- Consulter son wallet et l'historique de ses gains

**Ce que le livreur ne peut pas faire :**
- Choisir ses courses à l'avance (attribution automatique par proximité)
- Modifier les tarifs ou commissions
- Voir les données des autres livreurs

---

## Rôles administration (interface web Filament)

### Super Admin — Francis EBOAH
**Accès complet à tout.** Actions exclusives :
- Créer et désactiver tous les autres comptes admin
- Activer/désactiver le Mode Urgence
- Valider les remboursements > 50 000 F CFA
- Configurer les commissions plateforme (taux GoShop et GoColis)
- Accéder au dashboard Horizon (jobs, queues, workers)
- Consulter tous les logs d'audit sans filtre de ville
- Modifier les barèmes de livraison

---

### Directeur Général National (national_dg)
- Vue consolidée de toutes les villes
- Activation du Mode Urgence (co-habilitée avec Super Admin)
- Nommer les City Managers de chaque ville
- Consulter les KPIs nationaux (commandes, CA, taux de succès)
- Pas d'accès aux remboursements > 50 000 F CFA (Super Admin uniquement)

---

### Responsable Finances National (national_finance)
- Tableau de bord financier consolidé toutes villes
- Nommer les City Finance de chaque ville
- Superviser les wallets marchands et livreurs
- Générer les rapports financiers mensuels (exports CSV/PDF)
- Configurer les seuils d'alerte SMS (actuellement 2 500/mois)

---

### Responsable Technique National (national_tech)
- Accès au dashboard Horizon (supervision des queues et workers)
- Consultation des logs Sentry
- Activation/désactivation du Mode Pluie automatique (API météo)
- Gestion des paramètres techniques (timeouts, rate limits)
- Pas d'accès aux données financières des utilisateurs

---

### Support National (national_support)
- Traitement des litiges escaladés depuis les villes
- Vue en lecture seule sur toutes les commandes et expéditions
- Contact avec les City Managers pour escalade

---

### City Manager (city_manager) — Daloa
**Périmètre : ville assignée uniquement (City Context)**
- Piloter toutes les opérations de la ville
- Valider les remboursements de 10 000 à 50 000 F CFA
- Activer/désactiver le Mode Pluie manuellement
- Réassigner manuellement une course sans livreur (statut `waiting`)
- Tableau de bord : commandes du jour, CA, livreurs en ligne, commerces ouverts, litiges ouverts, validations en attente, temps moyen de livraison
- Nommer les City Support et City Agents de sa ville
- Superviser les City Support et City Agents

---

### Admin Financier Local (city_finance) — Daloa
**Périmètre : ville assignée uniquement**
- Valider les remboursements de 2 000 à 10 000 F CFA
- Consulter les transactions et wallets de la ville
- Générer les rapports financiers de la ville
- Pas d'accès aux opérations (commandes, livreurs)

---

### Admin Support Local (city_support) — Daloa
**Périmètre : ville assignée uniquement**
- Valider les remboursements < 2 000 F CFA
- Débloquer manuellement les codes de confirmation après 3 erreurs (contact vocal préalable)
- Valider les inscriptions des commerces et livreurs
- Traiter les litiges de la ville
- Astreinte : Lundi–Vendredi 07h00–22h00, Samedi–Dimanche 08h00–23h00

---

### Agent de Terrain (city_agent) — Daloa (×2)
**Périmètre : ville assignée uniquement**
- Valider les inscriptions des commerces et livreurs (terrain)
- Effectuer les vérifications physiques (moto livreur, identité, commerce)
- Aider à la formation présentielle
- Pas d'accès aux données financières ni aux litiges

---

## Règle de session par rôle

| Rôle | Durée d'inactivité avant déconnexion |
|---|---|
| Super Admin, Admins Nationaux | 2 heures |
| City Manager, City Finance | 4 heures |
| City Support, City Agent | 8 heures (terrain) |
| Client | Session persistante (multi-appareils toléré) |
| Merchant, Driver | Session unique — nouvelle connexion = déconnexion ancienne session |
