import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:golivreur_core/config/app_theme.dart';
import 'package:golivreur_core/widgets/golivreur_widgets.dart';
import 'package:image_picker/image_picker.dart';
import '../../auth/providers/auth_provider.dart';

/// Écran « Modifier mon profil » — GL Client.
///
/// Règles d'intégrité :
///  - Le nom est verrouillé après inscription (lecture seule).
///  - Le numéro de téléphone ne peut être modifié qu'avec un OTP envoyé au NOUVEAU numéro.
///  - L'ajout ou modification d'un email nécessite un code de confirmation envoyé par email.
class EditClientProfileScreen extends ConsumerStatefulWidget {
  const EditClientProfileScreen({super.key});

  @override
  ConsumerState<EditClientProfileScreen> createState() =>
      _EditClientProfileScreenState();
}

class _EditClientProfileScreenState
    extends ConsumerState<EditClientProfileScreen> {
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
  bool _loadFailed = false;
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
      _loadFailed = false;
      _errorMsg = null;
    });

    final err = await ref.read(authProvider.notifier).chargerProfil();
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
      setState(() {
        _isInitialLoading = false;
        _loadFailed = err != null;
        _errorMsg = err;
      });
    }
  }

  Future<void> _save() async {
    if (!_formKey.currentState!.validate()) return;

    setState(() {
      _isSaving = true;
      _loadFailed = false;
      _errorMsg = null;
    });

    final (success, error) =
        await ref.read(authProvider.notifier).mettreAJourProfil(
              defaultAddress: _addressCtrl.text.trim().isNotEmpty
                  ? _addressCtrl.text.trim()
                  : null,
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
      setState(() =>
          _errorMsg = error ?? 'Impossible de sauvegarder vos modifications.');
    }
  }

  Future<void> _openPhoneChangeSheet() async {
    final newPhoneCtrl = TextEditingController();
    final otpCtrl = TextEditingController();
    var step = 1; // 1 = saisir nouveau numéro, 2 = saisir OTP 6 chiffres
    var busy = false;
    String? sheetError;
    String? infoMessage;

    final updated = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(22)),
      ),
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setSheetState) {
          final bottomInset = MediaQuery.of(ctx).viewInsets.bottom;
          return Padding(
            padding: EdgeInsets.fromLTRB(20, 20, 20, 24 + bottomInset),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Row(
                  children: [
                    const Icon(Icons.phonelink_lock,
                        color: GoLivreurTheme.primaire),
                    const SizedBox(width: 10),
                    Expanded(
                      child: Text(
                        step == 1
                            ? 'Modifier mon numéro'
                            : 'Confirmer le nouveau numéro',
                        style: const TextStyle(
                          fontSize: 17,
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 6),
                Text(
                  step == 1
                      ? 'Un code OTP de confirmation à 6 chiffres sera envoyé par SMS sur votre nouveau numéro.'
                      : 'Saisissez le code OTP à 6 chiffres reçu sur le +225 ${AuthNotifier.normalizePhone(newPhoneCtrl.text)}.',
                  style: const TextStyle(
                    fontSize: 12.5,
                    color: GoLivreurTheme.texteSecondaire,
                  ),
                ),
                const SizedBox(height: 16),
                if (step == 1)
                  TextField(
                    controller: newPhoneCtrl,
                    keyboardType: TextInputType.phone,
                    decoration: const InputDecoration(
                      labelText: 'Nouveau numéro ivoirien (10 chiffres)',
                      hintText: '0701000099',
                      prefixText: '+225 ',
                      prefixIcon: Icon(Icons.phone_outlined),
                      border: OutlineInputBorder(),
                    ),
                  )
                else
                  TextField(
                    controller: otpCtrl,
                    keyboardType: TextInputType.number,
                    maxLength: 6,
                    textAlign: TextAlign.center,
                    style: const TextStyle(
                      fontSize: 20,
                      fontWeight: FontWeight.bold,
                      letterSpacing: 6,
                    ),
                    inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                    decoration: const InputDecoration(
                      labelText: 'Code OTP (6 chiffres)',
                      border: OutlineInputBorder(),
                      counterText: '',
                    ),
                  ),
                if (infoMessage != null) ...[
                  const SizedBox(height: 10),
                  Text(
                    infoMessage!,
                    style: const TextStyle(
                      fontSize: 12.5,
                      color: GoLivreurTheme.secondaire,
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                ],
                if (sheetError != null) ...[
                  const SizedBox(height: 10),
                  Text(
                    sheetError!,
                    style: const TextStyle(
                      fontSize: 12.5,
                      color: GoLivreurTheme.erreur,
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                ],
                const SizedBox(height: 18),
                if (step == 1)
                  FilledButton(
                    style: FilledButton.styleFrom(
                      backgroundColor: GoLivreurTheme.primaire,
                      padding: const EdgeInsets.symmetric(vertical: 14),
                    ),
                    onPressed: busy
                        ? null
                        : () async {
                            final validationErr =
                                ProfileFormHelpers.validateIvorianPhone(
                                    newPhoneCtrl.text);
                            if (validationErr != null) {
                              setSheetState(() => sheetError = validationErr);
                              return;
                            }
                            setSheetState(() {
                              busy = true;
                              sheetError = null;
                              infoMessage = null;
                            });
                            final (ok, msg) = await ref
                                .read(authProvider.notifier)
                                .demanderOtpChangementTelephone(
                                    newPhoneCtrl.text);
                            if (!ctx.mounted) return;
                            setSheetState(() {
                              busy = false;
                              if (ok) {
                                step = 2;
                                infoMessage = msg;
                              } else {
                                sheetError = msg;
                              }
                            });
                          },
                    child: busy
                        ? const SizedBox(
                            width: 20,
                            height: 20,
                            child: CircularProgressIndicator(
                              strokeWidth: 2,
                              color: Colors.white,
                            ),
                          )
                        : const Text('Envoyer le code OTP'),
                  )
                else ...[
                  FilledButton(
                    style: FilledButton.styleFrom(
                      backgroundColor: GoLivreurTheme.secondaire,
                      padding: const EdgeInsets.symmetric(vertical: 14),
                    ),
                    onPressed: busy
                        ? null
                        : () async {
                            if (!RegExp(r'^\d{6}$')
                                .hasMatch(otpCtrl.text.trim())) {
                              setSheetState(() => sheetError =
                                  'Saisissez le code OTP à 6 chiffres.');
                              return;
                            }
                            setSheetState(() {
                              busy = true;
                              sheetError = null;
                            });
                            final (ok, msg) = await ref
                                .read(authProvider.notifier)
                                .confirmerChangementTelephone(
                                  newPhone: newPhoneCtrl.text,
                                  code: otpCtrl.text,
                                );
                            if (!ctx.mounted) return;
                            if (ok) {
                              Navigator.of(ctx).pop(true);
                            } else {
                              setSheetState(() {
                                busy = false;
                                sheetError = msg;
                              });
                            }
                          },
                    child: busy
                        ? const SizedBox(
                            width: 20,
                            height: 20,
                            child: CircularProgressIndicator(
                              strokeWidth: 2,
                              color: Colors.white,
                            ),
                          )
                        : const Text('Confirmer le nouveau numéro'),
                  ),
                  const SizedBox(height: 6),
                  TextButton(
                    onPressed: busy
                        ? null
                        : () => setSheetState(() {
                              step = 1;
                              otpCtrl.clear();
                              sheetError = null;
                              infoMessage = null;
                            }),
                    child: const Text('Modifier le numéro saisi'),
                  ),
                ],
              ],
            ),
          );
        },
      ),
    );

    newPhoneCtrl.dispose();
    otpCtrl.dispose();

    if (updated == true && mounted) {
      final refreshed = ref.read(authProvider).user;
      if (refreshed != null) {
        setState(() => _phoneCtrl.text = refreshed.phone);
      }
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Numéro de téléphone confirmé et mis à jour !'),
          backgroundColor: GoLivreurTheme.succes,
        ),
      );
    }
  }

  Future<void> _openEmailChangeSheet() async {
    final newEmailCtrl = TextEditingController();
    final otpCtrl = TextEditingController();
    var step = 1;
    var busy = false;
    String? sheetError;
    String? infoMessage;

    final updated = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(22)),
      ),
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setSheetState) {
          final bottomInset = MediaQuery.of(ctx).viewInsets.bottom;
          return Padding(
            padding: EdgeInsets.fromLTRB(20, 20, 20, 24 + bottomInset),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Row(
                  children: [
                    const Icon(Icons.mark_email_read_outlined,
                        color: GoLivreurTheme.primaire),
                    const SizedBox(width: 10),
                    Expanded(
                      child: Text(
                        step == 1
                            ? 'Confirmer une adresse email'
                            : 'Saisir le code reçu par email',
                        style: const TextStyle(
                          fontSize: 17,
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 6),
                Text(
                  step == 1
                      ? 'Un code de confirmation à 6 chiffres sera envoyé à cette adresse email avant son enregistrement.'
                      : 'Saisissez le code à 6 chiffres envoyé à ${newEmailCtrl.text.trim().toLowerCase()}.',
                  style: const TextStyle(
                    fontSize: 12.5,
                    color: GoLivreurTheme.texteSecondaire,
                  ),
                ),
                const SizedBox(height: 16),
                if (step == 1)
                  TextField(
                    controller: newEmailCtrl,
                    keyboardType: TextInputType.emailAddress,
                    decoration: const InputDecoration(
                      labelText: 'Adresse email',
                      hintText: 'exemple@domaine.ci',
                      prefixIcon: Icon(Icons.email_outlined),
                      border: OutlineInputBorder(),
                    ),
                  )
                else
                  TextField(
                    controller: otpCtrl,
                    keyboardType: TextInputType.number,
                    maxLength: 6,
                    textAlign: TextAlign.center,
                    style: const TextStyle(
                      fontSize: 20,
                      fontWeight: FontWeight.bold,
                      letterSpacing: 6,
                    ),
                    inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                    decoration: const InputDecoration(
                      labelText: 'Code de confirmation (6 chiffres)',
                      border: OutlineInputBorder(),
                      counterText: '',
                    ),
                  ),
                if (infoMessage != null) ...[
                  const SizedBox(height: 10),
                  Text(
                    infoMessage!,
                    style: const TextStyle(
                      fontSize: 12.5,
                      color: GoLivreurTheme.secondaire,
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                ],
                if (sheetError != null) ...[
                  const SizedBox(height: 10),
                  Text(
                    sheetError!,
                    style: const TextStyle(
                      fontSize: 12.5,
                      color: GoLivreurTheme.erreur,
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                ],
                const SizedBox(height: 18),
                if (step == 1)
                  FilledButton(
                    style: FilledButton.styleFrom(
                      backgroundColor: GoLivreurTheme.primaire,
                      padding: const EdgeInsets.symmetric(vertical: 14),
                    ),
                    onPressed: busy
                        ? null
                        : () async {
                            final emailText = newEmailCtrl.text.trim();
                            if (emailText.isEmpty) {
                              setSheetState(() =>
                                  sheetError = 'Veuillez saisir une adresse email.');
                              return;
                            }
                            final validationErr =
                                ProfileFormHelpers.validateOptionalEmail(
                                    emailText);
                            if (validationErr != null) {
                              setSheetState(() => sheetError = validationErr);
                              return;
                            }
                            setSheetState(() {
                              busy = true;
                              sheetError = null;
                              infoMessage = null;
                            });
                            final (ok, msg) = await ref
                                .read(authProvider.notifier)
                                .demanderOtpChangementEmail(emailText);
                            if (!ctx.mounted) return;
                            setSheetState(() {
                              busy = false;
                              if (ok) {
                                step = 2;
                                infoMessage = msg;
                              } else {
                                sheetError = msg;
                              }
                            });
                          },
                    child: busy
                        ? const SizedBox(
                            width: 20,
                            height: 20,
                            child: CircularProgressIndicator(
                              strokeWidth: 2,
                              color: Colors.white,
                            ),
                          )
                        : const Text('Envoyer le code de confirmation'),
                  )
                else ...[
                  FilledButton(
                    style: FilledButton.styleFrom(
                      backgroundColor: GoLivreurTheme.secondaire,
                      padding: const EdgeInsets.symmetric(vertical: 14),
                    ),
                    onPressed: busy
                        ? null
                        : () async {
                            if (!RegExp(r'^\d{6}$')
                                .hasMatch(otpCtrl.text.trim())) {
                              setSheetState(() => sheetError =
                                  'Saisissez le code à 6 chiffres reçu par email.');
                              return;
                            }
                            setSheetState(() {
                              busy = true;
                              sheetError = null;
                            });
                            final (ok, msg) = await ref
                                .read(authProvider.notifier)
                                .confirmerChangementEmail(
                                  newEmail: newEmailCtrl.text,
                                  code: otpCtrl.text,
                                );
                            if (!ctx.mounted) return;
                            if (ok) {
                              Navigator.of(ctx).pop(true);
                            } else {
                              setSheetState(() {
                                busy = false;
                                sheetError = msg;
                              });
                            }
                          },
                    child: busy
                        ? const SizedBox(
                            width: 20,
                            height: 20,
                            child: CircularProgressIndicator(
                              strokeWidth: 2,
                              color: Colors.white,
                            ),
                          )
                        : const Text('Confirmer l\'adresse email'),
                  ),
                  const SizedBox(height: 6),
                  TextButton(
                    onPressed: busy
                        ? null
                        : () => setSheetState(() {
                              step = 1;
                              otpCtrl.clear();
                              sheetError = null;
                              infoMessage = null;
                            }),
                    child: const Text('Modifier l\'email saisi'),
                  ),
                ],
              ],
            ),
          );
        },
      ),
    );

    newEmailCtrl.dispose();
    otpCtrl.dispose();

    if (updated == true && mounted) {
      final refreshed = ref.read(authProvider).user;
      if (refreshed != null) {
        setState(() => _emailCtrl.text = refreshed.email ?? '');
      }
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Adresse email confirmée et enregistrée !'),
          backgroundColor: GoLivreurTheme.succes,
        ),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    final user = ref.watch(authProvider).user;
    final hasEmail = _emailCtrl.text.trim().isNotEmpty;

    return Scaffold(
      backgroundColor: GoLivreurTheme.fond,
      appBar: AppBar(
        title: const Text('Modifier mon profil'),
        backgroundColor: Colors.white,
        elevation: 0.5,
      ),
      body: _isInitialLoading
          ? const Center(
              child: CircularProgressIndicator(color: GoLivreurTheme.primaire))
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
                          border: Border.all(
                              color:
                                  GoLivreurTheme.erreur.withValues(alpha: 0.4)),
                        ),
                        child: Row(
                          children: [
                            const Icon(Icons.error_outline,
                                color: GoLivreurTheme.erreur),
                            const SizedBox(width: 8),
                            Expanded(
                              child: Text(
                                _errorMsg!,
                                style: const TextStyle(
                                    color: GoLivreurTheme.erreur, fontSize: 13),
                              ),
                            ),
                            TextButton(
                              onPressed: _isSaving
                                  ? null
                                  : (_loadFailed ? _loadFromApi : _save),
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
                            style: TextStyle(
                                fontSize: 15, fontWeight: FontWeight.bold),
                          ),
                          const SizedBox(height: 14),

                          // Nom verrouillé après inscription
                          TextFormField(
                            controller: _nameCtrl,
                            readOnly: true,
                            enabled: false,
                            decoration: const InputDecoration(
                              labelText: 'Nom et prénom (verrouillé)',
                              helperText:
                                  'Non modifiable après inscription — contactez le support en cas d\'erreur.',
                              helperMaxLines: 2,
                              prefixIcon: Icon(Icons.person_outline),
                              suffixIcon: Icon(Icons.lock_outline,
                                  size: 18,
                                  color: GoLivreurTheme.texteSecondaire),
                              filled: true,
                              fillColor: GoLivreurTheme.fond,
                              border: OutlineInputBorder(),
                            ),
                          ),
                          const SizedBox(height: 14),

                          // Numéro de téléphone modifiable uniquement via OTP sur le nouveau numéro
                          TextFormField(
                            controller: _phoneCtrl,
                            readOnly: true,
                            decoration: InputDecoration(
                              labelText: 'Téléphone ivoirien',
                              helperText:
                                  'Confirmation par code OTP sur le nouveau numéro requise.',
                              prefixIcon: const Icon(Icons.phone_outlined),
                              prefixText: '+225 ',
                              suffixIcon: TextButton(
                                onPressed: _openPhoneChangeSheet,
                                child: const Text(
                                  'Modifier',
                                  style: TextStyle(
                                    color: GoLivreurTheme.primaire,
                                    fontWeight: FontWeight.bold,
                                  ),
                                ),
                              ),
                              border: const OutlineInputBorder(),
                            ),
                          ),
                          const SizedBox(height: 14),

                          // Email modifiable / ajoutable uniquement via code de confirmation par email
                          TextFormField(
                            controller: _emailCtrl,
                            readOnly: true,
                            decoration: InputDecoration(
                              labelText: 'Adresse email (optionnelle)',
                              hintText: 'Aucune adresse email enregistrée',
                              helperText:
                                  'Un code de confirmation envoyé par email est requis.',
                              prefixIcon: const Icon(Icons.email_outlined),
                              suffixIcon: TextButton(
                                onPressed: _openEmailChangeSheet,
                                child: Text(
                                  hasEmail ? 'Modifier' : 'Ajouter',
                                  style: const TextStyle(
                                    color: GoLivreurTheme.primaire,
                                    fontWeight: FontWeight.bold,
                                  ),
                                ),
                              ),
                              border: const OutlineInputBorder(),
                            ),
                          ),
                          const SizedBox(height: 14),

                          TextFormField(
                            controller: _addressCtrl,
                            maxLines: 2,
                            decoration: const InputDecoration(
                              labelText: 'Adresse de livraison par défaut',
                              hintText: 'Ex: Nom du quartier, rue ou repère',
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
                            style: TextStyle(
                                fontSize: 15, fontWeight: FontWeight.bold),
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
                              DropdownMenuItem(
                                  value: 'fr', child: Text('Français')),
                              DropdownMenuItem(
                                  value: 'en', child: Text('English')),
                            ],
                            onChanged: (val) =>
                                setState(() => _language = val ?? 'fr'),
                          ),
                          const SizedBox(height: 8),
                          SwitchListTile(
                            contentPadding: EdgeInsets.zero,
                            title: const Text('Notifications de commande',
                                style: TextStyle(fontSize: 14)),
                            subtitle: const Text(
                                'Alertes de livraison et mode pluie',
                                style: TextStyle(fontSize: 12)),
                            value: _notificationsEnabled,
                            activeThumbColor: GoLivreurTheme.primaire,
                            onChanged: (val) =>
                                setState(() => _notificationsEnabled = val),
                          ),
                          const Divider(),
                          ListTile(
                            contentPadding: EdgeInsets.zero,
                            leading: const Icon(Icons.lock_outline,
                                color: GoLivreurTheme.primaire),
                            title: const Text('Changer mon mot de passe',
                                style: TextStyle(
                                    fontWeight: FontWeight.w600, fontSize: 14)),
                            trailing: const Icon(Icons.chevron_right),
                            onTap: () =>
                                ProfileFormHelpers.showChangePasswordDialog(
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
                          shape: RoundedRectangleBorder(
                              borderRadius: BorderRadius.circular(14)),
                        ),
                        onPressed: _isSaving ? null : _save,
                        icon: _isSaving
                            ? const SizedBox(
                                width: 18,
                                height: 18,
                                child: CircularProgressIndicator(
                                    strokeWidth: 2, color: Colors.white),
                              )
                            : const Icon(Icons.check_circle_outline),
                        label: Text(
                          _isSaving
                              ? 'Enregistrement en cours...'
                              : 'Enregistrer les modifications',
                          style: const TextStyle(
                              fontWeight: FontWeight.bold, fontSize: 15),
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
