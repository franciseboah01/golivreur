# GoLivreur Business — Guide de lancement

**Application** : GoLivreur Business (iOS + Android)  
**Public** : Commerçants partenaires (restaurants, pharmacies, boutiques...)

---

## Installation

```bash
# Package core en premier
cd packages/core && flutter pub get && cd ../..

# App Business
cd apps/golivreur_business
flutter pub get
flutter run
```

---

## Variables d'environnement — lib/config/env_business.dart

```dart
static const String apiBaseUrl = 'CHANGE_ME'; // http://10.0.2.2:8000/api/v1
```

---

## Compte de test Commerce

```
Commerce 1 : Téléphone 0702010001 (Maquis Chez Tantie Adjoua)
Commerce 2 : Téléphone 0702020001 (Restaurant Le Carrefour)
Commerce 3 : Téléphone 0702030001 (Fast Food Speed Burger)
```

---

## Fonctionnalités principales

### Réception des commandes
- Alerte sonore forte à chaque nouvelle commande (son `new_order.mp3`)
- Compte à rebours visible de 45 secondes
- Boutons larges "Accepter" (vert) et "Refuser" (rouge)

### Gestion du catalogue
- Ajouter/modifier/supprimer des produits
- Activer/désactiver la disponibilité d'un produit (rupture de stock)
- Réorganiser l'ordre d'affichage

### Statut ouvert/fermé
- Bouton dans l'AppBar pour ouvrir/fermer le commerce
- Quand fermé : aucune commande n'arrive

### Statistiques
- Chiffre d'affaires du jour/semaine/mois
- Nombre de commandes
- Produits les plus commandés

---

## Son d'alerte nouvelle commande

Le son `assets/sounds/new_order.mp3` doit être un son fort et distinctif.  
En développement, vous pouvez utiliser n'importe quel fichier `.mp3` renommé en `new_order.mp3`.

```bash
# Tester que le son fonctionne
# Créer une commande de test via l'API :
curl -X POST http://localhost:8000/api/v1/client/orders \
  -H "Authorization: Bearer [TOKEN_CLIENT]" \
  -H "Content-Type: application/json" \
  -d '{"merchant_id": 1, "items": [{"product_id": 1, "quantity": 1}], ...}'
```
