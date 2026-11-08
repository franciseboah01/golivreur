import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:golivreur_core/config/app_theme.dart';
import 'package:golivreur_core/widgets/golivreur_widgets.dart';
import 'features/auth/screens/login_screen.dart';
import 'features/navigation/screens/main_navigation_screen.dart';
import 'features/auth/providers/auth_provider.dart';

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
      title: 'GoLivreur Client',
      theme: GoLivreurTheme.theme,
      debugShowCheckedModeBanner: false,
      home: ClientRootGate(showSplash: showSplash),
    );
  }
}

class ClientRootGate extends StatefulWidget {
  final bool showSplash;
  const ClientRootGate({super.key, required this.showSplash});

  @override
  State<ClientRootGate> createState() => _ClientRootGateState();
}

class _ClientRootGateState extends State<ClientRootGate> {
  late bool _splashFinished;

  @override
  void initState() {
    super.initState();
    _splashFinished = !widget.showSplash;
  }

  @override
  Widget build(BuildContext context) {
    if (!_splashFinished) {
      return AnimatedSplashScreen(
        appType: GoLivreurAppType.client,
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

class ClientAuthGate extends ConsumerWidget {
  const ClientAuthGate({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final authState = ref.watch(authProvider);
    if (authState.status == AuthStatus.authenticated) {
      return const MainNavigationScreen();
    }
    return const LoginScreen();
  }
}
