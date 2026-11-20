import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:golivreur_core/config/app_theme.dart';
import 'package:golivreur_core/services/api_client.dart';
import 'package:golivreur_core/widgets/golivreur_widgets.dart';
import 'features/auth/screens/business_login_screen.dart';
import 'features/navigation/screens/business_main_navigation_screen.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  await PushNotificationService.initialize();
  runApp(
    const ProviderScope(
      child: GoLivreurBusinessApp(),
    ),
  );
}

class GoLivreurBusinessApp extends StatelessWidget {
  final bool showSplash;

  const GoLivreurBusinessApp({
    super.key,
    this.showSplash = true,
  });

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'GL Business',
      theme: GoLivreurTheme.theme,
      debugShowCheckedModeBanner: false,
      builder: (context, child) => InAppPushOverlay(
        child: child ?? const SizedBox.shrink(),
      ),
      home: BusinessRootGate(showSplash: showSplash),
    );
  }
}

class BusinessRootGate extends ConsumerStatefulWidget {
  final bool showSplash;
  const BusinessRootGate({super.key, required this.showSplash});

  @override
  ConsumerState<BusinessRootGate> createState() => _BusinessRootGateState();
}

class _BusinessRootGateState extends ConsumerState<BusinessRootGate> {
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
      }
    } catch (_) {
      // Plateforme de test sans plugin natif Keystore/Keychain
    }
  }

  Future<void> _handleIncomingUri(Uri uri) async {
    if (uri.scheme != GoLivreurAppTarget.business.scheme || uri.host != 'sso') {
      return;
    }
    try {
      final storage = SecureTokenStorage.createStorage();
      final api = ref.read(apiClientProvider);
      final result = await SsoSwitchService.exchangeIncomingDeepLink(
        apiClient: api,
        storage: storage,
        uri: uri,
        currentApp: GoLivreurAppTarget.business,
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
        appType: GoLivreurAppType.business,
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
        appName: 'GL Business',
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
      return const BusinessMainNavigationScreen();
    }

    return const BusinessLoginScreen();
  }
}
