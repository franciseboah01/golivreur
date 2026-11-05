# GoLivreur Client — Guide de lancement

**Application** : GoLivreur Client (iOS + Android)  
**Public** : Clients (achats GoShop + expéditions GoColis)  
**Temps de setup** : ~20 minutes

---

## Prérequis

Vérifiez que ces outils sont installés sur votre machine :

```bash
flutter --version   # Doit afficher Flutter 3.19.0 ou supérieur
dart --version      # Doit afficher Dart 3.3.0 ou supérieur
```

Si Flutter n'est pas installé : https://docs.flutter.dev/get-started/install

Vérifiez qu'un émulateur ou un téléphone est connecté :

```bash
flutter devices
# Doit lister au moins un appareil (émulateur Android ou iPhone simulateur ou téléphone physique)
```

---

## Installation — Étape par étape

### Étape 1 — Installer le package core (obligatoire en premier)

```bash
# Se placer dans le package core
cd packages/core

# Télécharger les dépendances
flutter pub get

# Vérifier qu'il n'y a pas d'erreur
flutter analyze
```

### Étape 2 — Installer les dépendances de l'app Client

```bash
# Revenir à la racine et aller dans l'app client
cd ../../apps/golivreur_client

flutter pub get
```

### Étape 3 — Configurer les variables d'environnement

```bash
# Ouvrir le fichier de configuration
# Sur Mac : open lib/config/env.dart
# Sur Windows : notepad lib\config\env.dart

# Remplacer toutes les valeurs CHANGE_ME :
#   apiBaseUrl        → URL de votre API (ex: http://10.0.2.2:8000/api/v1 pour émulateur Android)
#   reverbHost        → Hôte WebSocket (ex: 10.0.2.2 pour émulateur Android)
#   reverbAppKey      → Valeur REVERB_APP_KEY du fichier .env Laravel
#   googleMapsKeyAndroid → Clé Google Maps Android (dans Bitwarden)
#   googleMapsKeyIOS     → Clé Google Maps iOS (dans Bitwarden)
```

> ⚠️ **URL API selon votre appareil :**
> - Émulateur Android → `http://10.0.2.2:8000/api/v1`
> - Simulateur iOS   → `http://localhost:8000/api/v1`
> - Téléphone physique → IP de votre machine sur le réseau local (ex: `http://192.168.1.10:8000/api/v1`)

### Étape 4 — Configurer Firebase (optionnel en développement local)

```bash
# Si vous souhaitez tester les push notifications :
# 1. Télécharger google-services.json depuis Bitwarden > GoLivreur > Firebase
# 2. Le placer dans : android/app/google-services.json
# 3. Télécharger GoogleService-Info.plist
# 4. Le placer dans : ios/Runner/GoogleService-Info.plist
#
# Sans ces fichiers, l'app fonctionne mais sans notifications push.
```

### Étape 5 — Lancer l'application

```bash
# Lancer sur l'émulateur/téléphone par défaut
flutter run

# Lancer sur un appareil spécifique (si plusieurs appareils connectés)
flutter devices                    # Lister les appareils disponibles
flutter run -d emulator-5554       # Android émulateur
flutter run -d iPhone_15_Pro       # iOS simulateur

# Mode release (plus proche des performances production)
flutter run --release
```

---

## Comptes de test (après avoir lancé le seeder Laravel)

```
Client 1 : Téléphone 0701000001 → OTP reçu dans les logs Laravel
Client 2 : Téléphone 0701000002 → OTP reçu dans les logs Laravel
```

Pour voir les OTP en développement local (sans vrai SMS) :

```bash
# Dans le terminal Laravel (Docker)
docker compose exec golivreur_app php artisan tinker
>>> App\Models\OtpCode::latest()->first()
```

---

## Commandes utiles

```bash
# Générer les fichiers de code (Riverpod + Freezed)
flutter pub run build_runner build --delete-conflicting-outputs

# Analyser le code (doit retourner 0 erreur)
flutter analyze

# Lancer les tests
flutter test

# Voir les logs en temps réel
flutter logs

# Nettoyer le build (si erreurs bizarres)
flutter clean && flutter pub get && flutter run
```

---

## Structure des dossiers

```
lib/
├── config/
│   ├── env.dart              ← Variables d'environnement (CHANGE_ME)
│   └── router.dart           ← Routes go_router
├── features/
│   ├── auth/                 ← Inscription, connexion, OTP
│   ├── goshop/               ← Commandes, suivi GPS, notation
│   ├── gocolis/              ← Expéditions, suivi colis
│   ├── notifications/        ← Centre de notifications in-app
│   └── profile/              ← Profil, adresses favorites
└── mocks/
    └── mock_gocolis_api.dart ← Mock API GoColis (pour tests sans backend)
```

---

## Résolution des problèmes courants

### "No devices found"
```bash
# Démarrer un émulateur Android
flutter emulators --launch Pixel_6_API_34

# Ou ouvrir Android Studio > Device Manager > Play
```

### "Gradle build failed"
```bash
cd android
./gradlew clean
cd ..
flutter run
```

### "CocoaPods not installed" (iOS uniquement)
```bash
sudo gem install cocoapods
cd ios && pod install && cd ..
flutter run
```

### "CHANGE_ME" dans env.dart
L'app ne démarre pas si des valeurs `CHANGE_ME` sont présentes.  
Vérifiez que toutes les valeurs sont remplies dans `lib/config/env.dart`.

### L'API n'est pas joignable
```bash
# Vérifier que le backend Laravel tourne
docker compose ps  # Doit afficher golivreur_app comme "running"

# Vérifier l'URL selon votre appareil (voir Étape 3)
# Tester dans le navigateur de votre téléphone :
# http://[IP]:8000/api/v1/health  → doit retourner {"status":"ok"}
```
