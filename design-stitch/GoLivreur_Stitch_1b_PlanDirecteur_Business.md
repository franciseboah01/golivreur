# FICHIER 1b — PLAN DIRECTEUR : GOLIVREUR BUSINESS

## Objectif principal de l'application
Permettre à un commerce de Daloa (restaurant, pharmacie, boutique...) de gérer son catalogue et de recevoir/traiter les commandes GoShop simplement, sans culture numérique avancée requise.

## Convention de nommage des écrans
`BUSINESS-[MODULE]-[NomEcran]`

---

## MODULE : AUTH & INSCRIPTION (3 écrans)

| ID Écran | Nom | Objectif | Composants clés (id / data-action) |
|---|---|---|---|
| BUSINESS-AUTH-InscriptionCommerce | Inscription du commerce | Nom, catégorie, description, photos, localisation GPS | `#input-nom-commerce`, `#select-categorie`, `#btn-localiser-gps` (data-action="merchant.captureGps"), `#upload-logo`, `#upload-couverture`, `#btn-soumettre` (data-action="merchant.submitRegistration") |
| BUSINESS-AUTH-VerificationOTP | Vérification téléphone | Code SMS à 4 chiffres | `#input-otp-1` à `#input-otp-4`, `#btn-verifier` (data-action="auth.verifyOtp") |
| BUSINESS-AUTH-EnAttenteValidation | Écran d'attente | Informe que la validation par l'Admin Local est en cours | `#statut-en-attente`, `#btn-contacter-support` (data-action="support.contact") |

**Parcours clé :** InscriptionCommerce → VerificationOTP → EnAttenteValidation → (validation Admin) → CATALOGUE-ListeProduits

---

## MODULE : CATALOGUE (3 écrans)

| ID Écran | Nom | Objectif | Composants clés (id / data-action) |
|---|---|---|---|
| BUSINESS-CATALOGUE-ListeProduits | Liste des produits | Vue d'ensemble du catalogue, disponibilité | `#produit-carte-{id}`, `#toggle-disponibilite-{id}` (data-action="catalog.toggleAvailability"), `#btn-ajouter-produit` (data-action="catalog.addProduct") |
| BUSINESS-CATALOGUE-AjouterProduit | Ajout produit | Nom, photo, description, prix, catégorie | `#input-nom-produit`, `#upload-photo-produit`, `#input-prix`, `#select-categorie-produit`, `#btn-enregistrer` (data-action="catalog.saveProduct") |
| BUSINESS-CATALOGUE-ModifierProduit | Modification produit | Changement prix, photo, description, suppression | `#input-prix`, `#btn-supprimer-produit` (data-action="catalog.deleteProduct"), `#btn-enregistrer-modif` (data-action="catalog.updateProduct") |

**Parcours clé :** ListeProduits → AjouterProduit / ModifierProduit → retour ListeProduits

---

## MODULE : COMMANDES (4 écrans)

| ID Écran | Nom | Objectif | Composants clés (id / data-action) |
|---|---|---|---|
| BUSINESS-COMMANDES-AlerteNouvelleCommande | Alerte plein écran | Notification sonore forte, compte à rebours 45s | `#compteur-45s` (dynamique), `#btn-accepter` (data-action="order.accept"), `#btn-refuser` (data-action="order.refuse") |
| BUSINESS-COMMANDES-DetailCommande | Détail d'une commande | Produits, quantités, options, total, adresse client | `#liste-produits-commande`, `#adresse-client`, `#instructions-client` |
| BUSINESS-COMMANDES-CommandePrete | Commande en préparation | Bouton "Commande prête" quand emballée | `#btn-commande-prete` (data-action="order.markReady") |
| BUSINESS-COMMANDES-HistoriqueCommandes | Historique | Toutes les commandes reçues, filtrable par statut | `#commande-item-{id}` (data-action="order.openDetail"), `#filtre-statut` |

**Parcours clé :** AlerteNouvelleCommande → DetailCommande → CommandePrete → (livreur récupère) → HistoriqueCommandes

---

## MODULE : REVENUS (2 écrans)

| ID Écran | Nom | Objectif | Composants clés (id / data-action) |
|---|---|---|---|
| BUSINESS-REVENUS-DashboardRevenus | Tableau de bord revenus | Commandes du jour/semaine/mois, revenus nets | `#carte-ca-jour`, `#carte-ca-semaine`, `#carte-ca-mois`, `#graphique-revenus` |
| BUSINESS-REVENUS-ReleveVersements | Relevé des versements | Prochains versements, historique des transactions | `#ligne-versement-{id}`, `#date-prochain-versement` |

**Parcours clé :** Accès depuis la tab bar → DashboardRevenus → ReleveVersements

---

## MODULE : PARAMÈTRES (2 écrans)

| ID Écran | Nom | Objectif | Composants clés (id / data-action) |
|---|---|---|---|
| BUSINESS-PARAMETRES-StatutOuverture | Statut Ouvert/Fermé | Activer/désactiver la réception de commandes | `#toggle-statut-ouvert` (data-action="settings.toggleOpen") |
| BUSINESS-PARAMETRES-HorairesPreparation | Horaires et préparation | Jours/heures d'ouverture, temps de préparation estimé | `#selecteur-horaires`, `#input-temps-preparation`, `#btn-enregistrer-parametres` (data-action="settings.save") |

**Parcours clé :** Accès depuis la tab bar → StatutOuverture / HorairesPreparation

---

## DÉCOUPAGE EN LOTS POUR GÉNÉRATION STITCH (2 lots)

1. **Lot Auth + Catalogue** (6 écrans) — InscriptionCommerce, VerificationOTP, EnAttenteValidation, ListeProduits, AjouterProduit, ModifierProduit
2. **Lot Commandes + Revenus + Paramètres** (8 écrans) — AlerteNouvelleCommande, DetailCommande, CommandePrete, HistoriqueCommandes, DashboardRevenus, ReleveVersements, StatutOuverture, HorairesPreparation

**Total : 14 écrans**
