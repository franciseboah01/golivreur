# GoLivreur — Cahier des Charges Fonctionnel et Technique
## Version 5.0 — Lancement officiel à Daloa, Côte d'Ivoire

| Élément | Valeur |
|---|---|
| Nom du projet | GoLivreur |
| Fondateur | Francis EBOAH |
| Version | 5.0 |
| Date | 24 Juin 2026 |
| Ville de lancement | Daloa, Côte d'Ivoire |
| Slogan | Daloa bouge, on livre ! |
| Statut | **APPROUVÉ POUR EXÉCUTION** |

> Document confidentiel — Propriété de Francis EBOAH, Fondateur GoLivreur.

---

## TABLE DES MATIÈRES

1. [Présentation du Projet](#1-présentation-du-projet)
2. [Vision, Mission et Ambition](#2-vision-mission-et-ambition)
3. [Analyse du Marché de Daloa](#3-analyse-du-marché-de-daloa)
4. [Objectifs Stratégiques](#4-objectifs-stratégiques)
5. [Les Services GoLivreur](#5-les-services-golivreur)
6. [Description Détaillée des Acteurs](#6-description-détaillée-des-acteurs)
7. [Les Trois Applications Mobiles](#7-les-trois-applications-mobiles)
8. [Règles Métier et Flux Financiers](#8-règles-métier-et-flux-financiers)
9. [Architecture Administrative Complète](#9-architecture-administrative-complète)
10. [Interface Admin Web](#10-interface-admin-web)
11. [Spécifications Techniques](#11-spécifications-techniques)
12. [Parcours Utilisateurs Détaillés](#12-parcours-utilisateurs-détaillés)
13. [Planning d'Exécution](#13-planning-dexécution)
14. [Budget Prévisionnel v5](#14-budget-prévisionnel-v5)
15. [Critères de Réussite](#15-critères-de-réussite)
16. [Risques et Stratégies de Mitigation](#16-risques-et-stratégies-de-mitigation)
- [Annexes](#annexes)

---

## 1. Présentation du Projet

### 1.1. Identité

| Élément | Valeur |
|---|---|
| Nom du projet | GoLivreur |
| Fondateur | Francis EBOAH |
| Date de création | Juin 2026 |
| Ville de lancement | Daloa, Côte d'Ivoire |
| Siège social prévu | Abidjan (futur siège national) |
| Slogan | Daloa bouge, on livre ! |
| Site web | www.golivreur.ci |
| Services | GoShop (achats + livraison) et GoColis (expédition de colis) |

### 1.2. Description

GoLivreur est une plateforme technologique ivoirienne qui connecte les clients, les commerces et les livreurs via trois applications mobiles distinctes. Elle propose deux services complémentaires utilisant la même infrastructure :

- **GoShop** : Acheter dans les commerces locaux de tout type et se faire livrer en moins d'une heure au lancement (objectif 45 minutes à 6 mois).
- **GoColis** : Expédier un colis à une tierce personne avec photos de preuve, double code de vérification et assurance optionnelle.

La plateforme est pilotée par une administration hiérarchique à deux niveaux — National et Local — avec des rôles précis, une authentification double facteur et une traçabilité totale de toutes les actions.

---

## 2. Vision, Mission et Ambition

### 2.1. Vision

Devenir la plateforme de livraison et d'expédition de référence en Côte d'Ivoire, en commençant par les villes moyennes, puis en s'étendant à tout le pays et à l'Afrique de l'Ouest francophone.

### 2.2. Mission

Permettre à chaque Ivoirien d'acheter tout ce dont il a besoin dans les commerces de sa ville et de l'expédier à un tiers en moins d'une heure, tout en créant des emplois locaux et en digitalisant le commerce de proximité.

### 2.3. Ambition

| Horizon | Objectif |
|---|---|
| 6 mois | Dominer le marché de la livraison et de l'expédition à Daloa |
| 18 mois | Ouvrir Bouaké, Yamoussoukro, Korhogo, San Pedro |
| 36 mois | Présent dans 10 villes ivoiriennes, 5 000 commandes/expéditions par jour |
| 60 mois | Expansion en Afrique de l'Ouest francophone |

---

## 3. Analyse du Marché de Daloa

### 3.1. Données clés

| Indicateur | Valeur |
|---|---|
| Population | ~500 000 habitants |
| Statut | 3ème ville de Côte d'Ivoire |
| Position stratégique | Carrefour du Centre-Ouest |
| Universités | Université Jean Lorougnon Guédé |
| Tissu économique | Commerce, agriculture, administration, transport |

### 3.2. Opportunités

| Opportunité | Détail |
|---|---|
| Marché vierge | Aucun acteur majeur de livraison présent à Daloa (Glovo et Yango restés à Abidjan) |
| Population jeune et connectée | Forte présence étudiante, utilisation massive des smartphones |
| Densité de commerces | Des centaines de restaurants, maquis, boutiques, pharmacies sans présence numérique |
| Mobile Money mature | Orange Money, MTN MoMo et Wave utilisés quotidiennement |
| Besoin d'expédition | Les habitants s'envoient régulièrement documents, clés, objets, colis |

### 3.3. Menaces

| Menace | Stratégie |
|---|---|
| Arrivée d'un concurrent international | Avantage du premier entrant, double service GoShop+GoColis, ancrage local fort |
| Faible pouvoir d'achat | Tarifs adaptés, promotions régulières, livraison gratuite le premier mois |
| Réticence au numérique | Formation présentielle obligatoire, application ultra-simple, ambassadeurs locaux |
| Coupures réseau fréquentes | Mode hors-ligne, synchronisation automatique, SMS de secours |

---

## 4. Objectifs Stratégiques

### 4.1. Indicateurs clés

| Objectif | 3 mois | 6 mois | 12 mois |
|---|---|---|---|
| Commerces actifs | 30 | 80 | 150 |
| Livreurs actifs | 15 | 40 | 80 |
| Clients inscrits | 500 | 2 000 | 6 000 |
| Commandes GoShop / jour | 10 | 40 | 120 |
| Expéditions GoColis / jour | 5 | 20 | 50 |
| Temps moyen de livraison | < 55 min | < 45 min | < 40 min |
| Taux de succès | 90% | 95% | 97% |
| Note moyenne plateforme | 4.0/5 | 4.3/5 | 4.5/5 |
| CA mensuel plateforme | 500 000 F CFA | 1 800 000 F CFA | 7 000 000 F CFA |

> **Note v5.0 :** Le temps de livraison au lancement est fixé à < 55 minutes (objectif opérationnel réaliste). L'objectif < 45 minutes est atteint à 6 mois. La communication externe mentionne "moins d'une heure" au lancement.

### 4.2. Phasage des services

| Phase | Service | Période |
|---|---|---|
| Phase 1 | GoShop — Lancement | Semaine 16 |
| Phase 2 | GoColis — Lancement | Semaine 22 |

---

## 5. Les Services GoLivreur

### 5.1. GoShop — Acheter et se faire livrer

**Principe :** Le client parcourt les commerces de Daloa disponibles dans l'application, achète des produits et se fait livrer là où il se trouve.

#### Catégories de commerces disponibles

| Catégorie | Exemples de produits |
|---|---|
| Restaurants | Plats cuisinés, menus du jour |
| Maquis | Poulet braisé, attiéké, alloco, garba |
| Fast-foods | Burgers, frites, pizzas, shawarmas |
| Pâtisseries | Gâteaux, viennoiseries, pain |
| Pharmacies | Médicaments, parapharmacie, produits de beauté |
| Boutiques / Épiceries | Riz, huile, sucre, savon, conserves, produits ménagers |
| Mode / Habillement | Vêtements, chaussures, accessoires |
| High-tech / Accessoires | Chargeurs, écouteurs, coques, piles |
| Cosmétiques / Beauté | Maquillage, soins, parfums |
| Cadeaux / Fleurs | Bouquets, cartes, objets cadeaux |
| Librairies / Papeterie | Livres, cahiers, stylos, fournitures scolaires |
| Maison / Décoration | Vaisselle, petits électroménagers, linge de maison |
| Animalerie | Croquettes, accessoires pour animaux |

#### Fonctionnement GoShop

1. Le client ouvre GoLivreur Client → Onglet GoShop
2. Il parcourt les commerces par catégorie ou recherche
3. Il consulte le catalogue (produits avec photos, descriptions, prix)
4. Il ajoute des produits au panier
5. Il saisit les instructions spéciales, choisit l'adresse de livraison
6. Il paie par Orange Money ou en espèces à la livraison
7. Il suit sa commande en temps réel sur la carte
8. Le livreur arrive, le client donne le code de confirmation
9. Il reçoit sa commande et évalue le service

### 5.2. GoColis — Expédier un colis à un tiers

**Principe :** Le client expédie un colis à une personne de son choix à Daloa. Le destinataire n'a pas besoin d'avoir l'application.

#### Tailles de colis acceptées

| Taille | Dimensions max | Poids max | Exemples |
|---|---|---|---|
| Petit | 20×15×10 cm | 1 kg | Enveloppe, documents, clés, téléphone |
| Moyen | 40×30×20 cm | 5 kg | Boîte à chaussures, vêtements, livres |
| Grand | 60×40×30 cm | 15 kg | Carton, petit électroménager |

#### Fonctionnement GoColis

1. L'expéditeur ouvre GoLivreur Client → Onglet GoColis
2. Il remplit : nom et téléphone du destinataire, adresse, description, taille, valeur déclarée
3. Le système calcule le tarif selon la taille et la distance
4. L'expéditeur paie (Orange Money ou espèces)
5. Un livreur accepte et se rend chez l'expéditeur
6. Le livreur prend une photo du colis (preuve horodatée et géolocalisée)
7. L'expéditeur donne un code de ramassage (4 chiffres)
8. Le livreur se rend chez le destinataire
9. Le destinataire reçoit un SMS automatique avec son propre code de réception
10. Le livreur arrive, le destinataire donne son code, le livreur le saisit
11. Le livreur prend une photo de remise
12. L'expéditeur reçoit une notification : « Colis livré à [Destinataire] »

#### Objets interdits GoColis

- Armes, explosifs, drogues, produits illicites
- Animaux vivants
- Produits périssables non emballés
- Argent liquide (utiliser Mobile Money)
- Tout objet que le livreur juge dangereux ou suspect (droit de refus)

#### Sécurité et litiges GoColis

- Photos obligatoires au ramassage et à la livraison
- Double code de vérification (expéditeur + destinataire)
- Assurance optionnelle pour les colis de valeur déclarée > 20 000 F CFA
- Avec assurance : remboursement de la valeur déclarée après enquête
- Sans assurance : remboursement limité à 2× les frais d'expédition

---

## 6. Description Détaillée des Acteurs

### 6.1. Le Client

- Habitant de Daloa, âgé de 18 à 45 ans
- Possède un smartphone Android (majoritaire) ou iOS
- Utilise Orange Money, MTN MoMo ou Wave
- Achète des repas, des courses, des médicaments, des vêtements, de l'électronique
- Expédie occasionnellement des colis à des proches

### 6.2. Le Commerce (Business)

- Restaurants, maquis, fast-foods, pharmacies, boutiques, épiceries, magasins de vêtements, high-tech, librairies, parfumeries, fleuristes
- Dispose d'un smartphone Android
- N'a pas de site web ni de système de vente en ligne
- Veut augmenter son chiffre d'affaires sans investissement lourd

### 6.3. Le Livreur (Driver)

- Homme ou femme de 20 à 40 ans
- Possède une moto personnelle (indispensable)
- Connaît parfaitement les rues et quartiers de Daloa
- Cherche un revenu principal ou complémentaire

### 6.4. L'Administration

L'administration de GoLivreur est structurée en deux niveaux : National et Local (Ville). Voir le chapitre 9 pour le détail complet.

> **Définition v5.0 — Admin Local :** Désigne collectivement le City Manager, le City Support et les City Agents de la ville concernée. La validation d'un commerce ou d'un livreur peut être effectuée par le City Support ou un City Agent, sous supervision du City Manager.

---

## 7. Les Trois Applications Mobiles

### 7.1. Vue d'ensemble

| N° | Nom officiel | Public cible | Services |
|---|---|---|---|
| 1 | GoLivreur Client | Clients (grand public) | GoShop + GoColis |
| 2 | GoLivreur Business | Commerces de tout type | GoShop (réception commandes) |
| 3 | GoLivreur Driver | Livreurs à moto | GoShop + GoColis |

### 7.2. GoLivreur Client

#### 7.2.1. Authentification et profil

| Fonctionnalité | Détail |
|---|---|
| Inscription | Par numéro de téléphone avec vérification SMS OTP |
| Connexion | Numéro de téléphone, Google ou Apple ID |
| Profil | Nom, prénom, photo, adresses favorites |
| Gestion des adresses | Plusieurs adresses enregistrées avec repères locaux |
| Préférences | Notifications on/off par type — Langue : français uniquement |

#### 7.2.2. GoShop — Acheter

| Fonctionnalité | Détail |
|---|---|
| Page d'accueil | Liste des commerces disponibles autour de soi |
| Catégories | 14 catégories |
| Recherche | Par nom de commerce, type de produit, mot-clé (SQL LIKE) |
| Filtres | Distance, note, frais de livraison, temps estimé |
| Catalogue | Produits avec photos, descriptions, prix |
| Panier | Récapitulatif, quantités ajustables — 1 panier = 1 commerce |
| Paiement | Orange Money ou Espèces à la livraison |
| Suivi en direct | Carte GPS, statuts, photo et nom du livreur, temps estimé |
| Code confirmation | 4 chiffres à donner au livreur |
| Évaluation | Noter le commerce et le livreur (1 à 5 étoiles), commentaire |
| Notifications in-app | Centre de notifications (30 dernières, lu/non lu) |
| Timeout | Message "Connexion lente" après 8 secondes |

#### 7.2.3. GoColis — Expédier

| Fonctionnalité | Détail |
|---|---|
| Créer une expédition | Nom et téléphone du destinataire, adresse, description, taille, valeur déclarée |
| Calcul du tarif | Automatique selon taille et distance |
| Paiement | Orange Money ou espèces |
| Suivi en direct | Statuts, position livreur, photos du colis |
| Code expéditeur | 4 chiffres à donner au livreur au ramassage |
| Notation livreur (v5) | L'expéditeur note le livreur après livraison confirmée (1 à 5 étoiles) |

### 7.3. GoLivreur Business

#### Réception des commandes

| Fonctionnalité | Détail |
|---|---|
| Alerte nouvelle commande | Notification sonore forte + vibration |
| Délai d'acceptation | 45 secondes pour accepter ou refuser |
| Refus automatique | Si pas de réponse → statut `cancelled` |
| Refus volontaire (v5) | Le commerce peut refuser → statut `refused` → client notifié immédiatement |
| Bouton Commande prête | Signale que la commande est emballée et attend le livreur |

#### Paramètres

| Fonctionnalité | Détail |
|---|---|
| Statut Ouvert/Fermé | Activer ou désactiver la réception de commandes |
| Horaires | Jours et heures d'ouverture configurables |
| Temps de préparation estimé | Affiché au client |

### 7.4. GoLivreur Driver

#### 7.4.1. Portefeuille virtuel

| Fonctionnalité | Détail |
|---|---|
| Solde minimum | 10 000 F CFA pour accepter les commandes en espèces |
| Rechargement | Par Mobile Money ou espèces au bureau |
| Retrait | Versement sur Mobile Money (seuil minimum 2 000 F CFA) |

#### 7.4.2. Disponibilité

| Fonctionnalité | Détail |
|---|---|
| Bouton En ligne / Hors ligne | Activer ou désactiver la réception de courses |
| GPS | Actif uniquement quand le livreur est En ligne — envoi toutes les 5s si déplacement > 10m |
| Pénalité anti-filtrage | 3 refus consécutifs = Hors ligne automatique 15 minutes |
| Taille colis visible (v5) | Pour GoColis, le livreur voit la taille avant d'accepter |

#### 7.4.3. Mode hors-ligne

| Fonctionnalité | Détail |
|---|---|
| Données locales | Courses stockées en SQLite sur le téléphone |
| Codes vérifiés localement | Validation sans connexion |
| Photos sauvegardées | Upload automatique au retour de connexion |
| Synchronisation | Automatique dès que la connexion revient |

#### 7.4.4. Sessions Admin par rôle (v5)

| Rôle | Durée de session (inactivité) |
|---|---|
| Super Admin, Admins Nationaux | 2 heures |
| City Manager, City Finance | 4 heures |
| City Support, City Agent | 8 heures (terrain) |

---

## 8. Règles Métier et Flux Financiers

### 8.1. Structure financière GoShop

| Élément | Définition | Qui fixe |
|---|---|---|
| Prix du produit | Montant de l'article | Le commerce |
| Frais de livraison | Coût de la course | La plateforme (barème) |
| Commission commerce | 15% du prix des produits (configurable) | La plateforme |
| Commission livreur | 20% des frais de livraison (configurable) | La plateforme |

**Exemple GoShop :**

| Acteur | Reçoit | Calcul |
|---|---|---|
| Achat : 4 000 F + Livraison 3km : 800 F = Total client : **4 800 F CFA** | | |
| Commerce | 3 400 F CFA | 4 000 - 15% |
| Livreur | 640 F CFA | 800 - 20% |
| Plateforme | **760 F CFA** | 600 + 160 |

### 8.2. Structure financière GoColis

| Élément | Définition |
|---|---|
| Frais d'expédition | Tarif selon taille + distance |
| Assurance | 3% de la valeur déclarée (si > 20 000 F CFA) |
| Commission plateforme | 25% des frais d'expédition |
| Gain livreur | 75% des frais d'expédition |

**Exemple GoColis — Colis Moyen, 4 km : 1 800 F CFA**

| Acteur | Reçoit |
|---|---|
| Livreur | 1 350 F CFA (75%) |
| Plateforme | 450 F CFA (25%) |

### 8.3. Barème des frais de livraison GoShop

| Distance | Tarif normal | Heure de pointe* | Mode Pluie |
|---|---|---|---|
| 0 - 2 km | 500 F CFA | 700 F CFA | 1 000 F CFA |
| 2 - 4 km | 800 F CFA | 1 000 F CFA | 1 300 F CFA |
| 4 - 7 km | 1 200 F CFA | 1 500 F CFA | 1 800 F CFA |
| > 7 km | 1 500 F CFA | 1 800 F CFA | 2 000 F CFA |

*Heures de pointe : 11h30-14h00 et 18h00-21h00*

### 8.4. Barème des frais d'expédition GoColis

| Taille | 0-2 km | + par km supplémentaire |
|---|---|---|
| Petit | 800 F CFA | 200 F CFA |
| Moyen | 1 200 F CFA | 300 F CFA |
| Grand | 2 000 F CFA | 400 F CFA |

### 8.5. Remboursements — Délégation par plafond (v5)

| Montant | Qui peut valider | Délai max |
|---|---|---|
| < 2 000 F CFA | City Support | 2 heures |
| 2 000 – 10 000 F CFA | City Finance | 4 heures |
| 10 000 – 50 000 F CFA | City Manager | 24 heures |
| > 50 000 F CFA | Super Admin | 48 heures |

### 8.6. Modes de paiement

| Mode | Lancement | 3 mois |
|---|---|---|
| Orange Money | ✅ Oui | ✅ Oui |
| Espèces à la livraison | ✅ Oui | ✅ Oui |
| MTN Mobile Money (MoMo) | ❌ Non | ✅ Oui |
| Wave | ❌ Non | ✅ Oui |

### 8.7. Versements aux commerces

| Option | Fréquence | Délai | Frais | Disponibilité |
|---|---|---|---|---|
| Standard | Mardi et vendredi | J+1 à J+3 | Gratuit | Dès le lancement |
| Instantané | Après chaque commande | Immédiat | 1% (min. 50 F CFA) | À 3 mois |

---

## 9. Architecture Administrative Complète

### 9.1. Rôles et définitions

| N° | Rôle | Code | Niveau | Qui nomme |
|---|---|---|---|---|
| 1 | Super Admin | `super_admin` | Global | Fondateur |
| 2 | Directeur Général National | `national_dg` | National | Super Admin |
| 3 | Responsable Finances National | `national_finance` | National | Super Admin |
| 4 | Responsable Technique National | `national_tech` | National | Super Admin |
| 5 | Support National | `national_support` | National | Super Admin |
| 6 | Manager de Ville | `city_manager` | Ville | National DG |
| 7 | Admin Financier Local | `city_finance` | Ville | National Finance |
| 8 | Admin Support Local | `city_support` | Ville | City Manager |
| 9 | Agent de Terrain | `city_agent` | Ville | City Manager |

### 9.2. Authentification des Admins

| Règle | Détail |
|---|---|
| Méthode | Email professionnel + Mot de passe |
| Double facteur | Obligatoire pour tous — SMS OTP 6 chiffres, valable 5 minutes |
| Tentatives max | 5 échecs = blocage temporaire 30 minutes |
| IP tracking | Enregistrée à chaque connexion — alerte si nouvel appareil |
| Multi-appareils | Session unique stricte — nouvelle connexion invalide la précédente |

### 9.3. Équipe de lancement Daloa

| Rôle | Nombre | Responsabilités |
|---|---|---|
| Super Admin | 1 (Francis EBOAH) | Tout, validation finale, création des autres Admins |
| City Manager Daloa | 1 | Pilote toute l'opération, recrutement, qualité |
| City Support Daloa | 1 | Litiges, appels, validation manuelle, remboursements < 2 000 F CFA |
| City Agent Daloa | 2 | Terrain : recrutement commerces, formation livreurs |

### 9.4. Traçabilité totale — Audit Logs

Chaque action sensible d'un Admin est enregistrée de manière immuable dans `audit_logs`.

- Aucune suppression de log possible, même par le Super Admin
- Les logs `critical` déclenchent une alerte immédiate par email
- Masquage des données sensibles : téléphone `07****89`, OTP jamais loggé, JWT jamais loggé

---

## 10. Interface Admin Web

### 10.1. Technologie

Laravel Filament 3, dans le même projet que l'API Laravel.

### 10.2. Dashboard City Manager — Métriques

- Commandes du jour (GoShop + GoColis) en temps réel
- Chiffre d'affaires du jour / semaine / mois
- Livreurs en ligne en ce moment
- Commerces ouverts en ce moment
- Taux de succès des livraisons
- Commandes en attente de livreur (statut `waiting`)
- Litiges ouverts (alerte rouge si > 5)
- Validations en attente (commerces et livreurs)
- Temps moyen de livraison du jour

### 10.3. Widget Horizon dans Filament

Compteur jobs en attente/échec visible directement dans le dashboard Filament avec bouton "Ouvrir Horizon". Accès Horizon : `super_admin` et `national_tech` uniquement.

### 10.4. Mode Pluie — Semi-automatisé (v5)

- **Manuel** : par un Admin habilité (City Manager ou supérieur)
- **Automatique** : via API météo OpenWeatherMap — si pluie détectée à Daloa → activation automatique + notification push clients (interrogation toutes les 15 minutes)
- **Désactivation** : toujours manuelle pour éviter les faux positifs

### 10.5. Mode Urgence — Spécification fonctionnelle (v5)

Quand activé par Super Admin ou National DG :
- Toutes les nouvelles commandes et expéditions sont suspendues
- Les commandes en cours continuent jusqu'à livraison
- Bandeau rouge dans les 3 apps avec message configurable
- Les livreurs reçoivent une notification push immédiate
- Désactivation manuelle uniquement par Super Admin

---

## 11. Spécifications Techniques

### 11.1. Stack technologique

| Composant | Technologie | Version |
|---|---|---|
| Applications mobiles (3) | Flutter (Dart) | 3.x |
| State management | Riverpod | Dernière |
| Backend API | Laravel | 11.x |
| Base de données | PostgreSQL | 16 |
| Cache / Files d'attente | Redis | 7.x |
| WebSocket (temps réel) | Laravel Reverb | 1.x |
| Admin Web | Laravel Filament | 3.x |
| Cartographie | Google Maps | Dernière |
| Push notifications | Firebase Cloud Messaging | Dernière |
| SMS | Twilio / Orange API | — |
| Paiement Mobile Money | Hub2 ou CinetPay | — |
| Stockage photos | Amazon S3 / DigitalOcean Spaces | — |
| API Météo (v5) | OpenWeatherMap | Dernière |
| CI/CD | GitHub Actions + Fastlane | Dernière |
| Hébergement | VPS (8 Go RAM, 4 CPU) | — |
| Monitoring erreurs | Sentry (plan gratuit) | — |
| Monitoring queues | Laravel Horizon | — |
| Crashlytics | Firebase Crashlytics (3 apps) | — |

### 11.2. Architecture

- **Monorepo** : packages/core + 3 apps Flutter + backend Laravel
- **Architecture Laravel** : MVC + Services, modules isolés `Modules/GoShop` et `Modules/GoColis` dès S3
- **Multi-tenancy** : colonne `city_id` unique — nouvelle ville = nouvelle ligne dans `cities`
- **Auth** : JWT stateless — `/api/auth/refresh` prévu
- **Vitrine** : Blade simple + Bootstrap 5

### 11.3. Décisions techniques (ADR)

| Décision | Choix retenu | Raison |
|---|---|---|
| Auth API | JWT | Apps mobiles stateless |
| Multi-tenancy | city_id colonne | Simple, extensible sans BDD distincte |
| Recherche | SQL LIKE PostgreSQL | Suffisant à 150 commerces — Scout/Meilisearch en v2 |
| Architecture | MVC + Services | Équipe réduite, planning serré |
| Microservices | Non — monolithe | Adapté jusqu'à 10 villes |
| Framework tests | Pest | Syntaxe moderne, natif Laravel |

### 11.4. Sécurité

| Règle | Détail |
|---|---|
| OTP | Hashé bcrypt, durée 5 min, supprimé après validation |
| Codes confirmation | Hashés en base |
| Téléphones | Stockés en clair, accès BDD restreint |
| JWT | Jamais stocké en base |
| Rate limiting OTP | 3 tentatives / 10 min → blocage 1h |
| Rate limiting API | 60 req/min (auth) · 20 req/min (public) |
| HMAC webhooks | Validation obligatoire et bloquante |
| Secrets | Bitwarden · `.env` prod serveur uniquement |
| Conformité | Bonnes pratiques ARTCI documentées · Certification phase 2 |

### 11.5. API Endpoints principaux

| Méthode | Endpoint | Usage |
|---|---|---|
| POST | `/api/auth/register` | Inscription client |
| POST | `/api/auth/login` | Connexion |
| POST | `/api/auth/verify-otp` | Vérification SMS |
| POST | `/api/auth/refresh` | Renouvellement token JWT |
| GET | `/api/merchants` | Liste commerces |
| POST | `/api/orders` | Créer une commande |
| POST | `/api/orders/{id}/verify-code` | Livreur valide code client GoShop |
| PATCH | `/api/orders/{id}/status` | Mettre à jour le statut |
| POST | `/api/parcels` | Créer une expédition |
| POST | `/api/parcels/{id}/verify-sender-code` | Livreur valide code expéditeur |
| POST | `/api/parcels/{id}/verify-recipient-code` | Livreur valide code destinataire |
| POST | `/api/parcels/{id}/upload-photo` | Upload photo |
| POST | `/api/courier/location` | Mise à jour GPS (toutes les 5s si > 10m) |
| POST | `/api/orders/{id}/unlock-code` | Déblocage après 3 erreurs (Support) |
| GET | `/api/wallet/balance` | Solde portefeuille |
| POST | `/api/wallet/recharge` | Recharger |
| POST | `/api/wallet/withdraw` | Retirer |

### 11.6. Base de données — Tables principales

```
cities · users · otp_codes · merchants · couriers
products · product_options · orders · order_items
parcels · parcel_photos · wallets · wallet_transactions
payouts · ratings · notifications · admins
audit_logs · webhook_logs · admin_sessions
```

**Soft delete activé sur :** `users`, `merchants`, `products`, `couriers`

**Jamais supprimé :** `orders`, `parcels`, `wallets`, `wallet_transactions`, `audit_logs`

**Contrainte wallets :** `user_type` IN ('merchant', 'courier') — les clients n'ont pas de portefeuille

**Ratings — types :** `goshop_merchant`, `goshop_courier`, `gocolis_courier`

### 11.7. Queues Redis

| Queue | Usage |
|---|---|
| `high` | Paiements webhooks Hub2/CinetPay |
| `default` | Notifications FCM, SMS OTP |
| `low` | Mises à jour GPS |

---

## 12. Parcours Utilisateurs Détaillés

### 12.1. Parcours Client — GoShop

| Étape | Action | Écran |
|---|---|---|
| 1 | Ouvre GoLivreur Client | Accueil |
| 2 | Appuie sur l'onglet GoShop | Liste des commerces |
| 3 | Sélectionne une catégorie ou recherche | Résultats |
| 4 | Sélectionne un commerce | Page commerce |
| 5 | Ajoute des produits au panier | Catalogue |
| 6 | Vérifie le panier | Panier |
| 7 | Saisit les instructions spéciales | Panier |
| 8 | Choisit le mode de paiement et valide | Paiement |
| 9 | Suit la commande en temps réel | Suivi GPS |
| 10 | Donne le code au livreur | Livraison |
| 11 | Note le commerce et le livreur | Évaluation |

### 12.2. Parcours Client — GoColis

| Étape | Action |
|---|---|
| 1 | Ouvre GoLivreur Client → Onglet GoColis |
| 2 | Remplit le formulaire (destinataire, adresse, taille) |
| 3 | Tarif calculé automatiquement |
| 4 | Paie par Orange Money |
| 5 | Suit l'expédition en temps réel |
| 6 | Reçoit notification "Colis livré" |

### 12.3. Parcours Livreur — Course GoColis

| Étape | Action |
|---|---|
| 1 | Reçoit notification avec taille du colis visible |
| 2 | Accepte dans les 30 secondes |
| 3 | Navigation GPS vers l'expéditeur |
| 4 | Prend photo du colis |
| 5 | Saisit le code expéditeur (4 chiffres) |
| 6 | Navigation GPS vers le destinataire |
| 7 | Le destinataire donne son code SMS |
| 8 | Prend photo de remise |
| 9 | Colis livré, gain crédité |

---

## 13. Planning d'Exécution

### Phase 0 — Préparation (S1-S2)

> ⚠️ **PRIORITÉ ABSOLUE S1 :** Ouverture compte marchand Hub2/CinetPay — délai administratif 2 à 4 semaines incompressible.

| Tâche | Responsable |
|---|---|
| **Ouverture compte marchand Hub2/CinetPay** | **Fondateur (S1 obligatoire)** |
| Création comptes développeur Google Play / App Store | Fondateur |
| Achat nom de domaine golivreur.ci + emails professionnels | Fondateur |
| Configuration serveur de production | Lead Dev |
| Initialisation monorepo Flutter + Laravel | Lead Dev |
| Recrutement de 5 commerces pilotes à Daloa | Équipe terrain |
| Recrutement de 10 livreurs pilotes à Daloa | Équipe terrain |
| Location et aménagement bureau local Daloa | Fondateur |

### Phase 1 — Développement MVP (S3-S10)

| Semaine | Module | Livrables |
|---|---|---|
| S3-S4 | Authentification + Profils | API Auth, écrans inscription/connexion 3 apps |
| S5 | Module Business | Catalogue produits, réception commandes, statut `refused` |
| **S5 (contrainte critique)** | **Contrats API GoColis figés** | **Bruno + Scramble — mock prêt pour le renfort S7** |
| S6 | Mock GoColis | JSON statiques pour le renfort Flutter |
| S7-S8 | Module Client GoShop | Browse, panier, commande, suivi GPS |
| S9 | Module Driver GoShop | Courses, exécution, wallet, GPS location endpoint |
| S10 | Admin de base | Dashboard, gestion utilisateurs, délégation remboursements |

### Phase 2 — Paiement et notifications (S11-S12)

- Intégration Hub2/CinetPay (tests sandbox lancés en S9)
- Système de portefeuille et transactions
- Push notifications FCM
- SMS OTP avec compteur 2 500/mois + alerte email
- Tests de flux financiers complets

### Phase 3 — Tests et QA (S13-S14)

- Tests Pest : 70% global / 90% sur paiements, codes, permissions, commissions
- Tests k6 : 3 scénarios (100 commandes, 50 GPS, 20 paiements simultanés) — seuil 70% CPU
- Testeur QA dédié S13-S14
- Tests utilisateurs pilotes à Daloa
- Démo fonctionnelle sur téléphone du Fondateur

### Phase 4 — Déploiement GoShop (S15-S16)

- Publication Google Play Store + Apple App Store
- Formation présentielle des 5 commerces pilotes
- Formation présentielle des 10 livreurs pilotes
- **LANCEMENT OFFICIEL GoShop — DALOA**
- Campagne marketing locale

### Phase 5 — GoColis (S17-S22)

| Semaine | Module | Livrables |
|---|---|---|
| S17-S18 | Module Client GoColis | Création expédition, suivi, historique |
| S19 | Module Driver GoColis | Photos, double code, navigation |
| S20 | Admin GoColis | Gestion expéditions, litiges, photos |
| S21 | Tests et pilotes | Tests avec livreurs de confiance |
| S22 | **LANCEMENT OFFICIEL GoColis** | Campagne marketing |

### Definition of Done (par sprint)

- Code revu et mergé sur `develop`
- Tests Pest passants (couverture cible atteinte)
- `flutter analyze` sans erreur
- Larastan niveau 5 passant
- Déployé sur staging et testé manuellement
- Documentation mise à jour (OpenAPI + changelog si breaking change)
- **Démo fonctionnelle sur téléphone du Fondateur**
- **Zéro régression sur les modules déjà livrés**

---

## 14. Budget Prévisionnel v5

### 14.1. Développement

| Poste | Coût |
|---|---|
| Développeur Flutter senior (3 mois) | 3 000 000 F CFA |
| Développeur Flutter renfort GoColis (1,5 mois) | 1 500 000 F CFA |
| Développeur Laravel senior (3 mois) | 2 500 000 F CFA |
| Designer UI/UX (1 mois) | 500 000 F CFA |
| Testeur QA (2 semaines S13-S14) — nouveau v5 | 300 000 F CFA |
| Testeurs utilisateurs pilotes (indemnités) — nouveau v5 | 100 000 F CFA |
| **Sous-total développement** | **7 900 000 F CFA** |

### 14.2. Infrastructure (annuel)

| Poste | Coût |
|---|---|
| Serveur VPS (8 Go RAM, 4 CPU) | 600 000 F CFA |
| Nom de domaine golivreur.ci | 15 000 F CFA |
| Google Maps API | 150 000 F CFA |
| Stockage photos (S3 / DO Spaces) | 100 000 F CFA |
| Firebase | 0 F CFA (gratuit sous quota) |
| Compte développeur Google Play | 25 000 F CFA |
| Compte développeur Apple (annuel) | 50 000 F CFA |
| **Sous-total infrastructure** | **940 000 F CFA** |

### 14.3. SMS (annuel) — Nouveau v5

| Poste | Coût mensuel |
|---|---|
| SMS OTP inscriptions | 30 000 F CFA |
| SMS code destinataire GoColis | 20 000 F CFA |
| SMS de secours hors-ligne | 15 000 F CFA |
| **Total mensuel** | **65 000 F CFA** |
| **Total annuel** | **780 000 F CFA** |

> Alerte automatique email si > 2 500 SMS/mois. Budget v2 anticipé : 120 000 F CFA/mois.

### 14.4. Opérations lancement

| Poste | Coût |
|---|---|
| Location bureau Daloa (6 mois) | 600 000 F CFA |
| Campagne marketing (flyers, réseaux sociaux, radio) | 750 000 F CFA |
| Équipement livreurs (20 chasubles, 20 sacs isothermes) | 300 000 F CFA |
| Formation présentielle livreurs et commerces | 150 000 F CFA |
| Imprévus (10%) | 340 000 F CFA |
| **Sous-total opérations** | **2 140 000 F CFA** |

### 14.5. Budget total v5

| Catégorie | Montant |
|---|---|
| Développement | 7 900 000 F CFA |
| Infrastructure (1 an) | 940 000 F CFA |
| SMS (1 an) — nouveau v5 | 780 000 F CFA |
| Opérations lancement | 2 140 000 F CFA |
| **TOTAL** | **11 760 000 F CFA** |

---

## 15. Critères de Réussite

| Indicateur | 3 mois | 6 mois | 12 mois |
|---|---|---|---|
| Commerces actifs | 30 | 80 | 150 |
| Livreurs actifs | 15 | 40 | 80 |
| Clients inscrits | 500 | 2 000 | 6 000 |
| Commandes GoShop / jour | 10 | 40 | 120 |
| Expéditions GoColis / jour | 5 | 20 | 50 |
| Temps livraison moyen | < 55 min ⚠️ | < 45 min ✅ | < 40 min ✅ |
| Taux de succès | 90% | 95% | 97% |
| Note moyenne plateforme | 4.0/5 | 4.3/5 | 4.5/5 |
| CA mensuel plateforme | 500 000 F CFA | 1 800 000 F CFA | 7 000 000 F CFA |

---

## 16. Risques et Stratégies de Mitigation

| Risque | Probabilité | Impact | Stratégie |
|---|---|---|---|
| Faible adoption clients | Moyenne | Élevé | Campagne marketing, codes promo, livraison gratuite premier mois |
| Commerces réfractaires | Élevée | Moyen | Onboarding présentiel, app simplifiée, période d'essai gratuite |
| Pénurie de livreurs fiables | Moyenne | Élevé | Incitations financières, chasubles offertes, formation de qualité |
| Fraude (fausses commandes, vol) | Élevée | Élevé | Portefeuille prépayé, double code, photos preuves, vérification stricte |
| Coupures réseau/DATA | Élevée | Faible | Mode offline SQLite, synchronisation auto, SMS de secours |
| Panne paiement Mobile Money | Moyenne | Élevé | Espèces maintenues, 2ème agrégateur en backup |
| Litiges GoColis (perte, casse) | Moyenne | Élevé | Photos preuves, assurance, délégation remboursements par plafond |
| Concurrence soudaine | **Moyenne (v5)** | Élevé | Avantage premier entrant, double service, ancrage communautaire fort |

---

## Annexes

### Annexe A — Statuts de commande GoShop (v5)

| Statut | Signification |
|---|---|
| `pending` | Commande créée, en attente du commerce |
| `accepted` | Commerce a accepté, préparation en cours |
| `refused` | **(v5)** Commerce a refusé volontairement — client notifié immédiatement |
| `ready` | Commande prête, en attente de ramassage |
| `picked_up` | Livreur a récupéré la commande |
| `in_transit` | En cours de livraison |
| `delivered` | Livrée avec succès ✅ |
| `cancelled` | Annulée (timeout commerce ou annulation Admin) |

### Annexe B — Statuts d'expédition GoColis (v5)

| Statut | Signification |
|---|---|
| `pending` | Expédition créée, recherche livreur |
| `waiting` | **(v5)** Aucun livreur disponible après 3 tentatives — alerte Admin |
| `accepted` | Livreur accepté |
| `at_sender` | Livreur chez l'expéditeur |
| `picked_up` | Colis récupéré (photo + code expéditeur validés) |
| `in_transit` | En cours de livraison |
| `at_recipient` | Livreur chez le destinataire |
| `delivered` | Livré avec succès ✅ |
| `refused` | **(v5)** Refusé par le livreur (colis suspect ou interdit) |
| `cancelled` | Annulée |

### Annexe C — Algorithme d'attribution des courses

1. Proximité : course proposée au livreur disponible le plus proche du point de ramassage
2. Pour GoColis : la taille du colis est affichée au livreur avant acceptation **(v5)**
3. Si refus ou expiration des 30 secondes, proposée au 2ème plus proche
4. Si après 3 tentatives aucun livreur n'accepte : statut `waiting` + alerte Admin + notification client

### Annexe D — Codes de confirmation

| Service | Codes | Détail |
|---|---|---|
| GoShop | 1 code client | 4 chiffres, généré à la commande, affiché dans l'app client |
| GoColis | 2 codes | Code expéditeur (app) + Code destinataire (SMS) |

> 3 saisies erronées = blocage + alerte Admin. Déblocage **(v5)** : City Support contacte le client par appel vocal et débloque manuellement.

### Annexe E — Gestion des cas d'échec (v5)

| Cas | Déclencheur | Action système | Action Admin |
|---|---|---|---|
| Commerce ne répond pas (45s) | Timeout | Annulation + notif client | Aucune |
| Commerce refuse | Bouton Refuser | Statut `refused` + notif client | Aucune |
| Livreur introuvable (3 tentatives) | Algorithme | Statut `waiting` + alerte | Réassigner manuellement |
| Code erroné 3 fois | Compteur | Blocage + alerte Admin | Déblocage vocal (City Support) |
| Téléphone livreur hors service | Signalement | Mode offline activé | Validation manuelle Admin |
| Paiement Mobile Money échoué | Hub2/CinetPay | Commande non créée + notif | Aucune |

### Annexe F — Astreinte opérationnelle (v5)

| Période | Horaires | Responsable |
|---|---|---|
| Lundi – Vendredi | 07h00 – 22h00 | City Support (escalade City Manager) |
| Samedi – Dimanche | 08h00 – 23h00 | City Support (escalade City Manager) |
| Canal de contact | WhatsApp Business dédié | Livreurs et commerces uniquement |

### Annexe G — Charte graphique et UX

| Élément | Valeur |
|---|---|
| Couleur primaire | Orange `#F4A623` |
| Couleur secondaire | Vert foncé `#1B5E20` |
| Fond | Blanc cassé `#FAFAFA` |
| Texte principal | Gris très foncé `#212121` |
| Erreur | Rouge `#D32F2F` |
| Succès | Vert clair `#4CAF50` |
| Police | Inter |
| Style | Minimaliste — optimisé Tecno/Infinix entrée de gamme |
| Boutons | 52px minimum — larges et faciles à taper |
| Timeout API | 8 secondes → message "Connexion lente" + option annulation |

### Annexe H — Glossaire

| Terme | Définition |
|---|---|
| GoLivreur | La plateforme complète |
| GoShop | Service d'achat et livraison depuis les commerces locaux |
| GoColis | Service d'expédition de colis entre particuliers |
| GoLivreur Client | Application mobile pour les clients |
| GoLivreur Business | Application mobile pour les commerces |
| GoLivreur Driver | Application mobile pour les livreurs |
| Admin Local (v5) | Désigne collectivement City Manager, City Support et City Agents d'une ville |
| City Context | Filtre automatique limitant les Admins à leur ville assignée |
| Portefeuille virtuel | Compte interne à la plateforme (merchant ou courier uniquement) |
| Mode Pluie | Majoration des frais en cas de pluie (manuel ou auto via OpenWeatherMap) |
| Mode Urgence | Suspension de toute nouvelle commande + bandeau rouge dans les apps |

---

## Signatures et Approbation

Ce cahier des charges version 5.0 est approuvé pour exécution.

| Rôle | Nom | Date | Signature |
|---|---|---|---|
| Fondateur | Francis EBOAH | 24/06/2026 | ___________ |
| Lead Developer | ___________ | ___/2026 | ___________ |
| City Manager Daloa | ___________ | ___/2026 | ___________ |

---

*GoLivreur — Daloa bouge, on livre !*

*GoShop pour tous vos achats. GoColis pour toutes vos expéditions.*

*Une plateforme, trois applications — moins d'une heure au lancement.*
