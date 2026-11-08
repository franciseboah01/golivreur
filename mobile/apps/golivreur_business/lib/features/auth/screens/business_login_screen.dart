import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:golivreur_core/config/app_theme.dart';
import 'package:golivreur_core/widgets/atoms/widgets_atomiques.dart';
import 'package:golivreur_core/widgets/golivreur_logo.dart';
import '../../navigation/screens/business_main_navigation_screen.dart';

class BusinessLoginScreen extends StatefulWidget {
  const BusinessLoginScreen({super.key});

  @override
  State<BusinessLoginScreen> createState() => _BusinessLoginScreenState();
}

class _BusinessLoginScreenState extends State<BusinessLoginScreen> {
  late final TextEditingController _phoneController;
  late final TextEditingController _passwordController;
  bool _chargement = false;
  String? _erreur;

  @override
  void initState() {
    super.initState();
    _phoneController = TextEditingController(text: '0702010001');
    _passwordController = TextEditingController(text: '123456');
  }

  @override
  void dispose() {
    _phoneController.dispose();
    _passwordController.dispose();
    super.dispose();
  }

  void _remplirDemo() {
    setState(() {
      _phoneController.text = '0702010001';
      _passwordController.text = '123456';
      _erreur = null;
    });
  }

  void _login() {
    final phone = _phoneController.text.trim();
    final pwd = _passwordController.text.trim();

    if (phone.isEmpty || pwd.isEmpty) {
      setState(() => _erreur = 'Veuillez saisir votre téléphone et mot de passe.');
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
            builder: (_) => const BusinessMainNavigationScreen(),
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
                  type: GoLivreurAppType.business,
                  size: 92,
                ),
              ),
              const SizedBox(height: 12),
              Text(
                'GoLivreur Business',
                textAlign: TextAlign.center,
                style: Theme.of(context).textTheme.titleLarge?.copyWith(
                      fontWeight: FontWeight.bold,
                      color: GoLivreurTheme.textePrincipal,
                    ),
              ),
              const SizedBox(height: 6),
              const Text(
                'Espace Commerces & Restaurants partenaires',
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
                  color: const Color(0xFF2E75B6).withValues(alpha: 0.08),
                  borderRadius: BorderRadius.circular(10),
                  border: Border.all(color: const Color(0xFF2E75B6).withValues(alpha: 0.3)),
                ),
                child: Row(
                  children: [
                    const Icon(Icons.storefront, color: Color(0xFF2E75B6), size: 28),
                    const SizedBox(width: 10),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: const [
                          Text(
                            'Maquis Chez Tantie Adjoua (Daloa)',
                            style: TextStyle(fontWeight: FontWeight.bold, fontSize: 13, color: Color(0xFF2E75B6)),
                          ),
                          Text(
                            'Tél: 0702010001 • MDP: 123456',
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
                  labelText: 'Numéro de téléphone gérant',
                  prefixIcon: Icon(Icons.phone_outlined),
                  prefixText: '+225 ',
                ),
              ),
              const SizedBox(height: 16),
              TextFormField(
                controller: _passwordController,
                obscureText: true,
                decoration: const InputDecoration(
                  labelText: 'Mot de passe',
                  prefixIcon: Icon(Icons.lock_outline),
                ),
              ),
              const SizedBox(height: 24),
              BoutonPrimaire(
                label: 'Accéder à ma boutique',
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
