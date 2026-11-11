import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:golivreur_business/features/navigation/screens/business_main_navigation_screen.dart';
import 'package:golivreur_business/main.dart';
import 'package:golivreur_core/services/api_client.dart';
import 'package:golivreur_core/widgets/golivreur_widgets.dart';

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
      '/merchant/profile' => {
          'id': 1,
          'business_name': 'Maquis Test',
          'name': 'Tantie Adjoua',
          'phone': '0702010001',
          'category': 'maquis',
          'category_name': 'Maquis',
          'emoji': '🍖',
          'address': 'Daloa',
          'is_open': true,
          'prep_time_minutes': 20,
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

List<Override> _fakeBusinessOverrides() {
  final fakeApi = ApiClient(
    baseUrl: 'http://localhost/api/v1',
    storage: _FakeSecureStorage(),
    onDeconnexionForcee: () {},
    httpClientAdapter: _FakeHttpClientAdapter(),
  );
  return [apiClientProvider.overrideWithValue(fakeApi)];
}

void main() {
  testWidgets('GoLivreur Business - affiche AnimatedSplashScreen au démarrage',
      (WidgetTester tester) async {
    await tester.pumpWidget(
      ProviderScope(
        overrides: _fakeBusinessOverrides(),
        child: const GoLivreurBusinessApp(showSplash: true),
      ),
    );
    expect(find.byType(AnimatedSplashScreen), findsOneWidget);
    expect(find.text('BUSINESS'), findsOneWidget);
  });

  testWidgets('GoLivreur Business smoke test - démarrage application',
      (WidgetTester tester) async {
    await tester.pumpWidget(
      ProviderScope(
        overrides: _fakeBusinessOverrides(),
        child: const GoLivreurBusinessApp(showSplash: false),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.text('GoLivreur Business'), findsOneWidget);
  });

  testWidgets('BusinessMainNavigationScreen - affiche les 4 onglets avec Profil',
      (WidgetTester tester) async {
    await tester.pumpWidget(
      ProviderScope(
        overrides: _fakeBusinessOverrides(),
        child: const MaterialApp(
          home: BusinessMainNavigationScreen(),
        ),
      ),
    );
    await tester.pumpAndSettle();

    // Vérifie les 4 onglets : Commandes, Catalogue, Revenus et Profil
    expect(find.text('Commandes'), findsOneWidget);
    expect(find.text('Catalogue'), findsOneWidget);
    expect(find.text('Revenus'), findsOneWidget);
    expect(find.text('Profil'), findsOneWidget);
  });
}
