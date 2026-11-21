import 'dart:typed_data';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:golivreur_core/config/app_theme.dart';
import 'package:golivreur_core/widgets/golivreur_widgets.dart';
import 'package:image_picker/image_picker.dart';
import '../../business/providers/business_provider.dart';
import '../models/opening_hours_model.dart';

/// Écran « Profil & Horaires de ma boutique » — GoLivreur Business (Lot 9C Points 1, 2 & 4).
/// Les champs sensibles (nom, catégorie, adresse, quartier, position GPS) sont définis par GoLivreur
/// et affichés en lecture seule ; jamais envoyés depuis le téléphone du commerçant.
class EditBusinessProfileScreen extends ConsumerStatefulWidget {
  const EditBusinessProfileScreen({super.key});

  @override
  ConsumerState<EditBusinessProfileScreen> createState() =>
      _EditBusinessProfileScreenState();
}

class _EditBusinessProfileScreenState
    extends ConsumerState<EditBusinessProfileScreen> {
  final _formKey = GlobalKey<FormState>();

  late final TextEditingController _emojiCtrl;
  late final TextEditingController _descCtrl;

  late ShopOpeningHours _openingHours;
  String _language = 'fr';
  bool _notificationsEnabled = true;

  XFile? _pickedLogo;
  Uint8List? _pickedLogoBytes;
  XFile? _pickedStorePhoto;
  Uint8List? _pickedStorePhotoBytes;

  bool _isInitialLoading = true;
  bool _isSaving = false;
  String? _errorMsg;

  static const Color _businessColor = Color(0xFF2E75B6);
  static const List<String> _presetEmojis = [
    '🍖',
    '🍽️',
    '🍔',
    '🍕',
    '🍗',
    '🥐',
    '🍰',
    '🥤',
    '☕',
    '💊',
    '🛒',
    '👕',
    '📱',
    '💐',
    '🏪',
  ];

  @override
  void initState() {
    super.initState();
    final p = ref.read(businessProfileProvider);
    _emojiCtrl = TextEditingController(text: p.emoji ?? '🏪');
    _descCtrl = TextEditingController(text: p.description ?? '');
    _openingHours = p.openHoursConfig;
    _language = p.language;
    _notificationsEnabled = p.notificationsEnabled;
    WidgetsBinding.instance.addPostFrameCallback((_) => _loadInitialData());
  }

  @override
  void dispose() {
    _emojiCtrl.dispose();
    _descCtrl.dispose();
    super.dispose();
  }

  Future<void> _loadInitialData() async {
    setState(() {
      _isInitialLoading = true;
      _errorMsg = null;
    });
    await ref.read(businessProfileProvider.notifier).chargerProfil();
    if (!mounted) return;
    final p = ref.read(businessProfileProvider);
    _emojiCtrl.text = p.emoji ?? '🏪';
    _descCtrl.text = p.description ?? '';
    _openingHours = p.openHoursConfig;
    _language = p.language;
    _notificationsEnabled = p.notificationsEnabled;
    setState(() => _isInitialLoading = false);
  }

  Future<void> _pickTime(
    String dayKey,
    int intervalIndex,
    bool isStart,
  ) async {
    final currentList = List<TimeInterval>.from(
      _openingHours.days[dayKey] ?? const [],
    );
    if (intervalIndex >= currentList.length) return;
    final current = currentList[intervalIndex];
    final initialStr = isStart ? current.start : current.end;
    final parts = initialStr.split(':');
    final initialTime = TimeOfDay(
      hour: int.tryParse(parts.isNotEmpty ? parts[0] : '8') ?? 8,
      minute: int.tryParse(parts.length > 1 ? parts[1] : '0') ?? 0,
    );

    final picked = await showTimePicker(
      context: context,
      initialTime: initialTime,
      builder: (ctx, child) => MediaQuery(
        data: MediaQuery.of(ctx).copyWith(alwaysUse24HourFormat: true),
        child: child ?? const SizedBox.shrink(),
      ),
    );
    if (picked == null || !mounted) return;

    final formatted =
        '${picked.hour.toString().padLeft(2, '0')}:${picked.minute.toString().padLeft(2, '0')}';
    currentList[intervalIndex] = TimeInterval(
      start: isStart ? formatted : current.start,
      end: isStart ? current.end : formatted,
    );

    final updatedDays = Map<String, List<TimeInterval>>.from(_openingHours.days);
    updatedDays[dayKey] = currentList;
    setState(() {
      _openingHours = _openingHours.copyWith(days: updatedDays);
    });
  }

  void _toggleDayOpen(String dayKey, bool open) {
    final updatedDays = Map<String, List<TimeInterval>>.from(_openingHours.days);
    updatedDays[dayKey] = open
        ? [const TimeInterval(start: '08:00', end: '22:00')]
        : const [];
    setState(() {
      _openingHours = _openingHours.copyWith(is247: false, days: updatedDays);
    });
  }

  void _addSecondInterval(String dayKey) {
    final updatedDays = Map<String, List<TimeInterval>>.from(_openingHours.days);
    final list = List<TimeInterval>.from(updatedDays[dayKey] ?? const []);
    if (list.length >= 2) return;
    list.add(const TimeInterval(start: '18:00', end: '23:00'));
    updatedDays[dayKey] = list;
    setState(() {
      _openingHours = _openingHours.copyWith(days: updatedDays);
    });
  }

  void _removeInterval(String dayKey, int idx) {
    final updatedDays = Map<String, List<TimeInterval>>.from(_openingHours.days);
    final list = List<TimeInterval>.from(updatedDays[dayKey] ?? const []);
    if (idx < list.length) {
      list.removeAt(idx);
    }
    updatedDays[dayKey] = list;
    setState(() {
      _openingHours = _openingHours.copyWith(days: updatedDays);
    });
  }

  Future<void> _save() async {
    if (!_formKey.currentState!.validate()) return;
    final hoursErr = _openingHours.validate();
    if (hoursErr != null) {
      setState(() => _errorMsg = hoursErr);
      return;
    }

    setState(() {
      _isSaving = true;
      _errorMsg = null;
    });

    final (success, error) =
        await ref.read(businessProfileProvider.notifier).mettreAJourProfil(
              emoji: _emojiCtrl.text.trim().isNotEmpty
                  ? _emojiCtrl.text.trim()
                  : null,
              description: _descCtrl.text.trim().isNotEmpty
                  ? _descCtrl.text.trim()
                  : null,
              openHoursConfig: _openingHours,
              language: _language,
              notificationsEnabled: _notificationsEnabled,
              logoFile: _pickedLogo,
              merchantPhotoFile: _pickedStorePhoto,
            );

    if (!mounted) return;
    setState(() => _isSaving = false);

    if (success) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Profil et horaires mis à jour !'),
          backgroundColor: GoLivreurTheme.succes,
        ),
      );
      Navigator.of(context).pop(true);
    } else {
      setState(
        () => _errorMsg = error ?? 'Impossible de sauvegarder vos modifications.',
      );
    }
  }

  Future<void> _openPhoneChangeSheet() async {
    final phoneCtrl = TextEditingController();
    final otpCtrl = TextEditingController();
    var step = 1;
    var busy = false;
    String? err;

    await showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setSheetState) => Padding(
          padding: EdgeInsets.fromLTRB(
            20,
            20,
            20,
            MediaQuery.of(ctx).viewInsets.bottom + 20,
          ),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              const Text(
                'Changer de numéro (OTP)',
                style: TextStyle(fontSize: 17, fontWeight: FontWeight.bold),
              ),
              const SizedBox(height: 6),
              Text(
                step == 1
                    ? 'Un code SMS sera envoyé sur votre nouveau numéro.'
                    : 'Saisissez le code à 6 chiffres reçu par SMS.',
                style: const TextStyle(
                  fontSize: 13,
                  color: GoLivreurTheme.texteSecondaire,
                ),
              ),
              const SizedBox(height: 14),
              if (err != null) ...[
                Text(
                  err!,
                  style: const TextStyle(
                    color: GoLivreurTheme.erreur,
                    fontSize: 13,
                  ),
                ),
                const SizedBox(height: 10),
              ],
              if (step == 1)
                TextField(
                  controller: phoneCtrl,
                  keyboardType: TextInputType.phone,
                  decoration: const InputDecoration(
                    labelText: 'Nouveau numéro (ex: 0702010099)',
                    prefixIcon: Icon(Icons.phone_outlined),
                    border: OutlineInputBorder(),
                  ),
                )
              else
                TextField(
                  controller: otpCtrl,
                  keyboardType: TextInputType.number,
                  maxLength: 6,
                  decoration: const InputDecoration(
                    labelText: 'Code OTP',
                    prefixIcon: Icon(Icons.sms_outlined),
                    border: OutlineInputBorder(),
                  ),
                ),
              const SizedBox(height: 14),
              ElevatedButton(
                style: ElevatedButton.styleFrom(
                  backgroundColor: _businessColor,
                  foregroundColor: Colors.white,
                  padding: const EdgeInsets.symmetric(vertical: 14),
                ),
                onPressed: busy
                    ? null
                    : () async {
                        setSheetState(() {
                          busy = true;
                          err = null;
                        });
                        if (step == 1) {
                          final (ok, msg) = await ref
                              .read(businessProfileProvider.notifier)
                              .demanderChangementTelephone(phoneCtrl.text);
                          if (!ctx.mounted) return;
                          setSheetState(() {
                            busy = false;
                            if (ok) {
                              step = 2;
                            } else {
                              err = msg ?? 'Envoi impossible.';
                            }
                          });
                        } else {
                          final (ok, msg) = await ref
                              .read(businessProfileProvider.notifier)
                              .confirmerChangementTelephone(otpCtrl.text);
                          if (!ctx.mounted) return;
                          setSheetState(() => busy = false);
                          if (ok) {
                            Navigator.of(ctx).pop();
                            if (mounted) {
                              ScaffoldMessenger.of(context).showSnackBar(
                                SnackBar(
                                  content: Text(msg ?? 'Numéro mis à jour.'),
                                  backgroundColor: GoLivreurTheme.succes,
                                ),
                              );
                            }
                          } else {
                            setSheetState(
                              () => err = msg ?? 'Code OTP invalide.',
                            );
                          }
                        }
                      },
                child: Text(
                  busy
                      ? 'Traitement...'
                      : (step == 1
                          ? 'Envoyer le code SMS'
                          : 'Confirmer le numéro'),
                ),
              ),
            ],
          ),
        ),
      ),
    );
    phoneCtrl.dispose();
    otpCtrl.dispose();
  }

  Future<void> _openPasswordSheet(bool passwordSet) async {
    final currentCtrl = TextEditingController();
    final newCtrl = TextEditingController();
    final confirmCtrl = TextEditingController();
    var busy = false;
    String? err;

    await showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setSheetState) => Padding(
          padding: EdgeInsets.fromLTRB(
            20,
            20,
            20,
            MediaQuery.of(ctx).viewInsets.bottom + 20,
          ),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text(
                passwordSet
                    ? 'Changer mon mot de passe'
                    : 'Définir un mot de passe',
                style: const TextStyle(fontSize: 17, fontWeight: FontWeight.bold),
              ),
              const SizedBox(height: 12),
              if (err != null) ...[
                Text(
                  err!,
                  style: const TextStyle(
                    color: GoLivreurTheme.erreur,
                    fontSize: 13,
                  ),
                ),
                const SizedBox(height: 10),
              ],
              if (passwordSet) ...[
                TextField(
                  controller: currentCtrl,
                  obscureText: true,
                  decoration: const InputDecoration(
                    labelText: 'Ancien mot de passe',
                    border: OutlineInputBorder(),
                  ),
                ),
                const SizedBox(height: 10),
              ],
              TextField(
                controller: newCtrl,
                obscureText: true,
                decoration: const InputDecoration(
                  labelText: 'Nouveau mot de passe (min. 6 caractères)',
                  border: OutlineInputBorder(),
                ),
              ),
              const SizedBox(height: 10),
              TextField(
                controller: confirmCtrl,
                obscureText: true,
                decoration: const InputDecoration(
                  labelText: 'Confirmer le nouveau mot de passe',
                  border: OutlineInputBorder(),
                ),
              ),
              const SizedBox(height: 14),
              ElevatedButton(
                style: ElevatedButton.styleFrom(
                  backgroundColor: _businessColor,
                  foregroundColor: Colors.white,
                  padding: const EdgeInsets.symmetric(vertical: 14),
                ),
                onPressed: busy
                    ? null
                    : () async {
                        if (newCtrl.text.length < 6) {
                          setSheetState(
                            () => err = 'Au moins 6 caractères requis.',
                          );
                          return;
                        }
                        if (newCtrl.text != confirmCtrl.text) {
                          setSheetState(
                            () => err = 'Les mots de passe ne correspondent pas.',
                          );
                          return;
                        }
                        setSheetState(() {
                          busy = true;
                          err = null;
                        });
                        final (ok, msg) = await ref
                            .read(businessProfileProvider.notifier)
                            .definirMotDePasse(
                              currentPassword:
                                  passwordSet ? currentCtrl.text : null,
                              password: newCtrl.text,
                              passwordConfirmation: confirmCtrl.text,
                            );
                        if (!ctx.mounted) return;
                        setSheetState(() => busy = false);
                        if (ok) {
                          Navigator.of(ctx).pop();
                          if (mounted) {
                            ScaffoldMessenger.of(context).showSnackBar(
                              SnackBar(
                                content: Text(msg ?? 'Mot de passe mis à jour.'),
                                backgroundColor: GoLivreurTheme.succes,
                              ),
                            );
                          }
                        } else {
                          setSheetState(
                            () => err = msg ?? 'Modification impossible.',
                          );
                        }
                      },
                child: Text(busy ? 'Enregistrement...' : 'Enregistrer'),
              ),
            ],
          ),
        ),
      ),
    );
    currentCtrl.dispose();
    newCtrl.dispose();
    confirmCtrl.dispose();
  }

  Widget _readOnlyRow(IconData icon, String label, String value) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 7),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(icon, size: 19, color: GoLivreurTheme.texteSecondaire),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  label,
                  style: const TextStyle(
                    fontSize: 11.5,
                    color: GoLivreurTheme.texteSecondaire,
                  ),
                ),
                Text(
                  value.isNotEmpty ? value : 'Non renseigné',
                  style: const TextStyle(
                    fontSize: 14,
                    fontWeight: FontWeight.w600,
                    color: GoLivreurTheme.textePrincipal,
                  ),
                ),
              ],
            ),
          ),
          const Icon(Icons.lock_outline, size: 15, color: Colors.grey),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final profile = ref.watch(businessProfileProvider);

    return Scaffold(
      backgroundColor: GoLivreurTheme.fond,
      appBar: AppBar(
        title: const Text('Profil & Horaires'),
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
                    // ─── Infos Boutique (Défini par GoLivreur — Lecture seule) ───
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
                          Row(
                            children: [
                              const Expanded(
                                child: Text(
                                  'Identité & Position de la boutique',
                                  style: TextStyle(
                                    fontSize: 15,
                                    fontWeight: FontWeight.bold,
                                  ),
                                ),
                              ),
                              Container(
                                padding: const EdgeInsets.symmetric(
                                  horizontal: 8,
                                  vertical: 3,
                                ),
                                decoration: BoxDecoration(
                                  color: Colors.blue.shade50,
                                  borderRadius: BorderRadius.circular(8),
                                ),
                                child: Text(
                                  'Défini par GoLivreur',
                                  style: TextStyle(
                                    fontSize: 11,
                                    fontWeight: FontWeight.bold,
                                    color: Colors.blue.shade800,
                                  ),
                                ),
                              ),
                            ],
                          ),
                          const SizedBox(height: 6),
                          const Text(
                            'Nom, catégorie, quartier, adresse et position GPS sont gérés par l\'agence GoLivreur pour garantir des frais de livraison exacts.',
                            style: TextStyle(
                              fontSize: 12,
                              color: GoLivreurTheme.texteSecondaire,
                            ),
                          ),
                          const Divider(height: 20),
                          _readOnlyRow(
                            Icons.storefront_outlined,
                            'Nom de la boutique',
                            profile.name,
                          ),
                          _readOnlyRow(
                            Icons.category_outlined,
                            'Catégorie',
                            profile.category,
                          ),
                          _readOnlyRow(
                            Icons.location_city_outlined,
                            'Ville',
                            profile.cityName ?? '',
                          ),
                          _readOnlyRow(
                            Icons.place_outlined,
                            'Adresse / Quartier',
                            profile.quarter,
                          ),
                          _readOnlyRow(
                            Icons.my_location,
                            'Coordonnées GPS fixes',
                            (profile.lat != null && profile.lng != null)
                                ? '${profile.lat!.toStringAsFixed(5)}, ${profile.lng!.toStringAsFixed(5)}'
                                : 'Défini par GoLivreur',
                          ),
                        ],
                      ),
                    ),
                    const SizedBox(height: 16),

                    // ─── Visuels & Description (Libres) ──────────────────────────
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
                            'Vitrine & Description',
                            style: TextStyle(
                              fontSize: 15,
                              fontWeight: FontWeight.bold,
                            ),
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
                                size: 80,
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
                                size: 80,
                                isSquare: true,
                                isUploading:
                                    _isSaving && _pickedStorePhoto != null,
                                onImagePicked: (file, bytes) {
                                  setState(() {
                                    _pickedStorePhoto = file;
                                    _pickedStorePhotoBytes = bytes;
                                  });
                                },
                              ),
                            ],
                          ),
                          const SizedBox(height: 12),
                          Wrap(
                            spacing: 6,
                            runSpacing: 6,
                            children: _presetEmojis
                                .map(
                                  (e) => ChoiceChip(
                                    label: Text(e),
                                    selected: _emojiCtrl.text == e,
                                    onSelected: (_) =>
                                        setState(() => _emojiCtrl.text = e),
                                  ),
                                )
                                .toList(),
                          ),
                          const SizedBox(height: 14),
                          TextFormField(
                            controller: _descCtrl,
                            maxLines: 3,
                            decoration: const InputDecoration(
                              labelText: 'Description de la boutique',
                              hintText: 'Spécialités, ambiance, informations utiles...',
                              border: OutlineInputBorder(),
                            ),
                          ),
                        ],
                      ),
                    ),
                    const SizedBox(height: 16),

                    // ─── Horaires d'ouverture structurés (Point 4) ───────────────
                    Material(
                      color: Colors.white,
                      shape: RoundedRectangleBorder(
                        borderRadius: BorderRadius.circular(16),
                        side: const BorderSide(color: GoLivreurTheme.bordure),
                      ),
                      clipBehavior: Clip.antiAlias,
                      child: Padding(
                        padding: const EdgeInsets.all(16),
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            const Text(
                              'Horaires d\'ouverture (Africa/Abidjan)',
                              style: TextStyle(
                                fontSize: 15,
                                fontWeight: FontWeight.bold,
                              ),
                            ),
                            const SizedBox(height: 4),
                            Text(
                              _openingHours.toReadableSummary(),
                              style: const TextStyle(
                                fontSize: 12,
                                color: GoLivreurTheme.texteSecondaire,
                              ),
                            ),
                            SwitchListTile(
                              contentPadding: EdgeInsets.zero,
                              title: const Text(
                                'Ouvert 24h/24, 7j/7',
                                style: TextStyle(fontWeight: FontWeight.w600),
                              ),
                              value: _openingHours.is247,
                              activeThumbColor: _businessColor,
                              onChanged: (val) {
                                setState(() {
                                  if (val) {
                                    _openingHours =
                                        const ShopOpeningHours(is247: true);
                                  } else {
                                    final defaults = <String, List<TimeInterval>>{
                                      for (final k in ShopOpeningHours.dayKeys)
                                        k: [
                                          const TimeInterval(
                                            start: '08:00',
                                            end: '22:00',
                                          ),
                                        ],
                                    };
                                    _openingHours = ShopOpeningHours(
                                      is247: false,
                                      days: _openingHours.days.isNotEmpty
                                          ? _openingHours.days
                                          : defaults,
                                    );
                                  }
                                });
                              },
                            ),
                            if (!_openingHours.is247) ...[
                              const Divider(height: 12),
                              ...ShopOpeningHours.dayKeys.map((dayKey) {
                                final intervals =
                                    _openingHours.days[dayKey] ?? const [];
                                final isOpenDay = intervals.isNotEmpty;
                                return Padding(
                                  padding:
                                      const EdgeInsets.symmetric(vertical: 6),
                                  child: Column(
                                    crossAxisAlignment:
                                        CrossAxisAlignment.start,
                                    children: [
                                      Row(
                                        children: [
                                          SizedBox(
                                            width: 88,
                                            child: Text(
                                              ShopOpeningHours
                                                  .dayLabels[dayKey]!,
                                              style: const TextStyle(
                                                fontWeight: FontWeight.w600,
                                                fontSize: 13.5,
                                              ),
                                            ),
                                          ),
                                          Switch(
                                            value: isOpenDay,
                                            activeThumbColor: _businessColor,
                                            onChanged: (v) =>
                                                _toggleDayOpen(dayKey, v),
                                          ),
                                          Text(
                                            isOpenDay ? 'Ouvert' : 'Fermé',
                                            style: TextStyle(
                                              fontSize: 12,
                                              color: isOpenDay
                                                  ? GoLivreurTheme.succes
                                                  : GoLivreurTheme
                                                      .texteSecondaire,
                                            ),
                                          ),
                                          const Spacer(),
                                          if (isOpenDay && intervals.length < 2)
                                            TextButton(
                                              onPressed: () =>
                                                  _addSecondInterval(dayKey),
                                              child: const Text('+ 2e plage'),
                                            ),
                                        ],
                                      ),
                                      if (isOpenDay)
                                        ...intervals.asMap().entries.map((entry) {
                                          final idx = entry.key;
                                          final iv = entry.value;
                                          return Padding(
                                            padding: const EdgeInsets.only(
                                              left: 12,
                                              bottom: 6,
                                            ),
                                            child: Row(
                                              children: [
                                                OutlinedButton(
                                                  onPressed: () => _pickTime(
                                                    dayKey,
                                                    idx,
                                                    true,
                                                  ),
                                                  child: Text(iv.start),
                                                ),
                                                const Padding(
                                                  padding: EdgeInsets.symmetric(
                                                    horizontal: 8,
                                                  ),
                                                  child: Text('→'),
                                                ),
                                                OutlinedButton(
                                                  onPressed: () => _pickTime(
                                                    dayKey,
                                                    idx,
                                                    false,
                                                  ),
                                                  child: Text(
                                                    iv.isOvernight
                                                        ? '${iv.end} (+1j)'
                                                        : iv.end,
                                                  ),
                                                ),
                                                if (intervals.length > 1)
                                                  IconButton(
                                                    icon: const Icon(
                                                      Icons.close,
                                                      size: 18,
                                                      color:
                                                          GoLivreurTheme.erreur,
                                                    ),
                                                    onPressed: () =>
                                                        _removeInterval(
                                                      dayKey,
                                                      idx,
                                                    ),
                                                  ),
                                              ],
                                            ),
                                          );
                                        }),
                                    ],
                                  ),
                                );
                              }),
                            ],
                          ],
                        ),
                      ),
                    ),
                    const SizedBox(height: 16),

                    // ─── Sécurité : Téléphone OTP & Mot de passe ─────────────────
                    Material(
                      color: Colors.white,
                      shape: RoundedRectangleBorder(
                        borderRadius: BorderRadius.circular(16),
                        side: const BorderSide(color: GoLivreurTheme.bordure),
                      ),
                      clipBehavior: Clip.antiAlias,
                      child: Column(
                        children: [
                          ListTile(
                            leading: const Icon(
                              Icons.phone_android_outlined,
                              color: _businessColor,
                            ),
                            title: const Text('Téléphone (changement par OTP)'),
                            subtitle: Text(
                              profile.ownerPhone.isNotEmpty
                                  ? '+225 ${profile.ownerPhone}'
                                  : 'Non renseigné',
                            ),
                            trailing: const Icon(Icons.chevron_right),
                            onTap: _openPhoneChangeSheet,
                          ),
                          const Divider(height: 1),
                          ListTile(
                            leading: const Icon(
                              Icons.lock_reset_outlined,
                              color: GoLivreurTheme.primaire,
                            ),
                            title: Text(
                              profile.passwordSet
                                  ? 'Changer mon mot de passe'
                                  : 'Définir un mot de passe',
                            ),
                            trailing: const Icon(Icons.chevron_right),
                            onTap: () => _openPasswordSheet(profile.passwordSet),
                          ),
                          const Divider(height: 1),
                          SwitchListTile(
                            title: const Text('Notifications de commandes'),
                            value: _notificationsEnabled,
                            activeThumbColor: _businessColor,
                            onChanged: (v) =>
                                setState(() => _notificationsEnabled = v),
                          ),
                        ],
                      ),
                    ),
                    const SizedBox(height: 16),

                    if (_errorMsg != null) ...[
                      Text(
                        _errorMsg!,
                        style: const TextStyle(
                          color: GoLivreurTheme.erreur,
                          fontSize: 13,
                        ),
                      ),
                      const SizedBox(height: 10),
                    ],

                    SizedBox(
                      height: 52,
                      child: ElevatedButton.icon(
                        style: ElevatedButton.styleFrom(
                          backgroundColor: _businessColor,
                          foregroundColor: Colors.white,
                          shape: RoundedRectangleBorder(
                            borderRadius: BorderRadius.circular(14),
                          ),
                        ),
                        onPressed: _isSaving ? null : _save,
                        icon: const Icon(Icons.save_outlined),
                        label: Text(
                          _isSaving
                              ? 'Enregistrement...'
                              : 'Enregistrer les modifications',
                          style: const TextStyle(
                            fontWeight: FontWeight.bold,
                            fontSize: 15,
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
}
