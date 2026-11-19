import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:golivreur_core/services/pin_lock_service.dart';
import 'package:golivreur_core/services/secure_token_storage.dart';
import 'package:golivreur_core/services/sso_switch_service.dart';

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
}
