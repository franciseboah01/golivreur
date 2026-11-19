import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:golivreur_core/config/app_theme.dart';
import 'package:golivreur_core/widgets/atoms/widgets_atomiques.dart';
import 'package:golivreur_core/widgets/golivreur_logo.dart';
import 'package:golivreur_core/widgets/role_missing_dialog.dart';
import '../../gocolis/providers/gocolis_provider.dart';
import '../../goshop/providers/goshop_provider.dart';
import '../../goshop/providers/order_provider.dart';
import '../providers/auth_provider.dart';
import 'register_screen.dart';

class LoginScreen extends ConsumerStatefulWidget {
  const LoginScreen({super.key});

  @override
  ConsumerState<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends ConsumerState<LoginScreen> {
  final _phoneController = TextEditingController();
  final _passwordController = TextEditingController();
  final _formKey = GlobalKey<FormState>();
  bool _obscurePassword = true;

  @override
  void dispose() {
    _phoneController.dispose();
    _passwordController.dispose();
    super.dispose();
  }

  void _submit() async {
    if (!_formKey.currentState!.validate()) return;

    final phone = AuthNotifier.normalizePhone(_phoneController.text);
    final password = _passwordController.text;
    final success = await ref
        .read(authProvider.notifier)
        .connexionParMotDePasse(phone, password);

    if (success && mounted) {
      ref.read(cartProvider.notifier).clear();
      ref.read(searchQueryProvider.notifier).state = '';
      ref.invalidate(clientAddressesProvider);
      ref.invalidate(favoritesProvider);
      ref.invalidate(clientNotificationsProvider);
      ref.invalidate(ordersHistoryProvider);
      ref.invalidate(parcelsHistoryProvider);
      ref.invalidate(promoBannersProvider);
      ref.read(weatherProvider.notifier).chargerStatutVille();
      ref.read(clientAddressesProvider.notifier).chargerAdresses();
      ref.read(favoritesProvider.notifier).chargerFavoris();
    } else if (mounted) {
      final roleMissing = ref.read(authProvider).roleMissingInfo;
      if (roleMissing != null) {
        await showRoleMissingDialog(
          context,
          info: roleMissing,
          registerButtonLabel: 'Créer un compte client',
          onRegisterRole: () {
            ref.read(authProvider.notifier).clearError();
            Navigator.of(context).pushReplacement(
              MaterialPageRoute(builder: (_) => const RegisterScreen()),
            );
          },
        );
      }
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
                  'Achetez en toute simplicité.',
                  textAlign: TextAlign.center,
                  style: Theme.of(context).textTheme.titleLarge?.copyWith(
                        fontWeight: FontWeight.bold,
                        color: GoLivreurTheme.textePrincipal,
                      ),
                ),
                const SizedBox(height: 6),
                const Text(
                  'Se connecter en tant que client',
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
                  controller: _phoneController,
                  keyboardType: TextInputType.phone,
                  decoration: const InputDecoration(
                    labelText: 'Numéro de téléphone',
                    prefixIcon: Icon(Icons.phone_outlined),
                    prefixText: '+225 ',
                  ),
                  validator: (val) {
                    if (val == null ||
                        AuthNotifier.normalizePhone(val).length != 10) {
                      return 'Veuillez saisir un numéro à 10 chiffres';
                    }
                    return null;
                  },
                ),
                const SizedBox(height: 16),
                TextFormField(
                  controller: _passwordController,
                  obscureText: _obscurePassword,
                  decoration: InputDecoration(
                    labelText: 'Mot de passe',
                    prefixIcon: const Icon(Icons.lock_outline),
                    suffixIcon: IconButton(
                      icon: Icon(
                        _obscurePassword
                            ? Icons.visibility_outlined
                            : Icons.visibility_off_outlined,
                      ),
                      onPressed: () {
                        setState(() => _obscurePassword = !_obscurePassword);
                      },
                    ),
                  ),
                  validator: (val) {
                    if (val == null || val.trim().isEmpty) {
                      return 'Veuillez saisir votre mot de passe';
                    }
                    return null;
                  },
                ),
                const SizedBox(height: 24),
                BoutonPrimaire(
                  label: 'Se connecter',
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
                          MaterialPageRoute(
                            builder: (_) => const RegisterScreen(),
                          ),
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
