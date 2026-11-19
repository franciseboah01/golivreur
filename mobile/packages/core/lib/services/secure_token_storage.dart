import 'package:flutter_secure_storage/flutter_secure_storage.dart';

/// Stockage matériel sécurisé des jetons d'authentification GoLivreur :
///  - Android : Keystore / EncryptedSharedPreferences
///  - iOS     : Keychain (accessible après premier déverrouillage sur cet appareil uniquement)
class SecureTokenStorage {
  SecureTokenStorage._();

  static const String accessTokenKey = 'access_token';
  static const String refreshTokenKey = 'refresh_token';
  static const String encryptedRefreshTokenKey = 'encrypted_refresh_token';

  static const AndroidOptions androidOptions = AndroidOptions(
    encryptedSharedPreferences: true,
  );

  static const IOSOptions iosOptions = IOSOptions(
    accessibility: KeychainAccessibility.first_unlock_this_device,
  );

  static FlutterSecureStorage createStorage() {
    return const FlutterSecureStorage(
      aOptions: androidOptions,
      iOptions: iosOptions,
    );
  }

  static Future<void> saveTokens(
    FlutterSecureStorage storage, {
    required String accessToken,
    String? refreshToken,
  }) async {
    await storage.write(key: accessTokenKey, value: accessToken);
    if (refreshToken != null && refreshToken.isNotEmpty) {
      await storage.write(key: refreshTokenKey, value: refreshToken);
    }
  }

  static Future<String?> readAccessToken(FlutterSecureStorage storage) {
    return storage.read(key: accessTokenKey);
  }

  static Future<String?> readRefreshToken(FlutterSecureStorage storage) {
    return storage.read(key: refreshTokenKey);
  }

  static Future<void> clearSessionTokens(FlutterSecureStorage storage) async {
    await storage.delete(key: accessTokenKey);
    await storage.delete(key: refreshTokenKey);
    await storage.delete(key: encryptedRefreshTokenKey);
  }
}
