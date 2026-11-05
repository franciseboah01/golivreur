import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:golivreur_core/models/modeles.dart';
import 'package:golivreur_core/services/api_client.dart';
import '../../../config/env.dart';

final secureStorageProvider = Provider<FlutterSecureStorage>((ref) {
  return const FlutterSecureStorage();
});

final apiClientProvider = Provider<ApiClient>((ref) {
  final storage = ref.watch(secureStorageProvider);
  return ApiClient(
    baseUrl: Env.apiBaseUrl,
    storage: storage,
    onDeconnexionForcee: () {},
  );
});

enum AuthStatus {
  initial,
  loading,
  otpSent,
  authenticated,
  error,
}

class AuthState {
  final AuthStatus status;
  final UserModel? user;
  final String? phoneNumber;
  final String? errorMessage;

  const AuthState({
    this.status = AuthStatus.initial,
    this.user,
    this.phoneNumber,
    this.errorMessage,
  });

  AuthState copyWith({
    AuthStatus? status,
    UserModel? user,
    String? phoneNumber,
    String? errorMessage,
  }) {
    return AuthState(
      status: status ?? this.status,
      user: user ?? this.user,
      phoneNumber: phoneNumber ?? this.phoneNumber,
      errorMessage: errorMessage,
    );
  }
}

class AuthNotifier extends StateNotifier<AuthState> {
  final ApiClient _apiClient;
  final FlutterSecureStorage _storage;

  AuthNotifier(this._apiClient, this._storage) : super(const AuthState());

  Future<bool> demanderOtpInscription({
    required String phone,
    required String name,
    required int cityId,
  }) async {
    state = state.copyWith(status: AuthStatus.loading, errorMessage: null);
    try {
      final res = await _apiClient.post('/auth/register', data: {
        'phone': phone,
        'name': name,
        'city_id': cityId,
        'role': 'client',
      });

      if (res.statusCode == 200) {
        state = state.copyWith(
          status: AuthStatus.otpSent,
          phoneNumber: phone,
        );
        return true;
      }
    } catch (e) {
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
  }) async {
    state = state.copyWith(status: AuthStatus.loading, errorMessage: null);
    try {
      final res = await _apiClient.post('/auth/verify-register', data: {
        'phone': phone,
        'code': code,
      });

      if (res.statusCode == 200 || res.statusCode == 201) {
        final data = res.data['donnees'];
        final accessToken = data['access_token'];
        final refreshToken = data['refresh_token'];
        final userJson = data['user'];

        await _storage.write(key: 'access_token', value: accessToken);
        await _storage.write(key: 'refresh_token', value: refreshToken);

        final user = UserModel.fromJson(userJson);
        state = state.copyWith(
          status: AuthStatus.authenticated,
          user: user,
        );
        return true;
      }
    } catch (e) {
      state = state.copyWith(
        status: AuthStatus.error,
        errorMessage: 'Code OTP invalide ou expiré.',
      );
    }
    return false;
  }

  Future<bool> demanderOtpConnexion(String phone) async {
    state = state.copyWith(status: AuthStatus.loading, errorMessage: null);
    try {
      final res = await _apiClient.post('/auth/login', data: {
        'phone': phone,
      });

      if (res.statusCode == 200) {
        state = state.copyWith(
          status: AuthStatus.otpSent,
          phoneNumber: phone,
        );
        return true;
      }
    } catch (e) {
      state = state.copyWith(
        status: AuthStatus.error,
        errorMessage: 'Numéro inconnu ou inactif.',
      );
    }
    return false;
  }

  Future<bool> verifierOtpConnexion({
    required String phone,
    required String code,
  }) async {
    state = state.copyWith(status: AuthStatus.loading, errorMessage: null);
    try {
      final res = await _apiClient.post('/auth/verify-login', data: {
        'phone': phone,
        'code': code,
      });

      if (res.statusCode == 200) {
        final data = res.data['donnees'];
        final accessToken = data['access_token'];
        final refreshToken = data['refresh_token'];
        final userJson = data['user'];

        await _storage.write(key: 'access_token', value: accessToken);
        await _storage.write(key: 'refresh_token', value: refreshToken);

        final user = UserModel.fromJson(userJson);
        state = state.copyWith(
          status: AuthStatus.authenticated,
          user: user,
        );
        return true;
      }
    } catch (e) {
      state = state.copyWith(
        status: AuthStatus.error,
        errorMessage: 'Code OTP incorrect ou expiré.',
      );
    }
    return false;
  }

  void deconnecterLocalement() async {
    await _storage.deleteAll();
    state = const AuthState(status: AuthStatus.initial);
  }
}

final authProvider = StateNotifierProvider<AuthNotifier, AuthState>((ref) {
  final apiClient = ref.watch(apiClientProvider);
  final storage = ref.watch(secureStorageProvider);
  return AuthNotifier(apiClient, storage);
});
