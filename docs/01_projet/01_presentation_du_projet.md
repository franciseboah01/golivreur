# GoLivreur — Présentation du Projet

**Version** : 5.0  
**Date** : Juin 2026  
**Fondateur** : Francis EBOAH  
**Ville de lancement** : Daloa, Côte d'Ivoire  
**Slogan** : *Daloa bouge, on livre !*

---

## 1. Identité

| Élément | Valeur |
|---|---|
| Nom du projet | GoLivreur |
| Site web | www.golivreur.ci |
| Services | GoShop (achats + livraison) · GoColis (expédition de colis) |
| Applications | GoLivreur Client · GoLivreur Business · GoLivreur Driver |
| Backend | Laravel 11 + PostgreSQL 16 |
| Mobile | Flutter 3.x (Riverpod) |
| Admin | Laravel Filament 3 |

---

## 2. Description

GoLivreur est une plateforme technologique ivoirienne qui connecte les clients, les commerces et les livreurs via trois applications mobiles distinctes.

### GoShop
Le client parcourt les commerces de Daloa disponibles dans l'application, achète des produits et se fait livrer là où il se trouve en moins de 55 minutes au lancement (objectif 45 minutes à 6 mois).

### GoColis
Le client expédie un colis à une tierce personne avec photos de preuve, double code de vérification (expéditeur + destinataire) et assurance optionnelle.

---

## 3. Contexte de marché

- **Population Daloa** : ~500 000 habitants — 3ème ville de Côte d'Ivoire
- **Marché vierge** : aucun acteur majeur de livraison présent (Glovo et Yango restés à Abidjan)
- **Mobile Money mature** : Orange Money, MTN MoMo et Wave utilisés quotidiennement
- **Population jeune et connectée** : forte présence étudiante, smartphones Android majoritaires

---

## 4. Les cinq fonctionnalités clés

### 4.1 GoShop — Achat et livraison locale
Le client commande dans n'importe quel commerce de Daloa (restaurant, pharmacie, boutique, etc.), paie via Orange Money ou en espèces, et est livré en moins de 55 minutes avec suivi GPS en temps réel.

### 4.2 GoColis — Expédition sécurisée
L'expéditeur crée une expédition avec description, taille et valeur déclarée. Le livreur collecte le colis après validation d'un code expéditeur (4 chiffres, app), le livre au destinataire après validation d'un code destinataire (4 chiffres, SMS). Deux photos de preuve (ramassage + livraison) sont obligatoires.

### 4.3 Suivi GPS temps réel
Le client et l'expéditeur suivent la position du livreur sur une carte en temps réel via WebSocket (Laravel Reverb). Le GPS du livreur envoie sa position toutes les 5 secondes, uniquement si déplacement > 10 mètres.

### 4.4 Administration hiérarchique à deux niveaux
9 rôles distincts (Super Admin → City Agent) organisés en niveau National et niveau Ville. Chaque admin local est filtré automatiquement par `city_id` (City Context). Délégation de remboursements par plafond : City Support < 2 000 F CFA, City Finance < 10 000 F CFA, City Manager < 50 000 F CFA, Super Admin au-delà.

### 4.5 Paiement Mobile Money intégré
Orange Money au lancement via Hub2/CinetPay. MTN MoMo et Wave ajoutés à 3 mois. Espèces toujours acceptées. Webhooks de confirmation traités en queue haute priorité avec validation HMAC obligatoire.

---

## 5. Fonctionnalités secondaires

- **Mode Pluie** : majoration automatique des tarifs détectée via OpenWeatherMap (toutes les 15 min) ou déclenchée manuellement par le City Manager
- **Mode Urgence** : suspension de toutes nouvelles commandes, bandeau rouge dans les 3 apps, activé uniquement par Super Admin ou DG National
- **Assurance colis** : 3% de la valeur déclarée pour les colis > 20 000 F CFA
- **Notation** : clients notent commerces et livreurs (GoShop) et livreurs (GoColis) de 1 à 5 étoiles
- **Portefeuille virtuel** : wallets pour les marchands (commissions) et les livreurs (gains), rechargeable et retirable
- **Pénalité anti-filtrage** : 3 refus consécutifs = hors ligne automatique 15 minutes pour le livreur

---

## 6. Ambition et roadmap

| Horizon | Objectif |
|---|---|
| 6 mois | Dominer le marché de la livraison à Daloa |
| 18 mois | Ouvrir Bouaké, Yamoussoukro, Korhogo, San Pedro |
| 36 mois | 10 villes ivoiriennes · 5 000 commandes/jour |
| 60 mois | Expansion en Afrique de l'Ouest francophone |

---

## 7. Indicateurs de succès à 12 mois

| Indicateur | 3 mois | 6 mois | 12 mois |
|---|---|---|---|
| Commerces actifs | 30 | 80 | 150 |
| Livreurs actifs | 15 | 40 | 80 |
| Clients inscrits | 500 | 2 000 | 6 000 |
| Commandes GoShop/jour | 10 | 40 | 120 |
| Expéditions GoColis/jour | 5 | 20 | 50 |
| CA mensuel plateforme | 500 000 F CFA | 1 800 000 F CFA | 7 000 000 F CFA |
