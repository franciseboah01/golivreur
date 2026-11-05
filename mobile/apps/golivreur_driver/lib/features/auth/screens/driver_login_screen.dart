import 'package:flutter/material.dart';
import 'package:golivreur_core/config/app_theme.dart';
import 'package:golivreur_core/widgets/atoms/widgets_atomiques.dart';
import '../../gocolis/screens/codes_gocolis_screen.dart';

class DriverLoginScreen extends StatefulWidget {
  const DriverLoginScreen({super.key});

  @override
  State<DriverLoginScreen> createState() => _DriverLoginScreenState();
}

class _DriverLoginScreenState extends State<DriverLoginScreen> {
  final _phoneController = TextEditingController();
  final _plateController = TextEditingController();
  bool _chargement = false;

  @override
  void dispose() {
    _phoneController.dispose();
    _plateController.dispose();
    super.dispose();
  }

  void _login() {
    setState(() => _chargement = true);
    Future.delayed(const Duration(milliseconds: 600), () {
      if (mounted) {
        setState(() => _chargement = false);
        Navigator.of(context).pushReplacement(
          MaterialPageRoute(
            builder: (_) => const CodesGoColisScreen(parcelId: 1),
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
              const SizedBox(height: 32),
              Center(
                child: Container(
                  padding: const EdgeInsets.all(16),
                  decoration: BoxDecoration(
                    color: GoLivreurTheme.secondaire.withValues(alpha: 0.1),
                    shape: BoxShape.circle,
                  ),
                  child: const Icon(
                    Icons.two_wheeler,
                    size: 64,
                    color: GoLivreurTheme.secondaire,
                  ),
                ),
              ),
              const SizedBox(height: 16),
              Text(
                'GoLivreur Driver',
                textAlign: TextAlign.center,
                style: Theme.of(context).textTheme.headlineMedium?.copyWith(
                      fontWeight: FontWeight.bold,
                      color: GoLivreurTheme.textePrincipal,
                    ),
              ),
              const SizedBox(height: 8),
              const Text(
                'Espace Livreurs Moto Daloa',
                textAlign: TextAlign.center,
                style: TextStyle(color: GoLivreurTheme.texteSecondaire),
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
