import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:golivreur_core/config/app_theme.dart';
import 'package:golivreur_core/widgets/golivreur_widgets.dart';
import '../providers/auth_provider.dart';

/// Issue retournée à l'écran de connexion après fermeture du flux "Mot de passe oublié".
class ForgotPasswordOutcome {
  final bool resetSucceeded;
  final bool locked;
  final String? message;
  final String supportPhone;

  const ForgotPasswordOutcome({
    this.resetSucceeded = false,
    this.locked = false,
    this.message,
    this.supportPhone = SupportContactService.defaultSupportPhone,
  });
}

/// Affiche le dialogue bloquant avec bouton d'appel direct au service client
/// lorsque l'utilisateur atteint 2 échecs OTP ou dépasse la fenêtre de 5 minutes.
Future<void> showPasswordResetLockedDialog(
  BuildContext context, {
  required String message,
  required String supportPhone,
}) {
  return showDialog<void>(
    context: context,
    barrierDismissible: false,
    builder: (ctx) => AlertDialog(
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(18)),
      icon: const Icon(
        Icons.lock_clock_outlined,
        color: GoLivreurTheme.erreur,
        size: 44,
      ),
      title: const Text(
        'Réinitialisation bloquée',
        textAlign: TextAlign.center,
        style: TextStyle(fontWeight: FontWeight.bold, fontSize: 18),
      ),
      content: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Text(
            message,
            textAlign: TextAlign.center,
            style: const TextStyle(
              fontSize: 14,
              color: GoLivreurTheme.textePrincipal,
              height: 1.4,
            ),
          ),
          const SizedBox(height: 12),
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
            decoration: BoxDecoration(
              color: GoLivreurTheme.fond,
              borderRadius: BorderRadius.circular(10),
              border: Border.all(color: GoLivreurTheme.bordure),
            ),
            child: Row(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                const Icon(
                  Icons.support_agent,
                  size: 18,
                  color: GoLivreurTheme.secondaire,
                ),
                const SizedBox(width: 8),
                Text(
                  'Service client : $supportPhone',
                  style: const TextStyle(
                    fontWeight: FontWeight.w600,
                    fontSize: 13,
                    color: GoLivreurTheme.textePrincipal,
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
      actionsAlignment: MainAxisAlignment.center,
      actions: [
        TextButton(
          onPressed: () => Navigator.of(ctx).pop(),
          child: const Text('Fermer'),
        ),
        FilledButton.icon(
          style: FilledButton.styleFrom(
            backgroundColor: GoLivreurTheme.secondaire,
          ),
          onPressed: () async {
            final launched = await SupportContactService.callPhone(supportPhone);
            if (!launched && ctx.mounted) {
              ScaffoldMessenger.of(ctx).showSnackBar(
                SnackBar(
                  content: Text('Appelez le service client au $supportPhone'),
                ),
              );
            }
          },
          icon: const Icon(Icons.phone_in_talk, size: 18),
          label: const Text('Appeler le service client'),
        ),
      ],
    ),
  );
}

/// BottomSheet "Mot de passe oublié ?" en 2 étapes :
///  - Étape 1 : Numéro de téléphone + "Recevoir le code par SMS"
///  - Étape 2 : "Code de réinitialisation" (Code OTP + Nouveau mot de passe + Confirmation)
///    avec chrono strict de 5 minutes, 2 essais max et blocage automatique avec appel support.
class ForgotPasswordSheet extends ConsumerStatefulWidget {
  final String? initialPhone;
  final String fallbackSupportPhone;

  const ForgotPasswordSheet({
    super.key,
    this.initialPhone,
    this.fallbackSupportPhone = SupportContactService.defaultSupportPhone,
  });

  static Future<ForgotPasswordOutcome?> show(
    BuildContext context, {
    String? initialPhone,
    String fallbackSupportPhone = SupportContactService.defaultSupportPhone,
  }) {
    return showModalBottomSheet<ForgotPasswordOutcome>(
      context: context,
      isScrollControlled: true,
      isDismissible: false,
      enableDrag: false,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
      ),
      builder: (_) => ForgotPasswordSheet(
        initialPhone: initialPhone,
        fallbackSupportPhone: fallbackSupportPhone,
      ),
    );
  }

  @override
  ConsumerState<ForgotPasswordSheet> createState() =>
      _ForgotPasswordSheetState();
}

class _ForgotPasswordSheetState extends ConsumerState<ForgotPasswordSheet> {
  static const String _lockedMessage =
      'Trop de tentatives ou délai expiré. Veuillez contacter le service client pour réinitialiser votre mot de passe.';

  late final TextEditingController _phoneCtrl;
  final _otpCtrl = TextEditingController();
  final _newPasswordCtrl = TextEditingController();
  final _confirmPasswordCtrl = TextEditingController();

  int _step = 1; // 1 = saisie téléphone, 2 = formulaire de réinitialisation
  bool _busy = false;
  bool _obscureNew = true;
  bool _obscureConfirm = true;
  String? _errorMsg;
  String? _infoMsg;

  int _remainingSeconds = 300; // Chrono strict de 5 minutes (300s)
  int _resendCooldown = 0;
  int _remainingAttempts = 2; // 2 tentatives maximum
  String _supportPhone = SupportContactService.defaultSupportPhone;
  String? _autoVerifiedIdToken;

  Timer? _countdownTimer;
  StreamSubscription<({String phoneE164, String idToken})>? _autoVerifySub;
  bool _closed = false;

  @override
  void initState() {
    super.initState();
    _phoneCtrl = TextEditingController(
      text: widget.initialPhone != null
          ? AuthNotifier.normalizePhone(widget.initialPhone!)
          : '',
    );
    _supportPhone = widget.fallbackSupportPhone;
  }

  @override
  void dispose() {
    _countdownTimer?.cancel();
    _autoVerifySub?.cancel();
    _phoneCtrl.dispose();
    _otpCtrl.dispose();
    _newPasswordCtrl.dispose();
    _confirmPasswordCtrl.dispose();
    super.dispose();
  }

  void _startStrictFiveMinuteTimer(int initialSeconds) {
    _countdownTimer?.cancel();
    _remainingSeconds = initialSeconds.clamp(1, 300);
    _resendCooldown = 30;

    _countdownTimer = Timer.periodic(const Duration(seconds: 1), (timer) {
      if (!mounted) {
        timer.cancel();
        return;
      }
      setState(() {
        if (_remainingSeconds > 0) {
          _remainingSeconds--;
        }
        if (_resendCooldown > 0) {
          _resendCooldown--;
        }
      });

      if (_remainingSeconds <= 0) {
        timer.cancel();
        _closeWithLockout(_lockedMessage);
      }
    });
  }

  void _subscribeAutoVerified(String cleanPhone) {
    _autoVerifySub?.cancel();
    final expectedE164 = FirebasePhoneOtpService.toE164Ivorian(cleanPhone);
    _autoVerifySub = FirebasePhoneOtpService.onAutoVerified.listen((event) {
      if (!mounted || event.phoneE164 != expectedE164) return;
      setState(() {
        _autoVerifiedIdToken = event.idToken;
        _infoMsg =
            'Code SMS vérifié automatiquement ! Définissez votre nouveau mot de passe.';
      });
    });
  }

  void _closeWithLockout(String message) {
    if (_closed || !mounted) return;
    _closed = true;
    _countdownTimer?.cancel();
    FirebasePhoneOtpService.clearSession();
    Navigator.of(context).pop(
      ForgotPasswordOutcome(
        locked: true,
        message: message.isNotEmpty ? message : _lockedMessage,
        supportPhone: _supportPhone,
      ),
    );
  }

  Future<void> _requestCode({bool isResend = false}) async {
    final cleanPhone = AuthNotifier.normalizePhone(_phoneCtrl.text);
    if (!RegExp(r'^(01|05|07|21|25|27)[0-9]{8}$').hasMatch(cleanPhone)) {
      setState(() {
        _errorMsg = 'Veuillez saisir un numéro ivoirien valide à 10 chiffres.';
      });
      return;
    }

    setState(() {
      _busy = true;
      _errorMsg = null;
      _infoMsg = null;
    });

    final res = await ref.read(authProvider.notifier).demanderResetMotDePasse(
          cleanPhone,
          isResend: isResend,
        );

    if (!mounted) return;

    _supportPhone = res.supportPhone;

    if (res.locked) {
      _closeWithLockout(res.message);
      return;
    }

    if (!res.success) {
      setState(() {
        _busy = false;
        _errorMsg = res.message;
      });
      return;
    }

    _subscribeAutoVerified(cleanPhone);

    setState(() {
      _busy = false;
      _step = 2;
      _remainingAttempts = res.remainingAttempts;
      _autoVerifiedIdToken = res.autoVerifiedIdToken;
      _infoMsg = res.autoVerifiedIdToken != null
          ? 'Numéro vérifié automatiquement par SMS ! Choisissez votre nouveau mot de passe.'
          : 'Code envoyé au +225 $cleanPhone. Valable 5 minutes (${res.remainingAttempts} essai${res.remainingAttempts > 1 ? 's' : ''} max).';
      if (isResend) {
        _resendCooldown = 30;
      }
    });

    if (!isResend) {
      _startStrictFiveMinuteTimer(res.expiresInSeconds);
    }
  }

  Future<void> _submitReset() async {
    if (_remainingSeconds <= 0) {
      _closeWithLockout(_lockedMessage);
      return;
    }

    final cleanPhone = AuthNotifier.normalizePhone(_phoneCtrl.text);
    final code = _otpCtrl.text.trim();
    final newPass = _newPasswordCtrl.text;
    final confirmPass = _confirmPasswordCtrl.text;

    if (_autoVerifiedIdToken == null && !RegExp(r'^\d{6}$').hasMatch(code)) {
      setState(() {
        _errorMsg = 'Veuillez saisir le code de réinitialisation à 6 chiffres.';
      });
      return;
    }

    if (newPass.length < 6) {
      setState(() {
        _errorMsg = 'Le nouveau mot de passe doit contenir au moins 6 caractères.';
      });
      return;
    }

    if (newPass != confirmPass) {
      setState(() {
        _errorMsg = 'La confirmation du nouveau mot de passe ne correspond pas.';
      });
      return;
    }

    setState(() {
      _busy = true;
      _errorMsg = null;
    });

    final res = await ref.read(authProvider.notifier).confirmerResetMotDePasse(
          phone: cleanPhone,
          code: code,
          newPassword: newPass,
          passwordConfirmation: confirmPass,
          firebaseIdToken: _autoVerifiedIdToken,
        );

    if (!mounted) return;

    _supportPhone = res.supportPhone;

    if (res.success) {
      _closed = true;
      _countdownTimer?.cancel();
      Navigator.of(context).pop(
        ForgotPasswordOutcome(
          resetSucceeded: true,
          message: res.message,
          supportPhone: _supportPhone,
        ),
      );
      return;
    }

    if (res.locked || (res.remainingAttempts != null && res.remainingAttempts! <= 0)) {
      _closeWithLockout(res.message);
      return;
    }

    setState(() {
      _busy = false;
      if (res.remainingAttempts != null) {
        _remainingAttempts = res.remainingAttempts!;
      } else {
        _remainingAttempts = (_remainingAttempts - 1).clamp(0, 2);
      }
      _errorMsg = res.message;
    });

    if (_remainingAttempts <= 0) {
      _closeWithLockout(_lockedMessage);
    }
  }

  String _formatTimer(int totalSeconds) {
    final m = (totalSeconds ~/ 60).toString().padLeft(2, '0');
    final s = (totalSeconds % 60).toString().padLeft(2, '0');
    return '$m:$s';
  }

  @override
  Widget build(BuildContext context) {
    final bottomInset = MediaQuery.of(context).viewInsets.bottom;

    return Padding(
      padding: EdgeInsets.fromLTRB(20, 20, 20, 20 + bottomInset),
      child: SingleChildScrollView(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              children: [
                Icon(
                  _step == 1 ? Icons.lock_reset : Icons.verified_user_outlined,
                  color: GoLivreurTheme.primaire,
                  size: 26,
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: Text(
                    _step == 1
                        ? 'Mot de passe oublié'
                        : 'Code de réinitialisation',
                    style: const TextStyle(
                      fontSize: 18,
                      fontWeight: FontWeight.bold,
                      color: GoLivreurTheme.textePrincipal,
                    ),
                  ),
                ),
                IconButton(
                  tooltip: 'Annuler',
                  onPressed: _busy
                      ? null
                      : () {
                          FirebasePhoneOtpService.clearSession();
                          Navigator.of(context).pop();
                        },
                  icon: const Icon(Icons.close),
                ),
              ],
            ),
            const SizedBox(height: 6),

            if (_step == 1) ...[
              const Text(
                'Saisissez le numéro de téléphone associé à votre compte pour recevoir un code de réinitialisation par SMS.',
                style: TextStyle(
                  fontSize: 13,
                  color: GoLivreurTheme.texteSecondaire,
                ),
              ),
              const SizedBox(height: 16),
              TextField(
                controller: _phoneCtrl,
                keyboardType: TextInputType.phone,
                decoration: const InputDecoration(
                  labelText: 'Numéro de téléphone',
                  hintText: '0701020304',
                  prefixText: '+225 ',
                  prefixIcon: Icon(Icons.phone_outlined),
                  border: OutlineInputBorder(),
                ),
              ),
              if (_errorMsg != null) ...[
                const SizedBox(height: 12),
                _ErrorBanner(message: _errorMsg!),
              ],
              const SizedBox(height: 20),
              BoutonPrimaire(
                label: 'Recevoir le code par SMS',
                estEnChargement: _busy,
                onPressed: _busy ? null : () => _requestCode(isResend: false),
              ),
              const SizedBox(height: 8),
              TextButton(
                onPressed: _busy ? null : () => Navigator.of(context).pop(),
                child: const Text('Annuler'),
              ),
            ] else ...[
              // ─── Indicateurs stricts : Chrono 5 min + 2 tentatives max ─────
              Row(
                children: [
                  Expanded(
                    child: Container(
                      padding: const EdgeInsets.symmetric(
                        horizontal: 10,
                        vertical: 8,
                      ),
                      decoration: BoxDecoration(
                        color: (_remainingSeconds <= 60
                                ? GoLivreurTheme.erreur
                                : GoLivreurTheme.primaire)
                            .withValues(alpha: 0.12),
                        borderRadius: BorderRadius.circular(10),
                      ),
                      child: Row(
                        mainAxisAlignment: MainAxisAlignment.center,
                        children: [
                          Icon(
                            Icons.timer_outlined,
                            size: 16,
                            color: _remainingSeconds <= 60
                                ? GoLivreurTheme.erreur
                                : GoLivreurTheme.primaire,
                          ),
                          const SizedBox(width: 6),
                          Text(
                            'Temps : ${_formatTimer(_remainingSeconds)}',
                            style: TextStyle(
                              fontWeight: FontWeight.bold,
                              fontSize: 12.5,
                              color: _remainingSeconds <= 60
                                  ? GoLivreurTheme.erreur
                                  : GoLivreurTheme.textePrincipal,
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
                  const SizedBox(width: 8),
                  Expanded(
                    child: Container(
                      padding: const EdgeInsets.symmetric(
                        horizontal: 10,
                        vertical: 8,
                      ),
                      decoration: BoxDecoration(
                        color: (_remainingAttempts <= 1
                                ? GoLivreurTheme.erreur
                                : GoLivreurTheme.secondaire)
                            .withValues(alpha: 0.12),
                        borderRadius: BorderRadius.circular(10),
                      ),
                      child: Row(
                        mainAxisAlignment: MainAxisAlignment.center,
                        children: [
                          Icon(
                            Icons.shield_outlined,
                            size: 16,
                            color: _remainingAttempts <= 1
                                ? GoLivreurTheme.erreur
                                : GoLivreurTheme.secondaire,
                          ),
                          const SizedBox(width: 6),
                          Text(
                            '$_remainingAttempts essai${_remainingAttempts > 1 ? 's' : ''} restant${_remainingAttempts > 1 ? 's' : ''}',
                            style: TextStyle(
                              fontWeight: FontWeight.bold,
                              fontSize: 12.5,
                              color: _remainingAttempts <= 1
                                  ? GoLivreurTheme.erreur
                                  : GoLivreurTheme.secondaire,
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
                ],
              ),
              if (_infoMsg != null) ...[
                const SizedBox(height: 10),
                Text(
                  _infoMsg!,
                  style: const TextStyle(
                    fontSize: 12.5,
                    color: GoLivreurTheme.secondaire,
                    fontWeight: FontWeight.w500,
                  ),
                ),
              ],
              if (_errorMsg != null) ...[
                const SizedBox(height: 10),
                _ErrorBanner(message: _errorMsg!),
              ],
              const SizedBox(height: 14),

              // Champ 1 : Code de réinitialisation (OTP reçu par SMS)
              if (_autoVerifiedIdToken == null) ...[
                TextField(
                  controller: _otpCtrl,
                  keyboardType: TextInputType.number,
                  maxLength: 6,
                  textAlign: TextAlign.center,
                  inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                  style: const TextStyle(
                    fontSize: 22,
                    fontWeight: FontWeight.bold,
                    letterSpacing: 8,
                  ),
                  decoration: const InputDecoration(
                    labelText: 'Code de réinitialisation',
                    hintText: '••••••',
                    counterText: '',
                    prefixIcon: Icon(Icons.sms_outlined),
                    border: OutlineInputBorder(),
                  ),
                ),
                const SizedBox(height: 12),
              ],

              // Champ 2 : Nouveau mot de passe
              TextField(
                controller: _newPasswordCtrl,
                obscureText: _obscureNew,
                decoration: InputDecoration(
                  labelText: 'Nouveau mot de passe',
                  hintText: 'Min. 6 caractères',
                  prefixIcon: const Icon(Icons.lock_outline),
                  border: const OutlineInputBorder(),
                  suffixIcon: IconButton(
                    icon: Icon(
                      _obscureNew
                          ? Icons.visibility_outlined
                          : Icons.visibility_off_outlined,
                    ),
                    onPressed: () => setState(() => _obscureNew = !_obscureNew),
                  ),
                ),
              ),
              const SizedBox(height: 12),

              // Champ 3 : Confirmation du nouveau mot de passe
              TextField(
                controller: _confirmPasswordCtrl,
                obscureText: _obscureConfirm,
                decoration: InputDecoration(
                  labelText: 'Confirmation du nouveau mot de passe',
                  prefixIcon: const Icon(Icons.lock_person_outlined),
                  border: const OutlineInputBorder(),
                  suffixIcon: IconButton(
                    icon: Icon(
                      _obscureConfirm
                          ? Icons.visibility_outlined
                          : Icons.visibility_off_outlined,
                    ),
                    onPressed: () =>
                        setState(() => _obscureConfirm = !_obscureConfirm),
                  ),
                ),
              ),
              const SizedBox(height: 18),

              // Boutons en bas : Réinitialiser + Annuler + Renvoyer le code
              Row(
                children: [
                  Expanded(
                    child: OutlinedButton(
                      onPressed: _busy
                          ? null
                          : () {
                              FirebasePhoneOtpService.clearSession();
                              Navigator.of(context).pop();
                            },
                      style: OutlinedButton.styleFrom(
                        padding: const EdgeInsets.symmetric(vertical: 14),
                        shape: RoundedRectangleBorder(
                          borderRadius: BorderRadius.circular(12),
                        ),
                      ),
                      child: const Text('Annuler'),
                    ),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    flex: 2,
                    child: FilledButton(
                      onPressed: _busy ? null : _submitReset,
                      style: FilledButton.styleFrom(
                        backgroundColor: GoLivreurTheme.primaire,
                        padding: const EdgeInsets.symmetric(vertical: 14),
                        shape: RoundedRectangleBorder(
                          borderRadius: BorderRadius.circular(12),
                        ),
                      ),
                      child: _busy
                          ? const SizedBox(
                              width: 20,
                              height: 20,
                              child: CircularProgressIndicator(
                                strokeWidth: 2,
                                color: Colors.white,
                              ),
                            )
                          : const Text(
                              'Réinitialiser',
                              style: TextStyle(fontWeight: FontWeight.bold),
                            ),
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 8),
              Center(
                child: TextButton(
                  onPressed: (_busy || _resendCooldown > 0)
                      ? null
                      : () => _requestCode(isResend: true),
                  child: Text(
                    _resendCooldown > 0
                        ? 'Renvoyer le code (${_resendCooldown}s)'
                        : 'Renvoyer le code',
                  ),
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }
}

class _ErrorBanner extends StatelessWidget {
  final String message;

  const _ErrorBanner({required this.message});

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(10),
      decoration: BoxDecoration(
        color: GoLivreurTheme.erreur.withValues(alpha: 0.1),
        borderRadius: BorderRadius.circular(10),
        border: Border.all(color: GoLivreurTheme.erreur.withValues(alpha: 0.5)),
      ),
      child: Row(
        children: [
          const Icon(Icons.error_outline, color: GoLivreurTheme.erreur, size: 18),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              message,
              style: const TextStyle(
                color: GoLivreurTheme.erreur,
                fontSize: 12.5,
                fontWeight: FontWeight.w500,
              ),
            ),
          ),
        ],
      ),
    );
  }
}
