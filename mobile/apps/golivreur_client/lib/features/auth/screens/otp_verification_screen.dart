import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:golivreur_core/config/app_theme.dart';
import 'package:golivreur_core/widgets/golivreur_widgets.dart';
import '../../../main.dart';
import '../../gocolis/providers/gocolis_provider.dart';
import '../../goshop/providers/goshop_provider.dart';
import '../../goshop/providers/order_provider.dart';
import '../providers/auth_provider.dart';

class OtpVerificationScreen extends ConsumerStatefulWidget {
  final String phone;
  final bool isRegister;
  final String? name;
  final int? cityId;
  final String? password;

  const OtpVerificationScreen({
    super.key,
    required this.phone,
    required this.isRegister,
    this.name,
    this.cityId,
    this.password,
  });

  @override
  ConsumerState<OtpVerificationScreen> createState() =>
      _OtpVerificationScreenState();
}

class _OtpVerificationScreenState extends ConsumerState<OtpVerificationScreen> {
  final _otpController = TextEditingController();
  int _rebours = 60;
  Timer? _timer;
  StreamSubscription<({String phoneE164, String idToken})>? _autoVerifySub;
  bool _isSubmitting = false;

  @override
  void initState() {
    super.initState();
    _startTimer();
    final expectedE164 = FirebasePhoneOtpService.toE164Ivorian(widget.phone);
    _autoVerifySub = FirebasePhoneOtpService.onAutoVerified.listen((event) {
      if (!mounted || _isSubmitting) return;
      if (event.phoneE164 == expectedE164) {
        _verify(autoFirebaseIdToken: event.idToken);
      }
    });
  }

  void _startTimer() {
    _rebours = 60;
    _timer?.cancel();
    _timer = Timer.periodic(const Duration(seconds: 1), (t) {
      if (!mounted) {
        t.cancel();
        return;
      }
      if (_rebours > 0) {
        setState(() => _rebours--);
      } else {
        t.cancel();
      }
    });
  }

  @override
  void dispose() {
    _autoVerifySub?.cancel();
    _timer?.cancel();
    _otpController.dispose();
    super.dispose();
  }

  Future<void> _verify({String? autoFirebaseIdToken}) async {
    if (_isSubmitting) return;
    final code = _otpController.text.trim();
    if (autoFirebaseIdToken == null && code.length != 6) return;

    _isSubmitting = true;
    try {
      final notifier = ref.read(authProvider.notifier);
      final success = widget.isRegister
          ? await notifier.verifierOtpInscription(
              phone: widget.phone,
              code: code,
              firebaseIdToken: autoFirebaseIdToken,
              name: widget.name,
              cityId: widget.cityId,
              password: widget.password,
            )
          : await notifier.verifierOtpConnexion(
              phone: widget.phone,
              code: code,
              firebaseIdToken: autoFirebaseIdToken,
            );

      if (success && mounted) {
        ref.read(cartProvider.notifier).clear();
        ref.read(searchQueryProvider.notifier).state = '';
        ref.invalidate(clientAddressesProvider);
        ref.invalidate(favoritesProvider);
        ref.invalidate(clientNotificationsProvider);
        ref.invalidate(ordersHistoryProvider);
        ref.invalidate(parcelsHistoryProvider);
        ref.read(weatherProvider.notifier).chargerStatutVille();
        ref.read(clientAddressesProvider.notifier).chargerAdresses();
        ref.read(favoritesProvider.notifier).chargerFavoris();

        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text('Connexion réussie ! Bienvenue sur GoLivreur.'),
            backgroundColor: GoLivreurTheme.succes,
          ),
        );
        Navigator.of(context).pushAndRemoveUntil(
          MaterialPageRoute(builder: (_) => const ClientAuthGate()),
          (route) => false,
        );
      }
    } finally {
      _isSubmitting = false;
    }
  }

  @override
  Widget build(BuildContext context) {
    final authState = ref.watch(authProvider);

    return Scaffold(
      backgroundColor: GoLivreurTheme.fond,
      appBar: AppBar(title: const Text('Validation SMS OTP')),
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
                'Un code à 6 chiffres a été envoyé au +225 ${widget.phone}',
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
                maxLength: 6,
                textAlign: TextAlign.center,
                inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                style: const TextStyle(
                  fontSize: 28,
                  letterSpacing: 10,
                  fontWeight: FontWeight.bold,
                ),
                decoration: const InputDecoration(
                  counterText: '',
                  hintText: '••••••',
                ),
                onChanged: (val) {
                  if (val.length == 6) _verify();
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
                        onPressed: () async {
                          final navigator = Navigator.of(context);
                          _startTimer();
                          if (widget.isRegister) {
                            final ok = await ref
                                .read(authProvider.notifier)
                                .demanderOtpInscription(
                                  phone: widget.phone,
                                  name: widget.name ?? '',
                                  cityId: widget.cityId ?? 1,
                                  password: widget.password,
                                  isResend: true,
                                );
                            if (ok &&
                                mounted &&
                                ref.read(authProvider).status ==
                                    AuthStatus.authenticated) {
                              navigator.pushAndRemoveUntil(
                                MaterialPageRoute(
                                  builder: (_) => const ClientAuthGate(),
                                ),
                                (route) => false,
                              );
                            }
                          } else {
                            final ok = await ref
                                .read(authProvider.notifier)
                                .demanderOtpConnexion(
                                  widget.phone,
                                  isResend: true,
                                );
                            if (ok &&
                                mounted &&
                                ref.read(authProvider).status ==
                                    AuthStatus.authenticated) {
                              navigator.pushAndRemoveUntil(
                                MaterialPageRoute(
                                  builder: (_) => const ClientAuthGate(),
                                ),
                                (route) => false,
                              );
                            }
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
