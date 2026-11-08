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
    return MaterialApp(
      title: 'GoLivreur Business',
      theme: GoLivreurTheme.theme,
      debugShowCheckedModeBanner: false,
      home: BusinessRootGate(showSplash: showSplash),
    );
  }
}

class BusinessRootGate extends StatefulWidget {
  final bool showSplash;
  const BusinessRootGate({super.key, required this.showSplash});

  @override
  State<BusinessRootGate> createState() => _BusinessRootGateState();
}

class _BusinessRootGateState extends State<BusinessRootGate> {
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
        appType: GoLivreurAppType.business,
        onFinished: () {
          if (mounted) {
            setState(() => _splashFinished = true);
          }
        },
      );
    }
    return const BusinessLoginScreen();
  }
}
