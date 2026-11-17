import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:golivreur_core/config/app_theme.dart';
import 'package:golivreur_core/config/env_config.dart';
import 'package:golivreur_core/services/api_client.dart';
import 'package:golivreur_core/widgets/atoms/widgets_atomiques.dart';
import 'package:golivreur_core/widgets/golivreur_logo.dart';
import '../../navigation/screens/driver_main_navigation_screen.dart';
import 'driver_register_screen.dart';

class DriverLoginScreen extends StatefulWidget {
  final ApiClient? apiClientOverride;

  const DriverLoginScreen({
    super.key,
    this.apiClientOverride,
  });

  @override
  State<DriverLoginScreen> createState() => _DriverLoginScreenState();
}

class _DriverLoginScreenState extends State<DriverLoginScreen> {
  late final TextEditingController _phoneController;
  late final TextEditingController _passwordController;
  bool _obscurePassword = true;
  bool _chargement = false;
  String? _erreur;

  @override
  void initState() {
    super.initState();
    _phoneController = TextEditingController(text: '0703000001');
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
      _phoneController.text = '0703000001';
      _passwordController.text = '123456';
      _erreur = null;
    });
  }

  Future<void> _ouvrirPreInscription() async {
    final phoneInscrit = await Navigator.of(context).push<String>(
      MaterialPageRoute(
        builder: (_) => DriverRegisterScreen(
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

  Future<void> _login() async {
    final phone = _phoneController.text.trim();
    final password = _passwordController.text;

    if (phone.isEmpty || password.isEmpty) {
      setState(() => _erreur = 'Veuillez saisir votre numéro et votre mot de passe.');
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
        'password': password,
      });

      if (res.statusCode == 200 && res.data is Map && res.data['donnees'] != null) {
        final donnees = res.data['donnees'] as Map;
        final token = donnees['access_token'] as String?;
        final refresh = donnees['refresh_token'] as String?;
        if (token != null) await storage.write(key: 'access_token', value: token);
        if (refresh != null) await storage.write(key: 'refresh_token', value: refresh);

        if (mounted) {
          setState(() => _chargement = false);
          Navigator.of(context).pushReplacement(
            MaterialPageRoute(
              builder: (_) => const DriverMainNavigationScreen(),
            ),
          );
        }
        return;
      }

      if (mounted) {
        setState(() {
          _chargement = false;
          _erreur = 'Impossible de vous connecter. Vérifiez vos identifiants.';
        });
      }
    } on DioException catch (e) {
      String message = 'Numéro ou mot de passe incorrect.';
      final data = e.response?.data;
      if (data is Map && data['message'] is String) {
        message = data['message'] as String;
      }
      if (mounted) {
        setState(() {
          _chargement = false;
          _erreur = message;
        });
      }
    } catch (_) {
      if (mounted) {
        setState(() {
          _chargement = false;
          _erreur = 'Serveur inaccessible. Vérifiez votre connexion.';
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
                'Espace Livreurs Partenaires',
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
                    const Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            'Ouattara Seydou (Moto Daloa)',
                            style: TextStyle(fontWeight: FontWeight.bold, fontSize: 13, color: Color(0xFF1B5E20)),
                          ),
                          Text(
                            'Tél: 0703000001 • MDP: 123456',
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
                label: 'Prendre mon service',
                estEnChargement: _chargement,
                onPressed: _login,
              ),
              const SizedBox(height: 16),
              OutlinedButton.icon(
                onPressed: _ouvrirPreInscription,
                icon: const Icon(Icons.person_add_alt_1_outlined),
                label: const Text('Devenir livreur'),
                style: OutlinedButton.styleFrom(
                  padding: const EdgeInsets.symmetric(vertical: 14),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
