import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:golivreur_core/config/app_theme.dart';
import 'package:golivreur_core/widgets/golivreur_widgets.dart';
import 'features/auth/providers/auth_provider.dart';
import 'features/auth/screens/login_screen.dart';
import 'features/navigation/screens/main_navigation_screen.dart';

void main() {
  WidgetsFlutterBinding.ensureInitialized();
  runApp(
    const ProviderScope(
      child: GoLivreurClientApp(),
    ),
  );
}

class GoLivreurClientApp extends StatelessWidget {
  final bool showSplash;

  const GoLivreurClientApp({
    super.key,
    this.showSplash = true,
  });

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'GL Client',
      theme: GoLivreurTheme.theme,
      debugShowCheckedModeBanner: false,
      home: ClientRootGate(showSplash: showSplash),
    );
  }
}

class ClientRootGate extends ConsumerStatefulWidget {
  final bool showSplash;
  const ClientRootGate({super.key, required this.showSplash});

  @override
  ConsumerState<ClientRootGate> createState() => _ClientRootGateState();
}

class _ClientRootGateState extends ConsumerState<ClientRootGate> {
  late bool _splashFinished;

  @override
  void initState() {
    super.initState();
    _splashFinished = !widget.showSplash;
  }

  @override
  Widget build(BuildContext context) {
    final branding = ref.watch(appBrandingProvider);

    if (!_splashFinished) {
      return AnimatedSplashScreen(
        appType: GoLivreurAppType.client,
        logoUrl: branding.logoUrl,
        defaultEmoji: branding.defaultEmoji,
        onFinished: () {
          if (mounted) {
            setState(() => _splashFinished = true);
          }
        },
      );
    }
    return const ClientAuthGate();
  }
}

class ClientAuthGate extends ConsumerStatefulWidget {
  const ClientAuthGate({super.key});

  @override
  ConsumerState<ClientAuthGate> createState() => _ClientAuthGateState();
}

class _ClientAuthGateState extends ConsumerState<ClientAuthGate> {
  @override
  void initState() {
    super.initState();
    DeepLinkBridge.listen(_handleIncomingUri);
    WidgetsBinding.instance.addPostFrameCallback((_) => _bootstrapSession());
  }

  Future<void> _bootstrapSession() async {
    final initialUri = await DeepLinkBridge.getInitialUri();
    if (initialUri != null && initialUri.host == 'sso') {
      await _handleIncomingUri(initialUri);
      return;
    }

    final storage = ref.read(secureStorageProvider);
    final existingToken = await SecureTokenStorage.readAccessToken(storage);
    if (existingToken != null && existingToken.isNotEmpty) {
      await ref.read(authProvider.notifier).restaurerSessionLocale();
    }
  }

  Future<void> _handleIncomingUri(Uri uri) async {
    if (uri.scheme != GoLivreurAppTarget.client.scheme || uri.host != 'sso') {
      return;
    }
    final ok = await ref.read(authProvider.notifier).finaliserBasculeSso(uri);
    if (ok && mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Session transférée avec succès vers GL Client.'),
          backgroundColor: GoLivreurTheme.secondaire,
        ),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    final authState = ref.watch(authProvider);

    if (authState.status == AuthStatus.authenticated) {
      if (authState.user?.mustChangePassword == true) {
        return ForcePasswordChangeScreen(
          apiClient: ref.read(apiClientProvider),
          initialTemporaryPassword: authState.lastTemporaryPassword,
          onPasswordChanged: () {
            ref.read(authProvider.notifier).marquerMotDePasseChange();
          },
        );
      }

      return MainNavigationScreen(
        key: ValueKey('main_${authState.user?.id ?? "guest"}'),
      );
    }

    return const LoginScreen();
  }
}
