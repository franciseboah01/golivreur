import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:golivreur_core/services/api_client.dart';
import 'package:golivreur_core/widgets/golivreur_widgets.dart';
import 'package:golivreur_driver/features/navigation/screens/driver_main_navigation_screen.dart';
import 'package:golivreur_driver/main.dart';

class _FakeSecureStorage extends Fake implements FlutterSecureStorage {
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
      null;

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
  }) async {}

  @override
  Future<void> deleteAll({
    IOSOptions? iOptions,
    AndroidOptions? aOptions,
    LinuxOptions? lOptions,
    WebOptions? webOptions,
    MacOsOptions? mOptions,
    WindowsOptions? wOptions,
  }) async {}
}

class _FakeHttpClientAdapter implements HttpClientAdapter {
  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    final dynamic payload = switch (options.path) {
      '/driver/profile' => {
          'id': 1,
          'name': 'Ouattara Seydou',
          'phone': '0703000001',
          'vehicle_type': 'moto',
          'vehicle_type_name': 'Moto',
          'city_name': 'Daloa',
          'is_online': true,
        },
      '/driver/wallet' => {
          'wallet': {'balance': 15000},
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

List<Override> _fakeDriverOverrides() {
  final fakeApi = ApiClient(
    baseUrl: 'http://localhost/api/v1',
    storage: _FakeSecureStorage(),
    onDeconnexionForcee: () {},
    httpClientAdapter: _FakeHttpClientAdapter(),
  );
  return [apiClientProvider.overrideWithValue(fakeApi)];
}

void main() {
  testWidgets('GoLivreur Driver - affiche AnimatedSplashScreen au démarrage',
      (WidgetTester tester) async {
    await tester.pumpWidget(
      ProviderScope(
        overrides: _fakeDriverOverrides(),
        child: const GoLivreurDriverApp(showSplash: true),
      ),
    );
    expect(find.byType(AnimatedSplashScreen), findsOneWidget);
    expect(find.text('DRIVER'), findsOneWidget);
  });

  testWidgets('GoLivreur Driver smoke test - démarrage application',
      (WidgetTester tester) async {
    await tester.pumpWidget(
      ProviderScope(
        overrides: _fakeDriverOverrides(),
        child: const GoLivreurDriverApp(showSplash: false),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.text('GoLivreur Driver'), findsOneWidget);
  });

  testWidgets('DriverMainNavigationScreen - affiche les 4 onglets avec Profil',
      (WidgetTester tester) async {
    await tester.pumpWidget(
      ProviderScope(
        overrides: _fakeDriverOverrides(),
        child: const MaterialApp(
          home: DriverMainNavigationScreen(),
        ),
      ),
    );
    await tester.pumpAndSettle();

    // Vérifie les 4 onglets : Courses, Portefeuille, Gains et Profil
    expect(find.text('Courses'), findsOneWidget);
    expect(find.text('Portefeuille'), findsOneWidget);
    expect(find.text('Gains'), findsOneWidget);
    expect(find.text('Profil'), findsOneWidget);
  });
}
