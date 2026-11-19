import 'dart:async';
import 'dart:convert';
import 'dart:typed_data';

import 'package:flutter/widgets.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

import '../config/env_config.dart';
import '../services/api_client.dart';
import '../services/secure_token_storage.dart';

/// Modèle d'identité visuelle dynamique GoLivreur (partagé sur toutes les plateformes).
/// Le logo officiel est mis en cache localement pour rester visible en mode hors ligne.
/// Aucun emoji de secours n'est utilisé en cas de perte de connexion.
class AppBranding {
  final String? logoUrl;
  final Uint8List? logoBytes;
  final String defaultEmoji;
  final bool isLoaded;

  const AppBranding({
    this.logoUrl,
    this.logoBytes,
    this.defaultEmoji = '',
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
      defaultEmoji: defaultEmoji ?? this.defaultEmoji,
      isLoaded: isLoaded ?? this.isLoaded,
    );
  }
}

/// Notifier global synchronisant le logo depuis `GET /api/v1/branding`
/// avec persistance locale immédiate pour une disponibilité 100% hors-ligne.
class AppBrandingNotifier extends StateNotifier<AppBranding>
    with WidgetsBindingObserver {
  static const String _cacheLogoBase64Key = 'cached_branding_logo_b64';
  static const String _cacheLogoUrlKey = 'cached_branding_logo_url';

  final ApiClient _api;
  final FlutterSecureStorage _storage;
  Future<void>? _inFlightFetch;
  Timer? _pollTimer;

  AppBrandingNotifier(this._api, [FlutterSecureStorage? storage])
      : _storage = storage ?? SecureTokenStorage.createStorage(),
        super(const AppBranding()) {
    WidgetsBinding.instance.addObserver(this);
    final bindingType = WidgetsBinding.instance.runtimeType.toString();
    if (!bindingType.contains('TestWidgetsFlutterBinding') &&
        !bindingType.contains('AutomatedTestWidgetsFlutterBinding')) {
      _hydrateAndFetch();
      _pollTimer = Timer.periodic(
        const Duration(seconds: 30),
        (_) => loadBranding(),
      );
    }
  }

  Future<void> _hydrateAndFetch() async {
    await _hydrateFromLocalCache();
    await loadBranding();
  }

  Future<void> _hydrateFromLocalCache() async {
    try {
      final cachedBase64 = await _storage.read(key: _cacheLogoBase64Key);
      final cachedUrl = await _storage.read(key: _cacheLogoUrlKey);
      if (!mounted) return;

      Uint8List? bytes;
      if (cachedBase64 != null && cachedBase64.isNotEmpty) {
        try {
          bytes = base64Decode(cachedBase64);
        } catch (_) {
          bytes = null;
        }
      }

      if ((bytes != null && bytes.isNotEmpty) ||
          (cachedUrl != null && cachedUrl.isNotEmpty)) {
        state = AppBranding(
          logoUrl: cachedUrl,
          logoBytes: bytes,
          isLoaded: true,
        );
      }
    } catch (_) {}
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
      final data = res.data is Map
          ? res.data['donnees'] as Map<String, dynamic>?
          : null;
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

        // Met à jour le cache local hors ligne dès qu'un vrai logo est reçu
        if (rawBase64 != null && rawBase64.isNotEmpty) {
          try {
            await _storage.write(key: _cacheLogoBase64Key, value: rawBase64);
          } catch (_) {}
        }
        if (resolvedLogo != null && resolvedLogo.isNotEmpty) {
          try {
            await _storage.write(key: _cacheLogoUrlKey, value: resolvedLogo);
          } catch (_) {}
        }

        state = AppBranding(
          logoUrl: (resolvedLogo != null && resolvedLogo.isNotEmpty)
              ? resolvedLogo
              : state.logoUrl,
          logoBytes: decodedBytes ?? state.logoBytes,
          isLoaded: true,
        );
      }
    } catch (_) {
      // Hors ligne : conserve impérativement le logo mis en cache sans jamais le remplacer par un emoji
      if (mounted && !state.isLoaded) {
        state = state.copyWith(isLoaded: true);
      }
    }
  }

  Future<void> chargerBranding() => loadBranding();

  void updateFromValues(String? rawLogoUrl, String? rawEmoji) {
    if (!mounted) return;
    final resolvedLogo = GoLivreurEnv.resolveMediaUrl(rawLogoUrl);
    final hasLogo = resolvedLogo != null && resolvedLogo.isNotEmpty;
    if (hasLogo) {
      _storage.write(key: _cacheLogoUrlKey, value: resolvedLogo).then((_) {}, onError: (_) {});
    }
    state = AppBranding(
      logoUrl: hasLogo ? resolvedLogo : state.logoUrl,
      logoBytes: state.logoBytes,
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

final appBrandingProvider =
    StateNotifierProvider<AppBrandingNotifier, AppBranding>((ref) {
  final api = ref.watch(apiClientProvider);
  return AppBrandingNotifier(api);
});
