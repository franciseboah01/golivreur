import 'dart:math' as math;
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:golivreur_core/config/app_theme.dart';
import 'package:golivreur_core/services/api_client.dart' hide apiClientProvider;
import 'package:golivreur_core/widgets/golivreur_widgets.dart';
import 'package:image_picker/image_picker.dart';
import '../../auth/providers/auth_provider.dart';
import '../../goshop/providers/goshop_provider.dart';
import '../providers/gocolis_provider.dart';
import 'parcel_tracking_screen.dart';

class NewParcelScreen extends ConsumerStatefulWidget {
  const NewParcelScreen({super.key});

  @override
  ConsumerState<NewParcelScreen> createState() => _NewParcelScreenState();
}

class _NewParcelScreenState extends ConsumerState<NewParcelScreen> {
  final _formKey = GlobalKey<FormState>();
  final _recipientNameController = TextEditingController();
  final _recipientPhoneController = TextEditingController();
  final _descriptionController = TextEditingController();
  final _declaredValueController = TextEditingController();
  final _imagePicker = ImagePicker();

  double? _pickupLat;
  double? _pickupLng;
  String? _pickupAddressText;
  bool _locatingPickup = false;

  DeliveryNeighborhood? _selectedNeighborhood;
  String? _selectedSize; // petit | moyen | grand (obligatoire)
  String _selectedPaymentMethod = 'cash';

  XFile? _parcelPhotoFile;
  Uint8List? _parcelPhotoBytes;

  bool _isSubmitting = false;

  @override
  void initState() {
    super.initState();
    _recipientNameController.addListener(_onRequiredFieldChanged);
    _recipientPhoneController.addListener(_onRequiredFieldChanged);

    WidgetsBinding.instance.addPostFrameCallback((_) {
      // 1. Auto-actualisation GPS de ramassage dès l'entrée sur la page
      _detectPickupGps(silent: true);

      // 2. S'assurer que la liste dynamique des quartiers (API Admin) est à jour
      final weather = ref.read(weatherProvider);
      if (weather.neighborhoods.isEmpty) {
        ref.read(weatherProvider.notifier).chargerStatutVille();
      }
    });
  }

  void _onRequiredFieldChanged() {
    if (mounted) setState(() {});
  }

  @override
  void dispose() {
    _recipientNameController.removeListener(_onRequiredFieldChanged);
    _recipientPhoneController.removeListener(_onRequiredFieldChanged);
    _recipientNameController.dispose();
    _recipientPhoneController.dispose();
    _descriptionController.dispose();
    _declaredValueController.dispose();
    super.dispose();
  }

  /// Vrai uniquement lorsque tous les champs obligatoires (Nom, Tél, Quartier, Format) sont valides.
  bool get _isFormReady {
    final nameOk = _recipientNameController.text.trim().length >= 2;
    final cleanPhone = AuthNotifier.normalizePhone(_recipientPhoneController.text);
    final phoneOk = RegExp(r'^(01|05|07|21|25|27)[0-9]{8}$').hasMatch(cleanPhone);
    final neighborhoodOk = _selectedNeighborhood != null;
    final sizeOk = _selectedSize != null && _selectedSize!.isNotEmpty;
    return nameOk && phoneOk && neighborhoodOk && sizeOk;
  }

  double _computedDistanceKm() {
    final q = _selectedNeighborhood;
    if (q != null) {
      if (_pickupLat != null &&
          _pickupLng != null &&
          q.lat != 0.0 &&
          q.lng != 0.0) {
        final raw = GpsLocationService.distanceKm(
          _pickupLat!,
          _pickupLng!,
          q.lat,
          q.lng,
        );
        return math.max(1.0, double.parse((raw * 1.3).toStringAsFixed(1)));
      }
      if (q.defaultDistanceKm > 0) {
        return q.defaultDistanceKm;
      }
    }
    return 2.0;
  }

  double _computeShippingFee(ParcelTariffsConfig tarifs) {
    final size = _selectedSize ?? 'moyen';
    final tier = tarifs.forSize(size);
    final dist = _computedDistanceKm();
    final extraKm = math.max(0.0, dist - 2.0);
    return (tier.base + (extraKm * tier.parKm)).roundToDouble();
  }

  double _computeInsurance(ParcelTariffsConfig tarifs) {
    final declared = double.tryParse(_declaredValueController.text.trim()) ?? 0;
    if (declared <= tarifs.seuilAssurance) return 0;
    return (declared * (tarifs.tauxAssurance / 100.0)).roundToDouble();
  }

  String _pickupSummaryText() {
    if (_pickupAddressText != null && _pickupAddressText!.trim().isNotEmpty) {
      if (_pickupLat != null && _pickupLng != null) {
        return '${_pickupAddressText!.trim()} (${_pickupLat!.toStringAsFixed(4)}, ${_pickupLng!.toStringAsFixed(4)})';
      }
      return _pickupAddressText!.trim();
    }
    if (_pickupLat != null && _pickupLng != null) {
      return 'Position GPS (${_pickupLat!.toStringAsFixed(4)}, ${_pickupLng!.toStringAsFixed(4)})';
    }
    final cityName = ref.read(weatherProvider).cityName;
    return cityName.isNotEmpty
        ? 'Position actuelle — $cityName (GPS en attente)'
        : 'Position de ramassage par défaut';
  }

  String _sizeLabel(String size) {
    return switch (size) {
      'petit' => 'Petit (max 1 kg)',
      'moyen' => 'Moyen (max 5 kg)',
      'grand' => 'Grand (max 15 kg)',
      _ => size,
    };
  }

  String _paymentLabel(String method) {
    return switch (method) {
      'cash' => 'Espèces au ramassage',
      'wave' => 'Wave',
      'orange_money' => 'Orange Money Côte d\'Ivoire',
      'mtn_momo' => 'MTN Mobile Money',
      'moov' => 'Moov Money (Flooz)',
      _ => method,
    };
  }

  Future<void> _detectPickupGps({bool silent = false}) async {
    if (_locatingPickup) return;
    setState(() => _locatingPickup = true);

    final res = await GpsLocationService.obtenirPositionActuelle(
      withAddressLookup: true,
    );

    if (!mounted) return;
    setState(() {
      _locatingPickup = false;
      if (res.isSuccess) {
        _pickupLat = res.lat;
        _pickupLng = res.lng;
        if (res.formattedAddress != null &&
            res.formattedAddress!.trim().isNotEmpty) {
          _pickupAddressText = res.formattedAddress!.trim();
        }
      }
    });

    if (!silent) {
      if (res.isSuccess) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text('Position GPS de ramassage actualisée !'),
            backgroundColor: GoLivreurTheme.secondaire,
          ),
        );
      } else if (res.errorMessage != null) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(res.errorMessage!)),
        );
      }
    }
  }

  Future<void> _pickParcelPhoto(ImageSource source) async {
    try {
      final picked = await _imagePicker.pickImage(
        source: source,
        maxWidth: 1280,
        maxHeight: 1280,
        imageQuality: 82,
      );
      if (picked == null) return;
      final bytes = await picked.readAsBytes();
      if (!mounted) return;
      setState(() {
        _parcelPhotoFile = picked;
        _parcelPhotoBytes = bytes;
      });
    } catch (_) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text(
            'Impossible d\'accéder à la photo. Vérifiez les autorisations appareil photo / galerie.',
          ),
        ),
      );
    }
  }

  Future<void> _openPhotoSourcePicker() async {
    await showModalBottomSheet<void>(
      context: context,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
      builder: (ctx) => SafeArea(
        child: Padding(
          padding: const EdgeInsets.symmetric(vertical: 12, horizontal: 16),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              const Text(
                'Photo du colis',
                style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold),
              ),
              const SizedBox(height: 12),
              ListTile(
                leading: const CircleAvatar(
                  backgroundColor: Color(0xFFFFF4E0),
                  child: Icon(Icons.camera_alt_outlined,
                      color: GoLivreurTheme.primaire),
                ),
                title: const Text('Prendre une photo'),
                subtitle: const Text('Utiliser l\'appareil photo du téléphone'),
                onTap: () {
                  Navigator.of(ctx).pop();
                  _pickParcelPhoto(ImageSource.camera);
                },
              ),
              ListTile(
                leading: const CircleAvatar(
                  backgroundColor: Color(0xFFE8F5E9),
                  child: Icon(Icons.photo_library_outlined,
                      color: GoLivreurTheme.secondaire),
                ),
                title: const Text('Choisir depuis la galerie'),
                subtitle: const Text('Sélectionner une image existante'),
                onTap: () {
                  Navigator.of(ctx).pop();
                  _pickParcelPhoto(ImageSource.gallery);
                },
              ),
            ],
          ),
        ),
      ),
    );
  }

  /// Étape 5 : Ouvre le BottomSheet récapitulatif complet avant "Confirmer et Payer".
  Future<void> _openSummarySheet() async {
    if (!_formKey.currentState!.validate() || !_isFormReady) return;

    final tarifs = ref.read(weatherProvider).tarifsColis;
    final shippingFee = _computeShippingFee(tarifs);
    final insurance = _computeInsurance(tarifs);
    final totalPrice = shippingFee + insurance;
    final declaredVal =
        double.tryParse(_declaredValueController.text.trim()) ?? 0;
    final cleanPhone =
        AuthNotifier.normalizePhone(_recipientPhoneController.text);
    final isMobileMoney = _selectedPaymentMethod != 'cash';

    final confirmed = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
      ),
      builder: (ctx) => SafeArea(
        child: DraggableScrollableSheet(
          expand: false,
          initialChildSize: 0.78,
          minChildSize: 0.5,
          maxChildSize: 0.92,
          builder: (_, scrollCtrl) => SingleChildScrollView(
            controller: scrollCtrl,
            padding: const EdgeInsets.fromLTRB(20, 16, 20, 24),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Center(
                  child: Container(
                    width: 40,
                    height: 4,
                    decoration: BoxDecoration(
                      color: GoLivreurTheme.bordure,
                      borderRadius: BorderRadius.circular(2),
                    ),
                  ),
                ),
                const SizedBox(height: 14),
                const Text(
                  'Résumé de l\'expédition',
                  style: TextStyle(
                    fontSize: 19,
                    fontWeight: FontWeight.bold,
                    color: GoLivreurTheme.textePrincipal,
                  ),
                ),
                const SizedBox(height: 4),
                const Text(
                  'Vérifiez les informations avant de confirmer et payer.',
                  style: TextStyle(
                    fontSize: 13,
                    color: GoLivreurTheme.texteSecondaire,
                  ),
                ),
                const SizedBox(height: 16),

                // 1. Adresse de ramassage (GPS / Texte)
                _SummarySectionCard(
                  stepNumber: '1',
                  title: 'Adresse de ramassage',
                  icon: Icons.my_location,
                  iconColor: GoLivreurTheme.secondaire,
                  child: Text(
                    _pickupSummaryText(),
                    style: const TextStyle(
                      fontSize: 13.5,
                      color: GoLivreurTheme.textePrincipal,
                    ),
                  ),
                ),
                const SizedBox(height: 10),

                // 2. Destinataire (Nom + Tél + Quartier)
                _SummarySectionCard(
                  stepNumber: '2',
                  title: 'Destinataire',
                  icon: Icons.person_pin_circle_outlined,
                  iconColor: GoLivreurTheme.primaire,
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      _SummaryLine(
                        label: 'Nom',
                        value: _recipientNameController.text.trim(),
                      ),
                      const SizedBox(height: 4),
                      _SummaryLine(
                        label: 'Téléphone',
                        value: '+225 $cleanPhone',
                      ),
                      const SizedBox(height: 4),
                      _SummaryLine(
                        label: 'Quartier',
                        value: _selectedNeighborhood!.name,
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: 10),

                // 3. Détails du colis (Contenu + Valeur + Photo)
                _SummarySectionCard(
                  stepNumber: '3',
                  title: 'Détails du colis',
                  icon: Icons.inventory_2_outlined,
                  iconColor: const Color(0xFF5E35B1),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      _SummaryLine(
                        label: 'Contenu',
                        value: _descriptionController.text.trim().isNotEmpty
                            ? _descriptionController.text.trim()
                            : 'Non précisé',
                      ),
                      const SizedBox(height: 4),
                      _SummaryLine(
                        label: 'Valeur déclarée',
                        value: declaredVal > 0
                            ? '${_formatFcfa(declaredVal)} CFA'
                            : 'Aucune',
                      ),
                      const SizedBox(height: 8),
                      if (_parcelPhotoBytes != null)
                        Row(
                          children: [
                            ClipRRect(
                              borderRadius: BorderRadius.circular(10),
                              child: Image.memory(
                                _parcelPhotoBytes!,
                                width: 64,
                                height: 64,
                                fit: BoxFit.cover,
                              ),
                            ),
                            const SizedBox(width: 10),
                            const Expanded(
                              child: Text(
                                'Photo du colis jointe (sera transmise au serveur)',
                                style: TextStyle(
                                  fontSize: 12,
                                  color: GoLivreurTheme.secondaire,
                                  fontWeight: FontWeight.w600,
                                ),
                              ),
                            ),
                          ],
                        )
                      else
                        const Text(
                          'Photo du colis : non ajoutée',
                          style: TextStyle(
                            fontSize: 12.5,
                            color: GoLivreurTheme.texteSecondaire,
                          ),
                        ),
                    ],
                  ),
                ),
                const SizedBox(height: 10),

                // 4. Format et Prix total
                _SummarySectionCard(
                  stepNumber: '4',
                  title: 'Format et Prix total',
                  icon: Icons.local_shipping_outlined,
                  iconColor: GoLivreurTheme.primaire,
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      _SummaryLine(
                        label: 'Format',
                        value: _sizeLabel(_selectedSize!),
                      ),
                      const SizedBox(height: 4),
                      _SummaryLine(
                        label: 'Frais d\'expédition',
                        value: '${_formatFcfa(shippingFee)} CFA',
                      ),
                      if (insurance > 0) ...[
                        const SizedBox(height: 4),
                        _SummaryLine(
                          label: 'Assurance colis',
                          value: '${_formatFcfa(insurance)} CFA',
                        ),
                      ],
                      const Divider(height: 14),
                      Row(
                        mainAxisAlignment: MainAxisAlignment.spaceBetween,
                        children: [
                          const Text(
                            'Prix total',
                            style: TextStyle(
                              fontWeight: FontWeight.bold,
                              fontSize: 15,
                              color: GoLivreurTheme.textePrincipal,
                            ),
                          ),
                          Text(
                            '${_formatFcfa(totalPrice)} CFA',
                            style: const TextStyle(
                              fontWeight: FontWeight.bold,
                              fontSize: 17,
                              color: GoLivreurTheme.secondaire,
                            ),
                          ),
                        ],
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: 10),

                // 5. Mode de paiement sélectionné
                _SummarySectionCard(
                  stepNumber: '5',
                  title: 'Mode de paiement sélectionné',
                  icon: Icons.account_balance_wallet_outlined,
                  iconColor: GoLivreurTheme.secondaire,
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        _paymentLabel(_selectedPaymentMethod),
                        style: const TextStyle(
                          fontWeight: FontWeight.bold,
                          fontSize: 14,
                          color: GoLivreurTheme.textePrincipal,
                        ),
                      ),
                      if (isMobileMoney) ...[
                        const SizedBox(height: 4),
                        Container(
                          padding: const EdgeInsets.symmetric(
                            horizontal: 8,
                            vertical: 4,
                          ),
                          decoration: BoxDecoration(
                            color: GoLivreurTheme.primaire.withValues(alpha: 0.14),
                            borderRadius: BorderRadius.circular(6),
                          ),
                          child: const Text(
                            'Mode test : paiement simulé sans débit réel',
                            style: TextStyle(
                              fontSize: 11.5,
                              color: GoLivreurTheme.textePrincipal,
                              fontWeight: FontWeight.w600,
                            ),
                          ),
                        ),
                      ],
                    ],
                  ),
                ),
                const SizedBox(height: 20),

                SizedBox(
                  width: double.infinity,
                  height: 52,
                  child: ElevatedButton.icon(
                    style: ElevatedButton.styleFrom(
                      backgroundColor: GoLivreurTheme.secondaire,
                      foregroundColor: Colors.white,
                      shape: RoundedRectangleBorder(
                        borderRadius: BorderRadius.circular(14),
                      ),
                    ),
                    onPressed: () => Navigator.of(ctx).pop(true),
                    icon: const Icon(Icons.check_circle_outline),
                    label: Text(
                      'Confirmer et Payer • ${_formatFcfa(totalPrice)} CFA',
                      style: const TextStyle(
                        fontSize: 15.5,
                        fontWeight: FontWeight.bold,
                      ),
                    ),
                  ),
                ),
                const SizedBox(height: 8),
                TextButton(
                  onPressed: () => Navigator.of(ctx).pop(false),
                  child: const Text('Modifier les informations'),
                ),
              ],
            ),
          ),
        ),
      ),
    );

    if (confirmed == true && mounted) {
      await _submitParcel();
    }
  }

  Future<void> _submitParcel() async {
    if (_isSubmitting || !_isFormReady) return;

    setState(() => _isSubmitting = true);
    final api = ref.read(apiClientProvider);
    final weather = ref.read(weatherProvider);
    final neighborhood = _selectedNeighborhood!;
    final cleanPhone =
        AuthNotifier.normalizePhone(_recipientPhoneController.text);
    final declaredValue =
        double.tryParse(_declaredValueController.text.trim()) ?? 0;

    final effectivePickupLat = _pickupLat ?? weather.centerLat;
    final effectivePickupLng = _pickupLng ?? weather.centerLng;
    final effectiveDeliveryLat =
        neighborhood.lat != 0.0 ? neighborhood.lat : weather.centerLat;
    final effectiveDeliveryLng =
        neighborhood.lng != 0.0 ? neighborhood.lng : weather.centerLng;

    try {
      final fields = <String, dynamic>{
        'recipient_name': _recipientNameController.text.trim(),
        'recipient_phone': cleanPhone,
        if (neighborhood.id != null) 'neighborhood_id': neighborhood.id,
        'delivery_neighborhood': neighborhood.name,
        'delivery_address': neighborhood.name,
        if (_pickupAddressText != null && _pickupAddressText!.isNotEmpty)
          'pickup_address': _pickupAddressText,
        if (effectivePickupLat != null) 'pickup_lat': effectivePickupLat,
        if (effectivePickupLng != null) 'pickup_lng': effectivePickupLng,
        if (effectiveDeliveryLat != null) 'delivery_lat': effectiveDeliveryLat,
        if (effectiveDeliveryLng != null) 'delivery_lng': effectiveDeliveryLng,
        'size': _selectedSize!,
        'description': _descriptionController.text.trim(),
        'declared_value': declaredValue,
        'payment_method': _selectedPaymentMethod,
        'distance_km': _computedDistanceKm(),
        'simulate_payment': '1',
      };

      final Response<dynamic> response;
      if (_parcelPhotoFile != null) {
        response = await api.uploadMultipart(
          '/client/parcels',
          method: 'POST',
          fields: fields.map((k, v) => MapEntry(k, '$v')),
          files: {'photo': _parcelPhotoFile!},
        );
      } else {
        response = await api.post('/client/parcels', data: {
          ...fields,
          'simulate_payment': true,
        });
      }

      final rawId = response.data is Map && response.data['donnees'] is Map
          ? response.data['donnees']['id']
          : null;

      if ((response.statusCode == 201 || response.statusCode == 200) &&
          rawId != null) {
        final parcelId = rawId is int ? rawId : int.parse('$rawId');
        if (mounted) {
          setState(() => _isSubmitting = false);
          ref.invalidate(parcelsHistoryProvider);

          ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(
              content: Text('Expédition confirmée et enregistrée avec succès !'),
              backgroundColor: GoLivreurTheme.succes,
            ),
          );

          Navigator.of(context).pushReplacement(
            MaterialPageRoute(
              builder: (_) => ParcelTrackingScreen(parcelId: parcelId),
            ),
          );
        }
        return;
      }

      if (mounted) {
        setState(() => _isSubmitting = false);
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text(
              'Impossible d\'enregistrer l\'expédition. Vérifiez les informations saisies.',
            ),
            backgroundColor: GoLivreurTheme.erreur,
          ),
        );
      }
    } on DioException catch (e) {
      if (mounted) {
        setState(() => _isSubmitting = false);
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(ApiClient.messageErreur(e)),
            backgroundColor: GoLivreurTheme.erreur,
          ),
        );
      }
    } catch (_) {
      if (mounted) {
        setState(() => _isSubmitting = false);
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text(
              'Erreur lors de l\'envoi du colis. Vérifiez votre connexion.',
            ),
            backgroundColor: GoLivreurTheme.erreur,
          ),
        );
      }
    }
  }

  static String _formatFcfa(double amount) {
    final digits = amount.round().toString();
    final buf = StringBuffer();
    for (var i = 0; i < digits.length; i++) {
      final posFromEnd = digits.length - i;
      buf.write(digits[i]);
      if (posFromEnd > 1 && posFromEnd % 3 == 1) {
        buf.write(' ');
      }
    }
    return '${buf.toString()} F';
  }

  @override
  Widget build(BuildContext context) {
    final weather = ref.watch(weatherProvider);
    final tarifs = weather.tarifsColis;
    final neighborhoods = weather.neighborhoods;
    final shippingFee = _computeShippingFee(tarifs);
    final insurance = _computeInsurance(tarifs);
    final totalFee = shippingFee + insurance;

    // Garde la valeur sélectionnée alignée avec la liste chargée depuis l'API
    DeliveryNeighborhood? validDropdownValue;
    if (_selectedNeighborhood != null) {
      for (final n in neighborhoods) {
        if ((n.id != null && n.id == _selectedNeighborhood!.id) ||
            n.name == _selectedNeighborhood!.name) {
          validDropdownValue = n;
          break;
        }
      }
    }

    return Scaffold(
      backgroundColor: GoLivreurTheme.fond,
      appBar: AppBar(
        title: const Text('Nouvelle Expédition'),
      ),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(16),
        child: Form(
          key: _formKey,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              // ─── 1. POSITION GPS DE RAMASSAGE (auto-actualisée) ───────────
              Container(
                padding: const EdgeInsets.all(14),
                decoration: BoxDecoration(
                  color: Colors.white,
                  borderRadius: BorderRadius.circular(14),
                  border: Border.all(color: GoLivreurTheme.bordure),
                ),
                child: Row(
                  children: [
                    Icon(
                      _pickupLat != null ? Icons.gps_fixed : Icons.my_location,
                      color: GoLivreurTheme.secondaire,
                    ),
                    const SizedBox(width: 10),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          const Text(
                            'Position GPS de ramassage',
                            style: TextStyle(
                              fontWeight: FontWeight.bold,
                              fontSize: 13.5,
                            ),
                          ),
                          const SizedBox(height: 2),
                          Text(
                            _locatingPickup
                                ? 'Localisation GPS en cours...'
                                : _pickupSummaryText(),
                            style: const TextStyle(
                              fontSize: 11.5,
                              color: GoLivreurTheme.texteSecondaire,
                            ),
                          ),
                        ],
                      ),
                    ),
                    TextButton(
                      onPressed: _locatingPickup
                          ? null
                          : () => _detectPickupGps(silent: false),
                      child: _locatingPickup
                          ? const SizedBox(
                              width: 16,
                              height: 16,
                              child: CircularProgressIndicator(strokeWidth: 2),
                            )
                          : Text(_pickupLat != null ? 'Actualiser' : 'Localiser'),
                    ),
                  ],
                ),
              ),
              const SizedBox(height: 18),

              // ─── 2. INFORMATIONS DU DESTINATAIRE (sans GPS, Dropdown Quartiers) ─
              const Text(
                'Informations du destinataire',
                style: TextStyle(
                  fontSize: 16,
                  fontWeight: FontWeight.bold,
                  color: GoLivreurTheme.textePrincipal,
                ),
              ),
              const SizedBox(height: 10),
              Container(
                padding: const EdgeInsets.all(16),
                decoration: BoxDecoration(
                  color: Colors.white,
                  borderRadius: BorderRadius.circular(14),
                  border: Border.all(color: GoLivreurTheme.bordure),
                ),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    TextFormField(
                      controller: _recipientNameController,
                      decoration: const InputDecoration(
                        labelText: 'Nom complet du destinataire *',
                        hintText: 'Ex: Kouassi Amenan',
                        prefixIcon: Icon(Icons.person_outline),
                      ),
                      validator: (val) => val == null || val.trim().length < 2
                          ? 'Veuillez saisir le nom complet du destinataire'
                          : null,
                    ),
                    const SizedBox(height: 12),
                    TextFormField(
                      controller: _recipientPhoneController,
                      keyboardType: TextInputType.phone,
                      decoration: const InputDecoration(
                        labelText: 'Téléphone (+225) *',
                        hintText: '0701020304',
                        prefixIcon: Icon(Icons.phone_outlined),
                        prefixText: '+225 ',
                      ),
                      validator: (val) {
                        final clean = AuthNotifier.normalizePhone(val ?? '');
                        if (!RegExp(r'^(01|05|07|21|25|27)[0-9]{8}$')
                            .hasMatch(clean)) {
                          return 'Numéro ivoirien à 10 chiffres requis';
                        }
                        return null;
                      },
                    ),
                    const SizedBox(height: 12),
                    if (neighborhoods.isNotEmpty)
                      DropdownButtonFormField<DeliveryNeighborhood>(
                        initialValue: validDropdownValue,
                        isExpanded: true,
                        decoration: InputDecoration(
                          labelText: weather.cityName.isNotEmpty
                              ? 'Quartier de livraison (${weather.cityName}) *'
                              : 'Quartier de livraison *',
                          prefixIcon: const Icon(Icons.location_city_outlined),
                        ),
                        hint: const Text('Sélectionnez un quartier'),
                        items: neighborhoods
                            .map(
                              (q) => DropdownMenuItem<DeliveryNeighborhood>(
                                value: q,
                                child: Text(
                                  q.name,
                                  overflow: TextOverflow.ellipsis,
                                ),
                              ),
                            )
                            .toList(),
                        onChanged: (val) =>
                            setState(() => _selectedNeighborhood = val),
                        validator: (val) => val == null
                            ? 'Veuillez sélectionner le quartier de livraison'
                            : null,
                      )
                    else
                      Container(
                        padding: const EdgeInsets.all(12),
                        decoration: BoxDecoration(
                          color: GoLivreurTheme.fond,
                          borderRadius: BorderRadius.circular(10),
                          border: Border.all(color: GoLivreurTheme.bordure),
                        ),
                        child: Row(
                          children: [
                            const Icon(
                              Icons.info_outline,
                              size: 18,
                              color: GoLivreurTheme.texteSecondaire,
                            ),
                            const SizedBox(width: 8),
                            Expanded(
                              child: Text(
                                weather.isLoaded
                                    ? 'Aucun quartier actif configuré pour cette ville.'
                                    : 'Chargement des quartiers de votre ville...',
                                style: const TextStyle(
                                  fontSize: 12.5,
                                  color: GoLivreurTheme.texteSecondaire,
                                ),
                              ),
                            ),
                            TextButton(
                              onPressed: () => ref
                                  .read(weatherProvider.notifier)
                                  .chargerStatutVille(),
                              child: const Text('Actualiser'),
                            ),
                          ],
                        ),
                      ),
                  ],
                ),
              ),
              const SizedBox(height: 20),

              // ─── 3. NOUVELLE SECTION : INFORMATIONS SUR LE COLIS ──────────
              const Text(
                'Informations sur le colis',
                style: TextStyle(
                  fontSize: 16,
                  fontWeight: FontWeight.bold,
                  color: GoLivreurTheme.textePrincipal,
                ),
              ),
              const SizedBox(height: 10),
              Container(
                padding: const EdgeInsets.all(16),
                decoration: BoxDecoration(
                  color: Colors.white,
                  borderRadius: BorderRadius.circular(14),
                  border: Border.all(color: GoLivreurTheme.bordure),
                ),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    TextFormField(
                      controller: _descriptionController,
                      decoration: const InputDecoration(
                        labelText: 'Contenu du colis',
                        hintText: 'Ex: Enveloppe documents, chaussures...',
                        prefixIcon: Icon(Icons.description_outlined),
                      ),
                    ),
                    const SizedBox(height: 12),
                    TextFormField(
                      controller: _declaredValueController,
                      keyboardType: TextInputType.number,
                      onChanged: (_) => setState(() {}),
                      decoration: const InputDecoration(
                        labelText: 'Valeur déclarée (optionnel)',
                        prefixIcon: Icon(Icons.verified_outlined),
                        suffixText: 'F CFA',
                      ),
                    ),
                    const SizedBox(height: 14),
                    const Text(
                      'Photo du colis (optionnel)',
                      style: TextStyle(
                        fontSize: 13,
                        fontWeight: FontWeight.w600,
                        color: GoLivreurTheme.textePrincipal,
                      ),
                    ),
                    const SizedBox(height: 8),
                    if (_parcelPhotoBytes != null)
                      Container(
                        padding: const EdgeInsets.all(10),
                        decoration: BoxDecoration(
                          color: GoLivreurTheme.fond,
                          borderRadius: BorderRadius.circular(12),
                          border: Border.all(color: GoLivreurTheme.bordure),
                        ),
                        child: Row(
                          children: [
                            ClipRRect(
                              borderRadius: BorderRadius.circular(10),
                              child: Image.memory(
                                _parcelPhotoBytes!,
                                width: 72,
                                height: 72,
                                fit: BoxFit.cover,
                              ),
                            ),
                            const SizedBox(width: 12),
                            Expanded(
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  const Text(
                                    'Photo sélectionnée',
                                    style: TextStyle(
                                      fontWeight: FontWeight.bold,
                                      fontSize: 13,
                                    ),
                                  ),
                                  const SizedBox(height: 2),
                                  Text(
                                    _parcelPhotoFile?.name ?? 'colis.jpg',
                                    maxLines: 1,
                                    overflow: TextOverflow.ellipsis,
                                    style: const TextStyle(
                                      fontSize: 11.5,
                                      color: GoLivreurTheme.texteSecondaire,
                                    ),
                                  ),
                                  const SizedBox(height: 6),
                                  Row(
                                    children: [
                                      TextButton.icon(
                                        onPressed: _openPhotoSourcePicker,
                                        icon: const Icon(Icons.edit, size: 15),
                                        label: const Text('Changer'),
                                        style: TextButton.styleFrom(
                                          padding: EdgeInsets.zero,
                                          minimumSize: Size.zero,
                                          tapTargetSize:
                                              MaterialTapTargetSize.shrinkWrap,
                                        ),
                                      ),
                                      const SizedBox(width: 12),
                                      TextButton.icon(
                                        onPressed: () => setState(() {
                                          _parcelPhotoFile = null;
                                          _parcelPhotoBytes = null;
                                        }),
                                        icon: const Icon(
                                          Icons.delete_outline,
                                          size: 15,
                                          color: GoLivreurTheme.erreur,
                                        ),
                                        label: const Text(
                                          'Retirer',
                                          style: TextStyle(
                                            color: GoLivreurTheme.erreur,
                                          ),
                                        ),
                                        style: TextButton.styleFrom(
                                          padding: EdgeInsets.zero,
                                          minimumSize: Size.zero,
                                          tapTargetSize:
                                              MaterialTapTargetSize.shrinkWrap,
                                        ),
                                      ),
                                    ],
                                  ),
                                ],
                              ),
                            ),
                          ],
                        ),
                      )
                    else
                      OutlinedButton.icon(
                        onPressed: _openPhotoSourcePicker,
                        icon: const Icon(Icons.add_a_photo_outlined),
                        label: const Text('Ajouter une photo du colis'),
                        style: OutlinedButton.styleFrom(
                          foregroundColor: GoLivreurTheme.primaire,
                          side: const BorderSide(color: GoLivreurTheme.primaire),
                          padding: const EdgeInsets.symmetric(vertical: 13),
                          shape: RoundedRectangleBorder(
                            borderRadius: BorderRadius.circular(12),
                          ),
                        ),
                      ),
                  ],
                ),
              ),
              const SizedBox(height: 20),

              // ─── 4. FORMAT DU COLIS (Petit / Moyen / Grand) ───────────────
              const Text(
                'Format du colis *',
                style: TextStyle(
                  fontSize: 16,
                  fontWeight: FontWeight.bold,
                  color: GoLivreurTheme.textePrincipal,
                ),
              ),
              const SizedBox(height: 10),
              Row(
                children: [
                  _buildSizeOption(
                    'petit',
                    'Petit',
                    _formatFcfa(tarifs.petit.base),
                    'Enveloppe, clés, plis',
                  ),
                  const SizedBox(width: 8),
                  _buildSizeOption(
                    'moyen',
                    'Moyen',
                    _formatFcfa(tarifs.moyen.base),
                    'Boîte, chaussures, habits',
                  ),
                  const SizedBox(width: 8),
                  _buildSizeOption(
                    'grand',
                    'Grand',
                    _formatFcfa(tarifs.grand.base),
                    'Carton, électro, sacs',
                  ),
                ],
              ),
              const SizedBox(height: 20),

              // ─── 5. MOYEN DE PAIEMENT ─────────────────────────────────────
              const Text(
                'Moyen de paiement',
                style: TextStyle(
                  fontSize: 16,
                  fontWeight: FontWeight.bold,
                  color: GoLivreurTheme.textePrincipal,
                ),
              ),
              const SizedBox(height: 10),
              _buildPaymentTile(
                'cash',
                'Espèces au ramassage',
                Icons.payments_outlined,
                GoLivreurTheme.secondaire,
              ),
              const SizedBox(height: 8),
              _buildPaymentTile(
                'wave',
                'Wave',
                Icons.waves,
                const Color(0xFF1EA8E7),
              ),
              const SizedBox(height: 8),
              _buildPaymentTile(
                'orange_money',
                'Orange Money Côte d\'Ivoire',
                Icons.phone_android,
                const Color(0xFFFF6600),
              ),
              const SizedBox(height: 8),
              _buildPaymentTile(
                'mtn_momo',
                'MTN Mobile Money',
                Icons.flash_on,
                const Color(0xFFFFCC00),
              ),
              const SizedBox(height: 8),
              _buildPaymentTile(
                'moov',
                'Moov Money (Flooz)',
                Icons.account_balance_wallet,
                const Color(0xFF005CA9),
              ),
              const SizedBox(height: 24),

              // ─── 6. BOUTON VALIDATION (désactivé tant que champs obligatoires incomplets) ─
              if (!_isFormReady)
                const Padding(
                  padding: EdgeInsets.only(bottom: 8),
                  child: Text(
                    'Remplissez le nom, le téléphone (10 chiffres), le quartier et le format du colis pour continuer.',
                    textAlign: TextAlign.center,
                    style: TextStyle(
                      fontSize: 12,
                      color: GoLivreurTheme.texteSecondaire,
                    ),
                  ),
                ),
              SizedBox(
                width: double.infinity,
                height: 52,
                child: ElevatedButton(
                  style: ElevatedButton.styleFrom(
                    backgroundColor: GoLivreurTheme.primaire,
                    disabledBackgroundColor:
                        GoLivreurTheme.primaire.withValues(alpha: 0.35),
                    shape: RoundedRectangleBorder(
                      borderRadius: BorderRadius.circular(12),
                    ),
                  ),
                  onPressed:
                      (_isSubmitting || !_isFormReady) ? null : _openSummarySheet,
                  child: _isSubmitting
                      ? const CircularProgressIndicator(color: Colors.white)
                      : Text(
                          _selectedSize != null
                              ? 'Valider l\'expédition • ${_formatFcfa(totalFee)} CFA'
                              : 'Valider l\'expédition',
                          style: const TextStyle(
                            fontSize: 16,
                            fontWeight: FontWeight.bold,
                            color: Colors.white,
                          ),
                        ),
                ),
              ),
              const SizedBox(height: 24),
            ],
          ),
        ),
      ),
    );
  }

  Widget _buildSizeOption(String id, String label, String price, String desc) {
    final isSelected = _selectedSize == id;

    return Expanded(
      child: InkWell(
        onTap: () => setState(() => _selectedSize = id),
        borderRadius: BorderRadius.circular(12),
        child: Container(
          padding: const EdgeInsets.symmetric(vertical: 12, horizontal: 8),
          decoration: BoxDecoration(
            color: isSelected
                ? GoLivreurTheme.primaire.withValues(alpha: 0.08)
                : Colors.white,
            borderRadius: BorderRadius.circular(12),
            border: Border.all(
              color:
                  isSelected ? GoLivreurTheme.primaire : GoLivreurTheme.bordure,
              width: isSelected ? 2 : 1,
            ),
          ),
          child: Column(
            children: [
              Text(
                label,
                style: TextStyle(
                  fontWeight: FontWeight.bold,
                  color: isSelected
                      ? GoLivreurTheme.primaire
                      : GoLivreurTheme.textePrincipal,
                ),
              ),
              const SizedBox(height: 4),
              Text(
                price,
                style: const TextStyle(
                  fontWeight: FontWeight.bold,
                  color: GoLivreurTheme.secondaire,
                  fontSize: 15,
                ),
              ),
              const SizedBox(height: 4),
              Text(
                desc,
                textAlign: TextAlign.center,
                style: const TextStyle(
                  fontSize: 10,
                  color: GoLivreurTheme.texteSecondaire,
                ),
                maxLines: 2,
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _buildPaymentTile(
    String id,
    String label,
    IconData icon,
    Color color,
  ) {
    final isSelected = _selectedPaymentMethod == id;

    return InkWell(
      onTap: () => setState(() => _selectedPaymentMethod = id),
      borderRadius: BorderRadius.circular(12),
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(12),
          border: Border.all(
            color:
                isSelected ? GoLivreurTheme.primaire : GoLivreurTheme.bordure,
            width: isSelected ? 2 : 1,
          ),
        ),
        child: Row(
          children: [
            Icon(icon, color: color, size: 22),
            const SizedBox(width: 12),
            Expanded(
              child: Text(
                label,
                style: TextStyle(
                  fontWeight: isSelected ? FontWeight.bold : FontWeight.w500,
                  color: GoLivreurTheme.textePrincipal,
                ),
              ),
            ),
            Icon(
              isSelected
                  ? Icons.radio_button_checked
                  : Icons.radio_button_off,
              color:
                  isSelected ? GoLivreurTheme.primaire : GoLivreurTheme.bordure,
            ),
          ],
        ),
      ),
    );
  }
}

class _SummarySectionCard extends StatelessWidget {
  final String stepNumber;
  final String title;
  final IconData icon;
  final Color iconColor;
  final Widget child;

  const _SummarySectionCard({
    required this.stepNumber,
    required this.title,
    required this.icon,
    required this.iconColor,
    required this.child,
  });

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: GoLivreurTheme.fond,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: GoLivreurTheme.bordure),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              CircleAvatar(
                radius: 12,
                backgroundColor: iconColor.withValues(alpha: 0.14),
                child: Text(
                  stepNumber,
                  style: TextStyle(
                    fontSize: 11.5,
                    fontWeight: FontWeight.bold,
                    color: iconColor,
                  ),
                ),
              ),
              const SizedBox(width: 8),
              Icon(icon, size: 17, color: iconColor),
              const SizedBox(width: 6),
              Expanded(
                child: Text(
                  title,
                  style: const TextStyle(
                    fontWeight: FontWeight.bold,
                    fontSize: 13.5,
                    color: GoLivreurTheme.textePrincipal,
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 8),
          child,
        ],
      ),
    );
  }
}

class _SummaryLine extends StatelessWidget {
  final String label;
  final String value;

  const _SummaryLine({required this.label, required this.value});

  @override
  Widget build(BuildContext context) {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      mainAxisAlignment: MainAxisAlignment.spaceBetween,
      children: [
        Text(
          label,
          style: const TextStyle(
            fontSize: 13,
            color: GoLivreurTheme.texteSecondaire,
          ),
        ),
        const SizedBox(width: 12),
        Flexible(
          child: Text(
            value,
            textAlign: TextAlign.end,
            style: const TextStyle(
              fontSize: 13,
              fontWeight: FontWeight.w600,
              color: GoLivreurTheme.textePrincipal,
            ),
          ),
        ),
      ],
    );
  }
}
