import 'dart:typed_data';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:golivreur_core/config/app_theme.dart';
import 'package:golivreur_core/services/api_client.dart';
import 'package:golivreur_core/widgets/golivreur_widgets.dart';
import 'package:image_picker/image_picker.dart';
import '../../driver/providers/driver_provider.dart';

/// Écran « Modifier mon profil » — GoLivreur Driver.
class EditDriverProfileScreen extends ConsumerStatefulWidget {
  const EditDriverProfileScreen({super.key});

  @override
  ConsumerState<EditDriverProfileScreen> createState() => _EditDriverProfileScreenState();
}

class _EditDriverProfileScreenState extends ConsumerState<EditDriverProfileScreen> {
  final _formKey = GlobalKey<FormState>();

  late final TextEditingController _nameCtrl;
  late final TextEditingController _phoneCtrl;
  late final TextEditingController _emailCtrl;
  late final TextEditingController _plateCtrl;
  late final TextEditingController _licenseCtrl;
  late final TextEditingController _idCardCtrl;

  String _vehicleType = 'moto';
  String _language = 'fr';
  bool _notificationsEnabled = true;

  XFile? _pickedPhoto;
  Uint8List? _pickedBytes;

  List<Map<String, String>> _vehicleTypes = const [
    {'slug': 'moto', 'name': 'Moto / Scooter'},
    {'slug': 'velo', 'name': 'Vélo'},
    {'slug': 'voiture', 'name': 'Voiture / Véhicule léger'},
    {'slug': 'tricycle', 'name': 'Tricycle motorisé'},
  ];

  bool _isInitialLoading = true;
  bool _isSaving = false;
  String? _errorMsg;

  @override
  void initState() {
    super.initState();
    final profile = ref.read(driverProfileProvider);
    _nameCtrl = TextEditingController(text: profile.name);
    _phoneCtrl = TextEditingController(text: profile.phone);
    _emailCtrl = TextEditingController(text: profile.email ?? '');
    _plateCtrl = TextEditingController(text: profile.motoPlate);
    _licenseCtrl = TextEditingController(text: profile.driverLicenseNumber ?? '');
    _idCardCtrl = TextEditingController(text: profile.idCardNumber ?? '');
    _vehicleType = profile.vehicleType;
    _language = profile.language;
    _notificationsEnabled = profile.notificationsEnabled;

    WidgetsBinding.instance.addPostFrameCallback((_) => _loadInitialData());
  }

  @override
  void dispose() {
    _nameCtrl.dispose();
    _phoneCtrl.dispose();
    _emailCtrl.dispose();
    _plateCtrl.dispose();
    _licenseCtrl.dispose();
    _idCardCtrl.dispose();
    super.dispose();
  }

  Future<void> _loadInitialData() async {
    setState(() {
      _isInitialLoading = true;
      _errorMsg = null;
    });

    await Future.wait([
      ref.read(driverProfileProvider.notifier).chargerProfil(),
      _loadVehicleTypes(),
    ]);

    if (!mounted) return;
    final profile = ref.read(driverProfileProvider);
    _nameCtrl.text = profile.name;
    _phoneCtrl.text = profile.phone;
    _emailCtrl.text = profile.email ?? '';
    _plateCtrl.text = profile.motoPlate;
    _licenseCtrl.text = profile.driverLicenseNumber ?? '';
    _idCardCtrl.text = profile.idCardNumber ?? '';
    _vehicleType = profile.vehicleType;
    _language = profile.language;
    _notificationsEnabled = profile.notificationsEnabled;

    setState(() => _isInitialLoading = false);
  }

  Future<void> _loadVehicleTypes() async {
    try {
      final res = await ref.read(apiClientProvider).get('/vehicle-types');
      if (res.statusCode == 200 && res.data['succes'] == true) {
        final list = (res.data['donnees'] as List)
            .map((e) => {
                  'slug': e['slug'].toString(),
                  'name': e['name'].toString(),
                })
            .toList();
        if (list.isNotEmpty && mounted) {
          setState(() => _vehicleTypes = list);
        }
      }
    } catch (_) {}
  }

  Future<void> _save() async {
    if (!_formKey.currentState!.validate()) return;

    setState(() {
      _isSaving = true;
      _errorMsg = null;
    });

    final (success, error) = await ref.read(driverProfileProvider.notifier).mettreAJourProfil(
          name: _nameCtrl.text.trim(),
          phone: _phoneCtrl.text.trim(),
          email: _emailCtrl.text.trim().isNotEmpty ? _emailCtrl.text.trim() : null,
          vehicleType: _vehicleType,
          vehiclePlate: _plateCtrl.text.trim().isNotEmpty ? _plateCtrl.text.trim() : null,
          driverLicenseNumber: _licenseCtrl.text.trim().isNotEmpty ? _licenseCtrl.text.trim() : null,
          idCardNumber: _idCardCtrl.text.trim().isNotEmpty ? _idCardCtrl.text.trim() : null,
          language: _language,
          notificationsEnabled: _notificationsEnabled,
          photo: _pickedPhoto,
        );

    if (!mounted) return;

    setState(() => _isSaving = false);

    if (success) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Profil livreur mis à jour avec succès !'),
          backgroundColor: GoLivreurTheme.succes,
        ),
      );
      Navigator.of(context).pop(true);
    } else {
      setState(() => _errorMsg = error ?? 'Impossible de sauvegarder vos modifications.');
    }
  }

  @override
  Widget build(BuildContext context) {
    final profile = ref.watch(driverProfileProvider);

    return Scaffold(
      backgroundColor: GoLivreurTheme.fond,
      appBar: AppBar(
        title: const Text('Modifier mon profil'),
        backgroundColor: Colors.white,
        elevation: 0.5,
      ),
      body: _isInitialLoading
          ? const Center(child: CircularProgressIndicator(color: GoLivreurTheme.secondaire))
          : SingleChildScrollView(
              padding: const EdgeInsets.all(16),
              child: Form(
                key: _formKey,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    // ─── Photo du livreur réutilisable ────────────────────────
                    Container(
                      padding: const EdgeInsets.symmetric(vertical: 20),
                      decoration: BoxDecoration(
                        color: Colors.white,
                        borderRadius: BorderRadius.circular(16),
                        border: Border.all(color: GoLivreurTheme.bordure),
                      ),
                      child: Center(
                        child: ProfileAvatarPicker(
                          currentImageUrl: profile.photoUrl,
                          previewBytes: _pickedBytes,
                          fallbackIcon: Icons.two_wheeler,
                          accentColor: GoLivreurTheme.secondaire,
                          isUploading: _isSaving && _pickedPhoto != null,
                          onImagePicked: (file, bytes) {
                            setState(() {
                              _pickedPhoto = file;
                              _pickedBytes = bytes;
                            });
                          },
                        ),
                      ),
                    ),
                    const SizedBox(height: 16),

                    if (_errorMsg != null) ...[
                      Container(
                        padding: const EdgeInsets.all(12),
                        decoration: BoxDecoration(
                          color: GoLivreurTheme.erreur.withValues(alpha: 0.1),
                          borderRadius: BorderRadius.circular(12),
                          border: Border.all(color: GoLivreurTheme.erreur.withValues(alpha: 0.4)),
                        ),
                        child: Row(
                          children: [
                            const Icon(Icons.error_outline, color: GoLivreurTheme.erreur),
                            const SizedBox(width: 8),
                            Expanded(
                              child: Text(
                                _errorMsg!,
                                style: const TextStyle(color: GoLivreurTheme.erreur, fontSize: 13),
                              ),
                            ),
                            TextButton(
                              onPressed: _isSaving ? null : _save,
                              child: const Text('Réessayer'),
                            ),
                          ],
                        ),
                      ),
                      const SizedBox(height: 16),
                    ],

                    // ─── Identité & Contact ───────────────────────────────────
                    Container(
                      padding: const EdgeInsets.all(16),
                      decoration: BoxDecoration(
                        color: Colors.white,
                        borderRadius: BorderRadius.circular(16),
                        border: Border.all(color: GoLivreurTheme.bordure),
                      ),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          const Text(
                            'Identité & Contact',
                            style: TextStyle(fontSize: 15, fontWeight: FontWeight.bold),
                          ),
                          const SizedBox(height: 14),
                          TextFormField(
                            controller: _nameCtrl,
                            decoration: const InputDecoration(
                              labelText: 'Nom et prénom *',
                              prefixIcon: Icon(Icons.person_outline),
                              border: OutlineInputBorder(),
                            ),
                            validator: ProfileFormHelpers.validateRequiredName,
                          ),
                          const SizedBox(height: 14),
                          TextFormField(
                            controller: _phoneCtrl,
                            keyboardType: TextInputType.phone,
                            decoration: const InputDecoration(
                              labelText: 'Téléphone ivoirien *',
                              hintText: '0703000001',
                              prefixIcon: Icon(Icons.phone_outlined),
                              border: OutlineInputBorder(),
                            ),
                            validator: ProfileFormHelpers.validateIvorianPhone,
                          ),
                          const SizedBox(height: 14),
                          TextFormField(
                            controller: _emailCtrl,
                            keyboardType: TextInputType.emailAddress,
                            decoration: const InputDecoration(
                              labelText: 'Adresse email (optionnelle)',
                              prefixIcon: Icon(Icons.email_outlined),
                              border: OutlineInputBorder(),
                            ),
                            validator: ProfileFormHelpers.validateOptionalEmail,
                          ),
                        ],
                      ),
                    ),
                    const SizedBox(height: 16),

                    // ─── Véhicule & Pièces ────────────────────────────────────
                    Container(
                      padding: const EdgeInsets.all(16),
                      decoration: BoxDecoration(
                        color: Colors.white,
                        borderRadius: BorderRadius.circular(16),
                        border: Border.all(color: GoLivreurTheme.bordure),
                      ),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          const Text(
                            'Véhicule & Documents',
                            style: TextStyle(fontSize: 15, fontWeight: FontWeight.bold),
                          ),
                          const SizedBox(height: 14),
                          DropdownButtonFormField<String>(
                            initialValue: _vehicleTypes.any((v) => v['slug'] == _vehicleType)
                                ? _vehicleType
                                : _vehicleTypes.first['slug'],
                            decoration: const InputDecoration(
                              labelText: 'Type de véhicule *',
                              prefixIcon: Icon(Icons.two_wheeler),
                              border: OutlineInputBorder(),
                            ),
                            items: _vehicleTypes
                                .map((v) => DropdownMenuItem(
                                      value: v['slug'],
                                      child: Text(v['name']!),
                                    ))
                                .toList(),
                            onChanged: (val) {
                              if (val != null) setState(() => _vehicleType = val);
                            },
                          ),
                          const SizedBox(height: 14),
                          TextFormField(
                            controller: _plateCtrl,
                            decoration: const InputDecoration(
                              labelText: 'Plaque d\'immatriculation',
                              hintText: 'Ex: 1234-JK-01',
                              prefixIcon: Icon(Icons.confirmation_number_outlined),
                              border: OutlineInputBorder(),
                            ),
                          ),
                          const SizedBox(height: 14),
                          TextFormField(
                            controller: _licenseCtrl,
                            decoration: const InputDecoration(
                              labelText: 'Numéro de permis de conduire',
                              prefixIcon: Icon(Icons.credit_card_outlined),
                              border: OutlineInputBorder(),
                            ),
                          ),
                          const SizedBox(height: 14),
                          TextFormField(
                            controller: _idCardCtrl,
                            decoration: const InputDecoration(
                              labelText: 'N° CNI / Pièce d\'identité',
                              prefixIcon: Icon(Icons.badge_outlined),
                              border: OutlineInputBorder(),
                            ),
                          ),
                        ],
                      ),
                    ),
                    const SizedBox(height: 16),

                    // ─── Préférences & Sécurité ───────────────────────────────
                    Container(
                      padding: const EdgeInsets.all(16),
                      decoration: BoxDecoration(
                        color: Colors.white,
                        borderRadius: BorderRadius.circular(16),
                        border: Border.all(color: GoLivreurTheme.bordure),
                      ),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          const Text(
                            'Préférences & Sécurité',
                            style: TextStyle(fontSize: 15, fontWeight: FontWeight.bold),
                          ),
                          const SizedBox(height: 12),
                          DropdownButtonFormField<String>(
                            initialValue: _language,
                            decoration: const InputDecoration(
                              labelText: 'Langue',
                              prefixIcon: Icon(Icons.language),
                              border: OutlineInputBorder(),
                            ),
                            items: const [
                              DropdownMenuItem(value: 'fr', child: Text('Français')),
                              DropdownMenuItem(value: 'en', child: Text('English')),
                            ],
                            onChanged: (val) => setState(() => _language = val ?? 'fr'),
                          ),
                          const SizedBox(height: 8),
                          SwitchListTile(
                            contentPadding: EdgeInsets.zero,
                            title: const Text('Notifications de nouvelles courses', style: TextStyle(fontSize: 14)),
                            value: _notificationsEnabled,
                            activeThumbColor: GoLivreurTheme.secondaire,
                            onChanged: (val) => setState(() => _notificationsEnabled = val),
                          ),
                          const Divider(),
                          ListTile(
                            contentPadding: EdgeInsets.zero,
                            leading: const Icon(Icons.lock_outline, color: GoLivreurTheme.secondaire),
                            title: const Text('Changer mon mot de passe', style: TextStyle(fontWeight: FontWeight.w600, fontSize: 14)),
                            trailing: const Icon(Icons.chevron_right),
                            onTap: () => ProfileFormHelpers.showChangePasswordDialog(
                              context,
                              apiClient: ref.read(apiClientProvider),
                              accentColor: GoLivreurTheme.secondaire,
                            ),
                          ),
                        ],
                      ),
                    ),
                    const SizedBox(height: 24),

                    SizedBox(
                      height: 52,
                      child: ElevatedButton.icon(
                        style: ElevatedButton.styleFrom(
                          backgroundColor: GoLivreurTheme.secondaire,
                          foregroundColor: Colors.white,
                          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
                        ),
                        onPressed: _isSaving ? null : _save,
                        icon: _isSaving
                            ? const SizedBox(
                                width: 18,
                                height: 18,
                                child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
                              )
                            : const Icon(Icons.check_circle_outline),
                        label: Text(
                          _isSaving ? 'Enregistrement en cours...' : 'Enregistrer les modifications',
                          style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 15),
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
