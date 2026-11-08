import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:golivreur_core/config/app_theme.dart';
import 'package:golivreur_core/widgets/golivreur_widgets.dart';
import 'features/auth/screens/driver_login_screen.dart';

void main() {
  WidgetsFlutterBinding.ensureInitialized();
  runApp(
    const ProviderScope(
      child: GoLivreurDriverApp(),
    ),
  );
}

class GoLivreurDriverApp extends StatelessWidget {
  final bool showSplash;

  const GoLivreurDriverApp({
    super.key,
    this.showSplash = true,
  });

  @override
  Widget build(BuildContext context) {
    const targetScreen = DriverLoginScreen();

    return MaterialApp(
      title: 'GoLivreur Driver',
      theme: GoLivreurTheme.theme,
      debugShowCheckedModeBanner: false,
      home: showSplash
          ? const AnimatedSplashScreen(
              appType: GoLivreurAppType.driver,
              nextScreen: targetScreen,
            )
          : targetScreen,
    );
  }
}
