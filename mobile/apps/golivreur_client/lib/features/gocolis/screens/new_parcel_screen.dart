import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:golivreur_core/config/app_theme.dart';
import 'package:golivreur_core/widgets/golivreur_widgets.dart';
import '../../auth/providers/auth_provider.dart';
import '../../goshop/providers/goshop_provider.dart';
import '../../profile/screens/client_addresses_screen.dart';
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
  late final TextEditingController _addressController;
  final _descriptionController = TextEditingController();
  final _declaredValueController = TextEditingController();

  double? _pickupLat;
  double? _pickupLng;
  double? _deliveryLat;
  double? _deliveryLng;
  bool _locatingPickup = false;

  String _selectedSize = 'moyen'; // petit, moyen, grand
  String _selectedPaymentMethod = 'cash';
  bool _isSubmitting = false;

  @override
  void initState() {
    super.initState();
    final destination = ref.read(activeDeliveryDestinationProvider);
    _addressController = TextEditingController(text: destination.fullAddress);
    if (destination.lat != 0 && destination.lng != 0) {
      _deliveryLat = destination.lat;
      _deliveryLng = destination.lng;
    }
  }

  @override
  void dispose() {
    _recipientNameController.dispose();
    _recipientPhoneController.dispose();
    _addressController.dispose();
    _descriptionController.dispose();
    _declaredValueController.dispose();
    super.dispose();
  }

  double _computedDistanceKm() {
    if (_pickupLat != null &&
        _pickupLng != null &&
        _deliveryLat != null &&
        _deliveryLng != null) {
      final raw = GpsLocationService.distanceKm(
        _pickupLat!,
        _pickupLng!,
        _deliveryLat!,
        _deliveryLng!,
      );
      return math.max(1.0, double.parse((raw * 1.3).toStringAsFixed(1)));
    }
    return 2.0;
  }

  double _computeFee(ParcelTariffsConfig tarifs) {
    final tier = tarifs.forSize(_selectedSize);
    final dist = _computedDistanceKm();
    final extraKm = math.max(0.0, dist - 2.0);
    return (tier.base + (extraKm * tier.parKm)).roundToDouble();
  }

  Future<void> _detectPickupGps() async {
    setState(() => _locatingPickup = true);
    final res = await GpsLocationService.obtenirPositionActuelle(
      withAddressLookup: false,
    );
    if (!mounted) return;
    setState(() {
      _locatingPickup = false;
      if (res.isSuccess) {
        _pickupLat = res.lat;
        _pickupLng = res.lng;
      }
    });

    if (res.isSuccess) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Position GPS de ramassage capturée !'),
          backgroundColor: GoLivreurTheme.secondaire,
        ),
      );
    } else if (res.errorMessage != null) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(res.errorMessage!)),
      );
    }
  }

  Future<void> _pickDeliveryOnMap() async {
    final weather = ref.read(weatherProvider);
    final initLat = _deliveryLat ?? _pickupLat ?? weather.centerLat ?? 6.8770;
    final initLng = _deliveryLng ?? _pickupLng ?? weather.centerLng ?? -6.4502;

    final picked = await OsmLocationPickerScreen.pick(
      context,
      initialLat: initLat,
      initialLng: initLng,
      title: 'Point de livraison du colis',
      confirmLabel: 'Valider ce point de livraison',
    );
    if (picked != null && mounted) {
      setState(() {
        _deliveryLat = picked.lat;
        _deliveryLng = picked.lng;
        if (picked.addressLabel != null && picked.addressLabel!.isNotEmpty) {
          _addressController.text = picked.addressLabel!;
        }
      });
    }
  }

  void _submitParcel() async {
    if (!_formKey.currentState!.validate()) return;

    setState(() => _isSubmitting = true);
    final api = ref.read(apiClientProvider);

    try {
      final payload = {
        'recipient_name': _recipientNameController.text.trim(),
        'recipient_phone': _recipientPhoneController.text.trim(),
        'delivery_address': _addressController.text.trim(),
        if (_pickupLat != null) 'pickup_lat': _pickupLat,
        if (_pickupLng != null) 'pickup_lng': _pickupLng,
        if (_deliveryLat != null) 'delivery_lat': _deliveryLat,
        if (_deliveryLng != null) 'delivery_lng': _deliveryLng,
        'size': _selectedSize,
        'description': _descriptionController.text.trim(),
        'declared_value':
            double.tryParse(_declaredValueController.text.trim()) ?? 0,
        'payment_method': _selectedPaymentMethod,
        'distance_km': _computedDistanceKm(),
      };

      final response = await api.post('/client/parcels', data: payload);
      final rawId = response.data is Map && response.data['donnees'] is Map
          ? response.data['donnees']['id']
          : null;

      if ((response.statusCode == 201 || response.statusCode == 200) &&
          rawId != null) {
        final parcelId = rawId is int ? rawId : int.parse('$rawId');
        if (mounted) {
          setState(() => _isSubmitting = false);
          ref.invalidate(parcelsHistoryProvider);

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
    final tarifs = ref.watch(weatherProvider).tarifsColis;
    final currentFee = _computeFee(tarifs);

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
              // ─── Point GPS de ramassage (expéditeur) ──────────────────────
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
                                fontWeight: FontWeight.bold, fontSize: 13.5),
                          ),
                          Text(
                            _pickupLat != null
                                ? 'Capturée (${_pickupLat!.toStringAsFixed(4)}, ${_pickupLng!.toStringAsFixed(4)})'
                                : 'Recommandé pour calculer la distance exacte',
                            style: const TextStyle(
                              fontSize: 11.5,
                              color: GoLivreurTheme.texteSecondaire,
                            ),
                          ),
                        ],
                      ),
                    ),
                    TextButton(
                      onPressed: _locatingPickup ? null : _detectPickupGps,
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
              const SizedBox(height: 16),

              // ─── Destinataire ─────────────────────────────────────────────
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
                  children: [
                    TextFormField(
                      controller: _recipientNameController,
                      decoration: const InputDecoration(
                        labelText: 'Nom complet du destinataire *',
                        hintText: 'Ex: Kouassi Amenan',
                        prefixIcon: Icon(Icons.person_outline),
                      ),
                      validator: (val) =>
                          val == null || val.trim().isEmpty
                              ? 'Champ obligatoire'
                              : null,
                    ),
                    const SizedBox(height: 12),
                    TextFormField(
                      controller: _recipientPhoneController,
                      keyboardType: TextInputType.phone,
                      decoration: const InputDecoration(
                        labelText: 'Téléphone (+225) *',
                        hintText: '0700000000',
                        prefixIcon: Icon(Icons.phone_outlined),
                        prefixText: '+225 ',
                      ),
                      validator: (val) =>
                          val == null || val.trim().isEmpty
                              ? 'Champ obligatoire'
                              : null,
                    ),
                    const SizedBox(height: 12),
                    TextFormField(
                      controller: _addressController,
                      decoration: InputDecoration(
                        labelText: 'Adresse de livraison & Repère *',
                        prefixIcon: const Icon(Icons.location_on_outlined),
                        hintText: 'Ex: Quartier, face pharmacie ou carrefour',
                        suffixIcon: IconButton(
                          tooltip: 'Pointer sur la carte (OpenStreetMap)',
                          icon: const Icon(Icons.map_outlined,
                              color: GoLivreurTheme.primaire),
                          onPressed: _pickDeliveryOnMap,
                        ),
                      ),
                      validator: (val) =>
                          val == null || val.trim().isEmpty
                              ? 'Champ obligatoire'
                              : null,
                    ),
                    if (_deliveryLat != null && _deliveryLng != null) ...[
                      const SizedBox(height: 6),
                      Align(
                        alignment: Alignment.centerLeft,
                        child: Text(
                          '📍 GPS livraison : ${_deliveryLat!.toStringAsFixed(4)}, ${_deliveryLng!.toStringAsFixed(4)} (${_computedDistanceKm()} km)',
                          style: const TextStyle(
                            fontSize: 11.5,
                            color: GoLivreurTheme.secondaire,
                            fontWeight: FontWeight.w600,
                          ),
                        ),
                      ),
                    ],
                    const SizedBox(height: 10),
                    Consumer(
                      builder: (_, chipRef, __) {
                        final savedAddresses =
                            chipRef.watch(clientAddressesProvider).addresses;
                        return Row(
                          children: [
                            Expanded(
                              child: SingleChildScrollView(
                                scrollDirection: Axis.horizontal,
                                child: Row(
                                  children: [
                                    Padding(
                                      padding: const EdgeInsets.only(right: 6),
                                      child: ActionChip(
                                        avatar: const Icon(Icons.map_outlined,
                                            size: 15,
                                            color: GoLivreurTheme.primaire),
                                        label: const Text('Pointer sur carte',
                                            style: TextStyle(fontSize: 12)),
                                        onPressed: _pickDeliveryOnMap,
                                      ),
                                    ),
                                    ...savedAddresses.map(
                                      (addr) => Padding(
                                        padding:
                                            const EdgeInsets.only(right: 6),
                                        child: ActionChip(
                                          avatar: const Icon(
                                              Icons.bookmark_outline,
                                              size: 15),
                                          label: Text(
                                            '${addr.label} (${addr.neighborhood})',
                                            style:
                                                const TextStyle(fontSize: 12),
                                          ),
                                          onPressed: () {
                                            setState(() {
                                              _addressController.text =
                                                  addr.formattedAddress;
                                              if (addr.lat != null &&
                                                  addr.lng != null) {
                                                _deliveryLat = addr.lat;
                                                _deliveryLng = addr.lng;
                                              }
                                            });
                                          },
                                        ),
                                      ),
                                    ),
                                  ],
                                ),
                              ),
                            ),
                            TextButton(
                              onPressed: () async {
                                await Navigator.of(context).push(
                                  MaterialPageRoute(
                                    builder: (_) =>
                                        const ClientAddressesScreen(),
                                  ),
                                );
                              },
                              child: const Text('Adresses',
                                  style: TextStyle(fontSize: 12)),
                            ),
                          ],
                        );
                      },
                    ),
                  ],
                ),
              ),
              const SizedBox(height: 20),

              // ─── Taille du Colis (Tarifs dynamiques Admin) ────────────────
              const Text(
                'Format du colis',
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

              // ─── Description & Valeur déclarée ────────────────────────────
              Container(
                padding: const EdgeInsets.all(16),
                decoration: BoxDecoration(
                  color: Colors.white,
                  borderRadius: BorderRadius.circular(14),
                  border: Border.all(color: GoLivreurTheme.bordure),
                ),
                child: Column(
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
                      decoration: const InputDecoration(
                        labelText: 'Valeur déclarée (optionnelle pour assurance)',
                        prefixIcon: Icon(Icons.verified_outlined),
                        suffixText: 'F CFA',
                      ),
                    ),
                  ],
                ),
              ),
              const SizedBox(height: 20),

              // ─── Moyen de Paiement ────────────────────────────────────────
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
                'mtn',
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

              SizedBox(
                width: double.infinity,
                height: 52,
                child: ElevatedButton(
                  style: ElevatedButton.styleFrom(
                    backgroundColor: GoLivreurTheme.primaire,
                    shape: RoundedRectangleBorder(
                      borderRadius: BorderRadius.circular(12),
                    ),
                  ),
                  onPressed: _isSubmitting ? null : _submitParcel,
                  child: _isSubmitting
                      ? const CircularProgressIndicator(color: Colors.white)
                      : Text(
                          'Valider l\'expédition • ${_formatFcfa(currentFee)} CFA',
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
            color: Colors.white,
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
      String id, String label, IconData icon, Color color) {
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
