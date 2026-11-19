import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:golivreur_core/config/app_theme.dart';
import 'package:golivreur_core/config/env_config.dart';
import 'package:golivreur_core/services/api_client.dart';
import 'package:golivreur_core/widgets/atoms/widgets_atomiques.dart';
import 'package:golivreur_core/widgets/golivreur_logo.dart';
import '../../navigation/screens/business_main_navigation_screen.dart';
import 'business_register_screen.dart';

class BusinessLoginScreen extends StatefulWidget {
  final ApiClient? apiClientOverride;

  const BusinessLoginScreen({
    super.key,
    this.apiClientOverride,
  });

  @override
  State<BusinessLoginScreen> createState() => _BusinessLoginScreenState();
}

class _BusinessLoginScreenState extends State<BusinessLoginScreen> {
  late final TextEditingController _phoneController;
  late final TextEditingController _passwordController;
  bool _obscurePassword = true;
  bool _chargement = false;
  String? _erreur;

  @override
  void initState() {
    super.initState();
    _phoneController = TextEditingController();
    _passwordController = TextEditingController();
  }

  @override
  void dispose() {
    _phoneController.dispose();
    _passwordController.dispose();
    super.dispose();
  }

  Future<void> _ouvrirPreInscription() async {
    final phoneInscrit = await Navigator.of(context).push<String>(
      MaterialPageRoute(
        builder: (_) => BusinessRegisterScreen(
          apiClientOverride: widget.apiClientOverride,
        ),
      ),
    );
    if (phoneInscrit != null && phoneInscrit.isNotEmpty && mounted) {
      setState(() {
        _phoneController.text = phoneInscrit;
        _passwordController.clear();
        _erreur = null;
      });
    }
  }

  void _login() async {
    final phone = _phoneController.text.trim();
    final pwd = _passwordController.text;

    if (phone.isEmpty || pwd.isEmpty) {
      setState(
        () => _erreur = 'Veuillez saisir votre téléphone et mot de passe.',
      );
      return;
    }

    setState(() {
      _chargement = true;
      _erreur = null;
    });

    try {
      const storage = FlutterSecureStorage();
      final api = widget.apiClientOverride ??
          ApiClient(
            baseUrl: GoLivreurEnv.apiBaseUrl,
            storage: storage,
            onDeconnexionForcee: () {},
          );

      final res = await api.post('/auth/login-password', data: {
        'phone': phone,
        'password': pwd,
      });

      if (res.statusCode == 200 && res.data is Map && res.data['donnees'] != null) {
        final donnees = res.data['donnees'] as Map;
        final userRole = (donnees['user'] is Map ? donnees['user']['role'] : null)?.toString();
        if (userRole != null && userRole != 'merchant') {
          if (mounted) {
            setState(() {
              _chargement = false;
              _erreur = 'Ce compte n\'est pas un compte commerçant.';
            });
          }
          return;
        }
        final token = donnees['access_token'] as String?;
        final refresh = donnees['refresh_token'] as String?;
        if (token != null) {
          await storage.write(key: 'access_token', value: token);
        }
        if (refresh != null) {
          await storage.write(key: 'refresh_token', value: refresh);
        }

        if (mounted) {
          setState(() => _chargement = false);
          Navigator.of(context).pushReplacement(
            MaterialPageRoute(
              builder: (_) => const BusinessMainNavigationScreen(),
            ),
          );
        }
        return;
      }

      if (mounted) {
        setState(() {
          _chargement = false;
          _erreur = res.data['message'] as String? ?? 'Identifiants incorrects.';
        });
      }
    } on DioException catch (e) {
      if (mounted) {
        setState(() {
          _chargement = false;
          _erreur = ApiClient.messageErreur(e);
        });
      }
    } catch (_) {
      if (mounted) {
        setState(() {
          _chargement = false;
          _erreur = 'Impossible de se connecter au serveur. Vérifiez votre réseau.';
        });
      }
    }
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
                'Vendez en toute confiance.',
                textAlign: TextAlign.center,
                style: Theme.of(context).textTheme.titleLarge?.copyWith(
                      fontWeight: FontWeight.bold,
                      color: GoLivreurTheme.textePrincipal,
                    ),
              ),
              const SizedBox(height: 6),
              const Text(
                'Se connecter en tant que commerçant',
                textAlign: TextAlign.center,
                style: TextStyle(
                  color: GoLivreurTheme.texteSecondaire,
                  fontSize: 14,
                ),
              ),
              const SizedBox(height: 24),

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
              ),
              const SizedBox(height: 24),
              BoutonPrimaire(
                label: 'Accéder à ma boutique',
                estEnChargement: _chargement,
                onPressed: _login,
              ),
              const SizedBox(height: 16),
              OutlinedButton.icon(
                onPressed: _chargement ? null : _ouvrirPreInscription,
                icon: const Icon(Icons.add_business_outlined),
                label: const Text('Devenir commerçant partenaire'),
                style: OutlinedButton.styleFrom(
                  padding: const EdgeInsets.symmetric(vertical: 14),
                  foregroundColor: const Color(0xFF2E75B6),
                  side: const BorderSide(color: Color(0xFF2E75B6)),
                  shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(10),
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
