import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:golivreur_core/config/app_theme.dart';
import 'package:golivreur_core/config/env_config.dart';
import 'package:golivreur_core/services/api_client.dart';
import 'package:golivreur_core/widgets/golivreur_widgets.dart';

class DriverRegisterScreen extends StatefulWidget {
  final ApiClient? apiClientOverride;

  const DriverRegisterScreen({
    super.key,
    this.apiClientOverride,
  });

  @override
  State<DriverRegisterScreen> createState() => _DriverRegisterScreenState();
}

class _DriverRegisterScreenState extends State<DriverRegisterScreen> {
  final _nameController = TextEditingController();
  final _phoneController = TextEditingController();
  final _passwordController = TextEditingController();
  final _plateController = TextEditingController();

  bool _obscurePassword = true;
  bool _chargement = false;
  String? _erreur;
  String? _succesMessage;

  List<Map<String, dynamic>> _villes = [
    {'id': 1, 'name': 'Daloa'},
  ];
  int _selectedCityId = 1;

  List<Map<String, dynamic>> _typesVehicule = [
    {'slug': 'moto', 'name': 'Moto'},
  ];
  String _selectedVehicleType = 'moto';

  ApiClient _buildApi() {
    if (widget.apiClientOverride != null) {
      return widget.apiClientOverride!;
    }
    const storage = FlutterSecureStorage();
    return ApiClient(
      baseUrl: GoLivreurEnv.apiBaseUrl,
      storage: storage,
      onDeconnexionForcee: () {},
    );
  }

  @override
  void initState() {
    super.initState();
    _chargerOptions();
  }

  Future<void> _chargerOptions() async {
    final api = _buildApi();
    try {
      final resCities = await api.get('/cities');
      final rawCities = resCities.data is Map ? resCities.data['donnees'] : null;
      if (rawCities is List && rawCities.isNotEmpty) {
        final parsed = rawCities
            .whereType<Map>()
            .map((e) => {
                  'id': e['id'] is int
                      ? e['id'] as int
                      : int.tryParse('${e['id']}') ?? 1,
                  'name': '${e['name'] ?? 'Ville'}',
                })
            .toList();
        if (parsed.isNotEmpty && mounted) {
          setState(() {
            _villes = parsed;
            _selectedCityId = parsed.first['id'] as int;
          });
        }
      }
    } catch (_) {}

    try {
      final resTypes = await api.get('/vehicle-types');
      final rawTypes = resTypes.data is Map ? resTypes.data['donnees'] : null;
      if (rawTypes is List && rawTypes.isNotEmpty) {
        final parsed = rawTypes
            .whereType<Map>()
            .map((e) => {
                  'slug': '${e['slug'] ?? 'moto'}',
                  'name': '${e['name'] ?? 'Moto'}',
                })
            .toList();
        if (parsed.isNotEmpty && mounted) {
          setState(() {
            _typesVehicule = parsed;
            _selectedVehicleType = '${parsed.first['slug']}';
          });
        }
      }
    } catch (_) {}
  }

  @override
  void dispose() {
    _nameController.dispose();
    _phoneController.dispose();
    _passwordController.dispose();
    _plateController.dispose();
    super.dispose();
  }

  Future<void> _soumettrePreInscription() async {
    final name = _nameController.text.trim();
    final phone = _phoneController.text.trim();
    final password = _passwordController.text;
    final plate = _plateController.text.trim();

    if (name.isEmpty || phone.isEmpty || password.isEmpty || plate.isEmpty) {
      setState(() {
        _erreur = 'Veuillez renseigner tous les champs obligatoires.';
      });
      return;
    }

    if (password.length < 6) {
      setState(() {
        _erreur = 'Le mot de passe doit contenir au moins 6 caractères.';
      });
      return;
    }

    setState(() {
      _chargement = true;
      _erreur = null;
    });

    try {
      final api = _buildApi();
      final res = await api.post('/auth/register-driver', data: {
        'name': name,
        'phone': phone,
        'password': password,
        'city_id': _selectedCityId,
        'vehicle_type': _selectedVehicleType,
        'vehicle_plate': plate,
      });

      final msg = (res.data is Map && res.data['message'] is String)
          ? res.data['message'] as String
          : 'Pré-inscription enregistrée. Passez en agence GoLivreur pour vérifier vos pièces et activer votre compte.';

      if (mounted) {
        setState(() {
          _chargement = false;
          _succesMessage = msg;
        });
      }
    } on DioException catch (e) {
      String message = 'Impossible d\'enregistrer votre pré-inscription.';
      final data = e.response?.data;
      if (data is Map) {
        if (data['errors'] is Map && (data['errors'] as Map).isNotEmpty) {
          final firstErr = (data['errors'] as Map).values.first;
          if (firstErr is List && firstErr.isNotEmpty) {
            message = '${firstErr.first}';
          }
        } else if (data['message'] is String) {
          message = data['message'] as String;
        }
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
          _erreur = 'Erreur réseau. Vérifiez votre connexion et réessayez.';
        });
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: GoLivreurTheme.fond,
      appBar: AppBar(
        title: const Text('Devenir livreur GoLivreur'),
        backgroundColor: GoLivreurTheme.fond,
        foregroundColor: GoLivreurTheme.textePrincipal,
        elevation: 0,
      ),
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(20),
          child: _succesMessage != null
              ? _buildConfirmationAgence(context)
              : _buildFormulaire(),
        ),
      ),
    );
  }

  Widget _buildConfirmationAgence(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        const SizedBox(height: 24),
        Container(
          padding: const EdgeInsets.all(20),
          decoration: BoxDecoration(
            color: const Color(0xFF1B5E20).withValues(alpha: 0.08),
            borderRadius: BorderRadius.circular(14),
            border: Border.all(
              color: const Color(0xFF1B5E20).withValues(alpha: 0.35),
            ),
          ),
          child: Column(
            children: [
              const Icon(
                Icons.check_circle_outline,
                color: Color(0xFF1B5E20),
                size: 56,
              ),
              const SizedBox(height: 16),
              const Text(
                'Dossier transmis en agence',
                textAlign: TextAlign.center,
                style: TextStyle(
                  fontSize: 18,
                  fontWeight: FontWeight.bold,
                  color: Color(0xFF1B5E20),
                ),
              ),
              const SizedBox(height: 12),
              Text(
                _succesMessage!,
                textAlign: TextAlign.center,
                style: const TextStyle(
                  fontSize: 14,
                  height: 1.4,
                  color: GoLivreurTheme.textePrincipal,
                ),
              ),
              const SizedBox(height: 16),
              const Text(
                'Pièces à présenter en agence :\n• Pièce d\'identité (CNI / Passeport)\n• Permis de conduire\n• Votre véhicule pour vérification',
                textAlign: TextAlign.left,
                style: TextStyle(
                  fontSize: 13,
                  color: GoLivreurTheme.texteSecondaire,
                ),
              ),
            ],
          ),
        ),
        const SizedBox(height: 24),
        BoutonPrimaire(
          label: 'Retour à la connexion',
          onPressed: () => Navigator.of(context).pop(_phoneController.text.trim()),
        ),
      ],
    );
  }

  Widget _buildFormulaire() {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        const Center(
          child: GoLivreurLogo(
            type: GoLivreurAppType.driver,
            size: 68,
          ),
        ),
        const SizedBox(height: 16),
        Container(
          padding: const EdgeInsets.all(12),
          decoration: BoxDecoration(
            color: const Color(0xFF1B5E20).withValues(alpha: 0.08),
            borderRadius: BorderRadius.circular(10),
            border: Border.all(
              color: const Color(0xFF1B5E20).withValues(alpha: 0.3),
            ),
          ),
          child: const Row(
            children: [
              Icon(Icons.info_outline, color: Color(0xFF1B5E20), size: 22),
              SizedBox(width: 10),
              Expanded(
                child: Text(
                  'Étape 1 : Pré-inscrivez-vous ici.\nÉtape 2 : Passez en agence GoLivreur pour vérifier vos pièces et activer votre compte.',
                  style: TextStyle(fontSize: 12, height: 1.35),
                ),
              ),
            ],
          ),
        ),
        const SizedBox(height: 18),
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
          controller: _nameController,
          textCapitalization: TextCapitalization.words,
          decoration: const InputDecoration(
            labelText: 'Nom et prénom',
            prefixIcon: Icon(Icons.person_outline),
          ),
        ),
        const SizedBox(height: 14),
        TextFormField(
          controller: _phoneController,
          keyboardType: TextInputType.phone,
          decoration: const InputDecoration(
            labelText: 'Numéro de téléphone',
            prefixIcon: Icon(Icons.phone_outlined),
            prefixText: '+225 ',
          ),
        ),
        const SizedBox(height: 14),
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
        ),
        const SizedBox(height: 14),
        DropdownButtonFormField<int>(
          initialValue: _selectedCityId,
          decoration: const InputDecoration(
            labelText: 'Ville d\'activité',
            prefixIcon: Icon(Icons.location_city_outlined),
          ),
          items: _villes
              .map(
                (v) => DropdownMenuItem<int>(
                  value: v['id'] as int,
                  child: Text('${v['name']}'),
                ),
              )
              .toList(),
          onChanged: (val) {
            if (val != null) {
              setState(() => _selectedCityId = val);
            }
          },
        ),
        const SizedBox(height: 14),
        DropdownButtonFormField<String>(
          initialValue: _selectedVehicleType,
          decoration: const InputDecoration(
            labelText: 'Type de véhicule',
            prefixIcon: Icon(Icons.two_wheeler_outlined),
          ),
          items: _typesVehicule
              .map(
                (t) => DropdownMenuItem<String>(
                  value: '${t['slug']}',
                  child: Text('${t['name']}'),
                ),
              )
              .toList(),
          onChanged: (val) {
            if (val != null) {
              setState(() => _selectedVehicleType = val);
            }
          },
        ),
        const SizedBox(height: 14),
        TextFormField(
          controller: _plateController,
          textCapitalization: TextCapitalization.characters,
          decoration: const InputDecoration(
            labelText: 'Plaque d\'immatriculation (ex: 1234-JK-01)',
            prefixIcon: Icon(Icons.badge_outlined),
          ),
        ),
        const SizedBox(height: 24),
        BoutonPrimaire(
          label: 'Envoyer ma pré-inscription',
          estEnChargement: _chargement,
          onPressed: _soumettrePreInscription,
        ),
      ],
    );
  }
}

