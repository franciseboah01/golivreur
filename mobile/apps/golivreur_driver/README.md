# GoLivreur Driver — Guide de lancement

**Application** : GoLivreur Driver (iOS + Android)  
**Public** : Livreurs à moto  
**Spécificités** : GPS temps réel, double code GoColis, mode offline SQLite

---

## Installation

```bash
# Étape 1 — Package core (obligatoire en premier)
cd packages/core && flutter pub get && cd ../..

# Étape 2 — App Driver
cd apps/golivreur_driver
flutter pub get

# Étape 3 — Configurer env.dart (voir section Variables ci-dessous)
# Étape 4 — Lancer
flutter run
```

---

## Variables d'environnement — lib/config/env_driver.dart

```dart
// Remplacer toutes les valeurs CHANGE_ME :

static const String apiBaseUrl     = 'CHANGE_ME'; // http://10.0.2.2:8000/api/v1
static const String reverbHost     = 'CHANGE_ME'; // 10.0.2.2
static const String reverbAppKey   = 'CHANGE_ME'; // Depuis .env Laravel
static const String googleMapsKey  = 'CHANGE_ME'; // Depuis Bitwarden > Google Maps
```

---

## Compte de test Driver

```
Livreur 1 : Téléphone 0703000001 (Ouattara Seydou — déjà en ligne)
Livreur 2 : Téléphone 0703000002 (Coulibaly Issouf)
```

---

## Mode Offline SQLite — Comment ça fonctionne

> Cette section est commentée en détail car le mode offline est la partie la plus délicate.

### Principe

Quand le livreur perd sa connexion data (fréquent à Daloa), l'app :
1. Détecte la perte de connexion via `connectivity_plus`
2. Affiche une bannière orange "Mode hors-ligne"
3. Continue à enregistrer les positions GPS en local (SQLite)
4. Synchronise automatiquement quand la connexion revient

### Fichier principal : `lib/offline/offline_sync_service.dart`

```dart
// Ce service s'occupe de :
// 1. Créer la table SQLite locale 'gps_queue'
// 2. Stocker les positions GPS non envoyées
// 3. Surveiller la connexion en arrière-plan
// 4. Vider la queue SQLite vers l'API quand la connexion revient
//
// IMPORTANT : Ne pas modifier l'ordre des opérations dans syncQueue()
// Une position GPS mal synchronisée peut décaler le suivi du client.
```

### Structure de la table SQLite locale

```sql
CREATE TABLE gps_queue (
  id       INTEGER PRIMARY KEY AUTOINCREMENT,
  lat      REAL NOT NULL,
  lng      REAL NOT NULL,
  ts       INTEGER NOT NULL,  -- timestamp Unix
  synced   INTEGER DEFAULT 0  -- 0 = en attente, 1 = envoyé
);
```

### Tester le mode offline

```bash
# Sur émulateur Android :
# 1. Lancer l'app Driver avec un compte livreur connecté
# 2. Activer le mode "Offline" dans les paramètres de l'émulateur
#    (Extended Controls > Cellular > Network type: None)
# 3. La bannière "Mode hors-ligne" doit apparaître en 2-3 secondes
# 4. Se déplacer (simuler des coordonnées GPS)
# 5. Réactiver le réseau → les positions doivent se synchroniser
```

---

## Double code GoColis — Flux Driver

Le diagramme complet est dans :
`documentation/02_architecture/02b_diagramme_sequence_metier.md`

En résumé pour le Driver :

```
1. Recevoir notification (TAILLE du colis visible avant acceptation)
2. Accepter dans les 30 secondes
3. Naviguer vers l'expéditeur
4. Appuyer "Je suis chez l'expéditeur"
5. Prendre une PHOTO du colis (obligatoire)
6. Saisir le code expéditeur (4 chiffres donnés verbalement)
   → 3 erreurs = blocage + alerte Admin
7. Naviguer vers le destinataire
8. Appuyer "Je suis chez le destinataire"
9. Prendre une PHOTO de remise (obligatoire)
10. Saisir le code destinataire (4 chiffres reçu par SMS)
    → 3 erreurs = blocage + alerte Admin
11. Livraison confirmée → gain crédité sur wallet
```

**Règle absolue** : La photo DOIT être prise avant de pouvoir saisir le code.  
Si l'app refuse de valider le code, vérifiez que la photo a bien été uploadée.

---

## Pénalité anti-filtrage

```
Refus 1 : Avertissement affiché
Refus 2 : Deuxième avertissement
Refus 3 : Mise hors-ligne automatique pendant 15 minutes
```

L'app affiche un compte à rebours pendant la période de pénalité.

---

## Résolution des problèmes

### Le GPS ne se met pas à jour
```bash
# Sur émulateur Android — simuler un déplacement GPS :
# Extended Controls > Location > saisir de nouvelles coordonnées
# Ou utiliser le GPX Playback pour simuler un trajet
```

### "Photo obligatoire" alors que j'ai bien pris la photo
```bash
# Vérifier que le backend est accessible (l'upload photo nécessite le réseau)
# En mode offline, l'upload photo est mis en queue et synchronisé ensuite
# Consultez les logs : flutter logs | grep "photo"
```

### WebSocket Reverb ne se connecte pas
```bash
# Vérifier que Laravel Reverb tourne :
docker compose exec golivreur_app php artisan reverb:start

# Vérifier le port 8080 ouvert sur votre firewall local
# Sur émulateur : remplacer localhost par 10.0.2.2 dans env_driver.dart
```
