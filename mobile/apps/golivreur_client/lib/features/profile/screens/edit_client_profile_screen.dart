import 'dart:typed_data';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:golivreur_core/config/app_theme.dart';
import 'package:golivreur_core/widgets/golivreur_widgets.dart';
import 'package:image_picker/image_picker.dart';
import '../../auth/providers/auth_provider.dart';

/// Écran « Modifier mon profil » — GoLivreur Client.
class EditClientProfileScreen extends ConsumerStatefulWidget {
  const EditClientProfileScreen({super.key});

  @override
  ConsumerState<EditClientProfileScreen> createState() => _EditClientProfileScreenState();
}

class _EditClientProfileScreenState extends ConsumerState<EditClientProfileScreen> {
  final _formKey = GlobalKey<FormState>();

  late final TextEditingController _nameCtrl;
  late final TextEditingController _phoneCtrl;
  late final TextEditingController _emailCtrl;
  late final TextEditingController _addressCtrl;

  String _language = 'fr';
  bool _notificationsEnabled = true;
  XFile? _pickedPhoto;
  Uint8List? _pickedBytes;

  bool _isInitialLoading = true;
  bool _isSaving = false;
  String? _errorMsg;

  @override
  void initState() {
    super.initState();
    final user = ref.read(authProvider).user;
    _nameCtrl = TextEditingController(text: user?.name ?? '');
    _phoneCtrl = TextEditingController(text: user?.phone ?? '');
    _emailCtrl = TextEditingController(text: user?.email ?? '');
    _addressCtrl = TextEditingController(text: user?.defaultAddress ?? '');
    _language = user?.language ?? 'fr';
    _notificationsEnabled = user?.notificationsEnabled ?? true;

    WidgetsBinding.instance.addPostFrameCallback((_) => _loadFromApi());
  }

  @override
  void dispose() {
    _nameCtrl.dispose();
    _phoneCtrl.dispose();
    _emailCtrl.dispose();
    _addressCtrl.dispose();
    super.dispose();
  }

  Future<void> _loadFromApi() async {
    setState(() {
      _isInitialLoading = true;
      _errorMsg = null;
    });

    await ref.read(authProvider.notifier).chargerProfil();
    final user = ref.read(authProvider).user;
    if (mounted) {
      if (user != null) {
        _nameCtrl.text = user.name;
        _phoneCtrl.text = user.phone;
        _emailCtrl.text = user.email ?? '';
        _addressCtrl.text = user.defaultAddress ?? '';
        _language = user.language;
        _notificationsEnabled = user.notificationsEnabled;
      }
      setState(() => _isInitialLoading = false);
    }
  }

  Future<void> _save() async {
    if (!_formKey.currentState!.validate()) return;

    setState(() {
      _isSaving = true;
      _errorMsg = null;
    });

    final (success, error) = await ref.read(authProvider.notifier).mettreAJourProfil(
          name: _nameCtrl.text.trim(),
          phone: _phoneCtrl.text.trim(),
          email: _emailCtrl.text.trim().isNotEmpty ? _emailCtrl.text.trim() : null,
          defaultAddress: _addressCtrl.text.trim().isNotEmpty ? _addressCtrl.text.trim() : null,
          language: _language,
          notificationsEnabled: _notificationsEnabled,
          photo: _pickedPhoto,
        );

    if (!mounted) return;

    setState(() => _isSaving = false);

    if (success) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Profil mis à jour avec succès !'),
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
    final user = ref.watch(authProvider).user;

    return Scaffold(
      backgroundColor: GoLivreurTheme.fond,
      appBar: AppBar(
        title: const Text('Modifier mon profil'),
        backgroundColor: Colors.white,
        elevation: 0.5,
      ),
      body: _isInitialLoading
          ? const Center(child: CircularProgressIndicator(color: GoLivreurTheme.primaire))
          : SingleChildScrollView(
              padding: const EdgeInsets.all(16),
              child: Form(
                key: _formKey,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    // ─── Photo de profil réutilisable ─────────────────────────
                    Container(
                      padding: const EdgeInsets.symmetric(vertical: 20),
                      decoration: BoxDecoration(
                        color: Colors.white,
                        borderRadius: BorderRadius.circular(16),
                        border: Border.all(color: GoLivreurTheme.bordure),
                      ),
                      child: Center(
                        child: ProfileAvatarPicker(
                          currentImageUrl: user?.photoUrl,
                          previewBytes: _pickedBytes,
                          fallbackIcon: Icons.person,
                          accentColor: GoLivreurTheme.primaire,
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

                    // ─── Informations personnelles ────────────────────────────
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
                            'Informations personnelles',
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
                              hintText: '0701000001',
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
                          const SizedBox(height: 14),
                          TextFormField(
                            controller: _addressCtrl,
                            maxLines: 2,
                            decoration: const InputDecoration(
                              labelText: 'Adresse de livraison par défaut',
                              hintText: 'Ex: Quartier Résidentiel, Face Maquis Le Baoulé',
                              prefixIcon: Icon(Icons.location_on_outlined),
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
                              labelText: 'Langue de l\'application',
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
                            title: const Text('Notifications de commande', style: TextStyle(fontSize: 14)),
                            subtitle: const Text('Alertes de livraison et mode pluie', style: TextStyle(fontSize: 12)),
                            value: _notificationsEnabled,
                            activeThumbColor: GoLivreurTheme.primaire,
                            onChanged: (val) => setState(() => _notificationsEnabled = val),
                          ),
                          const Divider(),
                          ListTile(
                            contentPadding: EdgeInsets.zero,
                            leading: const Icon(Icons.lock_outline, color: GoLivreurTheme.primaire),
                            title: const Text('Changer mon mot de passe', style: TextStyle(fontWeight: FontWeight.w600, fontSize: 14)),
                            trailing: const Icon(Icons.chevron_right),
                            onTap: () => ProfileFormHelpers.showChangePasswordDialog(
                              context,
                              apiClient: ref.read(apiClientProvider),
                              accentColor: GoLivreurTheme.primaire,
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
                          backgroundColor: GoLivreurTheme.primaire,
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
