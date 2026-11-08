import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:golivreur_core/config/app_theme.dart';
import 'package:golivreur_core/widgets/golivreur_widgets.dart';
import '../../navigation/screens/main_navigation_screen.dart';
import '../providers/auth_provider.dart';

class OtpVerificationScreen extends ConsumerStatefulWidget {
  final String phone;
  final bool isRegister;

  const OtpVerificationScreen({
    super.key,
    required this.phone,
    required this.isRegister,
  });

  @override
  ConsumerState<OtpVerificationScreen> createState() => _OtpVerificationScreenState();
}

class _OtpVerificationScreenState extends ConsumerState<OtpVerificationScreen> {
  final _otpController = TextEditingController();
  int _rebours = 60;
  Timer? _timer;

  @override
  void initState() {
    super.initState();
    _startTimer();
  }

  void _startTimer() {
    _rebours = 60;
    _timer?.cancel();
    _timer = Timer.periodic(const Duration(seconds: 1), (t) {
      if (_rebours > 0) {
        setState(() => _rebours--);
      } else {
        t.cancel();
      }
    });
  }

  @override
  void dispose() {
    _timer?.cancel();
    _otpController.dispose();
    super.dispose();
  }

  void _verify() async {
    final code = _otpController.text.trim();
    if (code.length != 4) return;

    final notifier = ref.read(authProvider.notifier);
    final success = widget.isRegister
        ? await notifier.verifierOtpInscription(phone: widget.phone, code: code)
        : await notifier.verifierOtpConnexion(phone: widget.phone, code: code);

    if (success && mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Connexion réussie ! Bienvenue sur GoLivreur.')),
      );
      Navigator.of(context).pushAndRemoveUntil(
        MaterialPageRoute(builder: (_) => const MainNavigationScreen()),
        (route) => false,
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    final authState = ref.watch(authProvider);

    return Scaffold(
      backgroundColor: GoLivreurTheme.fond,
      appBar: AppBar(title: const Text('Validation OTP')),
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              const SizedBox(height: 20),
              const Center(
                child: GoLivreurLogo(
                  type: GoLivreurAppType.client,
                  size: 76,
                ),
              ),
              const SizedBox(height: 12),
              Text(
                'Validation du code SMS',
                textAlign: TextAlign.center,
                style: Theme.of(context).textTheme.titleLarge?.copyWith(
                      fontWeight: FontWeight.bold,
                      color: GoLivreurTheme.textePrincipal,
                    ),
              ),
              const SizedBox(height: 6),
              Text(
                'Un code à 4 chiffres a été envoyé au +225 ${widget.phone}',
                textAlign: TextAlign.center,
                style: const TextStyle(
                  color: GoLivreurTheme.texteSecondaire,
                  fontSize: 14,
                ),
              ),
              const SizedBox(height: 24),
              if (authState.errorMessage != null) ...[
                Container(
                  padding: const EdgeInsets.all(12),
                  decoration: BoxDecoration(
                    color: GoLivreurTheme.erreur.withValues(alpha: 0.1),
                    borderRadius: BorderRadius.circular(8),
                    border: Border.all(color: GoLivreurTheme.erreur),
                  ),
                  child: Text(
                    authState.errorMessage!,
                    style: const TextStyle(color: GoLivreurTheme.erreur),
                  ),
                ),
                const SizedBox(height: 16),
              ],
              TextFormField(
                controller: _otpController,
                keyboardType: TextInputType.number,
                maxLength: 4,
                textAlign: TextAlign.center,
                style: const TextStyle(fontSize: 32, letterSpacing: 16, fontWeight: FontWeight.bold),
                decoration: const InputDecoration(
                  counterText: '',
                  hintText: '••••',
                ),
                onChanged: (val) {
                  if (val.length == 4) _verify();
                },
              ),
              const SizedBox(height: 24),
              BoutonPrimaire(
                label: 'Confirmer',
                estEnChargement: authState.status == AuthStatus.loading,
                onPressed: _verify,
              ),
              const SizedBox(height: 16),
              Center(
                child: _rebours > 0
                    ? Text('Renvoyer un code dans ${_rebours}s')
                    : TextButton(
                        onPressed: () {
                          _startTimer();
                          if (widget.isRegister) {
                            // resend register
                          } else {
                            ref.read(authProvider.notifier).demanderOtpConnexion(widget.phone);
                          }
                        },
                        child: const Text('Renvoyer un nouveau code'),
                      ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
