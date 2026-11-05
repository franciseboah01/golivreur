import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:golivreur_core/config/app_theme.dart';
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
  const GoLivreurDriverApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'GoLivreur Driver',
      theme: GoLivreurTheme.theme,
      debugShowCheckedModeBanner: false,
      home: const DriverLoginScreen(),
    );
  }
}
