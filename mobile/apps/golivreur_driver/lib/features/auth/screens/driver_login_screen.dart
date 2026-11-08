import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:golivreur_core/config/app_theme.dart';
import 'package:golivreur_core/widgets/atoms/widgets_atomiques.dart';
import 'package:golivreur_core/widgets/golivreur_logo.dart';
import '../../navigation/screens/driver_main_navigation_screen.dart';

class DriverLoginScreen extends StatefulWidget {
  const DriverLoginScreen({super.key});

  @override
  State<DriverLoginScreen> createState() => _DriverLoginScreenState();
}

class _DriverLoginScreenState extends State<DriverLoginScreen> {
  late final TextEditingController _phoneController;
  late final TextEditingController _plateController;
  bool _chargement = false;

  @override
  void initState() {
    super.initState();
    _phoneController = TextEditingController(text: kDebugMode ? '0700000003' : '');
    _plateController = TextEditingController(text: kDebugMode ? '1234-JK-01' : '');
  }

  @override
  void dispose() {
    _phoneController.dispose();
    _plateController.dispose();
    super.dispose();
  }

  void _login() {
    setState(() => _chargement = true);
    Future.delayed(const Duration(milliseconds: 400), () {
      if (mounted) {
        setState(() => _chargement = false);
        Navigator.of(context).pushReplacement(
          MaterialPageRoute(
            builder: (_) => const DriverMainNavigationScreen(),
          ),
        );
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: GoLivreurTheme.fond,
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              const SizedBox(height: 24),
              const Center(
                child: GoLivreurLogo(
                  type: GoLivreurAppType.driver,
                  size: 92,
                ),
              ),
              const SizedBox(height: 12),
              Text(
                'GoLivreur Driver',
                textAlign: TextAlign.center,
                style: Theme.of(context).textTheme.titleLarge?.copyWith(
                      fontWeight: FontWeight.bold,
                      color: GoLivreurTheme.textePrincipal,
                    ),
              ),
              const SizedBox(height: 6),
              const Text(
                'Espace Livreurs Moto Daloa',
                textAlign: TextAlign.center,
                style: TextStyle(
                  color: GoLivreurTheme.texteSecondaire,
                  fontSize: 14,
                ),
              ),
              const SizedBox(height: 24),
              TextFormField(
                controller: _phoneController,
                keyboardType: TextInputType.phone,
                decoration: const InputDecoration(
                  labelText: 'Numéro de téléphone livreur',
                  prefixIcon: Icon(Icons.phone_outlined),
                  prefixText: '+225 ',
                ),
              ),
              const SizedBox(height: 16),
              TextFormField(
                controller: _plateController,
                decoration: const InputDecoration(
                  labelText: 'Immatriculation moto (ex: 1234-AB-01)',
                  prefixIcon: Icon(Icons.badge_outlined),
                ),
              ),
              const SizedBox(height: 24),
              BoutonPrimaire(
                label: 'Prendre mon service',
                estEnChargement: _chargement,
                onPressed: _login,
              ),
            ],
          ),
        ),
      ),
    );
  }
}
