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
  String? _erreur;

  @override
  void initState() {
    super.initState();
    _phoneController = TextEditingController(text: '0703000001');
    _plateController = TextEditingController(text: '1234-JK-01');
  }

  @override
  void dispose() {
    _phoneController.dispose();
    _plateController.dispose();
    super.dispose();
  }

  void _remplirDemo() {
    setState(() {
      _phoneController.text = '0703000001';
      _plateController.text = '1234-JK-01';
      _erreur = null;
    });
  }

  void _login() {
    final phone = _phoneController.text.trim();
    final plate = _plateController.text.trim();

    if (phone.isEmpty || plate.isEmpty) {
      setState(() => _erreur = 'Veuillez saisir le numéro et l\'immatriculation.');
      return;
    }

    setState(() {
      _chargement = true;
      _erreur = null;
    });

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
          padding: const EdgeInsets.all(20),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              const SizedBox(height: 20),
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

              // Carte info démo pour faciliter le test
              Container(
                padding: const EdgeInsets.all(12),
                decoration: BoxDecoration(
                  color: const Color(0xFF1B5E20).withValues(alpha: 0.08),
                  borderRadius: BorderRadius.circular(10),
                  border: Border.all(color: const Color(0xFF1B5E20).withValues(alpha: 0.3)),
                ),
                child: Row(
                  children: [
                    const Icon(Icons.two_wheeler, color: Color(0xFF1B5E20), size: 28),
                    const SizedBox(width: 10),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: const [
                          Text(
                            'Ouattara Seydou (Moto Daloa)',
                            style: TextStyle(fontWeight: FontWeight.bold, fontSize: 13, color: Color(0xFF1B5E20)),
                          ),
                          Text(
                            'Tél: 0703000001 • Moto: 1234-JK-01',
                            style: TextStyle(fontSize: 12, color: GoLivreurTheme.texteSecondaire),
                          ),
                        ],
                      ),
                    ),
                    TextButton(
                      onPressed: _remplirDemo,
                      child: const Text('Remplir', style: TextStyle(fontWeight: FontWeight.bold)),
                    ),
                  ],
                ),
              ),
              const SizedBox(height: 16),

              if (_erreur != null) ...[
                Container(
                  padding: const EdgeInsets.all(12),
                  decoration: BoxDecoration(
                    color: GoLivreurTheme.erreur.withValues(alpha: 0.1),
                    borderRadius: BorderRadius.circular(8),
                    border: Border.all(color: GoLivreurTheme.erreur),
                  ),
                  child: Text(
                    _erreur!,
                    style: const TextStyle(color: GoLivreurTheme.erreur),
                  ),
                ),
                const SizedBox(height: 16),
              ],

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
                  labelText: 'Immatriculation moto (ex: 1234-JK-01)',
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
