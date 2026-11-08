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
    return MaterialApp(
      title: 'GoLivreur Driver',
      theme: GoLivreurTheme.theme,
      debugShowCheckedModeBanner: false,
      home: DriverRootGate(showSplash: showSplash),
    );
  }
}

class DriverRootGate extends StatefulWidget {
  final bool showSplash;
  const DriverRootGate({super.key, required this.showSplash});

  @override
  State<DriverRootGate> createState() => _DriverRootGateState();
}

class _DriverRootGateState extends State<DriverRootGate> {
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
        appType: GoLivreurAppType.driver,
        onFinished: () {
          if (mounted) {
            setState(() => _splashFinished = true);
          }
        },
      );
    }
    return const DriverLoginScreen();
  }
}
