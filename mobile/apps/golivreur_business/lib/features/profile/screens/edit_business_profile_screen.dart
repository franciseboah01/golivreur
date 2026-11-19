import 'dart:typed_data';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:golivreur_core/config/app_theme.dart';
import 'package:golivreur_core/services/api_client.dart';
import 'package:golivreur_core/widgets/golivreur_widgets.dart';
import 'package:image_picker/image_picker.dart';
import '../../business/providers/business_provider.dart';

/// Écran « Modifier mon profil » — GoLivreur Business (Commerce + Gérant).
class EditBusinessProfileScreen extends ConsumerStatefulWidget {
  const EditBusinessProfileScreen({super.key});

  @override
  ConsumerState<EditBusinessProfileScreen> createState() => _EditBusinessProfileScreenState();
}

class _EditBusinessProfileScreenState extends ConsumerState<EditBusinessProfileScreen> {
  final _formKey = GlobalKey<FormState>();

  late final TextEditingController _businessNameCtrl;
  late final TextEditingController _emojiCtrl;
  late final TextEditingController _addressCtrl;
  late final TextEditingController _descCtrl;
  late final TextEditingController _hoursCtrl;
  late final TextEditingController _ownerNameCtrl;
  late final TextEditingController _ownerPhoneCtrl;
  late final TextEditingController _ownerEmailCtrl;

  String _categorySlug = 'maquis';
  String _language = 'fr';
  bool _notificationsEnabled = true;

  XFile? _pickedLogo;
  Uint8List? _pickedLogoBytes;
  XFile? _pickedStorePhoto;
  Uint8List? _pickedStorePhotoBytes;
  XFile? _pickedOwnerPhoto;
  Uint8List? _pickedOwnerPhotoBytes;

  List<Map<String, String>> _categories = const [
    {'slug': 'restaurant', 'name': 'Restaurants', 'emoji': '🍽️'},
    {'slug': 'maquis', 'name': 'Maquis & Grillades', 'emoji': '🍖'},
    {'slug': 'fast_food', 'name': 'Fast-Foods', 'emoji': '🍔'},
    {'slug': 'pharmacie', 'name': 'Pharmacies', 'emoji': '💊'},
    {'slug': 'boulangerie', 'name': 'Boulangeries & Pâtisseries', 'emoji': '🥐'},
    {'slug': 'supermarche', 'name': 'Supermarchés & Épiceries', 'emoji': '🛒'},
    {'slug': 'boissons', 'name': 'Boissons & Vins', 'emoji': '🥤'},
    {'slug': 'boutique', 'name': 'Mode & Tissus', 'emoji': '👕'},
    {'slug': 'high_tech', 'name': 'High-Tech & Téléphonie', 'emoji': '📱'},
    {'slug': 'autre', 'name': 'Autre Commerce', 'emoji': '🏪'},
  ];

  static const List<String> _presetEmojis = [
    '🍖', '🍽️', '🍔', '🍕', '🍗', '🥐', '🍰', '🥤', '☕', '💊', '🛒', '👕', '📱', '💐', '🏪',
  ];

  bool _isInitialLoading = true;
  bool _isSaving = false;
  bool _locatingGps = false;
  double? _shopLat;
  double? _shopLng;
  String? _errorMsg;

  static const Color _businessColor = Color(0xFF2E75B6);

  @override
  void initState() {
    super.initState();
    final p = ref.read(businessProfileProvider);
    _businessNameCtrl = TextEditingController(text: p.name);
    _emojiCtrl = TextEditingController(text: p.emoji ?? '🏪');
    _addressCtrl = TextEditingController(text: p.quarter);
    _descCtrl = TextEditingController(text: p.description ?? '');
    _hoursCtrl = TextEditingController(text: p.openHoursText);
    _ownerNameCtrl = TextEditingController(text: p.ownerName);
    _ownerPhoneCtrl = TextEditingController(text: p.ownerPhone);
    _ownerEmailCtrl = TextEditingController(text: p.ownerEmail ?? '');
    _categorySlug = p.categorySlug;
    _language = p.language;
    _notificationsEnabled = p.notificationsEnabled;
    _shopLat = p.lat;
    _shopLng = p.lng;

    WidgetsBinding.instance.addPostFrameCallback((_) => _loadInitialData());
  }

  @override
  void dispose() {
    _businessNameCtrl.dispose();
    _emojiCtrl.dispose();
    _addressCtrl.dispose();
    _descCtrl.dispose();
    _hoursCtrl.dispose();
    _ownerNameCtrl.dispose();
    _ownerPhoneCtrl.dispose();
    _ownerEmailCtrl.dispose();
    super.dispose();
  }

  Future<void> _loadInitialData() async {
    setState(() {
      _isInitialLoading = true;
      _errorMsg = null;
    });

    await Future.wait([
      ref.read(businessProfileProvider.notifier).chargerProfil(),
      _loadCategories(),
    ]);

    if (!mounted) return;
    final p = ref.read(businessProfileProvider);
    _businessNameCtrl.text = p.name;
    _emojiCtrl.text = p.emoji ?? '🏪';
    _addressCtrl.text = p.quarter;
    _descCtrl.text = p.description ?? '';
    _hoursCtrl.text = p.openHoursText;
    _ownerNameCtrl.text = p.ownerName;
    _ownerPhoneCtrl.text = p.ownerPhone;
    _ownerEmailCtrl.text = p.ownerEmail ?? '';
    _categorySlug = p.categorySlug;
    _language = p.language;
    _notificationsEnabled = p.notificationsEnabled;
    _shopLat = p.lat;
    _shopLng = p.lng;

    setState(() => _isInitialLoading = false);
  }

  Future<void> _loadCategories() async {
    try {
      final res = await ref.read(apiClientProvider).get('/client/categories');
      if (res.statusCode == 200 && res.data['succes'] == true) {
        final list = (res.data['donnees'] as List)
            .map((e) => {
                  'slug': e['slug'].toString(),
                  'name': e['name'].toString(),
                  'emoji': (e['emoji'] ?? '🏪').toString(),
                })
            .toList();
        if (list.isNotEmpty && mounted) {
          setState(() => _categories = list);
        }
      }
    } catch (_) {}
  }

  Future<void> _detecterGpsBoutique() async {
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
        if (_addressCtrl.text.trim().isEmpty &&
            res.formattedAddress != null &&
            res.formattedAddress!.trim().isNotEmpty) {
          _addressCtrl.text = res.formattedAddress!.trim();
        }
      });
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('📍 Position GPS de la boutique capturée ! Pensez à enregistrer.'),
          backgroundColor: GoLivreurTheme.succes,
        ),
      );
    } else if (res.errorMessage != null) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(res.errorMessage!),
          backgroundColor: GoLivreurTheme.erreur,
        ),
      );
    }
  }

  Future<void> _choisirSurCarteBoutique() async {
    final profile = ref.read(businessProfileProvider);
    final picked = await Navigator.of(context).push<PickedLocation>(
      MaterialPageRoute(
        builder: (_) => OsmLocationPickerScreen(
          title: 'Localisation de la boutique',
          initialLat: _shopLat ?? profile.cityCenterLat ?? 6.8770,
          initialLng: _shopLng ?? profile.cityCenterLng ?? -6.4502,
          confirmLabel: 'Valider l\'emplacement',
        ),
      ),
    );
    if (!mounted || picked == null) return;

    setState(() {
      _shopLat = picked.lat;
      _shopLng = picked.lng;
      if (_addressCtrl.text.trim().isEmpty &&
          picked.addressLabel != null &&
          picked.addressLabel!.trim().isNotEmpty) {
        _addressCtrl.text = picked.addressLabel!.trim();
      }
    });
  }

  Future<void> _save() async {
    if (!_formKey.currentState!.validate()) return;

    setState(() {
      _isSaving = true;
      _errorMsg = null;
    });

    final (success, error) = await ref.read(businessProfileProvider.notifier).mettreAJourProfil(
          ownerName: _ownerNameCtrl.text.trim(),
          ownerPhone: _ownerPhoneCtrl.text.trim(),
          ownerEmail: _ownerEmailCtrl.text.trim().isNotEmpty ? _ownerEmailCtrl.text.trim() : null,
          businessName: _businessNameCtrl.text.trim(),
          categorySlug: _categorySlug,
          emoji: _emojiCtrl.text.trim().isNotEmpty ? _emojiCtrl.text.trim() : null,
          address: _addressCtrl.text.trim(),
          lat: _shopLat,
          lng: _shopLng,
          description: _descCtrl.text.trim().isNotEmpty ? _descCtrl.text.trim() : null,
          openHoursText: _hoursCtrl.text.trim().isNotEmpty ? _hoursCtrl.text.trim() : null,
          language: _language,
          notificationsEnabled: _notificationsEnabled,
          logoFile: _pickedLogo,
          merchantPhotoFile: _pickedStorePhoto,
          ownerPhotoFile: _pickedOwnerPhoto,
        );

    if (!mounted) return;
    setState(() => _isSaving = false);

    if (success) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Profil boutique mis à jour avec succès !'),
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
    final profile = ref.watch(businessProfileProvider);

    return Scaffold(
      backgroundColor: GoLivreurTheme.fond,
      appBar: AppBar(
        title: const Text('Modifier mon profil'),
        backgroundColor: Colors.white,
        elevation: 0.5,
      ),
      body: _isInitialLoading
          ? const Center(child: CircularProgressIndicator(color: _businessColor))
          : SingleChildScrollView(
              padding: const EdgeInsets.all(16),
              child: Form(
                key: _formKey,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    // ─── Logo & Photo Vitrine ─────────────────────────────────
                    Container(
                      padding: const EdgeInsets.all(16),
                      decoration: BoxDecoration(
                        color: Colors.white,
                        borderRadius: BorderRadius.circular(16),
                        border: Border.all(color: GoLivreurTheme.bordure),
                      ),
                      child: Column(
                        children: [
                          const Text(
                            'Visuels de la boutique',
                            style: TextStyle(fontSize: 15, fontWeight: FontWeight.bold),
                          ),
                          const SizedBox(height: 14),
                          Row(
                            mainAxisAlignment: MainAxisAlignment.spaceEvenly,
                            children: [
                              ProfileAvatarPicker(
                                currentImageUrl: profile.logoUrl,
                                previewBytes: _pickedLogoBytes,
                                fallbackEmoji: _emojiCtrl.text.trim(),
                                fallbackIcon: Icons.storefront,
                                accentColor: _businessColor,
                                buttonLabel: 'Logo boutique',
                                size: 84,
                                isSquare: true,
                                isUploading: _isSaving && _pickedLogo != null,
                                onImagePicked: (file, bytes) {
                                  setState(() {
                                    _pickedLogo = file;
                                    _pickedLogoBytes = bytes;
                                  });
                                },
                              ),
                              ProfileAvatarPicker(
                                currentImageUrl: profile.photoUrl,
                                previewBytes: _pickedStorePhotoBytes,
                                fallbackIcon: Icons.add_photo_alternate_outlined,
                                accentColor: GoLivreurTheme.primaire,
                                buttonLabel: 'Photo vitrine',
                                size: 84,
                                isSquare: true,
                                isUploading: _isSaving && _pickedStorePhoto != null,
                                onImagePicked: (file, bytes) {
                                  setState(() {
                                    _pickedStorePhoto = file;
                                    _pickedStorePhotoBytes = bytes;
                                  });
                                },
                              ),
                            ],
                          ),
                        ],
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

                    // ─── Informations de la Boutique ──────────────────────────
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
                            'Informations du commerce',
                            style: TextStyle(fontSize: 15, fontWeight: FontWeight.bold),
                          ),
                          const SizedBox(height: 14),
                          TextFormField(
                            controller: _businessNameCtrl,
                            decoration: const InputDecoration(
                              labelText: 'Nom du commerce *',
                              prefixIcon: Icon(Icons.storefront_outlined),
                              border: OutlineInputBorder(),
                            ),
                            validator: ProfileFormHelpers.validateRequiredName,
                          ),
                          const SizedBox(height: 14),
                          DropdownButtonFormField<String>(
                            initialValue: _categories.any((c) => c['slug'] == _categorySlug)
                                ? _categorySlug
                                : _categories.first['slug'],
                            decoration: const InputDecoration(
                              labelText: 'Catégorie d\'activité *',
                              prefixIcon: Icon(Icons.category_outlined),
                              border: OutlineInputBorder(),
                            ),
                            items: _categories
                                .map((c) => DropdownMenuItem(
                                      value: c['slug'],
                                      child: Text('${c['emoji']} ${c['name']}'),
                                    ))
                                .toList(),
                            onChanged: (val) {
                              if (val != null) setState(() => _categorySlug = val);
                            },
                          ),
                          const SizedBox(height: 14),
                          TextFormField(
                            controller: _emojiCtrl,
                            decoration: const InputDecoration(
                              labelText: 'Emoji de la boutique',
                              prefixIcon: Icon(Icons.emoji_emotions_outlined),
                              border: OutlineInputBorder(),
                            ),
                          ),
                          const SizedBox(height: 8),
                          Wrap(
                            spacing: 6,
                            runSpacing: 6,
                            children: _presetEmojis
                                .map((em) => InkWell(
                                      onTap: () => setState(() => _emojiCtrl.text = em),
                                      borderRadius: BorderRadius.circular(8),
                                      child: Container(
                                        padding: const EdgeInsets.all(6),
                                        decoration: BoxDecoration(
                                          color: _emojiCtrl.text == em
                                              ? _businessColor.withValues(alpha: 0.15)
                                              : GoLivreurTheme.fond,
                                          borderRadius: BorderRadius.circular(8),
                                          border: Border.all(
                                            color: _emojiCtrl.text == em
                                                ? _businessColor
                                                : GoLivreurTheme.bordure,
                                          ),
                                        ),
                                        child: Text(em, style: const TextStyle(fontSize: 18)),
                                      ),
                                    ))
                                .toList(),
                          ),
                          const SizedBox(height: 14),
                          TextFormField(
                            controller: _addressCtrl,
                            decoration: const InputDecoration(
                              labelText: 'Adresse physique *',
                              prefixIcon: Icon(Icons.location_on_outlined),
                              border: OutlineInputBorder(),
                            ),
                            validator: ProfileFormHelpers.validateRequiredName,
                          ),
                          const SizedBox(height: 10),
                          // ─── Géolocalisation GPS gratuite + OpenStreetMap ─────────
                          Container(
                            padding: const EdgeInsets.all(12),
                            decoration: BoxDecoration(
                              color: GoLivreurTheme.fond,
                              borderRadius: BorderRadius.circular(12),
                              border: Border.all(color: GoLivreurTheme.bordure),
                            ),
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Row(
                                  children: [
                                    Icon(
                                      _shopLat != null && _shopLng != null
                                          ? Icons.gps_fixed
                                          : Icons.gps_not_fixed,
                                      size: 16,
                                      color: _shopLat != null && _shopLng != null
                                          ? GoLivreurTheme.succes
                                          : GoLivreurTheme.texteSecondaire,
                                    ),
                                    const SizedBox(width: 6),
                                    Expanded(
                                      child: Text(
                                        _shopLat != null && _shopLng != null
                                            ? 'Position GPS : ${_shopLat!.toStringAsFixed(5)}, ${_shopLng!.toStringAsFixed(5)}'
                                            : 'Position GPS de la boutique non définie',
                                        style: TextStyle(
                                          fontSize: 12,
                                          fontWeight: FontWeight.w600,
                                          color: _shopLat != null && _shopLng != null
                                              ? GoLivreurTheme.succes
                                              : GoLivreurTheme.texteSecondaire,
                                        ),
                                      ),
                                    ),
                                  ],
                                ),
                                const SizedBox(height: 8),
                                Row(
                                  children: [
                                    Expanded(
                                      child: OutlinedButton.icon(
                                        onPressed: _locatingGps ? null : _detecterGpsBoutique,
                                        icon: _locatingGps
                                            ? const SizedBox(
                                                width: 14,
                                                height: 14,
                                                child: CircularProgressIndicator(strokeWidth: 2),
                                              )
                                            : const Icon(Icons.my_location, size: 16),
                                        label: Text(
                                          _locatingGps ? 'GPS...' : 'Ma position',
                                          style: const TextStyle(fontSize: 12),
                                        ),
                                      ),
                                    ),
                                    const SizedBox(width: 8),
                                    Expanded(
                                      child: OutlinedButton.icon(
                                        onPressed: _choisirSurCarteBoutique,
                                        icon: const Icon(Icons.map_outlined, size: 16),
                                        label: const Text(
                                          'Sur la carte',
                                          style: TextStyle(fontSize: 12),
                                        ),
                                      ),
                                    ),
                                  ],
                                ),
                                const SizedBox(height: 8),
                                Row(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    const Icon(
                                      Icons.info_outline,
                                      size: 15,
                                      color: _businessColor,
                                    ),
                                    const SizedBox(width: 6),
                                    Expanded(
                                      child: Text(
                                        'Ville d\'implantation fixe${(profile.cityName ?? '').isNotEmpty ? ' (${profile.cityName})' : ''} : un commerce qui change de ville doit recréer sa boutique dans la nouvelle ville.',
                                        style: const TextStyle(
                                          fontSize: 11,
                                          color: GoLivreurTheme.texteSecondaire,
                                        ),
                                      ),
                                    ),
                                  ],
                                ),
                              ],
                            ),
                          ),
                          const SizedBox(height: 14),
                          TextFormField(
                            controller: _hoursCtrl,
                            decoration: const InputDecoration(
                              labelText: 'Horaires d\'ouverture',
                              hintText: 'Ex: Lun - Dim : 08h00 - 22h00',
                              prefixIcon: Icon(Icons.schedule_outlined),
                              border: OutlineInputBorder(),
                            ),
                          ),
                          const SizedBox(height: 14),
                          TextFormField(
                            controller: _descCtrl,
                            maxLines: 3,
                            decoration: const InputDecoration(
                              labelText: 'Description & Spécialités',
                              prefixIcon: Icon(Icons.description_outlined),
                              border: OutlineInputBorder(),
                            ),
                          ),
                        ],
                      ),
                    ),
                    const SizedBox(height: 16),

                    // ─── Compte Gestionnaire ──────────────────────────────────
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
                            'Gérant & Préférences',
                            style: TextStyle(fontSize: 15, fontWeight: FontWeight.bold),
                          ),
                          const SizedBox(height: 14),
                          Center(
                            child: ProfileAvatarPicker(
                              currentImageUrl: profile.ownerPhotoUrl,
                              previewBytes: _pickedOwnerPhotoBytes,
                              fallbackIcon: Icons.person,
                              accentColor: _businessColor,
                              buttonLabel: 'Photo du gérant',
                              size: 76,
                              isUploading: _isSaving && _pickedOwnerPhoto != null,
                              onImagePicked: (file, bytes) {
                                setState(() {
                                  _pickedOwnerPhoto = file;
                                  _pickedOwnerPhotoBytes = bytes;
                                });
                              },
                            ),
                          ),
                          const SizedBox(height: 14),
                          TextFormField(
                            controller: _ownerNameCtrl,
                            decoration: const InputDecoration(
                              labelText: 'Nom du gérant *',
                              prefixIcon: Icon(Icons.person_outline),
                              border: OutlineInputBorder(),
                            ),
                            validator: ProfileFormHelpers.validateRequiredName,
                          ),
                          const SizedBox(height: 14),
                          TextFormField(
                            controller: _ownerPhoneCtrl,
                            keyboardType: TextInputType.phone,
                            decoration: const InputDecoration(
                              labelText: 'Téléphone ivoirien *',
                              prefixIcon: Icon(Icons.phone_outlined),
                              border: OutlineInputBorder(),
                            ),
                            validator: ProfileFormHelpers.validateIvorianPhone,
                          ),
                          const SizedBox(height: 14),
                          TextFormField(
                            controller: _ownerEmailCtrl,
                            keyboardType: TextInputType.emailAddress,
                            decoration: const InputDecoration(
                              labelText: 'Email (optionnel)',
                              prefixIcon: Icon(Icons.email_outlined),
                              border: OutlineInputBorder(),
                            ),
                            validator: ProfileFormHelpers.validateOptionalEmail,
                          ),
                          const SizedBox(height: 14),
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
                            title: const Text('Notifications de commandes', style: TextStyle(fontSize: 14)),
                            value: _notificationsEnabled,
                            activeThumbColor: _businessColor,
                            onChanged: (val) => setState(() => _notificationsEnabled = val),
                          ),
                          const Divider(),
                          ListTile(
                            contentPadding: EdgeInsets.zero,
                            leading: const Icon(Icons.lock_outline, color: _businessColor),
                            title: const Text('Changer mon mot de passe', style: TextStyle(fontWeight: FontWeight.w600, fontSize: 14)),
                            trailing: const Icon(Icons.chevron_right),
                            onTap: () => ProfileFormHelpers.showChangePasswordDialog(
                              context,
                              apiClient: ref.read(apiClientProvider),
                              accentColor: _businessColor,
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
                          backgroundColor: _businessColor,
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
