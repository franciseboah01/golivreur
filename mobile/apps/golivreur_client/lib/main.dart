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

class GoLivreurClientApp extends ConsumerWidget {
  final bool showSplash;

  const GoLivreurClientApp({
    super.key,
    this.showSplash = true,
  });

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final authState = ref.watch(authProvider);
    final targetScreen = authState.status == AuthStatus.authenticated
        ? const MainNavigationScreen()
        : const LoginScreen();

    return MaterialApp(
      title: 'GoLivreur Client',
      theme: GoLivreurTheme.theme,
      debugShowCheckedModeBanner: false,
      home: showSplash
          ? AnimatedSplashScreen(
              appType: GoLivreurAppType.client,
              nextScreen: targetScreen,
            )
          : targetScreen,
    );
  }
}
