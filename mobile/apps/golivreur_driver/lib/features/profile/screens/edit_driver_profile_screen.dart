import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:golivreur_core/config/app_theme.dart';
import 'package:golivreur_core/config/env_config.dart';
import '../../driver/providers/driver_provider.dart';

/// Écran « Mon compte & sécurité » — GoLivreur Driver.
/// Conformément à la politique serveur (Lot 9A Point 1), l'identité, les pièces,
/// le véhicule, la ville et la photo sont en lecture seule (gérés par l'agence GoLivreur).
/// Le livreur peut modifier son téléphone (via OTP), son mot de passe et ses préférences.
class EditDriverProfileScreen extends ConsumerStatefulWidget {
  const EditDriverProfileScreen({super.key});

  @override
  ConsumerState<EditDriverProfileScreen> createState() =>
      _EditDriverProfileScreenState();
}

class _EditDriverProfileScreenState
    extends ConsumerState<EditDriverProfileScreen> {
  String _language = 'fr';
  bool _notificationsEnabled = true;
  bool _isInitialLoading = true;
  bool _isSaving = false;
  String? _errorMsg;

  @override
  void initState() {
    super.initState();
    final profile = ref.read(driverProfileProvider);
    _language = profile.language;
    _notificationsEnabled = profile.notificationsEnabled;
    WidgetsBinding.instance.addPostFrameCallback((_) => _loadInitialData());
  }

  Future<void> _loadInitialData() async {
    setState(() {
      _isInitialLoading = true;
      _errorMsg = null;
    });
    await ref.read(driverProfileProvider.notifier).chargerProfil();
    if (!mounted) return;
    final profile = ref.read(driverProfileProvider);
    setState(() {
      _language = profile.language;
      _notificationsEnabled = profile.notificationsEnabled;
      _isInitialLoading = false;
    });
  }

  Future<void> _savePreferences() async {
    setState(() {
      _isSaving = true;
      _errorMsg = null;
    });
    final (ok, err) = await ref
        .read(driverProfileProvider.notifier)
        .mettreAJourPreferences(
          language: _language,
          notificationsEnabled: _notificationsEnabled,
        );
    if (!mounted) return;
    setState(() => _isSaving = false);
    if (ok) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Préférences enregistrées.'),
          backgroundColor: GoLivreurTheme.succes,
        ),
      );
    } else {
      setState(() => _errorMsg = err ?? 'Impossible d\'enregistrer.');
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
                    labelText: 'Nouveau numéro (ex: 0703000099)',
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
                  backgroundColor: GoLivreurTheme.secondaire,
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
                              .read(driverProfileProvider.notifier)
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
                              .read(driverProfileProvider.notifier)
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
                      : (step == 1 ? 'Envoyer le code SMS' : 'Confirmer le numéro'),
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
                passwordSet ? 'Changer mon mot de passe' : 'Définir un mot de passe',
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
                  backgroundColor: GoLivreurTheme.secondaire,
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
                            .read(driverProfileProvider.notifier)
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
      padding: const EdgeInsets.symmetric(vertical: 8),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(icon, size: 20, color: GoLivreurTheme.texteSecondaire),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  label,
                  style: const TextStyle(
                    fontSize: 12,
                    color: GoLivreurTheme.texteSecondaire,
                  ),
                ),
                const SizedBox(height: 2),
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
          const Icon(Icons.lock_outline, size: 16, color: Colors.grey),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final profile = ref.watch(driverProfileProvider);
    final photoUrl = GoLivreurEnv.resolveMediaUrl(profile.photoUrl);

    return Scaffold(
      backgroundColor: GoLivreurTheme.fond,
      appBar: AppBar(
        title: const Text('Mon compte & sécurité'),
        backgroundColor: Colors.white,
        elevation: 0.5,
      ),
      body: _isInitialLoading
          ? const Center(
              child: CircularProgressIndicator(color: GoLivreurTheme.secondaire),
            )
          : ListView(
              padding: const EdgeInsets.all(16),
              children: [
                // ─── Bandeau Lecture Seule Agence ────────────────────────────
                Container(
                  padding: const EdgeInsets.all(14),
                  decoration: BoxDecoration(
                    color: Colors.blue.shade50,
                    borderRadius: BorderRadius.circular(14),
                    border: Border.all(color: Colors.blue.shade200),
                  ),
                  child: Row(
                    children: [
                      Icon(Icons.info_outline, color: Colors.blue.shade700),
                      const SizedBox(width: 10),
                      const Expanded(
                        child: Text(
                          'Contactez l\'agence GoLivreur pour modifier ces informations (identité, véhicule, pièces, ville ou photo).',
                          style: TextStyle(fontSize: 12.5, height: 1.35),
                        ),
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: 16),

                // ─── Dossier Livreur (Lecture seule) ─────────────────────────
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
                          CircleAvatar(
                            radius: 26,
                            backgroundColor:
                                GoLivreurTheme.secondaire.withValues(alpha: 0.15),
                            backgroundImage:
                                (photoUrl != null && photoUrl.isNotEmpty)
                                    ? NetworkImage(photoUrl)
                                    : null,
                            child: (photoUrl == null || photoUrl.isEmpty)
                                ? const Icon(
                                    Icons.two_wheeler,
                                    color: GoLivreurTheme.secondaire,
                                  )
                                : null,
                          ),
                          const SizedBox(width: 12),
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text(
                                  profile.name.isNotEmpty
                                      ? profile.name
                                      : 'Livreur GoLivreur',
                                  style: const TextStyle(
                                    fontSize: 16,
                                    fontWeight: FontWeight.bold,
                                  ),
                                ),
                                Text(
                                  'Statut : ${profile.accountStatus} • ${profile.completedDeliveries} livraisons',
                                  style: const TextStyle(
                                    fontSize: 12,
                                    color: GoLivreurTheme.texteSecondaire,
                                  ),
                                ),
                              ],
                            ),
                          ),
                        ],
                      ),
                      const Divider(height: 24),
                      _readOnlyRow(
                        Icons.person_outline,
                        'Nom complet',
                        profile.name,
                      ),
                      _readOnlyRow(
                        Icons.location_city_outlined,
                        'Ville d\'affectation',
                        profile.cityName ?? '',
                      ),
                      _readOnlyRow(
                        Icons.two_wheeler,
                        'Véhicule',
                        profile.vehicleTypeName.isNotEmpty
                            ? profile.vehicleTypeName
                            : profile.vehicleType,
                      ),
                      _readOnlyRow(
                        Icons.confirmation_number_outlined,
                        'Plaque d\'immatriculation',
                        profile.motoPlate,
                      ),
                      _readOnlyRow(
                        Icons.credit_card_outlined,
                        'Permis de conduire',
                        profile.driverLicenseNumber ?? '',
                      ),
                      _readOnlyRow(
                        Icons.badge_outlined,
                        'N° CNI / Pièce d\'identité',
                        profile.idCardNumber ?? '',
                      ),
                    ],
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
                          color: GoLivreurTheme.secondaire,
                        ),
                        title: const Text('Téléphone (changement par OTP)'),
                        subtitle: Text(
                          profile.phone.isNotEmpty
                              ? '+225 ${profile.phone}'
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
                        subtitle: Text(
                          profile.passwordSet
                              ? 'Mot de passe configuré'
                              : 'Aucun mot de passe défini',
                        ),
                        trailing: const Icon(Icons.chevron_right),
                        onTap: () => _openPasswordSheet(profile.passwordSet),
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: 16),

                // ─── Préférences locales ─────────────────────────────────────
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
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        const Text(
                          'Préférences',
                          style: TextStyle(
                            fontSize: 15,
                            fontWeight: FontWeight.bold,
                          ),
                        ),
                        SwitchListTile(
                          contentPadding: EdgeInsets.zero,
                          title: const Text('Notifications de courses'),
                          value: _notificationsEnabled,
                          activeThumbColor: GoLivreurTheme.secondaire,
                          onChanged: (v) =>
                              setState(() => _notificationsEnabled = v),
                        ),
                        const SizedBox(height: 8),
                        ElevatedButton(
                          style: ElevatedButton.styleFrom(
                            backgroundColor: GoLivreurTheme.secondaire,
                            foregroundColor: Colors.white,
                            padding: const EdgeInsets.symmetric(vertical: 14),
                          ),
                          onPressed: _isSaving ? null : _savePreferences,
                          child: Text(
                            _isSaving
                                ? 'Enregistrement...'
                                : 'Enregistrer les préférences',
                          ),
                        ),
                      ],
                    ),
                  ),
                ),
              ],
            ),
    );
  }
}
