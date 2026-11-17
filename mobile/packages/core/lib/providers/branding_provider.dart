import 'dart:async';

import 'package:flutter/widgets.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../config/env_config.dart';
import '../services/api_client.dart';

/// Modèle d'identité visuelle dynamique GoLivreur (partagé sur toutes les plateformes).
/// Permet d'afficher soit le logo uploadé par l'administrateur dans Filament Settings,
/// soit le logo unique par défaut GoLivreur (badge ambre dégradé + emoji 🛵).
class AppBranding {
  final String? logoUrl;
  final String defaultEmoji;
  final bool isLoaded;

  const AppBranding({
    this.logoUrl,
    this.defaultEmoji = '🛵',
    this.isLoaded = false,
  });

  bool get hasCustomLogo => logoUrl != null && logoUrl!.trim().isNotEmpty;

  String? get resolvedLogoUrl => GoLivreurEnv.resolveMediaUrl(logoUrl);

  AppBranding copyWith({
    String? logoUrl,
    String? defaultEmoji,
    bool? isLoaded,
    bool clearLogo = false,
  }) {
    return AppBranding(
      logoUrl: clearLogo ? null : (logoUrl ?? this.logoUrl),
      defaultEmoji: (defaultEmoji != null && defaultEmoji.trim().isNotEmpty)
          ? defaultEmoji.trim()
          : this.defaultEmoji,
      isLoaded: isLoaded ?? this.isLoaded,
    );
  }
}

/// Notifier global synchronisant automatiquement le logo depuis `GET /api/v1/branding`
/// au démarrage et à chaque retour au premier plan de l'application.
class AppBrandingNotifier extends StateNotifier<AppBranding>
    with WidgetsBindingObserver {
  final ApiClient _api;
  bool _isFetching = false;
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

  Future<void> loadBranding() async {
    if (_isFetching) return;
    _isFetching = true;
    try {
      final res = await _api.get('/branding');
      if (!mounted) return;
      final data = res.data['donnees'] as Map<String, dynamic>?;
      if (data != null) {
        final rawLogo = data['logo_url'] as String?;
        final resolvedLogo = GoLivreurEnv.resolveMediaUrl(rawLogo);
        final emoji = (data['default_emoji'] as String?)?.trim();
        state = AppBranding(
          logoUrl: (resolvedLogo != null && resolvedLogo.isNotEmpty)
              ? resolvedLogo
              : null,
          defaultEmoji: (emoji != null && emoji.isNotEmpty) ? emoji : '🛵',
          isLoaded: true,
        );
      }
    } catch (_) {
      // Conserve le logo par défaut unifié en cas d'indisponibilité réseau
    } finally {
      _isFetching = false;
    }
  }

  Future<void> chargerBranding() => loadBranding();

  void updateFromValues(String? rawLogoUrl, String? rawEmoji) {
    if (!mounted) return;
    final resolvedLogo = GoLivreurEnv.resolveMediaUrl(rawLogoUrl);
    final emoji = (rawEmoji ?? '').trim();
    state = AppBranding(
      logoUrl: (resolvedLogo != null && resolvedLogo.isNotEmpty)
          ? resolvedLogo
          : null,
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
