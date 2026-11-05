import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:golivreur_core/config/app_theme.dart';
import 'features/auth/screens/login_screen.dart';
import 'features/goshop/screens/suivi_gps_screen.dart';
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
  const GoLivreurClientApp({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final authState = ref.watch(authProvider);

    return MaterialApp(
      title: 'GoLivreur Client',
      theme: GoLivreurTheme.theme,
      debugShowCheckedModeBanner: false,
      home: authState.status == AuthStatus.authenticated
          ? const SuiviGPSScreen(orderId: 1)
          : const LoginScreen(),
    );
  }
}
