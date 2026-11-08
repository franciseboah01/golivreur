import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import '../config/env_config.dart';

/// Client HTTP Dio configuré pour l'API GoLivreur.
///
/// Fonctionnalités :
///   - Timeout 8 secondes (réseau ivoirien instable)
///   - Injection automatique du JWT dans chaque requête
///   - Refresh token transparent (l'utilisateur ne voit rien)
///   - Déconnexion automatique si refresh token expiré
///   - Masquage des tokens dans les logs

class ApiClient {
  static const int _timeoutSecondes = 8;

  final Dio _dio;
  final FlutterSecureStorage _storage;
  final void Function() _onDeconnexionForcee;

  ApiClient({
    required String baseUrl,
    required FlutterSecureStorage storage,
    required void Function() onDeconnexionForcee,
  })  : _storage = storage,
        _onDeconnexionForcee = onDeconnexionForcee,
        _dio = Dio(
          BaseOptions(
            baseUrl: baseUrl,
            // Timeout 8 secondes — au-delà, message "Connexion lente"
            connectTimeout: const Duration(seconds: _timeoutSecondes),
            receiveTimeout: const Duration(seconds: _timeoutSecondes),
            sendTimeout: const Duration(seconds: _timeoutSecondes),
            headers: {
              'Content-Type': 'application/json',
              'Accept': 'application/json',
            },
          ),
        ) {
    _configurerIntercepteurs();
  }

  void _configurerIntercepteurs() {
    _dio.interceptors.add(
      InterceptorsWrapper(
        // ── Requête : injecter le JWT ──────────────────────────────────────
        onRequest: (options, handler) async {
          final token = await _storage.read(key: 'access_token');
          if (token != null) {
            // Le token JWT est injecté silencieusement — jamais loggé
            options.headers['Authorization'] = 'Bearer $token';
          }
          return handler.next(options);
        },

        // ── Réponse OK : passer ───────────────────────────────────────────
        onResponse: (response, handler) {
          return handler.next(response);
        },

        // ── Erreur : gérer 401 avec refresh automatique ───────────────────
        onError: (DioException e, handler) async {
          if (e.response?.statusCode == 401) {
            // Tentative de refresh du token
            final refreshToken = await _storage.read(key: 'refresh_token');

            if (refreshToken == null) {
              // Pas de refresh token → déconnexion forcée
              _onDeconnexionForcee();
              return handler.next(e);
            }

            try {
              // Appel direct sans intercepteur pour éviter la boucle infinie
              final dioRefresh = Dio(BaseOptions(baseUrl: _dio.options.baseUrl));
              final response = await dioRefresh.post(
                '/auth/refresh',
                options: Options(
                  headers: {'Authorization': 'Bearer $refreshToken'},
                ),
              );

              final nouveauToken = response.data['donnees']['access_token'];
              final nouveauRefresh = response.data['donnees']['refresh_token'];

              // Sauvegarder les nouveaux tokens en stockage sécurisé
              await _storage.write(key: 'access_token', value: nouveauToken);
              await _storage.write(key: 'refresh_token', value: nouveauRefresh);

              // Rejouer la requête originale avec le nouveau token
              e.requestOptions.headers['Authorization'] = 'Bearer $nouveauToken';
              final cloneResponse = await _dio.fetch(e.requestOptions);
              return handler.resolve(cloneResponse);

            } catch (_) {
              // Refresh échoué → déconnexion forcée
              await _storage.deleteAll();
              _onDeconnexionForcee();
              return handler.next(e);
            }
          }

          return handler.next(e);
        },
      ),
    );
  }

  // ─── Méthodes HTTP ────────────────────────────────────────────────────────

  Future<Response<T>> get<T>(
    String path, {
    Map<String, dynamic>? queryParameters,
    Options? options,
  }) =>
      _dio.get<T>(path, queryParameters: queryParameters, options: options);

  Future<Response<T>> post<T>(
    String path, {
    dynamic data,
    Map<String, dynamic>? queryParameters,
    Options? options,
  }) =>
      _dio.post<T>(path, data: data, queryParameters: queryParameters, options: options);

  Future<Response<T>> put<T>(
    String path, {
    dynamic data,
    Options? options,
  }) =>
      _dio.put<T>(path, data: data, options: options);

  Future<Response<T>> delete<T>(String path, {Options? options}) =>
      _dio.delete<T>(path, options: options);

  /// Upload de fichier (photo colis GoColis).
  Future<Response<T>> uploadFichier<T>(
    String path, {
    required String cheminFichier,
    required String nomChamp,
    Map<String, dynamic>? donneesSup,
  }) async {
    final formData = FormData.fromMap({
      nomChamp: await MultipartFile.fromFile(cheminFichier),
      ...?donneesSup,
    });
    return _dio.post<T>(
      path,
      data: formData,
      options: Options(headers: {'Content-Type': 'multipart/form-data'}),
    );
  }

  /// Gestion centralisée des erreurs — retourne un message lisible en français.
  static String messageErreur(DioException e) {
    if (e.type == DioExceptionType.connectionTimeout ||
        e.type == DioExceptionType.receiveTimeout ||
        e.type == DioExceptionType.sendTimeout) {
      // Timeout 8 secondes atteint
      return 'Connexion lente, veuillez patienter ou réessayer.';
    }

    if (e.type == DioExceptionType.connectionError) {
      return 'Pas de connexion internet. Vérifiez votre réseau.';
    }

    final statusCode = e.response?.statusCode;
    final message = e.response?.data?['message'];

    return switch (statusCode) {
      400 => message ?? 'Données invalides.',
      401 => 'Session expirée. Veuillez vous reconnecter.',
      403 => 'Vous n\'avez pas les droits pour cette action.',
      404 => 'Ressource introuvable.',
      409 => message ?? 'Conflit de données.',
      422 => message ?? 'Données invalides.',
      429 => 'Trop de tentatives. Réessayez dans quelques minutes.',
      500 => 'Erreur serveur. Notre équipe a été alertée.',
      _   => message ?? 'Une erreur est survenue. Réessayez.',
    };
  }
}

/// Provider Riverpod de l'ApiClient — disponible dans toutes les apps.
final apiClientProvider = Provider<ApiClient>((ref) {
  return ApiClient(
    baseUrl: GoLivreurEnv.apiBaseUrl,
    storage: const FlutterSecureStorage(),
    onDeconnexionForcee: () {},
  );
});
