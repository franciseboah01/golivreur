import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:golivreur_core/config/app_theme.dart';
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
  const GoLivreurBusinessApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'GoLivreur Business',
      theme: GoLivreurTheme.theme,
      debugShowCheckedModeBanner: false,
      home: const BusinessLoginScreen(),
    );
  }
}
