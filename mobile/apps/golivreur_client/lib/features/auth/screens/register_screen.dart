import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:golivreur_core/config/app_theme.dart';
import 'package:golivreur_core/widgets/atoms/widgets_atomiques.dart';
import 'package:golivreur_core/widgets/golivreur_logo.dart';
import '../../../main.dart';
import '../../gocolis/providers/gocolis_provider.dart';
import '../../goshop/providers/goshop_provider.dart';
import '../../goshop/providers/order_provider.dart';
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
  final _passwordController = TextEditingController();
  bool _obscurePassword = true;
  int? _selectedCityId;

  @override
  void dispose() {
    _nameController.dispose();
    _phoneController.dispose();
    _passwordController.dispose();
    super.dispose();
  }

  void _submit() async {
    if (!_formKey.currentState!.validate()) return;
    if (_selectedCityId == null) return;

    final phone = AuthNotifier.normalizePhone(_phoneController.text);
    final name = _nameController.text.trim();
    final password = _passwordController.text.trim();
    final cityId = _selectedCityId!;

    final success = await ref.read(authProvider.notifier).demanderOtpInscription(
          phone: phone,
          name: name,
          cityId: cityId,
          password: password,
        );

    if (success && mounted) {
      if (ref.read(authProvider).status == AuthStatus.authenticated) {
        ref.read(cartProvider.notifier).clear();
        ref.read(searchQueryProvider.notifier).state = '';
        ref.invalidate(clientAddressesProvider);
        ref.invalidate(favoritesProvider);
        ref.invalidate(clientNotificationsProvider);
        ref.invalidate(ordersHistoryProvider);
        ref.invalidate(parcelsHistoryProvider);
        ref.read(weatherProvider.notifier).chargerStatutVille();
        ref.read(clientAddressesProvider.notifier).chargerAdresses();
        ref.read(favoritesProvider.notifier).chargerFavoris();
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text('Numéro vérifié automatiquement ! Bienvenue sur GoLivreur.'),
            backgroundColor: GoLivreurTheme.succes,
          ),
        );
        Navigator.of(context).pushAndRemoveUntil(
          MaterialPageRoute(builder: (_) => const ClientAuthGate()),
          (route) => false,
        );
        return;
      }

      Navigator.of(context).push(
        MaterialPageRoute(
          builder: (_) => OtpVerificationScreen(
            phone: phone,
            isRegister: true,
            name: name,
            cityId: cityId,
            password: password,
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
                  'Achetez en toute simplicité.',
                  textAlign: TextAlign.center,
                  style: Theme.of(context).textTheme.titleLarge?.copyWith(
                        fontWeight: FontWeight.bold,
                        color: GoLivreurTheme.textePrincipal,
                      ),
                ),
                const SizedBox(height: 6),
                const Text(
                  'Créez votre compte et vérifiez votre numéro par SMS',
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
                    if (val == null ||
                        AuthNotifier.normalizePhone(val).length != 10) {
                      return 'Le numéro ivoirien doit comporter 10 chiffres';
                    }
                    return null;
                  },
                ),
                const SizedBox(height: 16),
                TextFormField(
                  controller: _passwordController,
                  obscureText: _obscurePassword,
                  decoration: InputDecoration(
                    labelText: 'Mot de passe (min. 6 caractères)',
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
                    if (val == null || val.trim().length < 6) {
                      return 'Le mot de passe doit contenir au moins 6 caractères';
                    }
                    return null;
                  },
                ),
                const SizedBox(height: 16),
                ref.watch(citiesListProvider).when(
                  loading: () => const Padding(
                    padding: EdgeInsets.symmetric(vertical: 8),
                    child: LinearProgressIndicator(
                      color: GoLivreurTheme.primaire,
                    ),
                  ),
                  error: (_, __) => const Text(
                    'Impossible de charger la liste des villes.',
                    style: TextStyle(
                      color: GoLivreurTheme.erreur,
                      fontSize: 13,
                    ),
                  ),
                  data: (cities) {
                    if (cities.isEmpty) {
                      return Container(
                        padding: const EdgeInsets.all(12),
                        decoration: BoxDecoration(
                          color: Colors.grey.shade100,
                          borderRadius: BorderRadius.circular(10),
                          border: Border.all(color: GoLivreurTheme.bordure),
                        ),
                        child: const Text(
                          'Aucune ville disponible pour le moment.',
                          style: TextStyle(
                            color: GoLivreurTheme.texteSecondaire,
                            fontSize: 13,
                          ),
                        ),
                      );
                    }
                    final validValue = cities.any((c) => c.id == _selectedCityId)
                        ? _selectedCityId
                        : null;
                    return DropdownButtonFormField<int>(
                      initialValue: validValue,
                      decoration: const InputDecoration(
                        labelText: 'Ville de livraison *',
                        prefixIcon: Icon(Icons.location_city),
                      ),
                      items: cities
                          .map((c) => DropdownMenuItem<int>(
                                value: c.id,
                                child: Text(c.name),
                              ))
                          .toList(),
                      onChanged: (val) => setState(() => _selectedCityId = val),
                      validator: (val) =>
                          val == null ? 'Veuillez choisir votre ville' : null,
                    );
                  },
                ),
                const SizedBox(height: 24),
                BoutonPrimaire(
                  label: 'Vérifier mon numéro par SMS',
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
                        ref.read(authProvider.notifier).clearError();
                        Navigator.of(context).pushReplacement(
                          MaterialPageRoute(
                            builder: (_) => const LoginScreen(),
                          ),
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
