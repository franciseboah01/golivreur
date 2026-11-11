import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:golivreur_core/config/app_theme.dart';
import 'package:golivreur_core/widgets/atoms/widgets_atomiques.dart';
import 'package:golivreur_core/widgets/golivreur_logo.dart';
import '../../goshop/providers/goshop_provider.dart';
import '../providers/auth_provider.dart';
import 'otp_verification_screen.dart';
import 'register_screen.dart';

class LoginScreen extends ConsumerStatefulWidget {
  const LoginScreen({super.key});

  @override
  ConsumerState<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends ConsumerState<LoginScreen> {
  final _phoneController = TextEditingController();
  final _formKey = GlobalKey<FormState>();

  @override
  void dispose() {
    _phoneController.dispose();
    super.dispose();
  }

  void _submit() async {
    if (!_formKey.currentState!.validate()) return;

    final phone = AuthNotifier.normalizePhone(_phoneController.text);
    final success = await ref.read(authProvider.notifier).demanderOtpConnexion(phone);

    if (success && mounted) {
      Navigator.of(context).push(
        MaterialPageRoute(
          builder: (_) => OtpVerificationScreen(
            phone: phone,
            isRegister: false,
          ),
        ),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    final authState = ref.watch(authProvider);
    final branding = ref.watch(appBrandingProvider);

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
                Center(
                  child: GoLivreurLogo(
                    type: GoLivreurAppType.client,
                    size: 92,
                    logoUrl: branding.logoUrl,
                    defaultEmoji: branding.defaultEmoji,
                  ),
                ),
                const SizedBox(height: 12),
                Text(
                  'Connexion GoLivreur',
                  textAlign: TextAlign.center,
                  style: Theme.of(context).textTheme.titleLarge?.copyWith(
                        fontWeight: FontWeight.bold,
                        color: GoLivreurTheme.textePrincipal,
                      ),
                ),
                const SizedBox(height: 6),
                const Text(
                  'Saisissez votre numéro pour recevoir un code de connexion',
                  textAlign: TextAlign.center,
                  style: TextStyle(
                    color: GoLivreurTheme.texteSecondaire,
                    fontSize: 14,
                  ),
                ),
                const SizedBox(height: 24),
                Container(
                  padding: const EdgeInsets.all(12),
                  decoration: BoxDecoration(
                    color: GoLivreurTheme.primaire.withValues(alpha: 0.08),
                    borderRadius: BorderRadius.circular(10),
                    border: Border.all(color: GoLivreurTheme.primaire.withValues(alpha: 0.3)),
                  ),
                  child: Row(
                    children: [
                      const Icon(Icons.info_outline, color: GoLivreurTheme.primaire, size: 24),
                      const SizedBox(width: 10),
                      const Expanded(
                        child: Text(
                          'Compte Démo Client : 0701000001\nCode OTP universel : 1234',
                          style: TextStyle(fontSize: 12, fontWeight: FontWeight.w600),
                        ),
                      ),
                      TextButton(
                        onPressed: () {
                          setState(() {
                            _phoneController.text = '0701000001';
                          });
                        },
                        child: const Text('Remplir', style: TextStyle(fontWeight: FontWeight.bold)),
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: 16),
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
                  controller: _phoneController,
                  keyboardType: TextInputType.phone,
                  decoration: const InputDecoration(
                    labelText: 'Numéro de téléphone',
                    prefixIcon: Icon(Icons.phone_outlined),
                    prefixText: '+225 ',
                  ),
                  validator: (val) {
                    if (val == null || AuthNotifier.normalizePhone(val).length != 10) {
                      return 'Veuillez saisir un numéro à 10 chiffres';
                    }
                    return null;
                  },
                ),
                const SizedBox(height: 24),
                BoutonPrimaire(
                  label: 'Recevoir le code SMS',
                  estEnChargement: authState.status == AuthStatus.loading,
                  onPressed: _submit,
                ),
                const SizedBox(height: 16),
                Row(
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [
                    const Text('Nouveau sur GoLivreur ? '),
                    TextButton(
                      onPressed: () {
                        ref.read(authProvider.notifier).clearError();
                        Navigator.of(context).pushReplacement(
                          MaterialPageRoute(builder: (_) => const RegisterScreen()),
                        );
                      },
                      child: const Text('Créer un compte'),
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
