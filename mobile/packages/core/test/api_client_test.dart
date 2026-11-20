import 'dart:async';
import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:golivreur_core/services/api_client.dart';
import 'package:golivreur_core/widgets/atoms/widgets_atomiques.dart';
import 'package:image_picker/image_picker.dart';

class _MemorySecureStorage extends Fake implements FlutterSecureStorage {
  final Map<String, String> _values;
  bool deleteAllCalled = false;

  _MemorySecureStorage([Map<String, String>? initial])
      : _values = Map<String, String>.from(initial ?? {});

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
      _values[key];

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
      _values.remove(key);
    } else {
      _values[key] = value;
    }
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
    deleteAllCalled = true;
    _values.clear();
  }
}

typedef _AdapterHandler = Future<ResponseBody> Function(RequestOptions options);

class _FakeHttpClientAdapter implements HttpClientAdapter {
  final _AdapterHandler handler;

  _FakeHttpClientAdapter(this.handler);

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) =>
      handler(options);

  @override
  void close({bool force = false}) {}
}

ResponseBody _jsonResponse(Map<String, dynamic> body, int statusCode) {
  return ResponseBody.fromString(
    jsonEncode(body),
    statusCode,
    headers: {
      Headers.contentTypeHeader: [Headers.jsonContentType],
    },
  );
}

class _SlowBannerHarness extends StatefulWidget {
  const _SlowBannerHarness();

  @override
  State<_SlowBannerHarness> createState() => _SlowBannerHarnessState();
}

class _SlowBannerHarnessState extends State<_SlowBannerHarness> {
  Timer? _timeoutTimer;
  bool _showSlowBanner = false;
  bool _cancelled = false;

  @override
  void initState() {
    super.initState();
    _timeoutTimer = Timer(
      const Duration(seconds: ApiClient.timeoutSecondes),
      () {
        if (mounted) {
          setState(() => _showSlowBanner = true);
        }
      },
    );
  }

  @override
  void dispose() {
    _timeoutTimer?.cancel();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      home: Scaffold(
        body: Stack(
          children: [
            Center(child: Text(_cancelled ? 'Annulé' : 'Chargement')),
            if (_showSlowBanner)
              BanniereConnexionLente(
                onAnnuler: () => setState(() {
                  _showSlowBanner = false;
                  _cancelled = true;
                }),
              ),
          ],
        ),
      ),
    );
  }
}

void main() {
  group('ApiClient', () {
    test('configure un timeout de 8 secondes et le message Connexion lente', () {
      final storage = _MemorySecureStorage();
      final client = ApiClient(
        baseUrl: 'https://api.golivreur.test/api/v1',
        storage: storage,
        onDeconnexionForcee: () {},
        httpClientAdapter: _FakeHttpClientAdapter(
          (_) async => _jsonResponse({'succes': true}, 200),
        ),
      );

      expect(ApiClient.timeoutSecondes, 8);
      expect(client.dioForTest.options.connectTimeout, const Duration(seconds: 8));
      expect(client.dioForTest.options.receiveTimeout, const Duration(seconds: 8));
      expect(client.dioForTest.options.sendTimeout, const Duration(seconds: 8));

      final req = RequestOptions(path: '/client/orders');
      for (final type in const [
        DioExceptionType.connectionTimeout,
        DioExceptionType.receiveTimeout,
        DioExceptionType.sendTimeout,
      ]) {
        final msg = ApiClient.messageErreur(DioException(requestOptions: req, type: type));
        expect(msg, 'Connexion lente, veuillez patienter ou réessayer.');
      }
    });

    test('rafraichit automatiquement le JWT sur 401 et rejoue la requete', () async {
      final storage = _MemorySecureStorage({
        'access_token': 'expired_jwt',
        'refresh_token': 'valid_refresh',
      });
      var forcedLogoutCalls = 0;
      final recordedAuthHeaders = <String?>[];
      var profileAttempts = 0;

      final adapter = _FakeHttpClientAdapter((options) async {
        recordedAuthHeaders.add(options.headers['Authorization'] as String?);
        if (options.path == '/client/profile') {
          profileAttempts++;
          if (profileAttempts == 1) {
            return _jsonResponse({'message': 'Token expiré'}, 401);
          }
          return _jsonResponse({
            'succes': true,
            'donnees': {'name': 'Awa Koné'},
          }, 200);
        }
        if (options.path == '/auth/refresh') {
          expect(options.headers['Authorization'], 'Bearer valid_refresh');
          return _jsonResponse({
            'succes': true,
            'donnees': {
              'access_token': 'fresh_jwt',
              'refresh_token': 'fresh_refresh',
            },
          }, 200);
        }
        return _jsonResponse({}, 404);
      });

      final client = ApiClient(
        baseUrl: 'https://api.golivreur.test/api/v1',
        storage: storage,
        onDeconnexionForcee: () => forcedLogoutCalls++,
        httpClientAdapter: adapter,
      );

      final response = await client.get<Map<String, dynamic>>('/client/profile');

      expect(response.statusCode, 200);
      expect(response.data?['donnees']?['name'], 'Awa Koné');
      expect(profileAttempts, 2);
      expect(forcedLogoutCalls, 0);
      expect(recordedAuthHeaders, [
        'Bearer expired_jwt',
        'Bearer valid_refresh',
        'Bearer fresh_jwt',
      ]);
      expect(await storage.read(key: 'access_token'), 'fresh_jwt');
      expect(await storage.read(key: 'refresh_token'), 'fresh_refresh');
    });

    test('force la deconnexion si le refresh JWT est absent ou refuse', () async {
      final noRefreshStorage = _MemorySecureStorage({'access_token': 'expired_jwt'});
      var logoutWithoutRefresh = 0;
      final clientNoRefresh = ApiClient(
        baseUrl: 'https://api.golivreur.test/api/v1',
        storage: noRefreshStorage,
        onDeconnexionForcee: () => logoutWithoutRefresh++,
        httpClientAdapter: _FakeHttpClientAdapter(
          (_) async => _jsonResponse({'message': 'Non autorisé'}, 401),
        ),
      );

      await expectLater(
        clientNoRefresh.get('/client/profile'),
        throwsA(isA<DioException>()),
      );
      expect(logoutWithoutRefresh, 1);

      final failedRefreshStorage = _MemorySecureStorage({
        'access_token': 'expired_jwt',
        'refresh_token': 'revoked_refresh',
      });
      var logoutOnFailedRefresh = 0;
      final clientFailedRefresh = ApiClient(
        baseUrl: 'https://api.golivreur.test/api/v1',
        storage: failedRefreshStorage,
        onDeconnexionForcee: () => logoutOnFailedRefresh++,
        httpClientAdapter: _FakeHttpClientAdapter(
          (_) async => _jsonResponse({'message': 'Non autorisé'}, 401),
        ),
      );

      await expectLater(
        clientFailedRefresh.get('/client/profile'),
        throwsA(isA<DioException>()),
      );
      expect(logoutOnFailedRefresh, 1);
      expect(failedRefreshStorage.deleteAllCalled, isTrue);
      expect(await failedRefreshStorage.read(key: 'access_token'), isNull);
    });

    test('rejoue proprement un uploadMultipart apres 401 sans erreur FormData finalise', () async {
      final storage = _MemorySecureStorage({
        'access_token': 'expired_jwt',
        'refresh_token': 'valid_refresh',
      });
      var forcedLogoutCalls = 0;
      var uploadAttempts = 0;
      final seenFormData = <FormData>[];

      final adapter = _FakeHttpClientAdapter((options) async {
        if (options.path == '/client/parcels') {
          uploadAttempts++;
          // Dio 5 finalise le FormData juste avant d'appeler l'adapter HTTP :
          // sans reconstruction, le 2e appel lèverait StateError(already finalized).
          expect(options.data, isA<FormData>());
          final formData = options.data as FormData;
          seenFormData.add(formData);
          expect(formData.isFinalized, isTrue);

          if (uploadAttempts == 1) {
            return _jsonResponse({'message': 'Token expiré'}, 401);
          }
          expect(options.headers['Authorization'], 'Bearer fresh_jwt');
          return _jsonResponse({
            'succes': true,
            'donnees': {'id': 42},
          }, 201);
        }
        if (options.path == '/auth/refresh') {
          return _jsonResponse({
            'succes': true,
            'donnees': {
              'access_token': 'fresh_jwt',
              'refresh_token': 'fresh_refresh',
            },
          }, 200);
        }
        return _jsonResponse({}, 404);
      });

      final client = ApiClient(
        baseUrl: 'https://api.golivreur.test/api/v1',
        storage: storage,
        onDeconnexionForcee: () => forcedLogoutCalls++,
        httpClientAdapter: adapter,
      );

      final fakePhoto = XFile.fromData(
        Uint8List.fromList([0xFF, 0xD8, 0xFF, 0xE0, 1, 2, 3]),
        name: 'colis.jpg',
      );

      final response = await client.uploadMultipart<Map<String, dynamic>>(
        '/client/parcels',
        method: 'POST',
        fields: {'recipient_name': 'Koné Mariam', 'size': 'moyen'},
        files: {'photo': fakePhoto},
      );

      expect(response.statusCode, 201);
      expect(response.data?['donnees']?['id'], 42);
      expect(uploadAttempts, 2);
      expect(seenFormData.length, 2);
      expect(identical(seenFormData[0], seenFormData[1]), isFalse);
      expect(forcedLogoutCalls, 0);
    });

    test('propage une erreur 422 lors du rejeu apres 401 au lieu de la masquer en Session expiree', () async {
      final storage = _MemorySecureStorage({
        'access_token': 'expired_jwt',
        'refresh_token': 'valid_refresh',
      });
      var attempts = 0;

      final client = ApiClient(
        baseUrl: 'https://api.golivreur.test/api/v1',
        storage: storage,
        onDeconnexionForcee: () {},
        httpClientAdapter: _FakeHttpClientAdapter((options) async {
          if (options.path == '/client/parcels') {
            attempts++;
            if (attempts == 1) {
              return _jsonResponse({'message': 'Unauthenticated'}, 401);
            }
            return _jsonResponse({
              'message': 'Coordonnées hors périmètre.',
              'errors': {
                'pickup_lat': ['Coordonnées hors périmètre.'],
              },
            }, 422);
          }
          if (options.path == '/auth/refresh') {
            return _jsonResponse({
              'succes': true,
              'donnees': {
                'access_token': 'fresh_jwt',
                'refresh_token': 'fresh_refresh',
              },
            }, 200);
          }
          return _jsonResponse({}, 404);
        }),
      );

      DioException? caught;
      try {
        await client.post('/client/parcels', data: {'size': 'petit'});
      } on DioException catch (e) {
        caught = e;
      }

      expect(caught, isNotNull);
      expect(caught!.response?.statusCode, 422);
      expect(ApiClient.messageErreur(caught), 'Coordonnées hors périmètre.');
    });

    test('ne deconnecte pas lutilisateur si /auth/refresh echoue pour erreur serveur temporaire 503', () async {
      final storage = _MemorySecureStorage({
        'access_token': 'expired_jwt',
        'refresh_token': 'valid_refresh',
      });
      var forcedLogoutCalls = 0;

      final client = ApiClient(
        baseUrl: 'https://api.golivreur.test/api/v1',
        storage: storage,
        onDeconnexionForcee: () => forcedLogoutCalls++,
        httpClientAdapter: _FakeHttpClientAdapter((options) async {
          if (options.path == '/auth/refresh') {
            return _jsonResponse({'message': 'Service indisponible'}, 503);
          }
          return _jsonResponse({'message': 'Token expiré'}, 401);
        }),
      );

      await expectLater(
        client.get('/client/profile'),
        throwsA(isA<DioException>()),
      );

      expect(forcedLogoutCalls, 0);
      expect(storage.deleteAllCalled, isFalse);
      expect(await storage.read(key: 'refresh_token'), 'valid_refresh');
    });
  });

  testWidgets('BanniereConnexionLente saffiche apres 8 secondes et se ferme sur Annuler',
      (WidgetTester tester) async {
    await tester.pumpWidget(const _SlowBannerHarness());

    expect(find.byType(BanniereConnexionLente), findsNothing);

    await tester.pump(const Duration(seconds: 7));
    expect(find.byType(BanniereConnexionLente), findsNothing);

    await tester.pump(const Duration(seconds: 1));
    expect(find.byType(BanniereConnexionLente), findsOneWidget);
    expect(find.text('Connexion lente, veuillez patienter...'), findsOneWidget);

    await tester.tap(find.text('Annuler'));
    await tester.pump();
    expect(find.byType(BanniereConnexionLente), findsNothing);
    expect(find.text('Annulé'), findsOneWidget);
  });
}

