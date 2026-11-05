# FICHIER 1a — PLAN DIRECTEUR : GOLIVREUR CLIENT

## Objectif principal de l'application
Permettre à un habitant de Daloa de commander dans n'importe quel commerce local (GoShop) et d'expédier un colis à un tiers en ville (GoColis), avec suivi en temps réel et paiement Mobile Money.

## Convention de nommage des écrans
`CLIENT-[MODULE]-[NomEcran]`

---

## MODULE : AUTH (5 écrans)

| ID Écran | Nom | Objectif | Composants clés (id / data-action) |
|---|---|---|---|
| CLIENT-AUTH-Splash | Écran de lancement | Affiche le logo pendant le chargement initial | `#logo-golivreur` |
| CLIENT-AUTH-Onboarding | Présentation en 3 slides | Explique GoShop et GoColis à un nouvel utilisateur | `#btn-suivant` (data-action="onboarding.next"), `#btn-passer` (data-action="onboarding.skip") |
| CLIENT-AUTH-InscriptionTelephone | Saisie du numéro | Collecte le numéro de téléphone ivoirien | `#input-telephone`, `#btn-envoyer-otp` (data-action="auth.sendOtp") |
| CLIENT-AUTH-VerificationOTP | Saisie code SMS | Valide le code à 4 chiffres reçu par SMS | `#input-otp-1` à `#input-otp-4`, `#btn-verifier` (data-action="auth.verifyOtp"), `#lien-renvoyer-code` (data-action="auth.resendOtp") |
| CLIENT-AUTH-Connexion | Connexion existante | Téléphone, Google ID ou Apple ID | `#btn-connexion-telephone`, `#btn-connexion-google` (data-action="auth.googleLogin"), `#btn-connexion-apple` (data-action="auth.appleLogin") |

**Parcours clé :** Splash → Onboarding (1ère fois uniquement) → InscriptionTelephone → VerificationOTP → GOSHOP-Accueil

---

## MODULE : GOSHOP — Découverte et commande (13 écrans)

| ID Écran | Nom | Objectif | Composants clés (id / data-action) |
|---|---|---|---|
| CLIENT-GOSHOP-Accueil | Liste des commerces | Affiche les commerces disponibles autour du client, par catégorie | `#barre-recherche` (data-action="goshop.search"), `#carte-categorie-{id}` (data-action="goshop.filterCategory"), `#carte-commerce-{id}` (data-action="goshop.openMerchant") |
| CLIENT-GOSHOP-Categories | Grille des 14 catégories | Navigation par type de commerce | `#tuile-categorie-{id}` (data-action="goshop.selectCategory") |
| CLIENT-GOSHOP-Recherche | Résultats de recherche | Recherche par nom, produit, mot-clé | `#input-recherche`, `#resultat-{id}` (data-action="goshop.openMerchant") |
| CLIENT-GOSHOP-Filtres | Panneau de filtres | Distance, note, frais de livraison, temps estimé | `#slider-distance`, `#select-note-min`, `#btn-appliquer-filtres` (data-action="goshop.applyFilters") |
| CLIENT-GOSHOP-CommerceDetail | Fiche commerce | Photo de couverture, note, horaires, accès au catalogue | `#btn-voir-catalogue` (data-action="goshop.viewCatalog"), `#btn-favori` (data-action="goshop.toggleFavorite") |
| CLIENT-GOSHOP-CatalogueProduit | Liste des produits | Produits du commerce avec photos et prix | `#produit-carte-{id}` (data-action="goshop.openProduct") |
| CLIENT-GOSHOP-ProduitDetailOptions | Détail produit | Variantes, suppléments, quantité | `#select-variante`, `#stepper-quantite`, `#btn-ajouter-panier` (data-action="goshop.addToCart") |
| CLIENT-GOSHOP-Panier | Panier (1 commerce) | Récapitulatif, quantités ajustables | `#ligne-produit-{id}`, `#btn-modifier-quantite-{id}` (data-action="goshop.updateQty"), `#btn-valider-panier` (data-action="goshop.checkout") |
| CLIENT-GOSHOP-InstructionsLivraison | Adresse et instructions | Choix adresse favorite + champ libre | `#select-adresse`, `#input-instructions`, `#btn-continuer` (data-action="goshop.confirmAddress") |
| CLIENT-GOSHOP-Paiement | Choix du paiement | Orange Money ou espèces à la livraison | `#option-orange-money` (data-action="payment.selectOrangeMoney"), `#option-especes` (data-action="payment.selectCash"), `#btn-payer` (data-action="payment.submit") |
| CLIENT-GOSHOP-SuiviCommande | Suivi en temps réel | Carte GPS, statuts, photo du livreur | `#carte-gps`, `#badge-statut` (dynamique), `#bouton-appeler-livreur` (data-action="tracking.callCourier") |
| CLIENT-GOSHOP-ConfirmationCode | Code de confirmation | Affiche le code 4 chiffres à donner au livreur | `#code-confirmation-affiche` |
| CLIENT-GOSHOP-Evaluation | Notation post-livraison | Note commerce + livreur, commentaire | `#etoiles-commerce`, `#etoiles-livreur`, `#input-commentaire`, `#btn-envoyer-evaluation` (data-action="rating.submit") |

**Parcours clé :** Accueil → CommerceDetail → CatalogueProduit → ProduitDetailOptions → Panier → InstructionsLivraison → Paiement → SuiviCommande → ConfirmationCode → Evaluation

---

## MODULE : GOCOLIS — Expédition (7 écrans)

| ID Écran | Nom | Objectif | Composants clés (id / data-action) |
|---|---|---|---|
| CLIENT-GOCOLIS-Accueil | Accueil GoColis | Point d'entrée + historique récent | `#btn-nouvelle-expedition` (data-action="gocolis.newParcel") |
| CLIENT-GOCOLIS-NouvelleExpeditionFormulaire | Formulaire expédition | Destinataire, adresse, description, taille | `#input-nom-destinataire`, `#input-telephone-destinataire`, `#input-adresse-livraison`, `#select-taille-colis`, `#input-valeur-declaree`, `#btn-calculer-tarif` (data-action="gocolis.calculateFee") |
| CLIENT-GOCOLIS-RecapitulatifTarif | Récapitulatif et tarif | Tarif calculé automatiquement (taille + distance) | `#montant-tarif-affiche`, `#btn-continuer-paiement` (data-action="gocolis.proceedToPayment") |
| CLIENT-GOCOLIS-PaiementColis | Paiement | Orange Money ou espèces | `#option-orange-money`, `#option-especes`, `#btn-payer` (data-action="payment.submit") |
| CLIENT-GOCOLIS-SuiviExpedition | Suivi en temps réel | Statuts, position livreur, photos du colis | `#carte-gps`, `#badge-statut`, `#photo-ramassage`, `#code-expediteur-affiche` |
| CLIENT-GOCOLIS-ConfirmationLivraison | Confirmation de livraison | Notification "Colis livré à [Destinataire]" + photo de remise | `#photo-remise`, `#btn-fermer` (data-action="gocolis.closeConfirmation") |
| CLIENT-GOCOLIS-HistoriqueExpeditions | Historique | Liste de toutes les expéditions envoyées | `#ligne-expedition-{id}` (data-action="gocolis.openParcelDetail") |

**Parcours clé :** Accueil → NouvelleExpeditionFormulaire → RecapitulatifTarif → PaiementColis → SuiviExpedition → ConfirmationLivraison

---

## MODULE : PROFIL & NOTIFICATIONS (5 écrans)

| ID Écran | Nom | Objectif | Composants clés (id / data-action) |
|---|---|---|---|
| CLIENT-PROFIL-Profil | Fiche profil | Nom, photo, informations personnelles | `#avatar-profil`, `#btn-modifier-profil` (data-action="profile.edit") |
| CLIENT-PROFIL-Adresses | Adresses favorites | Liste des adresses enregistrées avec repères | `#adresse-carte-{id}`, `#btn-ajouter-adresse` (data-action="profile.addAddress") |
| CLIENT-PROFIL-NotificationsCentre | Centre de notifications | 30 dernières notifications, lu/non lu | `#notification-item-{id}` (data-action="notif.markRead"), `#badge-non-lu` |
| CLIENT-PROFIL-Parametres | Paramètres | Notifications on/off par type, langue | `#toggle-notif-commandes`, `#toggle-notif-promos` |
| CLIENT-PROFIL-HistoriqueCommandes | Historique GoShop | Toutes les commandes passées, "Recommander" | `#commande-item-{id}`, `#btn-recommander-{id}` (data-action="goshop.reorder") |

**Parcours clé :** Accès depuis la tab bar à tout moment → Profil / Notifications / Historique

---

## DÉCOUPAGE EN LOTS POUR GÉNÉRATION STITCH (6 lots de 5 écrans)

1. **Lot Auth** — CLIENT-AUTH-Splash, Onboarding, InscriptionTelephone, VerificationOTP, Connexion
2. **Lot GoShop-Découverte** — Accueil, Categories, Recherche, Filtres, CommerceDetail
3. **Lot GoShop-Commande** — CatalogueProduit, ProduitDetailOptions, Panier, InstructionsLivraison, Paiement
4. **Lot GoShop-Suivi + GoColis-Début** — SuiviCommande, ConfirmationCode, Evaluation, GOCOLIS-Accueil, NouvelleExpeditionFormulaire
5. **Lot GoColis-Suite** — RecapitulatifTarif, PaiementColis, SuiviExpedition, ConfirmationLivraison, HistoriqueExpeditions
6. **Lot Profil** — Profil, Adresses, NotificationsCentre, Parametres, HistoriqueCommandes

**Total : 30 écrans**
