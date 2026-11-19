import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../config/app_theme.dart';
import '../services/pin_lock_service.dart';
import 'golivreur_logo.dart';

/// Écran de déverrouillage rapide par code PIN à 4 chiffres.
///
/// Vérifie le PIN localement AVANT de déchiffrer le Refresh Token.
/// Au bout de 3 tentatives échouées, le Refresh Token local est effacé
/// et [onWipedForceLogin] force la reconnexion complète par mot de passe.
class PinLockScreen extends StatefulWidget {
  final String appName;
  final PinLockService pinService;
  final Future<void> Function(String decryptedRefreshToken) onUnlocked;
  final Future<void> Function() onWipedForceLogin;

  const PinLockScreen({
    super.key,
    required this.appName,
    required this.pinService,
    required this.onUnlocked,
    required this.onWipedForceLogin,
  });

  @override
  State<PinLockScreen> createState() => _PinLockScreenState();
}

class _PinLockScreenState extends State<PinLockScreen> {
  String _pin = '';
  bool _verifying = false;
  String? _errorText;
  int _remainingAttempts = PinLockService.maxFailedAttempts;

  @override
  void initState() {
    super.initState();
    _loadRemaining();
  }

  Future<void> _loadRemaining() async {
    final rem = await widget.pinService.remainingAttempts();
    if (mounted) {
      setState(() => _remainingAttempts = rem);
    }
  }

  void _onDigit(String digit) {
    if (_verifying || _pin.length >= 4) return;
    HapticFeedback.lightImpact();
    setState(() {
      _pin += digit;
      _errorText = null;
    });
    if (_pin.length == 4) {
      _verifyPin();
    }
  }

  void _onBackspace() {
    if (_verifying || _pin.isEmpty) return;
    HapticFeedback.selectionClick();
    setState(() {
      _pin = _pin.substring(0, _pin.length - 1);
    });
  }

  Future<void> _verifyPin() async {
    setState(() => _verifying = true);
    final result = await widget.pinService.verifyPinAndUnlock(_pin);
    if (!mounted) return;

    switch (result.status) {
      case PinUnlockStatus.unlocked:
        await widget.onUnlocked(result.refreshToken!);
        break;
      case PinUnlockStatus.invalidPin:
        HapticFeedback.heavyImpact();
        setState(() {
          _verifying = false;
          _pin = '';
          _remainingAttempts = result.remainingAttempts;
          _errorText =
              'Code PIN incorrect. Il vous reste ${result.remainingAttempts} essai(s) avant verrouillage.';
        });
        break;
      case PinUnlockStatus.wipedForceLogin:
        HapticFeedback.heavyImpact();
        await widget.onWipedForceLogin();
        break;
      case PinUnlockStatus.notConfigured:
        await widget.onWipedForceLogin();
        break;
    }
  }

  Future<void> _switchToPasswordLogin() async {
    await widget.pinService.wipeAllAuthAndPinData();
    await widget.onWipedForceLogin();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: const Color(0xFF1A1A2E),
      body: SafeArea(
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 28, vertical: 20),
          child: Column(
            children: [
              const Spacer(),
              const GoLivreurBrandMark(
                size: 72,
                borderRadius: 18,
              ),
              const SizedBox(height: 16),
              Text(
                widget.appName,
                style: const TextStyle(
                  color: Colors.white,
                  fontSize: 22,
                  fontWeight: FontWeight.w800,
                ),
              ),
              const SizedBox(height: 6),
              const Text(
                'Saisissez votre code PIN à 4 chiffres',
                style: TextStyle(color: Colors.white70, fontSize: 14),
              ),
              const SizedBox(height: 28),
              // 4 indicateurs PIN
              Row(
                mainAxisAlignment: MainAxisAlignment.center,
                children: List.generate(4, (i) {
                  final filled = i < _pin.length;
                  return AnimatedContainer(
                    duration: const Duration(milliseconds: 140),
                    margin: const EdgeInsets.symmetric(horizontal: 10),
                    width: 18,
                    height: 18,
                    decoration: BoxDecoration(
                      shape: BoxShape.circle,
                      color: filled
                          ? GoLivreurTheme.primaire
                          : Colors.white.withValues(alpha: 0.16),
                      border: Border.all(
                        color: filled
                            ? GoLivreurTheme.primaire
                            : Colors.white.withValues(alpha: 0.45),
                        width: 1.5,
                      ),
                    ),
                  );
                }),
              ),
              const SizedBox(height: 18),
              SizedBox(
                height: 44,
                child: _verifying
                    ? const Center(
                        child: SizedBox(
                          width: 22,
                          height: 22,
                          child: CircularProgressIndicator(
                            strokeWidth: 2.5,
                            color: GoLivreurTheme.primaire,
                          ),
                        ),
                      )
                    : _errorText != null
                        ? Text(
                            _errorText!,
                            textAlign: TextAlign.center,
                            style: const TextStyle(
                              color: Color(0xFFFF8A80),
                              fontSize: 13,
                              fontWeight: FontWeight.w600,
                            ),
                          )
                        : Text(
                            '$_remainingAttempts essai(s) autorisé(s)',
                            style: TextStyle(
                              color: Colors.white.withValues(alpha: 0.55),
                              fontSize: 12,
                            ),
                          ),
              ),
              const SizedBox(height: 12),
              _PinKeypad(
                onDigit: _onDigit,
                onBackspace: _onBackspace,
              ),
              const Spacer(),
              TextButton.icon(
                onPressed: _verifying ? null : _switchToPasswordLogin,
                icon: const Icon(Icons.lock_reset, color: Colors.white70, size: 18),
                label: const Text(
                  'Se connecter avec mon mot de passe',
                  style: TextStyle(color: Colors.white70, fontSize: 13),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _PinKeypad extends StatelessWidget {
  final ValueChanged<String> onDigit;
  final VoidCallback onBackspace;

  const _PinKeypad({required this.onDigit, required this.onBackspace});

  @override
  Widget build(BuildContext context) {
    final rows = [
      ['1', '2', '3'],
      ['4', '5', '6'],
      ['7', '8', '9'],
      ['', '0', 'back'],
    ];

    return Column(
      mainAxisSize: MainAxisSize.min,
      children: rows.map((row) {
        return Padding(
          padding: const EdgeInsets.only(bottom: 12),
          child: Row(
            mainAxisAlignment: MainAxisAlignment.spaceEvenly,
            children: row.map((key) {
              if (key.isEmpty) {
                return const SizedBox(width: 72, height: 64);
              }
              if (key == 'back') {
                return SizedBox(
                  width: 72,
                  height: 64,
                  child: IconButton(
                    onPressed: onBackspace,
                    icon: const Icon(
                      Icons.backspace_outlined,
                      color: Colors.white70,
                      size: 24,
                    ),
                  ),
                );
              }
              return SizedBox(
                width: 72,
                height: 64,
                child: Material(
                  color: Colors.white.withValues(alpha: 0.08),
                  shape: const CircleBorder(),
                  child: InkWell(
                    customBorder: const CircleBorder(),
                    onTap: () => onDigit(key),
                    child: Center(
                      child: Text(
                        key,
                        style: const TextStyle(
                          color: Colors.white,
                          fontSize: 24,
                          fontWeight: FontWeight.w600,
                        ),
                      ),
                    ),
                  ),
                ),
              );
            }).toList(),
          ),
        );
      }).toList(),
    );
  }
}

/// Feuille de configuration (activation / désactivation) du code PIN rapide 4 chiffres.
class PinSetupSheet extends StatefulWidget {
  final PinLockService pinService;

  const PinSetupSheet({super.key, required this.pinService});

  static Future<bool?> show(BuildContext context, {PinLockService? pinService}) {
    return showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
      ),
      builder: (_) => PinSetupSheet(
        pinService: pinService ?? PinLockService(),
      ),
    );
  }

  @override
  State<PinSetupSheet> createState() => _PinSetupSheetState();
}

class _PinSetupSheetState extends State<PinSetupSheet> {
  final _pinCtrl = TextEditingController();
  final _confirmCtrl = TextEditingController();
  bool _loading = true;
  bool _isEnabled = false;
  bool _submitting = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _loadStatus();
  }

  @override
  void dispose() {
    _pinCtrl.dispose();
    _confirmCtrl.dispose();
    super.dispose();
  }

  Future<void> _loadStatus() async {
    final enabled = await widget.pinService.isPinEnabled();
    if (mounted) {
      setState(() {
        _isEnabled = enabled;
        _loading = false;
      });
    }
  }

  Future<void> _enable() async {
    final pin = _pinCtrl.text.trim();
    final confirm = _confirmCtrl.text.trim();
    if (!RegExp(r'^\d{4}$').hasMatch(pin)) {
      setState(() => _error = 'Le code PIN doit contenir exactement 4 chiffres.');
      return;
    }
    if (pin != confirm) {
      setState(() => _error = 'Les deux codes PIN ne correspondent pas.');
      return;
    }

    setState(() {
      _submitting = true;
      _error = null;
    });
    try {
      await widget.pinService.enablePin(pin);
      if (mounted) {
        Navigator.of(context).pop(true);
      }
    } catch (e) {
      if (mounted) {
        setState(() {
          _submitting = false;
          _error = e.toString().replaceFirst('Bad state: ', '');
        });
      }
    }
  }

  Future<void> _disable() async {
    final pin = _pinCtrl.text.trim();
    if (!RegExp(r'^\d{4}$').hasMatch(pin)) {
      setState(() => _error = 'Saisissez votre code PIN actuel (4 chiffres).');
      return;
    }

    setState(() {
      _submitting = true;
      _error = null;
    });
    final ok = await widget.pinService.disablePin(pin);
    if (!mounted) return;
    if (ok) {
      Navigator.of(context).pop(false);
    } else {
      setState(() {
        _submitting = false;
        _error = 'Code PIN actuel incorrect.';
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final bottomInset = MediaQuery.of(context).viewInsets.bottom;
    return Padding(
      padding: EdgeInsets.fromLTRB(24, 20, 24, 24 + bottomInset),
      child: _loading
          ? const SizedBox(
              height: 160,
              child: Center(child: CircularProgressIndicator()),
            )
          : Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Row(
                  children: [
                    Container(
                      padding: const EdgeInsets.all(10),
                      decoration: BoxDecoration(
                        color: GoLivreurTheme.primaire.withValues(alpha: 0.14),
                        borderRadius: BorderRadius.circular(12),
                      ),
                      child: const Icon(
                        Icons.pin_outlined,
                        color: GoLivreurTheme.primaire,
                      ),
                    ),
                    const SizedBox(width: 12),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            _isEnabled
                                ? 'Code PIN rapide actif'
                                : 'Activer le code PIN rapide',
                            style: const TextStyle(
                              fontSize: 17,
                              fontWeight: FontWeight.w700,
                            ),
                          ),
                          Text(
                            _isEnabled
                                ? 'Votre jeton de session est chiffré localement.'
                                : 'Protège votre session (max 3 essais avant purge).',
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
                const SizedBox(height: 18),
                TextField(
                  controller: _pinCtrl,
                  keyboardType: TextInputType.number,
                  obscureText: true,
                  maxLength: 4,
                  inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                  decoration: InputDecoration(
                    labelText: _isEnabled
                        ? 'Code PIN actuel (4 chiffres)'
                        : 'Nouveau code PIN (4 chiffres)',
                    border: const OutlineInputBorder(),
                    counterText: '',
                  ),
                ),
                if (!_isEnabled) ...[
                  const SizedBox(height: 12),
                  TextField(
                    controller: _confirmCtrl,
                    keyboardType: TextInputType.number,
                    obscureText: true,
                    maxLength: 4,
                    inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                    decoration: const InputDecoration(
                      labelText: 'Confirmer le code PIN',
                      border: OutlineInputBorder(),
                      counterText: '',
                    ),
                  ),
                ],
                if (_error != null) ...[
                  const SizedBox(height: 10),
                  Text(
                    _error!,
                    style: const TextStyle(
                      color: GoLivreurTheme.erreur,
                      fontSize: 13,
                      fontWeight: FontWeight.w500,
                    ),
                  ),
                ],
                const SizedBox(height: 18),
                FilledButton(
                  onPressed: _submitting ? null : (_isEnabled ? _disable : _enable),
                  style: FilledButton.styleFrom(
                    backgroundColor:
                        _isEnabled ? GoLivreurTheme.erreur : GoLivreurTheme.primaire,
                    padding: const EdgeInsets.symmetric(vertical: 14),
                  ),
                  child: _submitting
                      ? const SizedBox(
                          width: 20,
                          height: 20,
                          child: CircularProgressIndicator(
                            strokeWidth: 2,
                            color: Colors.white,
                          ),
                        )
                      : Text(
                          _isEnabled
                              ? 'Désactiver le code PIN'
                              : 'Activer et chiffrer la session',
                        ),
                ),
              ],
            ),
    );
  }
}
