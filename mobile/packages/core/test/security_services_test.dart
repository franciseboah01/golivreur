import 'package:firebase_auth/firebase_auth.dart';
import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:golivreur_core/services/firebase_phone_otp_service.dart';
import 'package:golivreur_core/services/gps_location_service.dart';
import 'package:golivreur_core/services/pin_lock_service.dart';
import 'package:golivreur_core/services/push_notification_service.dart';
import 'package:golivreur_core/services/secure_token_storage.dart';
import 'package:golivreur_core/services/sso_switch_service.dart';
import 'package:golivreur_core/widgets/in_app_push_banner.dart';

class _InMemorySecureStorage extends Fake implements FlutterSecureStorage {
  final Map<String, String> data = {};

  @override
  Future<String?> read({
    required String key,
    IOSOptions? iOptions,
    AndroidOptions? aOptions,
    LinuxOptions? lOptions,
    WebOptions? webOptions,
    MacOsOptions? mOptions,
    WindowsOptions? wOptions,
  }) async =>
      data[key];

  @override
  Future<void> write({
    required String key,
    required String? value,
    IOSOptions? iOptions,
    AndroidOptions? aOptions,
    LinuxOptions? lOptions,
    WebOptions? webOptions,
    MacOsOptions? mOptions,
    WindowsOptions? wOptions,
  }) async {
    if (value == null) {
      data.remove(key);
    } else {
      data[key] = value;
    }
  }

  @override
  Future<void> delete({
    required String key,
    IOSOptions? iOptions,
    AndroidOptions? aOptions,
    LinuxOptions? lOptions,
    WebOptions? webOptions,
    MacOsOptions? mOptions,
    WindowsOptions? wOptions,
  }) async {
    data.remove(key);
  }

  @override
  Future<void> deleteAll({
    IOSOptions? iOptions,
    AndroidOptions? aOptions,
    LinuxOptions? lOptions,
    WebOptions? webOptions,
    MacOsOptions? mOptions,
    WindowsOptions? wOptions,
  }) async {
    data.clear();
  }
}

void main() {
  tearDown(PinLockService.lockInMemory);

  group('PinLockService', () {
    test('chiffre le refresh token et supprime la version en clair du stockage', () async {
      final storage = _InMemorySecureStorage();
      await storage.write(
        key: SecureTokenStorage.refreshTokenKey,
        value: 'super-secret-refresh-token-123456',
      );
      final service = PinLockService(storage: storage);

      await service.enablePin('2468');

      // Le refresh token en clair ne doit plus exister dans le stockage persistant
      expect(storage.data[SecureTokenStorage.refreshTokenKey], isNull);
      expect(storage.data[SecureTokenStorage.encryptedRefreshTokenKey], isNotNull);
      expect(
        storage.data[SecureTokenStorage.encryptedRefreshTokenKey],
        isNot(contains('super-secret-refresh-token-123456')),
      );
      // Le PIN n'est jamais stocké en clair
      expect(storage.data.values.any((v) => v == '2468'), isFalse);

      // Verrouillage simulé (redémarrage app) puis déverrouillage avec le bon PIN
      PinLockService.lockInMemory();
      expect(await service.requiresUnlock(), isTrue);

      final ok = await service.verifyPinAndUnlock('2468');
      expect(ok.status, PinUnlockStatus.unlocked);
      expect(ok.refreshToken, 'super-secret-refresh-token-123456');
    });

    test('purge le refresh token local apres 3 tentatives PIN echouees', () async {
      final storage = _InMemorySecureStorage();
      await storage.write(
        key: SecureTokenStorage.accessTokenKey,
        value: 'jwt-access-token',
      );
      await storage.write(
        key: SecureTokenStorage.refreshTokenKey,
        value: 'refresh-to-protect',
      );
      final service = PinLockService(storage: storage);
      await service.enablePin('1357');
      PinLockService.lockInMemory();

      final fail1 = await service.verifyPinAndUnlock('0000');
      expect(fail1.status, PinUnlockStatus.invalidPin);
      expect(fail1.remainingAttempts, 2);

      final fail2 = await service.verifyPinAndUnlock('9999');
      expect(fail2.status, PinUnlockStatus.invalidPin);
      expect(fail2.remainingAttempts, 1);

      final fail3 = await service.verifyPinAndUnlock('4321');
      expect(fail3.status, PinUnlockStatus.wipedForceLogin);
      expect(fail3.remainingAttempts, 0);

      // Tous les jetons et le PIN doivent être effacés
      expect(storage.data[SecureTokenStorage.accessTokenKey], isNull);
      expect(storage.data[SecureTokenStorage.refreshTokenKey], isNull);
      expect(storage.data[SecureTokenStorage.encryptedRefreshTokenKey], isNull);
      expect(await service.isPinEnabled(), isFalse);
    });
  });

  group('SsoSwitchService PKCE S256', () {
    test('genere un code_verifier URL-safe et un code_challenge RFC 7636 conforme', () {
      final verifier = SsoSwitchService.generateCodeVerifier();
      expect(verifier.length, greaterThanOrEqualTo(43));
      expect(verifier.contains('='), isFalse);

      // Vecteur de test officiel RFC 7636 Appendice B
      const rfcVerifier = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
      const rfcExpectedChallenge = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';
      expect(
        SsoSwitchService.computeCodeChallenge(rfcVerifier),
        rfcExpectedChallenge,
      );
    });
  });

  group('GpsLocationService Haversine', () {
    test('calcule la distance reelle entre deux points GPS en Cote d Ivoire', () {
      // Abidjan Plateau (5.3204, -4.0161) -> Yamoussoukro (6.8276, -5.2893) ~ 218 km
      final km = GpsLocationService.distanceKm(5.3204, -4.0161, 6.8276, -5.2893);
      expect(km, greaterThan(200));
      expect(km, lessThan(235));
      expect(GpsLocationService.distanceKm(6.8770, -6.4502, 6.8770, -6.4502), closeTo(0, 0.001));
    });
  });

  group('InAppPushOverlay (Push In-App)', () {
    testWidgets('affiche la banniere flottante a la reception dun Push In-App et se ferme proprement', (tester) async {
      InAppPushMessage? received;
      InAppPushMessage? tapped;

      await tester.pumpWidget(
        MaterialApp(
          home: InAppPushOverlay(
            onMessageReceived: (m) => received = m,
            onMessageTapped: (m) => tapped = m,
            child: const Scaffold(body: Center(child: Text('Contenu App'))),
          ),
        ),
      );

      expect(find.text('Contenu App'), findsOneWidget);

      PushNotificationService.emitInAppMessage(
        InAppPushMessage(
          title: 'Dépôt espèces crédité (+10 000 F CFA)',
          body: 'Votre portefeuille Commerce a été crédité.',
          type: 'wallet_cash_deposit',
          channelId: 'general',
          data: const {'type': 'wallet_cash_deposit'},
          receivedAt: DateTime.now(),
        ),
      );

      await tester.pump();

      expect(received?.type, 'wallet_cash_deposit');
      expect(find.text('Dépôt espèces crédité (+10 000 F CFA)'), findsOneWidget);
      expect(find.text('Votre portefeuille Commerce a été crédité.'), findsOneWidget);

      await tester.tap(find.text('Dépôt espèces crédité (+10 000 F CFA)'));
      await tester.pump();

      expect(tapped?.type, 'wallet_cash_deposit');
      expect(find.text('Dépôt espèces crédité (+10 000 F CFA)'), findsNothing);
    });
  });

  group('FirebasePhoneOtpService', () {
    test('normalise les numeros ivoiriens au format E.164 (+225XXXXXXXXXX)', () {
      expect(FirebasePhoneOtpService.toE164Ivorian('0700000001'), '+2250700000001');
      expect(FirebasePhoneOtpService.toE164Ivorian('07 00 00 00 01'), '+2250700000001');
      expect(FirebasePhoneOtpService.toE164Ivorian('+225 05 00 00 00 02'), '+2250500000002');
      expect(FirebasePhoneOtpService.toE164Ivorian('00225 0100000003'), '+2250100000003');
      expect(FirebasePhoneOtpService.toE164Ivorian('2250701020304'), '+2250701020304');
      expect(FirebasePhoneOtpService.toE164Ivorian(''), '');
    });

    test('traduit proprement les erreurs Firebase Phone Auth en francais', () {
      expect(
        FirebasePhoneOtpService.mapFirebaseError(
          FirebaseAuthException(code: 'invalid-verification-code'),
        ),
        contains('Code SMS incorrect'),
      );
      expect(
        FirebasePhoneOtpService.mapFirebaseError(
          FirebaseAuthException(code: 'app-not-authorized'),
        ),
        contains('SHA-1'),
      );
      expect(
        FirebasePhoneOtpService.mapFirebaseError(
          FirebaseAuthException(code: 'quota-exceeded'),
        ),
        contains('Quota SMS'),
      );
    });
  });
}
