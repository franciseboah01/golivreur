import 'dart:async';
import 'package:firebase_auth/firebase_auth.dart';
import 'package:firebase_core/firebase_core.dart';
import 'package:flutter/foundation.dart';

/// Résultat d'une demande d'envoi de code SMS OTP via Firebase Phone Auth.
class FirebaseOtpSendResult {
  final bool success;
  final String? verificationId;
  final String? autoVerifiedIdToken;
  final String? errorMessage;

  const FirebaseOtpSendResult({
    required this.success,
    this.verificationId,
    this.autoVerifiedIdToken,
    this.errorMessage,
  });

  bool get isAutoVerified =>
      success &&
      autoVerifiedIdToken != null &&
      autoVerifiedIdToken!.isNotEmpty;
}

/// Résultat de la validation d'un code SMS à 6 chiffres auprès de Firebase Auth.
class FirebaseOtpVerifyResult {
  final bool success;
  final String? idToken;
  final String? errorMessage;

  const FirebaseOtpVerifyResult({
    required this.success,
    this.idToken,
    this.errorMessage,
  });
}

/// Service centralisé GoLivreur pour l'authentification par SMS OTP via Firebase Phone Auth.
///
/// Fonctionne aussi bien avec :
///  - Les vrais numéros ivoiriens (`+225XXXXXXXXXX`) — envoi d'un vrai SMS sur le téléphone de l'utilisateur
///  - Les numéros de test configurés dans la Console Firebase (gratuits, sans SMS consommé)
///  - L'auto-vérification instantanée Android (SMS Retriever / Play Integrity)
class FirebasePhoneOtpService {
  static String? _lastVerificationId;
  static String? _lastPhoneE164;
  static int? _resendToken;

  static final StreamController<({String phoneE164, String idToken})>
      _autoVerifiedController =
      StreamController<({String phoneE164, String idToken})>.broadcast();

  /// Flux déclenché si Android valide automatiquement le SMS après `codeSent`.
  static Stream<({String phoneE164, String idToken})> get onAutoVerified =>
      _autoVerifiedController.stream;

  /// Vrai uniquement lorsque Firebase est initialisé (ex: Android avec `google-services.json`).
  static bool get isFirebaseReady {
    try {
      return Firebase.apps.isNotEmpty;
    } catch (_) {
      return false;
    }
  }

  /// Convertit un numéro ivoirien (local 10 chiffres ou déjà préfixé) au format E.164 `+225XXXXXXXXXX`.
  static String toE164Ivorian(String rawPhone) {
    final cleaned = rawPhone.replaceAll(RegExp(r'[\s\.\-\(\)]+'), '').trim();
    if (cleaned.isEmpty) return '';
    if (cleaned.startsWith('+225')) {
      return cleaned;
    }
    if (cleaned.startsWith('00225')) {
      return '+225${cleaned.substring(5)}';
    }
    if (cleaned.length == 13 && cleaned.startsWith('225')) {
      return '+$cleaned';
    }
    if (cleaned.startsWith('+')) {
      return cleaned;
    }
    return '+225$cleaned';
  }

  /// Indique si une session OTP Firebase active existe pour ce numéro.
  static bool hasActiveVerificationFor(String phone) {
    final e164 = toE164Ivorian(phone);
    return _lastVerificationId != null &&
        _lastVerificationId!.isNotEmpty &&
        _lastPhoneE164 == e164;
  }

  /// Réinitialise la session OTP en mémoire.
  static void clearSession() {
    _lastVerificationId = null;
    _lastPhoneE164 = null;
    _resendToken = null;
  }

  /// Déclenche l'envoi du code SMS OTP à 6 chiffres via Firebase Phone Auth.
  static Future<FirebaseOtpSendResult> sendOtp(
    String phone, {
    bool isResend = false,
  }) async {
    if (!isFirebaseReady) {
      return const FirebaseOtpSendResult(
        success: false,
        errorMessage:
            'Firebase n\'est pas initialisé sur cet appareil. Vérifiez google-services.json.',
      );
    }

    final phoneE164 = toE164Ivorian(phone);
    if (phoneE164.length < 12) {
      return const FirebaseOtpSendResult(
        success: false,
        errorMessage: 'Numéro de téléphone invalide pour l\'envoi du SMS.',
      );
    }

    final auth = FirebaseAuth.instance;
    try {
      await auth.setLanguageCode('fr');
    } catch (_) {}

    final completer = Completer<FirebaseOtpSendResult>();

    try {
      await auth.verifyPhoneNumber(
        phoneNumber: phoneE164,
        timeout: const Duration(seconds: 60),
        forceResendingToken:
            (isResend && _lastPhoneE164 == phoneE164) ? _resendToken : null,
        verificationCompleted: (PhoneAuthCredential credential) async {
          final idToken = await _signInAndExtractIdToken(auth, credential);
          if (idToken != null && idToken.isNotEmpty) {
            if (!completer.isCompleted) {
              completer.complete(
                FirebaseOtpSendResult(
                  success: true,
                  autoVerifiedIdToken: idToken,
                ),
              );
            } else {
              _autoVerifiedController.add(
                (phoneE164: phoneE164, idToken: idToken),
              );
            }
          }
        },
        verificationFailed: (FirebaseAuthException e) {
          debugPrint(
            '[FirebasePhoneOtpService] verificationFailed (${e.code}): ${e.message}',
          );
          if (!completer.isCompleted) {
            completer.complete(
              FirebaseOtpSendResult(
                success: false,
                errorMessage: mapFirebaseError(e),
              ),
            );
          }
        },
        codeSent: (String verificationId, int? resendToken) {
          _lastVerificationId = verificationId;
          _lastPhoneE164 = phoneE164;
          _resendToken = resendToken;
          if (!completer.isCompleted) {
            completer.complete(
              FirebaseOtpSendResult(
                success: true,
                verificationId: verificationId,
              ),
            );
          }
        },
        codeAutoRetrievalTimeout: (String verificationId) {
          _lastVerificationId = verificationId;
          _lastPhoneE164 = phoneE164;
        },
      );
    } on FirebaseAuthException catch (e) {
      return FirebaseOtpSendResult(
        success: false,
        errorMessage: mapFirebaseError(e),
      );
    } catch (e) {
      debugPrint('[FirebasePhoneOtpService] sendOtp error: $e');
      return const FirebaseOtpSendResult(
        success: false,
        errorMessage:
            'Impossible de déclencher l\'envoi du SMS. Vérifiez votre connexion Internet.',
      );
    }

    return completer.future.timeout(
      const Duration(seconds: 65),
      onTimeout: () => const FirebaseOtpSendResult(
        success: false,
        errorMessage:
            'Délai d\'envoi du SMS dépassé. Vérifiez votre réseau et réessayez.',
      ),
    );
  }

  /// Valide le code SMS à 6 chiffres saisi par l'utilisateur et retourne le Firebase ID Token
  /// vérifiable côté serveur Laravel (`POST /auth/verify-register`, `/auth/verify-login`, `/client/profile/phone/confirm`).
  static Future<FirebaseOtpVerifyResult> verifySmsCode(
    String smsCode, {
    String? verificationId,
  }) async {
    if (!isFirebaseReady) {
      return const FirebaseOtpVerifyResult(
        success: false,
        errorMessage: 'Firebase n\'est pas initialisé sur cet appareil.',
      );
    }

    final cleanCode = smsCode.trim();
    if (!RegExp(r'^\d{6}$').hasMatch(cleanCode)) {
      return const FirebaseOtpVerifyResult(
        success: false,
        errorMessage: 'Veuillez saisir le code SMS à 6 chiffres.',
      );
    }

    final effectiveVerificationId = verificationId ?? _lastVerificationId;
    if (effectiveVerificationId == null || effectiveVerificationId.isEmpty) {
      return const FirebaseOtpVerifyResult(
        success: false,
        errorMessage: 'Session SMS expirée. Veuillez redemander un nouveau code.',
      );
    }

    final auth = FirebaseAuth.instance;
    try {
      final credential = PhoneAuthProvider.credential(
        verificationId: effectiveVerificationId,
        smsCode: cleanCode,
      );
      final idToken = await _signInAndExtractIdToken(auth, credential);
      if (idToken == null || idToken.isEmpty) {
        return const FirebaseOtpVerifyResult(
          success: false,
          errorMessage:
              'Impossible de récupérer le jeton de vérification Firebase.',
        );
      }
      clearSession();
      return FirebaseOtpVerifyResult(success: true, idToken: idToken);
    } on FirebaseAuthException catch (e) {
      return FirebaseOtpVerifyResult(
        success: false,
        errorMessage: mapFirebaseError(e),
      );
    } catch (e) {
      debugPrint('[FirebasePhoneOtpService] verifySmsCode error: $e');
      return const FirebaseOtpVerifyResult(
        success: false,
        errorMessage: 'Code SMS invalide ou expiré.',
      );
    }
  }

  static Future<String?> _signInAndExtractIdToken(
    FirebaseAuth auth,
    PhoneAuthCredential credential,
  ) async {
    final userCredential = await auth.signInWithCredential(credential);
    final idToken = await userCredential.user?.getIdToken(true);
    // GoLivreur utilise son propre JWT Laravel : on ferme la session Firebase Auth locale.
    try {
      await auth.signOut();
    } catch (_) {}
    return idToken;
  }

  /// Traduit les codes d'erreur Firebase Phone Auth en messages clairs en français.
  static String mapFirebaseError(FirebaseAuthException e) {
    switch (e.code) {
      case 'invalid-phone-number':
        return 'Numéro de téléphone invalide. Vérifiez le format ivoirien à 10 chiffres.';
      case 'invalid-verification-code':
        return 'Code SMS incorrect. Vérifiez les 6 chiffres reçus.';
      case 'session-expired':
      case 'invalid-verification-id':
        return 'Le code SMS a expiré. Demandez un nouveau code.';
      case 'too-many-requests':
        return 'Trop de demandes de SMS sur cet appareil. Patientez quelques minutes avant de réessayer.';
      case 'quota-exceeded':
        return 'Quota SMS Firebase temporairement atteint. Utilisez un numéro de test ou réessayez plus tard.';
      case 'operation-not-allowed':
        return 'L\'authentification par téléphone n\'est pas encore activée dans la console Firebase.';
      case 'app-not-authorized':
      case 'missing-client-identifier':
      case 'invalid-app-credential':
        return 'Application Android non autorisée sur Firebase (vérifiez les empreintes SHA-1/SHA-256 dans la console Firebase).';
      case 'captcha-check-failed':
        return 'Vérification de sécurité reCAPTCHA annulée ou échouée. Réessayez.';
      case 'network-request-failed':
        return 'Connexion Internet indisponible pour envoyer le code SMS.';
      default:
        return e.message ?? 'Échec de vérification SMS (${e.code}).';
    }
  }
}
