import 'dart:convert';
import 'dart:math';
import 'dart:typed_data';

import 'package:crypto/crypto.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

import 'secure_token_storage.dart';

/// Résultat d'une tentative de déverrouillage par code PIN 4 chiffres.
enum PinUnlockStatus {
  unlocked,
  invalidPin,
  wipedForceLogin,
  notConfigured,
}

class PinUnlockResult {
  final PinUnlockStatus status;
  final String? refreshToken;
  final int remainingAttempts;

  const PinUnlockResult({
    required this.status,
    this.refreshToken,
    this.remainingAttempts = 0,
  });
}

/// Service de verrouillage rapide local (Code PIN à 4 chiffres).
///
/// Garanties de sécurité :
///  1. Le code PIN n'est JAMAIS stocké en clair (seul un sel aléatoire + vérif dérivée HMAC-SHA256 itérée sont persistés).
///  2. Le code PIN est vérifié localement AVANT toute tentative de déchiffrement du Refresh Token.
///  3. Le Refresh Token est chiffré (flux HMAC-SHA256 en mode compteur + MAC Encrypt-then-MAC) puis stocké
///     dans le Keystore Android (EncryptedSharedPreferences) / Keychain iOS.
///  4. Au-delà de [maxFailedAttempts] (3 tentatives échouées), le Refresh Token local et les jetons de session
///     sont définitivement supprimés et une reconnexion par mot de passe complet est imposée.
class PinLockService {
  static const int maxFailedAttempts = 3;
  static const int _kdfIterations = 4000;

  static const String _keyPinEnabled = 'gl_pin_enabled';
  static const String _keyPinSalt = 'gl_pin_salt';
  static const String _keyPinVerifier = 'gl_pin_verifier';
  static const String _keyPinFailedAttempts = 'gl_pin_failed_attempts';
  static const String _keyPinSetAt = 'gl_pin_set_at';

  // État mémoire de la session déverrouillée uniquement (jamais persisté sur disque)
  static Uint8List? _activeEncKey;
  static String? _activeRefreshToken;
  static bool _sessionUnlocked = false;

  final FlutterSecureStorage _storage;

  PinLockService({FlutterSecureStorage? storage})
      : _storage = storage ?? SecureTokenStorage.createStorage();

  /// Indique si la session courante est déverrouillée en mémoire.
  static bool get isSessionUnlocked => _sessionUnlocked;

  /// Refresh token déchiffré en mémoire uniquement après déverrouillage PIN.
  static String? get unlockedRefreshToken => _activeRefreshToken;

  /// Verrouille la session en RAM (ex: retour d'arrière-plan ou démarrage).
  static void lockInMemory() {
    if (_activeEncKey != null) {
      _activeEncKey!.fillRange(0, _activeEncKey!.length, 0);
    }
    _activeEncKey = null;
    _activeRefreshToken = null;
    _sessionUnlocked = false;
  }

  /// Met à jour le refresh token chiffré après une rotation silencieuse côté ApiClient.
  static Future<void> syncRotatedRefreshToken(
    FlutterSecureStorage storage,
    String newRefreshToken,
  ) async {
    final enabled = await storage.read(key: _keyPinEnabled);
    if (enabled == 'true' && _activeEncKey != null) {
      _activeRefreshToken = newRefreshToken;
      final encrypted = _encryptRefreshToken(newRefreshToken, _activeEncKey!);
      await storage.write(
        key: SecureTokenStorage.encryptedRefreshTokenKey,
        value: encrypted,
      );
      await storage.delete(key: SecureTokenStorage.refreshTokenKey);
    } else {
      await storage.write(
        key: SecureTokenStorage.refreshTokenKey,
        value: newRefreshToken,
      );
    }
  }

  /// Indique si le verrouillage par PIN est activé et possède un Refresh Token chiffré.
  Future<bool> isPinEnabled() async {
    final enabled = await _storage.read(key: _keyPinEnabled);
    if (enabled != 'true') return false;
    final encrypted = await _storage.read(
      key: SecureTokenStorage.encryptedRefreshTokenKey,
    );
    return encrypted != null && encrypted.isNotEmpty;
  }

  /// Indique si l'écran PIN doit bloquer l'accès au démarrage.
  Future<bool> requiresUnlock() async {
    if (_sessionUnlocked) return false;
    return isPinEnabled();
  }

  Future<int> failedAttempts() async {
    final raw = await _storage.read(key: _keyPinFailedAttempts);
    return int.tryParse(raw ?? '0') ?? 0;
  }

  Future<int> remainingAttempts() async {
    final failed = await failedAttempts();
    return max(0, maxFailedAttempts - failed);
  }

  /// Active (ou modifie) le code PIN à 4 chiffres et chiffre immédiatement le Refresh Token local.
  Future<void> enablePin(String pin, {String? refreshTokenOverride}) async {
    if (!_isValidPinFormat(pin)) {
      throw ArgumentError('Le code PIN doit contenir exactement 4 chiffres.');
    }

    final refreshToken = refreshTokenOverride ??
        _activeRefreshToken ??
        await _storage.read(key: SecureTokenStorage.refreshTokenKey);

    if (refreshToken == null || refreshToken.isEmpty) {
      throw StateError(
        'Aucun Refresh Token actif disponible. Reconnectez-vous avant d\'activer le code PIN.',
      );
    }

    final salt = _randomBytes(16);
    final keys = _deriveKeys(pin, salt);
    final encryptedRefresh = _encryptRefreshToken(refreshToken, keys.encKey);

    await _storage.write(key: _keyPinSalt, value: base64Encode(salt));
    await _storage.write(
      key: _keyPinVerifier,
      value: base64Encode(keys.verifier),
    );
    await _storage.write(
      key: SecureTokenStorage.encryptedRefreshTokenKey,
      value: encryptedRefresh,
    );
    await _storage.write(key: _keyPinEnabled, value: 'true');
    await _storage.write(key: _keyPinFailedAttempts, value: '0');
    await _storage.write(
      key: _keyPinSetAt,
      value: DateTime.now().toUtc().toIso8601String(),
    );

    // Supprime le refresh token en clair du stockage persistant : seul le chiffré reste stocké
    await _storage.delete(key: SecureTokenStorage.refreshTokenKey);

    _activeEncKey = keys.encKey;
    _activeRefreshToken = refreshToken;
    _sessionUnlocked = true;
  }

  /// Désactive le verrouillage PIN (après vérification du PIN actuel).
  Future<bool> disablePin(String currentPin) async {
    final result = await verifyPinAndUnlock(currentPin);
    if (result.status != PinUnlockStatus.unlocked || result.refreshToken == null) {
      return false;
    }

    // Restaure le refresh token dans le Keystore/Keychain avant de supprimer les métadonnées PIN
    await _storage.write(
      key: SecureTokenStorage.refreshTokenKey,
      value: result.refreshToken!,
    );
    await _clearPinMetadataOnly();
    lockInMemory();
    return true;
  }

  /// Vérifie le code PIN localement AVANT de déchiffrer le Refresh Token.
  /// Après [maxFailedAttempts] échecs, purge définitivement le Refresh Token local.
  Future<PinUnlockResult> verifyPinAndUnlock(String pin) async {
    final enabled = await isPinEnabled();
    if (!enabled) {
      return const PinUnlockResult(status: PinUnlockStatus.notConfigured);
    }

    final saltB64 = await _storage.read(key: _keyPinSalt);
    final verifB64 = await _storage.read(key: _keyPinVerifier);
    final encryptedRefresh = await _storage.read(
      key: SecureTokenStorage.encryptedRefreshTokenKey,
    );

    if (saltB64 == null || verifB64 == null || encryptedRefresh == null) {
      await wipeAllAuthAndPinData();
      return const PinUnlockResult(
        status: PinUnlockStatus.wipedForceLogin,
        remainingAttempts: 0,
      );
    }

    if (!_isValidPinFormat(pin)) {
      return _registerFailedAttempt();
    }

    final salt = base64Decode(saltB64);
    final expectedVerif = base64Decode(verifB64);
    final derived = _deriveKeys(pin, salt);

    // 1. Vérification locale en temps constant AVANT tout déchiffrement
    if (!_constantTimeEquals(derived.verifier, expectedVerif)) {
      return _registerFailedAttempt();
    }

    // 2. Déchiffrement authentifié du Refresh Token
    final decrypted = _decryptRefreshToken(encryptedRefresh, derived.encKey);
    if (decrypted == null || decrypted.isEmpty) {
      await wipeAllAuthAndPinData();
      return const PinUnlockResult(
        status: PinUnlockStatus.wipedForceLogin,
        remainingAttempts: 0,
      );
    }

    // 3. Succès : réinitialiser le compteur et exposer le Refresh Token uniquement en RAM
    await _storage.write(key: _keyPinFailedAttempts, value: '0');
    _activeEncKey = derived.encKey;
    _activeRefreshToken = decrypted;
    _sessionUnlocked = true;

    return PinUnlockResult(
      status: PinUnlockStatus.unlocked,
      refreshToken: decrypted,
      remainingAttempts: maxFailedAttempts,
    );
  }

  /// Applique une réinitialisation PIN ordonnée par le Service Client (backend).
  Future<bool> applyServerPinResetIfNeeded(String? pinResetRequiredAtIso) async {
    if (pinResetRequiredAtIso == null || pinResetRequiredAtIso.isEmpty) {
      return false;
    }
    final resetAt = DateTime.tryParse(pinResetRequiredAtIso);
    if (resetAt == null) return false;

    final pinSetAtRaw = await _storage.read(key: _keyPinSetAt);
    final pinSetAt = pinSetAtRaw != null ? DateTime.tryParse(pinSetAtRaw) : null;

    if (pinSetAt == null || resetAt.isAfter(pinSetAt)) {
      await _clearPinMetadataOnly();
      lockInMemory();
      return true;
    }
    return false;
  }

  /// Purge complète des jetons et du PIN (échec après 3 tentatives ou déconnexion).
  Future<void> wipeAllAuthAndPinData() async {
    lockInMemory();
    await SecureTokenStorage.clearSessionTokens(_storage);
    await _clearPinMetadataOnly();
  }

  Future<PinUnlockResult> _registerFailedAttempt() async {
    final nextFailed = (await failedAttempts()) + 1;
    if (nextFailed >= maxFailedAttempts) {
      await wipeAllAuthAndPinData();
      return const PinUnlockResult(
        status: PinUnlockStatus.wipedForceLogin,
        remainingAttempts: 0,
      );
    }

    await _storage.write(
      key: _keyPinFailedAttempts,
      value: nextFailed.toString(),
    );
    return PinUnlockResult(
      status: PinUnlockStatus.invalidPin,
      remainingAttempts: maxFailedAttempts - nextFailed,
    );
  }

  Future<void> _clearPinMetadataOnly() async {
    await _storage.delete(key: _keyPinEnabled);
    await _storage.delete(key: _keyPinSalt);
    await _storage.delete(key: _keyPinVerifier);
    await _storage.delete(key: _keyPinFailedAttempts);
    await _storage.delete(key: _keyPinSetAt);
    await _storage.delete(key: SecureTokenStorage.encryptedRefreshTokenKey);
  }

  static bool _isValidPinFormat(String pin) {
    return RegExp(r'^\d{4}$').hasMatch(pin);
  }

  static Uint8List _randomBytes(int length) {
    final rng = Random.secure();
    return Uint8List.fromList(
      List<int>.generate(length, (_) => rng.nextInt(256)),
    );
  }

  static ({Uint8List encKey, Uint8List verifier}) _deriveKeys(
    String pin,
    Uint8List salt,
  ) {
    final pinBytes = utf8.encode(pin);
    var state = Uint8List.fromList(
      Hmac(sha256, pinBytes).convert(<int>[...salt, 0x00, 0x00, 0x00, 0x01]).bytes,
    );
    final acc = Uint8List.fromList(state);

    for (var i = 1; i < _kdfIterations; i++) {
      state = Uint8List.fromList(Hmac(sha256, pinBytes).convert(state).bytes);
      for (var j = 0; j < acc.length; j++) {
        acc[j] ^= state[j];
      }
    }

    final encKey = Uint8List.fromList(
      Hmac(sha256, acc).convert(utf8.encode('golivreur:pin:enc:v1')).bytes,
    );
    final verifier = Uint8List.fromList(
      Hmac(sha256, acc).convert(utf8.encode('golivreur:pin:verify:v1')).bytes,
    );

    return (encKey: encKey, verifier: verifier);
  }

  static String _encryptRefreshToken(String plaintext, Uint8List encKey) {
    final iv = _randomBytes(16);
    final plainBytes = utf8.encode(plaintext);
    final keystream = _generateKeystream(encKey, iv, plainBytes.length);
    final cipher = Uint8List(plainBytes.length);
    for (var i = 0; i < plainBytes.length; i++) {
      cipher[i] = plainBytes[i] ^ keystream[i];
    }

    final macKey = Hmac(sha256, encKey).convert(utf8.encode('golivreur:pin:mac:v1')).bytes;
    final mac = Hmac(sha256, macKey).convert(<int>[...iv, ...cipher]).bytes;

    return '${base64Encode(iv)}.${base64Encode(cipher)}.${base64Encode(mac)}';
  }

  static String? _decryptRefreshToken(String payload, Uint8List encKey) {
    final parts = payload.split('.');
    if (parts.length != 3) return null;

    try {
      final iv = base64Decode(parts[0]);
      final cipher = base64Decode(parts[1]);
      final mac = base64Decode(parts[2]);

      final macKey = Hmac(sha256, encKey).convert(utf8.encode('golivreur:pin:mac:v1')).bytes;
      final expectedMac = Hmac(sha256, macKey).convert(<int>[...iv, ...cipher]).bytes;
      if (!_constantTimeEquals(Uint8List.fromList(mac), Uint8List.fromList(expectedMac))) {
        return null;
      }

      final keystream = _generateKeystream(encKey, iv, cipher.length);
      final plain = Uint8List(cipher.length);
      for (var i = 0; i < cipher.length; i++) {
        plain[i] = cipher[i] ^ keystream[i];
      }
      return utf8.decode(plain);
    } catch (_) {
      return null;
    }
  }

  static Uint8List _generateKeystream(Uint8List key, Uint8List iv, int length) {
    final out = Uint8List(length);
    var offset = 0;
    var counter = 0;
    while (offset < length) {
      final counterBytes = <int>[
        (counter >> 24) & 0xff,
        (counter >> 16) & 0xff,
        (counter >> 8) & 0xff,
        counter & 0xff,
      ];
      final block = Hmac(sha256, key).convert(<int>[...iv, ...counterBytes]).bytes;
      final take = min(block.length, length - offset);
      out.setRange(offset, offset + take, block);
      offset += take;
      counter++;
    }
    return out;
  }

  static bool _constantTimeEquals(Uint8List a, Uint8List b) {
    if (a.length != b.length) return false;
    var diff = 0;
    for (var i = 0; i < a.length; i++) {
      diff |= a[i] ^ b[i];
    }
    return diff == 0;
  }
}
