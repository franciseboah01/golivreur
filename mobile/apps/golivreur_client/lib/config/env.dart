import 'package:golivreur_core/config/env_config.dart';

/// Variables d'environnement GoLivreur Client.
///
/// Configurable dynamiquement via --dart-define ou détection automatique de plateforme.
class Env {
  Env._();

  // ── API Backend ────────────────────────────────────────────────────────────
  /// URL de base de l'API GoLivreur (détection automatique Android émulateur, Web, ou surchargée).
  static String get apiBaseUrl => GoLivreurEnv.apiBaseUrl;

  // ── WebSocket Reverb (GPS temps réel) ──────────────────────────────────────
  static String get reverbHost => GoLivreurEnv.reverbHost;
  static int get reverbPort => GoLivreurEnv.reverbPort;
  static String get reverbAppKey => GoLivreurEnv.reverbAppKey;
  static String get reverbScheme => GoLivreurEnv.reverbScheme;

  // ── Google Maps ────────────────────────────────────────────────────────────
  static String get googleMapsKeyAndroid => GoLivreurEnv.googleMapsKeyAndroid;
  static String get googleMapsKeyIOS => GoLivreurEnv.googleMapsKeyIOS;

  // ── Configuration app ──────────────────────────────────────────────────────
  static const String appName = 'GoLivreur';
  static String get appVersion => GoLivreurEnv.appVersion;

  // Timeout requêtes API (8 secondes — réseau ivoirien instable)
  static int get timeoutSecondes => GoLivreurEnv.timeoutSeconds;

  // Intervalle GPS en secondes (filtre : envoie seulement si déplacement > 10m)
  static const int gpsIntervalleSecondes = 5;
  static const double gpsFiltreMetres = 10.0;
}
