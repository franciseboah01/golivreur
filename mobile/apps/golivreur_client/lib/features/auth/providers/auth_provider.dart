import 'dart:convert';
import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:golivreur_core/models/modeles.dart';
import 'package:golivreur_core/services/api_client.dart';
import 'package:golivreur_core/services/secure_token_storage.dart';
import 'package:golivreur_core/services/sso_switch_service.dart';
import 'package:golivreur_core/widgets/role_missing_dialog.dart';
import 'package:image_picker/image_picker.dart';
import '../../../config/env.dart';

void Function()? _forcedLogoutHandler;

final Provider<FlutterSecureStorage> secureStorageProvider = Provider<FlutterSecureStorage>((ref) {
  return SecureTokenStorage.createStorage();
});

final Provider<ApiClient> apiClientProvider = Provider<ApiClient>((ref) {
  final storage = ref.watch(secureStorageProvider);
  return ApiClient(
    baseUrl: Env.apiBaseUrl,
    storage: storage,
    onDeconnexionForcee: () => _forcedLogoutHandler?.call(),
  );
});

enum AuthStatus {
  initial,
  restoringSession,
  loading,
  otpSent,
  authenticated,
  error,
}

class AuthState {
  final AuthStatus status;
  final UserModel? user;
  final String? phoneNumber;
  final String? pendingName;
  final int? pendingCityId;
  final String? pendingPassword;
  final String? errorMessage;
  final RoleMissingInfo? roleMissingInfo;
  final String? lastTemporaryPassword;

  const AuthState({
    this.status = AuthStatus.initial,
    this.user,
    this.phoneNumber,
    this.pendingName,
    this.pendingCityId,
    this.pendingPassword,
    this.errorMessage,
    this.roleMissingInfo,
    this.lastTemporaryPassword,
  });

  AuthState copyWith({
    AuthStatus? status,
    UserModel? user,
    bool clearUser = false,
    String? phoneNumber,
    String? pendingName,
    int? pendingCityId,
    String? pendingPassword,
    bool clearPendingRegistration = false,
    String? errorMessage,
    RoleMissingInfo? roleMissingInfo,
    bool clearRoleMissing = true,
    String? lastTemporaryPassword,
    bool clearTempPassword = false,
  }) {
    return AuthState(
      status: status ?? this.status,
      user: clearUser ? null : (user ?? this.user),
      phoneNumber: clearUser ? null : (phoneNumber ?? this.phoneNumber),
      pendingName: clearPendingRegistration ? null : (pendingName ?? this.pendingName),
      pendingCityId: clearPendingRegistration ? null : (pendingCityId ?? this.pendingCityId),
      pendingPassword: clearPendingRegistration ? null : (pendingPassword ?? this.pendingPassword),
      errorMessage: errorMessage,
      roleMissingInfo: roleMissingInfo ?? (clearRoleMissing ? null : this.roleMissingInfo),
      lastTemporaryPassword: clearTempPassword
          ? null
          : (lastTemporaryPassword ?? this.lastTemporaryPassword),
    );
  }
}

class AuthNotifier extends StateNotifier<AuthState> {
  static const String cachedUserKey = 'cached_client_user_json';

  final ApiClient _apiClient;
  final FlutterSecureStorage _storage;
  bool _bootstrapped = false;

  AuthNotifier(this._apiClient, this._storage) : super(const AuthState()) {
    _forcedLogoutHandler = forcerDeconnexionLocale;
  }

  Future<void> _cacheUser(UserModel user) async {
    try {
      await _storage.write(
        key: cachedUserKey,
        value: jsonEncode(user.toJson()),
      );
    } catch (_) {}
  }

  /// Hydrate instantanément la session locale au démarrage (exécuté dès le Splash Screen)
  /// afin d'éviter tout flash ou latence de 2-3s sur l'écran de connexion.
  Future<void> initialiserSessionAuDemarrage() async {
    if (_bootstrapped || state.status == AuthStatus.authenticated) return;
    _bootstrapped = true;

    final accessToken = await SecureTokenStorage.readAccessToken(_storage);
    final refreshToken = await SecureTokenStorage.readRefreshToken(_storage);
    final hasSessionToken =
        (accessToken != null && accessToken.isNotEmpty) ||
            (refreshToken != null && refreshToken.isNotEmpty);

    if (!hasSessionToken) {
      if (state.status == AuthStatus.restoringSession) {
        state = const AuthState(status: AuthStatus.initial);
      }
      return;
    }

    // 1. Hydratation instantanée (<5 ms) depuis le profil mis en cache localement
    final rawCachedUser = await _storage.read(key: cachedUserKey);
    if (rawCachedUser != null && rawCachedUser.isNotEmpty) {
      try {
        final decoded = jsonDecode(rawCachedUser);
        if (decoded is Map<String, dynamic>) {
          final cachedUser = UserModel.fromJson(decoded);
          state = state.copyWith(
            status: AuthStatus.authenticated,
            user: cachedUser,
            phoneNumber: cachedUser.phone,
            errorMessage: null,
          );
          // Synchronisation silencieuse en arrière-plan (ne bloque jamais l'accès à Accueil)
          chargerProfil();
          return;
        }
      } catch (_) {}
    }

    // 2. Token existant mais pas encore de profil en cache : restaurer sans afficher LoginScreen
    state = state.copyWith(
      status: AuthStatus.restoringSession,
      errorMessage: null,
    );
    final restored = await restaurerSessionLocale();
    if (!restored && state.status == AuthStatus.restoringSession) {
      state = const AuthState(status: AuthStatus.initial);
    }
  }

  static String normalizePhone(String raw) {
    var cleaned = raw.replaceAll(RegExp(r'[\s\.\-\(\)]+'), '').trim();
    if (cleaned.startsWith('+225')) {
      cleaned = cleaned.substring(4);
    } else if (cleaned.startsWith('00225')) {
      cleaned = cleaned.substring(5);
    } else if (cleaned.length == 13 && cleaned.startsWith('225')) {
      cleaned = cleaned.substring(3);
    }
    return cleaned;
  }

  void clearError() {
    if (state.errorMessage != null || state.status == AuthStatus.error) {
      state = state.copyWith(
        status: state.user != null ? AuthStatus.authenticated : AuthStatus.initial,
        errorMessage: null,
      );
    }
  }

  Future<bool> demanderOtpInscription({
    required String phone,
    required String name,
    required int cityId,
    String? password,
  }) async {
    final cleanPhone = normalizePhone(phone);
    final cleanName = name.trim();
    final cleanPassword = password?.trim();
    state = state.copyWith(
      status: AuthStatus.loading,
      phoneNumber: cleanPhone,
      pendingName: cleanName,
      pendingCityId: cityId,
      pendingPassword: cleanPassword,
      errorMessage: null,
    );
    try {
      final res = await _apiClient.post('/auth/register', data: {
        'phone': cleanPhone,
        'name': cleanName,
        'city_id': cityId,
        if (cleanPassword != null && cleanPassword.isNotEmpty)
          'password': cleanPassword,
        'role': 'client',
      });

      if (res.statusCode == 200) {
        state = state.copyWith(
          status: AuthStatus.otpSent,
          phoneNumber: cleanPhone,
          pendingName: cleanName,
          pendingCityId: cityId,
          pendingPassword: cleanPassword,
          errorMessage: null,
        );
        return true;
      }
      state = state.copyWith(
        status: AuthStatus.error,
        errorMessage: 'Impossible d\'envoyer le code SMS. Vérifiez le numéro.',
      );
    } on DioException catch (e) {
      state = state.copyWith(
        status: AuthStatus.error,
        errorMessage: ApiClient.messageErreur(e),
      );
    } catch (_) {
      state = state.copyWith(
        status: AuthStatus.error,
        errorMessage: 'Impossible d\'envoyer le code SMS. Vérifiez le numéro.',
      );
    }
    return false;
  }

  Future<bool> verifierOtpInscription({
    required String phone,
    required String code,
    String? name,
    int? cityId,
    String? password,
  }) async {
    final cleanPhone = normalizePhone(phone);
    final effectiveName = (name ?? state.pendingName ?? '').trim();
    final effectiveCityId = cityId ?? state.pendingCityId;
    final effectivePassword = (password ?? state.pendingPassword ?? '').trim();

    state = state.copyWith(status: AuthStatus.loading, errorMessage: null);
    try {
      final res = await _apiClient.post('/auth/verify-register', data: {
        'phone': cleanPhone,
        'code': code.trim(),
        'otp': code.trim(),
        if (effectiveName.isNotEmpty) 'name': effectiveName,
        if (effectiveCityId != null) 'city_id': effectiveCityId,
        if (effectivePassword.isNotEmpty) 'password': effectivePassword,
      });

      if (res.statusCode == 200 || res.statusCode == 201) {
        final data = res.data['donnees'] as Map<String, dynamic>;
        final accessToken = data['access_token'] as String?;
        final refreshToken = data['refresh_token'] as String?;
        final userJson = data['user'] as Map<String, dynamic>?;

        if (accessToken == null || accessToken.isEmpty || userJson == null) {
          state = state.copyWith(
            status: AuthStatus.error,
            errorMessage: 'Réponse d\'inscription incomplète du serveur.',
          );
          return false;
        }

        await _storage.deleteAll();
        await _storage.write(key: 'access_token', value: accessToken);
        if (refreshToken != null) {
          await _storage.write(key: 'refresh_token', value: refreshToken);
        }

        var user = UserModel.fromJson(userJson);
        if (user.name.trim().isEmpty && effectiveName.isNotEmpty) {
          user = user.copyWith(name: effectiveName);
        }
        await _cacheUser(user);
        state = state.copyWith(
          status: AuthStatus.authenticated,
          user: user,
          phoneNumber: user.phone,
          clearPendingRegistration: true,
          errorMessage: null,
        );
        await chargerProfil();
        return true;
      }
      state = state.copyWith(
        status: AuthStatus.error,
        errorMessage: 'Code OTP invalide ou expiré.',
      );
    } on DioException catch (e) {
      state = state.copyWith(
        status: AuthStatus.error,
        errorMessage: ApiClient.messageErreur(e),
      );
    } catch (_) {
      state = state.copyWith(
        status: AuthStatus.error,
        errorMessage: 'Code OTP invalide ou expiré.',
      );
    }
    return false;
  }

  Future<bool> connexionParMotDePasse(String phone, String password) async {
    final cleanPhone = normalizePhone(phone);
    final trimmedPassword = password.trim();
    state = state.copyWith(
      status: AuthStatus.loading,
      clearPendingRegistration: true,
      errorMessage: null,
      clearRoleMissing: true,
    );
    try {
      final res = await _apiClient.post('/auth/login-password', data: {
        'phone': cleanPhone,
        'password': trimmedPassword,
        'app_type': 'client',
      });

      if (res.statusCode == 200 && res.data['succes'] == true) {
        final data = res.data['donnees'] as Map<String, dynamic>;
        final accessToken = data['access_token'] as String?;
        final refreshToken = data['refresh_token'] as String?;
        final userJson = data['user'] as Map<String, dynamic>?;

        if (accessToken == null || accessToken.isEmpty || userJson == null) {
          state = state.copyWith(
            status: AuthStatus.error,
            errorMessage: 'Réponse de connexion incomplète du serveur.',
          );
          return false;
        }

        await SecureTokenStorage.clearSessionTokens(_storage);
        await SecureTokenStorage.saveTokens(
          _storage,
          accessToken: accessToken,
          refreshToken: refreshToken,
        );

        final user = UserModel.fromJson(userJson);
        await _cacheUser(user);
        state = state.copyWith(
          status: AuthStatus.authenticated,
          user: user,
          phoneNumber: user.phone,
          errorMessage: null,
          lastTemporaryPassword: user.mustChangePassword ? trimmedPassword : null,
          clearTempPassword: !user.mustChangePassword,
        );
        await chargerProfil();
        return true;
      }

      state = state.copyWith(
        status: AuthStatus.error,
        errorMessage: 'Identifiants incorrects.',
      );
    } on DioException catch (e) {
      final roleMissing = RoleMissingInfo.tryParse(e);
      if (roleMissing != null) {
        state = state.copyWith(
          status: AuthStatus.error,
          errorMessage: roleMissing.message,
          roleMissingInfo: roleMissing,
          clearRoleMissing: false,
        );
        return false;
      }

      final msg = e.response?.statusCode == 404
          ? 'Aucun compte trouvé avec ce numéro. Veuillez vous inscrire.'
          : ApiClient.messageErreur(e);
      state = state.copyWith(
        status: AuthStatus.error,
        errorMessage: msg,
      );
    } catch (_) {
      state = state.copyWith(
        status: AuthStatus.error,
        errorMessage: 'Impossible de contacter le serveur.',
      );
    }
    return false;
  }

  Future<bool> demanderOtpConnexion(String phone) async {
    final cleanPhone = normalizePhone(phone);
    state = state.copyWith(
      status: AuthStatus.loading,
      clearPendingRegistration: true,
      errorMessage: null,
    );
    try {
      final res = await _apiClient.post('/auth/login', data: {
        'phone': cleanPhone,
      });

      if (res.statusCode == 200) {
        state = state.copyWith(
          status: AuthStatus.otpSent,
          phoneNumber: cleanPhone,
          errorMessage: null,
        );
        return true;
      }
      state = state.copyWith(
        status: AuthStatus.error,
        errorMessage: 'Numéro inconnu ou compte désactivé.',
      );
    } on DioException catch (e) {
      final msg = e.response?.statusCode == 404
          ? 'Aucun compte trouvé avec ce numéro. Veuillez vous inscrire.'
          : ApiClient.messageErreur(e);
      state = state.copyWith(
        status: AuthStatus.error,
        errorMessage: msg,
      );
    } catch (_) {
      state = state.copyWith(
        status: AuthStatus.error,
        errorMessage: 'Impossible de contacter le serveur.',
      );
    }
    return false;
  }

  Future<bool> verifierOtpConnexion({
    required String phone,
    required String code,
  }) async {
    final cleanPhone = normalizePhone(phone);
    state = state.copyWith(status: AuthStatus.loading, errorMessage: null);
    try {
      final res = await _apiClient.post('/auth/verify-login', data: {
        'phone': cleanPhone,
        'code': code.trim(),
        'otp': code.trim(),
      });

      if (res.statusCode == 200) {
        final data = res.data['donnees'] as Map<String, dynamic>;
        final accessToken = data['access_token'] as String?;
        final refreshToken = data['refresh_token'] as String?;
        final userJson = data['user'] as Map<String, dynamic>?;

        if (accessToken == null || accessToken.isEmpty || userJson == null) {
          state = state.copyWith(
            status: AuthStatus.error,
            errorMessage: 'Réponse de connexion incomplète du serveur.',
          );
          return false;
        }

        await _storage.deleteAll();
        await _storage.write(key: 'access_token', value: accessToken);
        if (refreshToken != null) {
          await _storage.write(key: 'refresh_token', value: refreshToken);
        }

        final user = UserModel.fromJson(userJson);
        await _cacheUser(user);
        state = state.copyWith(
          status: AuthStatus.authenticated,
          user: user,
          phoneNumber: user.phone,
          clearPendingRegistration: true,
          errorMessage: null,
        );
        await chargerProfil();
        return true;
      }
      state = state.copyWith(
        status: AuthStatus.error,
        errorMessage: 'Code OTP incorrect ou expiré.',
      );
    } on DioException catch (e) {
      state = state.copyWith(
        status: AuthStatus.error,
        errorMessage: ApiClient.messageErreur(e),
      );
    } catch (_) {
      state = state.copyWith(
        status: AuthStatus.error,
        errorMessage: 'Code OTP incorrect ou expiré.',
      );
    }
    return false;
  }

  Future<String?> chargerProfil() async {
    try {
      final res = await _apiClient.get('/client/profile');
      if (res.statusCode == 200 && res.data['succes'] == true && res.data['donnees'] != null) {
        final user = UserModel.fromJson(res.data['donnees'] as Map<String, dynamic>);
        await _cacheUser(user);
        state = state.copyWith(
          status: AuthStatus.authenticated,
          user: user,
          phoneNumber: user.phone,
          errorMessage: null,
          clearTempPassword: !user.mustChangePassword,
        );
        return null;
      }
      return 'Impossible de charger votre profil.';
    } on DioException catch (e) {
      return ApiClient.messageErreur(e);
    } catch (_) {
      return 'Impossible de charger votre profil.';
    }
  }

  /// Restaure la session client si un jeton actif est déjà présent.
  Future<bool> restaurerSessionLocale() async {
    final accessToken = await SecureTokenStorage.readAccessToken(_storage);
    final refreshToken = await SecureTokenStorage.readRefreshToken(_storage);
    if ((accessToken == null || accessToken.isEmpty) &&
        (refreshToken == null || refreshToken.isEmpty)) {
      return false;
    }

    final err = await chargerProfil();
    return err == null && state.status == AuthStatus.authenticated;
  }

  /// Échange un Deep Link SSO PKCE (`golivreur-client://sso?code=...&verifier=...`).
  Future<bool> finaliserBasculeSso(Uri uri) async {
    try {
      final result = await SsoSwitchService.exchangeIncomingDeepLink(
        apiClient: _apiClient,
        storage: _storage,
        uri: uri,
        currentApp: GoLivreurAppTarget.client,
      );
      if (result == null) return false;
      final user = UserModel.fromJson(result.userJson);
      await _cacheUser(user);
      state = state.copyWith(
        status: AuthStatus.authenticated,
        user: user,
        phoneNumber: user.phone,
        errorMessage: null,
      );
      await chargerProfil();
      return true;
    } catch (_) {
      return false;
    }
  }

  Future<void> marquerMotDePasseChange() async {
    final current = state.user;
    if (current != null) {
      state = state.copyWith(
        user: current.copyWith(mustChangePassword: false),
        clearTempPassword: true,
      );
    }
    await chargerProfil();
  }

  Future<(bool, String?)> mettreAJourProfil({
    String? defaultAddress,
    String language = 'fr',
    bool notificationsEnabled = true,
    XFile? photo,
  }) async {
    try {
      final res = await _apiClient.uploadMultipart(
        '/client/profile',
        fields: {
          'default_address': defaultAddress?.trim() ?? '',
          'language': language,
          'notifications_enabled': notificationsEnabled ? '1' : '0',
        },
        files: photo != null ? {'photo': photo} : null,
      );

      if (res.statusCode == 200 && res.data['succes'] == true) {
        final user = UserModel.fromJson(res.data['donnees'] as Map<String, dynamic>);
        await _cacheUser(user);
        state = state.copyWith(
          status: AuthStatus.authenticated,
          user: user,
          phoneNumber: user.phone,
          errorMessage: null,
        );
        return (true, null);
      }
      return (false, 'Réponse inattendue du serveur.');
    } on DioException catch (e) {
      return (false, ApiClient.messageErreur(e));
    } catch (_) {
      return (false, 'Impossible de mettre à jour le profil.');
    }
  }

  Future<(bool, String)> demanderOtpChangementTelephone(String newPhone) async {
    try {
      final res = await _apiClient.post(
        '/client/profile/phone/request-otp',
        data: {'phone': normalizePhone(newPhone)},
      );
      if (res.statusCode == 200 && res.data['succes'] == true) {
        return (
          true,
          res.data['message']?.toString() ??
              'Code OTP envoyé sur votre nouveau numéro.',
        );
      }
      return (
        false,
        res.data['message']?.toString() ?? 'Impossible d\'envoyer le code OTP.',
      );
    } on DioException catch (e) {
      return (false, ApiClient.messageErreur(e));
    } catch (_) {
      return (false, 'Impossible d\'envoyer le code OTP.');
    }
  }

  Future<(bool, String)> confirmerChangementTelephone({
    required String newPhone,
    required String code,
  }) async {
    try {
      final res = await _apiClient.post(
        '/client/profile/phone/confirm',
        data: {
          'phone': normalizePhone(newPhone),
          'code': code.trim(),
        },
      );
      if (res.statusCode == 200 &&
          res.data['succes'] == true &&
          res.data['donnees'] is Map<String, dynamic>) {
        final user =
            UserModel.fromJson(res.data['donnees'] as Map<String, dynamic>);
        await _cacheUser(user);
        state = state.copyWith(
          status: AuthStatus.authenticated,
          user: user,
          phoneNumber: user.phone,
          errorMessage: null,
        );
        return (
          true,
          res.data['message']?.toString() ??
              'Numéro de téléphone mis à jour avec succès.',
        );
      }
      return (
        false,
        res.data['message']?.toString() ?? 'Code OTP invalide ou expiré.',
      );
    } on DioException catch (e) {
      return (false, ApiClient.messageErreur(e));
    } catch (_) {
      return (false, 'Impossible de confirmer le nouveau numéro.');
    }
  }

  Future<(bool, String)> demanderOtpChangementEmail(String newEmail) async {
    try {
      final res = await _apiClient.post(
        '/client/profile/email/request-otp',
        data: {'email': newEmail.trim().toLowerCase()},
      );
      if (res.statusCode == 200 && res.data['succes'] == true) {
        return (
          true,
          res.data['message']?.toString() ??
              'Code de confirmation envoyé par email.',
        );
      }
      return (
        false,
        res.data['message']?.toString() ??
            'Impossible d\'envoyer le code par email.',
      );
    } on DioException catch (e) {
      return (false, ApiClient.messageErreur(e));
    } catch (_) {
      return (false, 'Impossible d\'envoyer le code par email.');
    }
  }

  Future<(bool, String)> confirmerChangementEmail({
    required String newEmail,
    required String code,
  }) async {
    try {
      final res = await _apiClient.post(
        '/client/profile/email/confirm',
        data: {
          'email': newEmail.trim().toLowerCase(),
          'code': code.trim(),
        },
      );
      if (res.statusCode == 200 &&
          res.data['succes'] == true &&
          res.data['donnees'] is Map<String, dynamic>) {
        final user =
            UserModel.fromJson(res.data['donnees'] as Map<String, dynamic>);
        await _cacheUser(user);
        state = state.copyWith(
          status: AuthStatus.authenticated,
          user: user,
          phoneNumber: user.phone,
          errorMessage: null,
        );
        return (
          true,
          res.data['message']?.toString() ??
              'Adresse email confirmée et enregistrée.',
        );
      }
      return (
        false,
        res.data['message']?.toString() ??
            'Code de confirmation invalide ou expiré.',
      );
    } on DioException catch (e) {
      return (false, ApiClient.messageErreur(e));
    } catch (_) {
      return (false, 'Impossible de confirmer l\'adresse email.');
    }
  }

  Future<void> deconnecter() async {
    try {
      await _apiClient.post('/auth/logout').timeout(const Duration(seconds: 3));
    } catch (_) {
      // Déconnexion locale même si le serveur ou le réseau est indisponible
    }
    await forcerDeconnexionLocale();
  }

  Future<void> forcerDeconnexionLocale() async {
    _bootstrapped = false;
    await SecureTokenStorage.clearSessionTokens(_storage);
    try {
      await _storage.delete(key: cachedUserKey);
    } catch (_) {}
    state = const AuthState(status: AuthStatus.initial);
  }

  Future<void> deconnecterLocalement() => deconnecter();
}

final StateNotifierProvider<AuthNotifier, AuthState> authProvider =
    StateNotifierProvider<AuthNotifier, AuthState>((ref) {
  final apiClient = ref.watch(apiClientProvider);
  final storage = ref.watch(secureStorageProvider);
  return AuthNotifier(apiClient, storage);
});

class CityOption {
  final int id;
  final String name;

  const CityOption({required this.id, required this.name});

  factory CityOption.fromJson(Map<String, dynamic> json) => CityOption(
        id: json['id'] as int,
        name: json['name'] as String? ?? '',
      );
}

/// Liste des villes actives chargée depuis l'API (/api/v1/cities), sans liste statique codée en dur.
final FutureProvider<List<CityOption>> citiesListProvider =
    FutureProvider<List<CityOption>>((ref) async {
  final apiClient = ref.watch(apiClientProvider);
  try {
    final res = await apiClient.get('/cities');
    if (res.statusCode == 200 && res.data['succes'] == true) {
      final raw = res.data['donnees'] as List<dynamic>? ?? const [];
      return raw
          .map((e) => CityOption.fromJson(e as Map<String, dynamic>))
          .where((c) => c.name.isNotEmpty)
          .toList();
    }
  } catch (_) {}
  return const [];
});
