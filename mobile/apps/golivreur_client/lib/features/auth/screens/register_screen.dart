import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:golivreur_core/config/app_theme.dart';
import 'package:golivreur_core/widgets/atoms/widgets_atomiques.dart';
import 'package:golivreur_core/widgets/golivreur_logo.dart';
import '../providers/auth_provider.dart';
import 'otp_verification_screen.dart';
import 'login_screen.dart';

class RegisterScreen extends ConsumerStatefulWidget {
  const RegisterScreen({super.key});

  @override
  ConsumerState<RegisterScreen> createState() => _RegisterScreenState();
}

class _RegisterScreenState extends ConsumerState<RegisterScreen> {
  final _formKey = GlobalKey<FormState>();
  final _nameController = TextEditingController();
  final _phoneController = TextEditingController();
  int _selectedCityId = 1; // Daloa par défaut

  @override
  void dispose() {
    _nameController.dispose();
    _phoneController.dispose();
    super.dispose();
  }

  void _submit() async {
    if (!_formKey.currentState!.validate()) return;

    final phone = _phoneController.text.trim();
    final name = _nameController.text.trim();

    final success = await ref.read(authProvider.notifier).demanderOtpInscription(
          phone: phone,
          name: name,
          cityId: _selectedCityId,
        );

    if (success && mounted) {
      Navigator.of(context).push(
        MaterialPageRoute(
          builder: (_) => OtpVerificationScreen(
            phone: phone,
            isRegister: true,
          ),
        ),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    final authState = ref.watch(authProvider);

    return Scaffold(
      backgroundColor: GoLivreurTheme.fond,
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(16),
          child: Form(
            key: _formKey,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                const SizedBox(height: 24),
                const Center(
                  child: GoLivreurLogo(
                    type: GoLivreurAppType.client,
                    size: 92,
                  ),
                ),
                const SizedBox(height: 12),
                Text(
                  'Inscription GoLivreur',
                  textAlign: TextAlign.center,
                  style: Theme.of(context).textTheme.titleLarge?.copyWith(
                        fontWeight: FontWeight.bold,
                        color: GoLivreurTheme.textePrincipal,
                      ),
                ),
                const SizedBox(height: 6),
                const Text(
                  'Créez votre compte pour commander à Daloa',
                  textAlign: TextAlign.center,
                  style: TextStyle(
                    color: GoLivreurTheme.texteSecondaire,
                    fontSize: 14,
                  ),
                ),
                const SizedBox(height: 24),
                if (authState.errorMessage != null) ...[
                  Container(
                    padding: const EdgeInsets.all(12),
                    decoration: BoxDecoration(
                      color: GoLivreurTheme.erreur.withValues(alpha: 0.1),
                      borderRadius: BorderRadius.circular(8),
                      border: Border.all(color: GoLivreurTheme.erreur),
                    ),
                    child: Text(
                      authState.errorMessage!,
                      style: const TextStyle(color: GoLivreurTheme.erreur),
                    ),
                  ),
                  const SizedBox(height: 16),
                ],
                TextFormField(
                  controller: _nameController,
                  decoration: const InputDecoration(
                    labelText: 'Nom et Prénom',
                    prefixIcon: Icon(Icons.person_outline),
                  ),
                  validator: (val) {
                    if (val == null || val.trim().length < 2) {
                      return 'Veuillez saisir votre nom complet';
                    }
                    return null;
                  },
                ),
                const SizedBox(height: 16),
                TextFormField(
                  controller: _phoneController,
                  keyboardType: TextInputType.phone,
                  decoration: const InputDecoration(
                    labelText: 'Numéro de téléphone (10 chiffres)',
                    prefixIcon: Icon(Icons.phone_outlined),
                    prefixText: '+225 ',
                  ),
                  validator: (val) {
                    if (val == null || val.trim().length != 10) {
                      return 'Le numéro ivoirien doit comporter 10 chiffres';
                    }
                    return null;
                  },
                ),
                const SizedBox(height: 16),
                DropdownButtonFormField<int>(
                  initialValue: _selectedCityId,
                  decoration: const InputDecoration(
                    labelText: 'Ville de livraison',
                    prefixIcon: Icon(Icons.location_city),
                  ),
                  items: const [
                    DropdownMenuItem(value: 1, child: Text('Daloa (Pilote)')),
                    DropdownMenuItem(value: 2, child: Text('Bouaké (Prochainement)')),
                  ],
                  onChanged: (val) {
                    if (val != null) setState(() => _selectedCityId = val);
                  },
                ),
                const SizedBox(height: 24),
                BoutonPrimaire(
                  label: 'S\'inscrire par SMS',
                  estEnChargement: authState.status == AuthStatus.loading,
                  onPressed: _submit,
                ),
                const SizedBox(height: 16),
                Row(
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [
                    const Text('Déjà un compte ? '),
                    TextButton(
                      onPressed: () {
                        Navigator.of(context).pushReplacement(
                          MaterialPageRoute(builder: (_) => const LoginScreen()),
                        );
                      },
                      child: const Text('Se connecter'),
                    ),
                  ],
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
