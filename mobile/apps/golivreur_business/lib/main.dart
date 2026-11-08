import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:golivreur_core/config/app_theme.dart';
import 'package:golivreur_core/widgets/golivreur_widgets.dart';
import 'features/auth/screens/business_login_screen.dart';

void main() {
  WidgetsFlutterBinding.ensureInitialized();
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
    const targetScreen = BusinessLoginScreen();

    return MaterialApp(
      title: 'GoLivreur Business',
      theme: GoLivreurTheme.theme,
      debugShowCheckedModeBanner: false,
      home: showSplash
          ? const AnimatedSplashScreen(
              appType: GoLivreurAppType.business,
              nextScreen: targetScreen,
            )
          : targetScreen,
    );
  }
}
