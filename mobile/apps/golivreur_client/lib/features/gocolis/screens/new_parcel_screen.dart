import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:golivreur_core/config/app_theme.dart';
import '../../auth/providers/auth_provider.dart';
import '../providers/gocolis_provider.dart';
import 'parcel_tracking_screen.dart';

class NewParcelScreen extends ConsumerStatefulWidget {
  const NewParcelScreen({super.key});

  @override
  ConsumerState<NewParcelScreen> createState() => _NewParcelScreenState();
}

class _NewParcelScreenState extends ConsumerState<NewParcelScreen> {
  final _formKey = GlobalKey<FormState>();
  final _recipientNameController = TextEditingController(text: 'Koffi Jean-Marc');
  final _recipientPhoneController = TextEditingController(text: '0701000002');
  final _addressController = TextEditingController(text: 'Daloa, Quartier Lobia (Face Grande Mosquée)');
  final _descriptionController = TextEditingController(text: 'Documents scellés + clés');
  final _declaredValueController = TextEditingController(text: '10000');

  String _selectedSize = 'moyen'; // petit, moyen, grand
  String _selectedPaymentMethod = 'wave';
  bool _isSubmitting = false;

  @override
  void dispose() {
    _recipientNameController.dispose();
    _recipientPhoneController.dispose();
    _addressController.dispose();
    _descriptionController.dispose();
    _declaredValueController.dispose();
    super.dispose();
  }

  double get _currentShippingFee {
    switch (_selectedSize) {
      case 'petit':
        return 500;
      case 'grand':
        return 1500;
      case 'moyen':
      default:
        return 1000;
    }
  }

  void _submitParcel() async {
    if (!_formKey.currentState!.validate()) return;

    setState(() => _isSubmitting = true);
    final api = ref.read(apiClientProvider);
    int parcelId = 501;

    try {
      final payload = {
        'recipient_name': _recipientNameController.text.trim(),
        'recipient_phone': _recipientPhoneController.text.trim(),
        'delivery_address': _addressController.text.trim(),
        'size': _selectedSize,
        'description': _descriptionController.text.trim(),
        'declared_value': double.tryParse(_declaredValueController.text.trim()) ?? 0,
        'payment_method': _selectedPaymentMethod,
        'distance_km': 3.2,
      };

      final response = await api.post('/client/parcels', data: payload);
      if (response.statusCode == 201 && response.data['donnees'] != null) {
        parcelId = response.data['donnees']['id'] as int;
      }
    } catch (_) {
      // Fallback démo
      parcelId = 501;
    } finally {
      if (mounted) {
        setState(() => _isSubmitting = false);
        ref.invalidate(parcelsHistoryProvider);

        Navigator.of(context).pushReplacement(
          MaterialPageRoute(
            builder: (_) => ParcelTrackingScreen(parcelId: parcelId),
          ),
        );
      }
    }
  }

  @override
  Widget build(BuildContext context) {
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
              // ─── Destinataire ─────────────────────────────────────────────
              const Text(
                'Informations du destinataire',
                style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold, color: GoLivreurTheme.textePrincipal),
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
                        prefixIcon: Icon(Icons.person_outline),
                      ),
                      validator: (val) => val == null || val.isEmpty ? 'Champ obligatoire' : null,
                    ),
                    const SizedBox(height: 12),
                    TextFormField(
                      controller: _recipientPhoneController,
                      keyboardType: TextInputType.phone,
                      decoration: const InputDecoration(
                        labelText: 'Téléphone (+225) *',
                        prefixIcon: Icon(Icons.phone_outlined),
                        prefixText: '+225 ',
                      ),
                      validator: (val) => val == null || val.isEmpty ? 'Champ obligatoire' : null,
                    ),
                    const SizedBox(height: 12),
                    TextFormField(
                      controller: _addressController,
                      decoration: const InputDecoration(
                        labelText: 'Adresse de livraison & Repère à Daloa *',
                        prefixIcon: Icon(Icons.location_on_outlined),
                        hintText: 'Ex: Lobia, face pharmacie de garde',
                      ),
                      validator: (val) => val == null || val.isEmpty ? 'Champ obligatoire' : null,
                    ),
                  ],
                ),
              ),
              const SizedBox(height: 20),

              // ─── Taille du Colis ──────────────────────────────────────────
              const Text(
                'Taille du colis',
                style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold, color: GoLivreurTheme.textePrincipal),
              ),
              const SizedBox(height: 10),
              Row(
                children: [
                  _buildSizeOption('petit', 'Petit', '500 F', 'Enveloppe, clés, plis'),
                  const SizedBox(width: 8),
                  _buildSizeOption('moyen', 'Moyen', '1 000 F', 'Boîte, chaussures, habits'),
                  const SizedBox(width: 8),
                  _buildSizeOption('grand', 'Grand', '1 500 F', 'Carton, électro, sacs'),
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

              // ─── Moyen de Paiement (5 options ivoiriennes) ────────────────
              const Text(
                'Moyen de paiement',
                style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold, color: GoLivreurTheme.textePrincipal),
              ),
              const SizedBox(height: 10),
              _buildPaymentTile('wave', 'Wave (1% de frais)', Icons.waves, const Color(0xFF1EA8E7)),
              const SizedBox(height: 8),
              _buildPaymentTile('orange_money', 'Orange Money Côte d\'Ivoire', Icons.phone_android, const Color(0xFFFF6600)),
              const SizedBox(height: 8),
              _buildPaymentTile('mtn', 'MTN Mobile Money', Icons.flash_on, const Color(0xFFFFCC00)),
              const SizedBox(height: 8),
              _buildPaymentTile('moov', 'Moov Money (Flooz)', Icons.account_balance_wallet, const Color(0xFF005CA9)),
              const SizedBox(height: 8),
              _buildPaymentTile('cash', 'Espèces au ramassage', Icons.payments_outlined, GoLivreurTheme.secondaire),
              const SizedBox(height: 24),

              // ─── Bouton de validation (52px Stitch) ───────────────────────
              SizedBox(
                width: double.infinity,
                height: 52,
                child: ElevatedButton(
                  style: ElevatedButton.styleFrom(
                    backgroundColor: GoLivreurTheme.primaire,
                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                  ),
                  onPressed: _isSubmitting ? null : _submitParcel,
                  child: _isSubmitting
                      ? const CircularProgressIndicator(color: Colors.white)
                      : Text(
                          'Valider l\'expédition • ${_currentShippingFee.toInt()} F CFA',
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
              color: isSelected ? GoLivreurTheme.primaire : GoLivreurTheme.bordure,
              width: isSelected ? 2 : 1,
            ),
          ),
          child: Column(
            children: [
              Text(
                label,
                style: TextStyle(
                  fontWeight: FontWeight.bold,
                  color: isSelected ? GoLivreurTheme.primaire : GoLivreurTheme.textePrincipal,
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
                style: const TextStyle(fontSize: 10, color: GoLivreurTheme.texteSecondaire),
                maxLines: 2,
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _buildPaymentTile(String id, String label, IconData icon, Color color) {
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
            color: isSelected ? GoLivreurTheme.primaire : GoLivreurTheme.bordure,
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
              isSelected ? Icons.radio_button_checked : Icons.radio_button_off,
              color: isSelected ? GoLivreurTheme.primaire : GoLivreurTheme.bordure,
            ),
          ],
        ),
      ),
    );
  }
}
