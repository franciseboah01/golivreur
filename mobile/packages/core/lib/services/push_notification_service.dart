import 'dart:async';

import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/foundation.dart' show kIsWeb;

import 'api_client.dart';

/// Handler top-level exigé par Firebase Cloud Messaging lorsque l'app est en arrière-plan.
@pragma('vm:entry-point')
Future<void> golivreurFcmBackgroundHandler(RemoteMessage message) async {
  try {
    if (Firebase.apps.isEmpty) {
      await Firebase.initializeApp();
    }
  } catch (_) {}
}

/// Modèle normalisé d'un Push FCM reçu (au premier plan / In-App ou via clic notification).
class InAppPushMessage {
  final String title;
  final String body;
  final String type;
  final String channelId;
  final Map<String, dynamic> data;
  final DateTime receivedAt;

  const InAppPushMessage({
    required this.title,
    required this.body,
    required this.type,
    required this.channelId,
    required this.data,
    required this.receivedAt,
  });

  factory InAppPushMessage.fromRemoteMessage(RemoteMessage message) {
    final data = Map<String, dynamic>.from(message.data);
    final title = (message.notification?.title ??
            data['title']?.toString() ??
            'GoLivreur')
        .trim();
    final body =
        (message.notification?.body ?? data['body']?.toString() ?? '').trim();
    final type = (data['type']?.toString() ?? 'general').trim();
    final channelId = (data['channel_id']?.toString() ?? 'general').trim();

    return InAppPushMessage(
      title: title.isNotEmpty ? title : 'GoLivreur',
      body: body,
      type: type,
      channelId: channelId,
      data: data,
      receivedAt: DateTime.now(),
    );
  }
}

/// Service centralisé Firebase Cloud Messaging (Push arrière-plan + Push In-App)
/// partagé par GL Client, GL Business et GL Driver.
class PushNotificationService {
  PushNotificationService._();

  static bool _initialized = false;
  static Completer<bool>? _initCompleter;
  static ApiClient? _activeApiClient;
  static String? _lastSyncedToken;
  static StreamSubscription<String>? _tokenRefreshSub;

  static final StreamController<InAppPushMessage> _foregroundController =
      StreamController<InAppPushMessage>.broadcast();
  static final StreamController<InAppPushMessage> _tapController =
      StreamController<InAppPushMessage>.broadcast();

  static bool get isInitialized => _initialized;

  /// Flux temps réel des notifications reçues pendant que l'app est ouverte (Push In-App).
  static Stream<InAppPushMessage> get onForegroundMessage =>
      _foregroundController.stream;

  /// Flux des notifications sur lesquelles l'utilisateur a cliqué.
  static Stream<InAppPushMessage> get onNotificationTap => _tapController.stream;

  /// Initialise Firebase & FCM en toute sécurité (ne crashe jamais si absent en test).
  static Future<bool> initialize() async {
    if (_initialized) return true;
    if (kIsWeb) return false;
    if (_initCompleter != null) return _initCompleter!.future;

    final completer = Completer<bool>();
    _initCompleter = completer;

    try {
      if (Firebase.apps.isEmpty) {
        await Firebase.initializeApp();
      }

      FirebaseMessaging.onBackgroundMessage(golivreurFcmBackgroundHandler);

      final messaging = FirebaseMessaging.instance;

      await messaging.requestPermission(
        alert: true,
        badge: true,
        sound: true,
        provisional: false,
      );

      await messaging.setForegroundNotificationPresentationOptions(
        alert: true,
        badge: true,
        sound: true,
      );

      FirebaseMessaging.onMessage.listen((RemoteMessage message) {
        final parsed = InAppPushMessage.fromRemoteMessage(message);
        if (parsed.title.isNotEmpty || parsed.body.isNotEmpty) {
          _foregroundController.add(parsed);
        }
      });

      FirebaseMessaging.onMessageOpenedApp.listen((RemoteMessage message) {
        _tapController.add(InAppPushMessage.fromRemoteMessage(message));
      });

      final initialMessage = await messaging.getInitialMessage();
      if (initialMessage != null) {
        _tapController.add(InAppPushMessage.fromRemoteMessage(initialMessage));
      }

      _initialized = true;
      completer.complete(true);
      return true;
    } catch (_) {
      completer.complete(false);
      return false;
    } finally {
      _initCompleter = null;
    }
  }

  /// Récupère le token FCM de l'appareil et l'enregistre côté backend (`POST /devices/token`).
  static Future<String?> syncTokenWithBackend(ApiClient apiClient) async {
    _activeApiClient = apiClient;

    if (!_initialized) {
      final ok = await initialize();
      if (!ok) return null;
    }

    try {
      final messaging = FirebaseMessaging.instance;
      final token = await messaging.getToken();

      _tokenRefreshSub ??= messaging.onTokenRefresh.listen((newToken) {
        final client = _activeApiClient;
        if (client != null && newToken.trim().isNotEmpty) {
          _sendToken(client, newToken.trim());
        }
      });

      if (token != null && token.trim().isNotEmpty) {
        await _sendToken(apiClient, token.trim());
        return token.trim();
      }
    } catch (_) {}

    return null;
  }

  /// Coupe la synchronisation automatique lors de la déconnexion.
  static void clearTokenSync() {
    _activeApiClient = null;
    _lastSyncedToken = null;
  }

  /// Émet un Push In-App local (utile pour les tests ou alertes applicatives).
  static void emitInAppMessage(InAppPushMessage message) {
    _foregroundController.add(message);
  }

  static Future<void> _sendToken(ApiClient apiClient, String token) async {
    if (_lastSyncedToken == token) return;
    try {
      final res = await apiClient.post(
        '/devices/token',
        data: {'token': token},
      );
      if (res.statusCode == 200) {
        _lastSyncedToken = token;
      }
    } catch (_) {}
  }
}
