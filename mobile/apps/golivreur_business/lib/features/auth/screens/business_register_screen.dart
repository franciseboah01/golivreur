import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:golivreur_core/config/app_theme.dart';
import 'package:golivreur_core/config/env_config.dart';
import 'package:golivreur_core/services/api_client.dart';
import 'package:golivreur_core/widgets/golivreur_widgets.dart';

class BusinessRegisterScreen extends StatefulWidget {
  final ApiClient? apiClientOverride;

  const BusinessRegisterScreen({
    super.key,
    this.apiClientOverride,
  });

  @override
  State<BusinessRegisterScreen> createState() => _BusinessRegisterScreenState();
}

class _BusinessRegisterScreenState extends State<BusinessRegisterScreen> {
  final _nameController = TextEditingController();
  final _managerController = TextEditingController();
  final _phoneController = TextEditingController();
  final _passwordController = TextEditingController();
  final _addressController = TextEditingController();

  bool _obscurePassword = true;
  bool _chargement = false;
  bool _chargementOptions = true;
  bool _locatingGps = false;
  double? _shopLat;
  double? _shopLng;
  String? _erreur;
  String? _succesMessage;

  List<Map<String, dynamic>> _cities = [];
  int? _selectedCityId;

  List<Map<String, String>> _categories = [
    {'slug': 'maquis', 'name': 'Maquis & Grillades'},
    {'slug': 'restaurant', 'name': 'Restaurant'},
    {'slug': 'fast_food', 'name': 'Fast-Food & Snack'},
    {'slug': 'pharmacie', 'name': 'Pharmacie'},
    {'slug': 'boulangerie', 'name': 'Boulangerie & Pâtisserie'},
    {'slug': 'supermarche', 'name': 'Supermarché & Épicerie'},
    {'slug': 'high_tech', 'name': 'Boutique & High-Tech'},
  ];
  String _selectedCategory = 'maquis';

  ApiClient get _api =>
      widget.apiClientOverride ??
      ApiClient(
        baseUrl: GoLivreurEnv.apiBaseUrl,
        storage: const FlutterSecureStorage(),
        onDeconnexionForcee: () {},
      );

  @override
  void initState() {
    super.initState();
    _chargerOptions();
  }

  @override
  void dispose() {
    _nameController.dispose();
    _managerController.dispose();
    _phoneController.dispose();
    _passwordController.dispose();
    _addressController.dispose();
    super.dispose();
  }

  Future<void> _chargerOptions() async {
    try {
      final results = await Future.wait([
        _api.get('/cities'),
        _api.get('/categories'),
      ]);

      final citiesData = results[0].data['donnees'];
      if (citiesData is List && citiesData.isNotEmpty) {
        final parsedCities = citiesData
            .whereType<Map>()
            .map((e) => {
                  'id': e['id'] as int? ?? 1,
                  'name': (e['name'] ?? 'Ville').toString(),
                  'center_lat': (e['center_lat'] as num?)?.toDouble(),
                  'center_lng': (e['center_lng'] as num?)?.toDouble(),
                })
            .toList();
        if (parsedCities.isNotEmpty) {
          _cities = parsedCities;
          _selectedCityId = _cities.first['id'] as int;
        }
      }

      final catData = results[1].data['donnees'];
      if (catData is List && catData.isNotEmpty) {
        final parsedCategories = catData
            .whereType<Map>()
            .map((e) => {
                  'slug': (e['slug'] ?? 'maquis').toString(),
                  'name': (e['name'] ?? 'Commerce').toString(),
                })
            .toList();
        if (parsedCategories.isNotEmpty) {
          _categories = parsedCategories;
          _selectedCategory = _categories.first['slug']!;
        }
      }
    } catch (_) {
      // Conserve les catégories standard si hors-ligne
    } finally {
      if (mounted) {
        setState(() => _chargementOptions = false);
      }
    }
  }

  Map<String, dynamic>? get _selectedCityMap {
    for (final c in _cities) {
      if (c['id'] == _selectedCityId) return c;
    }
    return null;
  }

  Future<void> _detecterGpsInscription() async {
    setState(() => _locatingGps = true);
    final res = await GpsLocationService.obtenirPositionActuelle(
      withAddressLookup: true,
    );
    if (!mounted) return;
    setState(() => _locatingGps = false);

    if (res.isSuccess && res.lat != null && res.lng != null) {
      setState(() {
        _shopLat = res.lat;
        _shopLng = res.lng;
        if (_addressController.text.trim().isEmpty &&
            res.formattedAddress != null &&
            res.formattedAddress!.trim().isNotEmpty) {
          _addressController.text = res.formattedAddress!.trim();
        }
      });
    } else if (res.errorMessage != null) {
      setState(() => _erreur = res.errorMessage);
    }
  }

  Future<void> _choisirSurCarteInscription() async {
    final city = _selectedCityMap;
    final initLat = _shopLat ?? (city?['center_lat'] as double?) ?? 6.8770;
    final initLng = _shopLng ?? (city?['center_lng'] as double?) ?? -6.4502;
    final picked = await Navigator.of(context).push<PickedLocation>(
      MaterialPageRoute(
        builder: (_) => OsmLocationPickerScreen(
          title: 'Position de votre commerce',
          initialLat: initLat,
          initialLng: initLng,
          confirmLabel: 'Valider l\'emplacement',
        ),
      ),
    );
    if (!mounted || picked == null) return;
    setState(() {
      _shopLat = picked.lat;
      _shopLng = picked.lng;
      if (_addressController.text.trim().isEmpty &&
          picked.addressLabel != null &&
          picked.addressLabel!.trim().isNotEmpty) {
        _addressController.text = picked.addressLabel!.trim();
      }
    });
  }

  Future<void> _soumettrePreInscription() async {
    final name = _nameController.text.trim();
    final manager = _managerController.text.trim();
    final phone = _phoneController.text.trim();
    final password = _passwordController.text;
    final address = _addressController.text.trim();

    if (name.length < 2) {
      setState(() => _erreur = 'Veuillez saisir le nom de votre commerce.');
      return;
    }
    if (phone.isEmpty) {
      setState(
        () => _erreur = 'Veuillez saisir le numéro de téléphone du gérant.',
      );
      return;
    }
    if (password.length < 6) {
      setState(
        () => _erreur = 'Le mot de passe doit contenir au moins 6 caractères.',
      );
      return;
    }
    if (_selectedCityId == null) {
      setState(
        () => _erreur = 'Veuillez sélectionner votre ville d\'implantation.',
      );
      return;
    }
    if (address.length < 2) {
      setState(
        () => _erreur = 'Veuillez saisir l\'adresse ou le quartier du commerce.',
      );
      return;
    }

    setState(() {
      _chargement = true;
      _erreur = null;
    });

    try {
      final res = await _api.post('/auth/register-merchant', data: {
        'name': name,
        if (manager.isNotEmpty) 'manager_name': manager,
        'phone': phone,
        'password': password,
        'city_id': _selectedCityId,
        'category': _selectedCategory,
        'address': address,
        if (_shopLat != null && _shopLng != null) 'lat': _shopLat,
        if (_shopLat != null && _shopLng != null) 'lng': _shopLng,
      });

      if (!mounted) return;

      if ((res.statusCode == 201 || res.statusCode == 200) &&
          res.data['succes'] == true) {
        setState(() {
          _chargement = false;
          _succesMessage = res.data['message'] as String? ??
              'Pré-inscription enregistrée. Passez en agence GoLivreur ou avec un agent terrain pour valider et compléter votre dossier.';
        });
        return;
      }

      setState(() {
        _chargement = false;
        _erreur = res.data['message'] as String? ??
            'Impossible d\'enregistrer la pré-inscription.';
      });
    } on DioException catch (e) {
      if (!mounted) return;
      setState(() {
        _chargement = false;
        _erreur = ApiClient.messageErreur(e);
      });
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _chargement = false;
        _erreur = 'Erreur réseau. Vérifiez votre connexion.';
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: GoLivreurTheme.fond,
      appBar: AppBar(
        title: const Text('Devenir commerçant GoLivreur'),
        backgroundColor: Colors.white,
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
            color: const Color(0xFF2E75B6).withValues(alpha: 0.08),
            borderRadius: BorderRadius.circular(14),
            border: Border.all(
              color: const Color(0xFF2E75B6).withValues(alpha: 0.35),
            ),
          ),
          child: Column(
            children: [
              const Icon(
                Icons.storefront_outlined,
                color: Color(0xFF2E75B6),
                size: 56,
              ),
              const SizedBox(height: 16),
              const Text(
                'Dossier commerce transmis',
                textAlign: TextAlign.center,
                style: TextStyle(
                  fontSize: 18,
                  fontWeight: FontWeight.bold,
                  color: Color(0xFF2E75B6),
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
                'Validation en agence ou avec un agent terrain :\n• Vérification de l\'emplacement et des horaires\n• Paramétrage du catalogue et de la commission\n• Activation immédiate de votre boutique',
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
          onPressed: () =>
              Navigator.of(context).pop(_phoneController.text.trim()),
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
            type: GoLivreurAppType.business,
            size: 68,
          ),
        ),
        const SizedBox(height: 16),
        Container(
          padding: const EdgeInsets.all(12),
          decoration: BoxDecoration(
            color: const Color(0xFF2E75B6).withValues(alpha: 0.08),
            borderRadius: BorderRadius.circular(10),
            border: Border.all(
              color: const Color(0xFF2E75B6).withValues(alpha: 0.3),
            ),
          ),
          child: const Row(
            children: [
              Icon(Icons.info_outline, color: Color(0xFF2E75B6), size: 22),
              SizedBox(width: 10),
              Expanded(
                child: Text(
                  'Étape 1 : Pré-inscrivez votre commerce ici.\nÉtape 2 : Validez ou complétez votre dossier en agence GoLivreur ou avec un agent terrain.',
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
            labelText: 'Nom du commerce / restaurant',
            prefixIcon: Icon(Icons.storefront_outlined),
          ),
        ),
        const SizedBox(height: 14),
        TextFormField(
          controller: _managerController,
          textCapitalization: TextCapitalization.words,
          decoration: const InputDecoration(
            labelText: 'Nom et prénom du gérant',
            prefixIcon: Icon(Icons.person_outline),
          ),
        ),
        const SizedBox(height: 14),
        TextFormField(
          controller: _phoneController,
          keyboardType: TextInputType.phone,
          decoration: const InputDecoration(
            labelText: 'Numéro de téléphone gérant',
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
          key: ValueKey('city_$_selectedCityId'),
          initialValue: _selectedCityId,
          decoration: const InputDecoration(
            labelText: 'Ville d\'implantation',
            prefixIcon: Icon(Icons.location_city_outlined),
          ),
          hint: const Text('Sélectionner une ville'),
          items: _cities
              .map(
                (city) => DropdownMenuItem<int>(
                  value: city['id'] as int,
                  child: Text(city['name'] as String),
                ),
              )
              .toList(),
          onChanged: _chargementOptions
              ? null
              : (val) {
                  if (val != null) {
                    setState(() => _selectedCityId = val);
                  }
                },
        ),
        const SizedBox(height: 14),
        DropdownButtonFormField<String>(
          initialValue: _selectedCategory,
          decoration: const InputDecoration(
            labelText: 'Catégorie d\'activité',
            prefixIcon: Icon(Icons.category_outlined),
          ),
          items: _categories
              .map(
                (cat) => DropdownMenuItem<String>(
                  value: cat['slug'],
                  child: Text(cat['name']!),
                ),
              )
              .toList(),
          onChanged: _chargementOptions
              ? null
              : (val) {
                  if (val != null) {
                    setState(() => _selectedCategory = val);
                  }
                },
        ),
        const SizedBox(height: 14),
        TextFormField(
          controller: _addressController,
          decoration: const InputDecoration(
            labelText: 'Adresse / Quartier (ex: Quartier Commerce)',
            prefixIcon: Icon(Icons.place_outlined),
          ),
        ),
        const SizedBox(height: 10),
        Row(
          children: [
            Expanded(
              child: OutlinedButton.icon(
                onPressed: _locatingGps ? null : _detecterGpsInscription,
                icon: _locatingGps
                    ? const SizedBox(
                        width: 14,
                        height: 14,
                        child: CircularProgressIndicator(strokeWidth: 2),
                      )
                    : const Icon(Icons.my_location, size: 16),
                label: Text(
                  _locatingGps ? 'GPS...' : 'Position GPS',
                  style: const TextStyle(fontSize: 12),
                ),
              ),
            ),
            const SizedBox(width: 8),
            Expanded(
              child: OutlinedButton.icon(
                onPressed: _choisirSurCarteInscription,
                icon: const Icon(Icons.map_outlined, size: 16),
                label: const Text(
                  'Pointer sur la carte',
                  style: TextStyle(fontSize: 12),
                ),
              ),
            ),
          ],
        ),
        if (_shopLat != null && _shopLng != null) ...[
          const SizedBox(height: 6),
          Text(
            '📍 GPS sélectionné : ${_shopLat!.toStringAsFixed(5)}, ${_shopLng!.toStringAsFixed(5)}',
            style: const TextStyle(
              fontSize: 12,
              fontWeight: FontWeight.w600,
              color: GoLivreurTheme.succes,
            ),
          ),
        ],
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

