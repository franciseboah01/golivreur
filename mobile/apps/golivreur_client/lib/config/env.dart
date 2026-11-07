/// Variables d'environnement GoLivreur Client.
///
/// INSTRUCTIONS POUR FRANCIS EBOAH :
///   Recherchez "CHANGE_ME" dans ce fichier et remplacez par vos vraies valeurs.
///   Ces valeurs sont dans Bitwarden > GoLivreur > Flutter Config.
///
/// ⚠️ Ne commitez JAMAIS ce fichier avec de vraies clés dans Git.

class Env {
  Env._();

  // ── API Backend ────────────────────────────────────────────────────────────
  /// URL de base de l'API GoLivreur.
  /// Local     : http://10.0.2.2:8000/api/v1  (émulateur Android → localhost)
  /// Local iOS : http://localhost:8000/api/v1
  /// Staging   : https://staging.golivreur.ci/api/v1
  /// Production: https://golivreur.ci/api/v1
  static const String apiBaseUrl = 'http://localhost:8080/api/v1'; // Local dev API

  // ── WebSocket Reverb (GPS temps réel) ──────────────────────────────────────
  /// Même hôte que l'API, port 8080.
  static const String reverbHost   = 'CHANGE_ME'; // Ex: golivreur.ci
  static const int    reverbPort   = 8080;
  static const String reverbAppKey = 'CHANGE_ME'; // Depuis .env Laravel: REVERB_APP_KEY
  static const String reverbScheme = 'https';     // Local: http

  // ── Google Maps ────────────────────────────────────────────────────────────
  /// Clé API Google Maps — restreinte aux apps mobiles Android/iOS.
  /// Récupérer dans Bitwarden > GoLivreur > Google Maps.
  static const String googleMapsKeyAndroid = 'CHANGE_ME';
  static const String googleMapsKeyIOS     = 'CHANGE_ME';

  // ── Firebase ───────────────────────────────────────────────────────────────
  /// Configuré via google-services.json (Android) et GoogleService-Info.plist (iOS).
  /// Fichiers à placer dans :
  ///   Android : android/app/google-services.json
  ///   iOS     : ios/Runner/GoogleService-Info.plist
  /// Ces fichiers sont dans Bitwarden > GoLivreur > Firebase.

  // ── Configuration app ──────────────────────────────────────────────────────
  static const String appName    = 'GoLivreur';
  static const String appVersion = '1.0.0';

  // Timeout requêtes API (8 secondes — réseau ivoirien instable)
  static const int timeoutSecondes = 8;

  // Intervalle GPS en secondes (filtre : envoie seulement si déplacement > 10m)
  static const int gpsIntervalleSecondes = 5;
  static const double gpsFiltreMetres   = 10.0;
}
