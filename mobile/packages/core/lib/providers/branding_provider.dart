import 'dart:async';
import 'dart:convert';
import 'dart:typed_data';

import 'package:flutter/widgets.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../config/env_config.dart';
import '../services/api_client.dart';

/// Modèle d'identité visuelle dynamique GoLivreur (partagé sur toutes les plateformes).
/// Permet d'afficher soit le logo uploadé par l'administrateur dans Filament Settings,
/// soit le logo unique par défaut GoLivreur (badge ambre dégradé + emoji 🛵).
class AppBranding {
  final String? logoUrl;
  final Uint8List? logoBytes;
  final String defaultEmoji;
  final bool isLoaded;

  const AppBranding({
    this.logoUrl,
    this.logoBytes,
    this.defaultEmoji = '🛵',
    this.isLoaded = false,
  });

  bool get hasCustomLogo =>
      (logoBytes != null && logoBytes!.isNotEmpty) ||
      (logoUrl != null && logoUrl!.trim().isNotEmpty);

  String? get resolvedLogoUrl => GoLivreurEnv.resolveMediaUrl(logoUrl);

  AppBranding copyWith({
    String? logoUrl,
    Uint8List? logoBytes,
    String? defaultEmoji,
    bool? isLoaded,
    bool clearLogo = false,
  }) {
    return AppBranding(
      logoUrl: clearLogo ? null : (logoUrl ?? this.logoUrl),
      logoBytes: clearLogo ? null : (logoBytes ?? this.logoBytes),
      defaultEmoji: (defaultEmoji != null && defaultEmoji.trim().isNotEmpty)
          ? defaultEmoji.trim()
          : this.defaultEmoji,
      isLoaded: isLoaded ?? this.isLoaded,
    );
  }
}

/// Notifier global synchronisant automatiquement le logo depuis `GET /api/v1/branding`
/// au démarrage, à chaque retour au premier plan et périodiquement.
class AppBrandingNotifier extends StateNotifier<AppBranding>
    with WidgetsBindingObserver {
  final ApiClient _api;
  Future<void>? _inFlightFetch;
  Timer? _pollTimer;

  AppBrandingNotifier(this._api) : super(const AppBranding()) {
    WidgetsBinding.instance.addObserver(this);
    final bindingType = WidgetsBinding.instance.runtimeType.toString();
    if (!bindingType.contains('TestWidgetsFlutterBinding') &&
        !bindingType.contains('AutomatedTestWidgetsFlutterBinding')) {
      loadBranding();
      _pollTimer = Timer.periodic(
        const Duration(seconds: 8),
        (_) => loadBranding(),
      );
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) {
      loadBranding();
    }
  }

  Future<void> loadBranding() {
    if (_inFlightFetch != null) {
      return _inFlightFetch!;
    }
    final future = _doLoadBranding();
    _inFlightFetch = future;
    future.whenComplete(() {
      _inFlightFetch = null;
    });
    return future;
  }

  Future<void> _doLoadBranding() async {
    try {
      final res = await _api.get('/branding');
      if (!mounted) return;
      final data = res.data['donnees'] as Map<String, dynamic>?;
      if (data != null) {
        final rawLogo = data['logo_url'] as String?;
        final resolvedLogo = GoLivreurEnv.resolveMediaUrl(rawLogo);
        final rawBase64 = (data['logo_base64'] as String?)?.trim();
        Uint8List? decodedBytes;
        if (rawBase64 != null && rawBase64.isNotEmpty) {
          try {
            decodedBytes = base64Decode(rawBase64);
          } catch (_) {
            decodedBytes = null;
          }
        }
        final emoji = (data['default_emoji'] as String?)?.trim();
        state = AppBranding(
          logoUrl: (resolvedLogo != null && resolvedLogo.isNotEmpty)
              ? resolvedLogo
              : null,
          logoBytes: decodedBytes,
          defaultEmoji: (emoji != null && emoji.isNotEmpty) ? emoji : '🛵',
          isLoaded: true,
        );
      }
    } catch (_) {
      // Conserve le logo par défaut unifié en cas d'indisponibilité réseau
    }
  }

  Future<void> chargerBranding() => loadBranding();

  void updateFromValues(String? rawLogoUrl, String? rawEmoji) {
    if (!mounted) return;
    final resolvedLogo = GoLivreurEnv.resolveMediaUrl(rawLogoUrl);
    final hasLogo = resolvedLogo != null && resolvedLogo.isNotEmpty;
    final emoji = (rawEmoji ?? '').trim();
    state = AppBranding(
      logoUrl: hasLogo ? resolvedLogo : null,
      logoBytes: hasLogo ? state.logoBytes : null,
      defaultEmoji: emoji.isNotEmpty ? emoji : '🛵',
      isLoaded: true,
    );
  }

  void updateFromCityStatus(Map<String, dynamic> data) {
    updateFromValues(
      data['app_logo_url'] as String?,
      data['app_default_emoji'] as String?,
    );
  }

  @override
  void dispose() {
    _pollTimer?.cancel();
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }
}

/// Provider global d'identité visuelle GoLivreur disponible dans Client, Business et Driver.
final appBrandingProvider =
    StateNotifierProvider<AppBrandingNotifier, AppBranding>((ref) {
  return AppBrandingNotifier(ref.watch(apiClientProvider));
});
