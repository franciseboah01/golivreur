import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:golivreur_client/features/auth/providers/auth_provider.dart'
    as auth_api;
import 'package:golivreur_client/features/auth/screens/login_screen.dart';
import 'package:golivreur_client/features/gocolis/providers/gocolis_provider.dart';
import 'package:golivreur_client/features/goshop/providers/goshop_provider.dart';
import 'package:golivreur_client/features/goshop/providers/order_provider.dart';
import 'package:golivreur_client/features/navigation/screens/main_navigation_screen.dart';
import 'package:golivreur_client/main.dart';
import 'package:golivreur_core/services/api_client.dart' as core_api;
import 'package:golivreur_core/widgets/golivreur_widgets.dart';

class _FakeSecureStorage extends Fake implements FlutterSecureStorage {
  final Map<String, String> _data = {};

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
      _data[key];

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
      _data.remove(key);
    } else {
      _data[key] = value;
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
    _data.clear();
  }
}

class _FakeHttpClientAdapter implements HttpClientAdapter {
  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    final dynamic payload = switch (options.path) {
      '/branding' => {'logo_url': null, 'default_emoji': '🛵'},
      '/client/city-status' => {
          'city_id': 1,
          'city_name': 'Daloa',
          'rain_mode_active': false,
          'rain_delay_minutes': 0,
          'emergency_mode_active': false,
          'bareme': <dynamic>[],
          'neighborhoods': <dynamic>[],
          'app_logo_url': null,
          'app_default_emoji': '🛵',
        },
      '/client/profile' => {
          'id': 1,
          'name': 'Client Test',
          'phone': '0700000001',
          'role': 'client',
          'city_id': 1,
          'city_name': 'Daloa',
          'is_active': true,
        },
      _ => <dynamic>[],
    };

    return ResponseBody.fromString(
      jsonEncode({'succes': true, 'donnees': payload}),
      200,
      headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
      },
    );
  }

  @override
  void close({bool force = false}) {}
}

List<Override> _fakeClientOverrides() {
  final storage = _FakeSecureStorage();
  final fakeApi = core_api.ApiClient(
    baseUrl: 'http://localhost/api/v1',
    storage: storage,
    onDeconnexionForcee: () {},
    httpClientAdapter: _FakeHttpClientAdapter(),
  );

  return [
    auth_api.secureStorageProvider.overrideWithValue(storage),
    auth_api.apiClientProvider.overrideWithValue(fakeApi),
    core_api.apiClientProvider.overrideWithValue(fakeApi),
  ];
}

void main() {
  testWidgets('GoLivreur Client - affiche AnimatedSplashScreen au démarrage',
      (WidgetTester tester) async {
    await tester.pumpWidget(
      ProviderScope(
        overrides: _fakeClientOverrides(),
        child: const GoLivreurClientApp(showSplash: true),
      ),
    );
    await tester.pump(const Duration(milliseconds: 50));

    expect(find.byType(AnimatedSplashScreen), findsOneWidget);
    expect(find.text('CLIENT'), findsOneWidget);
  });

  testWidgets('GoLivreur Client - affiche LoginScreen par défaut',
      (WidgetTester tester) async {
    await tester.pumpWidget(
      ProviderScope(
        overrides: _fakeClientOverrides(),
        child: const GoLivreurClientApp(showSplash: false),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.byType(LoginScreen), findsOneWidget);
    expect(find.text('Achetez en toute simplicité.'), findsOneWidget);
    expect(find.text('Se connecter en tant que client'), findsOneWidget);
    expect(find.text('Remplir'), findsNothing);
  });

  testWidgets('MainNavigationScreen - affiche la barre avec 4 onglets',
      (WidgetTester tester) async {
    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          ..._fakeClientOverrides(),
          merchantsListProvider.overrideWith((ref) => Future.value([])),
          ordersHistoryProvider.overrideWith((ref) => Future.value([])),
          parcelsHistoryProvider.overrideWith((ref) => Future.value([])),
        ],
        child: const MaterialApp(
          home: MainNavigationScreen(),
        ),
      ),
    );

    await tester.pumpAndSettle();

    // Vérifie la présence des 4 onglets définis dans Stitch
    expect(find.text('Accueil'), findsOneWidget);
    expect(find.text('GoColis'), findsOneWidget);
    expect(find.text('Commandes'), findsOneWidget);
    expect(find.text('Profil'), findsOneWidget);
  });
}
