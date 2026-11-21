import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:golivreur_core/config/app_theme.dart';
import 'package:golivreur_core/services/api_client.dart';
import 'package:golivreur_core/widgets/golivreur_widgets.dart';
import 'features/auth/screens/driver_login_screen.dart';
import 'features/navigation/screens/driver_main_navigation_screen.dart';

void main() {
  WidgetsFlutterBinding.ensureInitialized();
  runApp(
    const ProviderScope(
      child: GoLivreurDriverApp(),
    ),
  );
  unawaited(PushNotificationService.initialize());
}

class GoLivreurDriverApp extends StatelessWidget {
  final bool showSplash;

  const GoLivreurDriverApp({
    super.key,
    this.showSplash = true,
  });

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'GL Driver',
      theme: GoLivreurTheme.theme,
      debugShowCheckedModeBanner: false,
      builder: (context, child) => InAppPushOverlay(
        child: child ?? const SizedBox.shrink(),
      ),
      home: DriverRootGate(showSplash: showSplash),
    );
  }
}

class DriverRootGate extends ConsumerStatefulWidget {
  final bool showSplash;
  const DriverRootGate({super.key, required this.showSplash});

  @override
  ConsumerState<DriverRootGate> createState() => _DriverRootGateState();
}

class _DriverRootGateState extends ConsumerState<DriverRootGate> {
  late bool _splashFinished;
  bool _pinLocked = false;
  bool _ssoAuthenticated = false;
  bool _mustChangePassword = false;

  @override
  void initState() {
    super.initState();
    _splashFinished = !widget.showSplash;
    DeepLinkBridge.listen(_handleIncomingUri);
    WidgetsBinding.instance.addPostFrameCallback((_) => _bootstrapSecurity());
  }

  Future<void> _bootstrapSecurity() async {
    try {
      final storage = SecureTokenStorage.createStorage();
      final initialUri = await DeepLinkBridge.getInitialUri();
      if (initialUri != null && initialUri.host == 'sso') {
        await _handleIncomingUri(initialUri);
        return;
      }

      final needsPin = await PinLockService(storage: storage).requiresUnlock();
      if (needsPin && mounted) {
        setState(() => _pinLocked = true);
        return;
      }

      // Session persistante sans OTP ni reconnexion : si un jeton valide existe, on entre directement
      final accessToken = await storage.read(key: SecureTokenStorage.accessTokenKey);
      final refreshToken = await storage.read(key: SecureTokenStorage.refreshTokenKey);
      if (((accessToken != null && accessToken.isNotEmpty) ||
              (refreshToken != null && refreshToken.isNotEmpty)) &&
          mounted) {
        setState(() => _ssoAuthenticated = true);
      }
    } catch (_) {
      // Environnement de test sans plugin natif Keystore/Keychain
    }
  }

  Future<void> _handleIncomingUri(Uri uri) async {
    if (uri.scheme != GoLivreurAppTarget.driver.scheme || uri.host != 'sso') {
      return;
    }
    try {
      final storage = SecureTokenStorage.createStorage();
      final api = ref.read(apiClientProvider);
      final result = await SsoSwitchService.exchangeIncomingDeepLink(
        apiClient: api,
        storage: storage,
        uri: uri,
        currentApp: GoLivreurAppTarget.driver,
      );
      if (result != null && mounted) {
        setState(() {
          _pinLocked = false;
          _ssoAuthenticated = true;
          _mustChangePassword = result.mustChangePassword;
        });
      }
    } catch (_) {}
  }

  @override
  Widget build(BuildContext context) {
    final branding = ref.watch(appBrandingProvider);

    if (!_splashFinished) {
      return AnimatedSplashScreen(
        appType: GoLivreurAppType.driver,
        logoUrl: branding.logoUrl,
        defaultEmoji: branding.defaultEmoji,
        onFinished: () {
          if (mounted) {
            setState(() => _splashFinished = true);
          }
        },
      );
    }

    if (_pinLocked) {
      final storage = SecureTokenStorage.createStorage();
      return PinLockScreen(
        appName: 'GL Driver',
        pinService: PinLockService(storage: storage),
        onUnlocked: (_) async {
          if (mounted) {
            setState(() {
              _pinLocked = false;
              _ssoAuthenticated = true;
            });
          }
        },
        onWipedForceLogin: () async {
          if (mounted) {
            setState(() {
              _pinLocked = false;
              _ssoAuthenticated = false;
            });
          }
        },
      );
    }

    if (_ssoAuthenticated) {
      if (_mustChangePassword) {
        return ForcePasswordChangeScreen(
          apiClient: ref.read(apiClientProvider),
          onPasswordChanged: () {
            if (mounted) {
              setState(() => _mustChangePassword = false);
            }
          },
        );
      }
      return const DriverMainNavigationScreen();
    }

    return const DriverLoginScreen();
  }
}
