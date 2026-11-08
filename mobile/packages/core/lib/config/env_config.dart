import 'package:flutter/foundation.dart';

/// Configuration environnement universelle GoLivreur.
/// 
/// Toutes les valeurs sont configurables au build ou à l'exécution via `--dart-define` :
/// Exemple :
///   flutter run -d chrome --dart-define=API_URL=http://localhost:8080/api/v1
///   flutter build apk --dart-define=API_URL=https://golivreur.ci/api/v1
///
/// Si aucun paramètre n'est passé :
///   - Web / Desktop : http://localhost:8080/api/v1
///   - Android Émulateur : http://10.0.2.2:8080/api/v1
///   - Téléphone réel : passer `--dart-define=API_URL=http://<IP_PC>:8080/api/v1`
class GoLivreurEnv {
  GoLivreurEnv._();

  static const String _configuredApiUrl = String.fromEnvironment('API_URL');

  static String get defaultApiUrl {
    if (kIsWeb) return 'http://localhost:8080/api/v1';
    if (defaultTargetPlatform == TargetPlatform.android) {
      return 'http://10.0.2.2:8080/api/v1';
    }
    return 'http://localhost:8080/api/v1';
  }

  /// URL de base de l'API GoLivreur (détection intelligente ou surchargée).
  static String get apiBaseUrl =>
      _configuredApiUrl.isNotEmpty ? _configuredApiUrl : defaultApiUrl;

  /// WebSocket Reverb
  static const String reverbHost = String.fromEnvironment(
    'REVERB_HOST',
    defaultValue: '127.0.0.1',
  );

  static const int reverbPort = int.fromEnvironment(
    'REVERB_PORT',
    defaultValue: 8080,
  );

  static const String reverbAppKey = String.fromEnvironment(
    'REVERB_APP_KEY',
    defaultValue: 'golivreur_key',
  );

  static const String reverbScheme = String.fromEnvironment(
    'REVERB_SCHEME',
    defaultValue: 'http',
  );

  /// Google Maps Keys
  static const String googleMapsKeyAndroid = String.fromEnvironment(
    'GOOGLE_MAPS_KEY_ANDROID',
    defaultValue: '',
  );

  static const String googleMapsKeyIOS = String.fromEnvironment(
    'GOOGLE_MAPS_KEY_IOS',
    defaultValue: '',
  );

  /// Paramètres réseau
  static const int timeoutSeconds = int.fromEnvironment(
    'API_TIMEOUT_SECONDS',
    defaultValue: 8,
  );

  static const String appVersion = String.fromEnvironment(
    'APP_VERSION',
    defaultValue: '1.0.0',
  );
}

