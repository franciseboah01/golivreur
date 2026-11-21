import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:golivreur_core/services/api_client.dart';
import 'package:golivreur_core/widgets/golivreur_widgets.dart';
import 'package:golivreur_driver/features/auth/screens/driver_login_screen.dart';
import 'package:golivreur_driver/features/driver/providers/driver_provider.dart';
import 'package:golivreur_driver/features/navigation/screens/driver_main_navigation_screen.dart';
import 'package:golivreur_driver/features/profile/screens/edit_driver_profile_screen.dart';
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
      '/cities' => [
          {'id': 1, 'name': 'Daloa'},
        ],
      '/vehicle-types' => [
          {'slug': 'moto', 'name': 'Moto'},
        ],
      '/auth/register-driver' => {
          'courier_id': 99,
          'status': 'pending_validation',
          'is_active': false,
        },
      _ => <dynamic>[],
    };

    final String message = options.path == '/auth/register-driver'
        ? 'Pré-inscription enregistrée. Passez en agence GoLivreur pour vérifier vos pièces et activer votre compte.'
        : 'OK';

    return ResponseBody.fromString(
      jsonEncode({'succes': true, 'message': message, 'donnees': payload}),
      options.path == '/auth/register-driver' ? 201 : 200,
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
    expect(find.text('Livrez en toute liberté.'), findsOneWidget);
    expect(find.text('Se connecter en tant que livreur'), findsOneWidget);
    expect(find.text('Remplir'), findsNothing);
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

  testWidgets(
      'DriverLoginScreen affiche Mot de passe et permet la pre-inscription Devenir livreur',
      (WidgetTester tester) async {
    final fakeApi = ApiClient(
      baseUrl: 'http://localhost/api/v1',
      storage: _FakeSecureStorage(),
      onDeconnexionForcee: () {},
      httpClientAdapter: _FakeHttpClientAdapter(),
    );

    await tester.pumpWidget(
      MaterialApp(
        home: DriverLoginScreen(apiClientOverride: fakeApi),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('Mot de passe'), findsOneWidget);
    expect(find.text('Devenir livreur'), findsOneWidget);

    await tester.ensureVisible(find.text('Devenir livreur'));
    await tester.tap(find.text('Devenir livreur'));
    await tester.pumpAndSettle();

    expect(find.text('Devenir livreur GoLivreur'), findsOneWidget);

    await tester.enterText(
      find.widgetWithText(TextFormField, 'Nom et prénom'),
      'Kouamé Yao',
    );
    await tester.enterText(
      find.widgetWithText(TextFormField, 'Numéro de téléphone'),
      '0708112233',
    );
    await tester.enterText(
      find.widgetWithText(TextFormField, 'Mot de passe (min. 6 caractères)'),
      'DriverSecret2026',
    );
    await tester.enterText(
      find.widgetWithText(
          TextFormField, 'Plaque d\'immatriculation (ex: 1234-JK-01)'),
      '4589-JK-01',
    );

    await tester.ensureVisible(find.text('Envoyer ma pré-inscription'));
    await tester.tap(find.text('Envoyer ma pré-inscription'));
    await tester.pumpAndSettle();

    expect(
      find.text(
        'Pré-inscription enregistrée. Passez en agence GoLivreur pour vérifier vos pièces et activer votre compte.',
      ),
      findsOneWidget,
    );
  });

  testWidgets('GoLivreurLogo - affiche le logo unique par défaut et se met à jour dynamiquement via appBrandingProvider',
      (WidgetTester tester) async {
    final fakeApi = ApiClient(
      baseUrl: 'http://localhost/api/v1',
      storage: _FakeSecureStorage(),
      onDeconnexionForcee: () {},
      httpClientAdapter: _FakeHttpClientAdapter(),
    );

    final container = ProviderContainer(
      overrides: [
        apiClientProvider.overrideWithValue(fakeApi),
      ],
    );
    addTearDown(container.dispose);

    await tester.pumpWidget(
      UncontrolledProviderScope(
        container: container,
        child: MaterialApp(
          home: DriverLoginScreen(apiClientOverride: fakeApi),
        ),
      ),
    );
    await tester.pumpAndSettle();

    // Spec 1.2 : aucun emoji de repli ('🛵') n'est affiché hors ligne ; le monogramme vectoriel "GL" prend le relais
    expect(find.text('🛵'), findsNothing);
    expect(find.text('GL'), findsOneWidget);

    // Quand l'admin met à jour le logo dans les paramètres, toutes les vues réagissent automatiquement
    container
        .read(appBrandingProvider.notifier)
        .updateFromValues('http://localhost:8080/storage/branding/logo.png', '');
    await tester.pump();

    expect(find.byType(Image), findsOneWidget);
  });

  test('DriverJob.fromCourseJson parse un colis assigné manuellement sans planter', () {
    final job = DriverJob.fromCourseJson(
      {
        'id': 42,
        'course_type': 'gocolis',
        'status': 'accepted',
        'pickup_address': 'Marché Daloa',
        'delivery_address': 'Quartier Tazibouo',
        'pickup_lat': 6.88,
        'pickup_lng': -6.45,
        'delivery_lat': null,
        'delivery_lng': null,
        'distance_km': 3.4,
        'size_label': 'Moyen',
        'instructions': 'Appeler à la barrière bleue',
        'pickup_contact_name': 'Kouamé',
        'pickup_contact_phone': '0701020304',
        'delivery_contact_name': 'Awa',
        'delivery_contact_phone': '0705060708',
        'courier_amount': 1200,
        'amount_to_collect': 1500,
        'payment_method': 'cash',
      },
      type: DriverJobType.gocolis,
    );

    expect(job.id, 42);
    expect(job.step, DriverJobStep.accepted);
    expect(job.parcelSize, 'Moyen');
    expect(job.pickupContactName, 'Kouamé');
    expect(job.deliveryContactPhone, '0705060708');
    expect(job.instructions, 'Appeler à la barrière bleue');
    expect(job.amountToCollect, 1500);
    expect(job.deliveryLat, isNull);
  });

  testWidgets('EditDriverProfileScreen affiche le dossier en lecture seule et les actions OTP / mot de passe',
      (WidgetTester tester) async {
    await tester.pumpWidget(
      ProviderScope(
        overrides: _fakeDriverOverrides(),
        child: const MaterialApp(home: EditDriverProfileScreen()),
      ),
    );
    await tester.pumpAndSettle();

    expect(
      find.textContaining('Contactez l\'agence GoLivreur pour modifier ces informations'),
      findsOneWidget,
    );
    await tester.scrollUntilVisible(
      find.text('Téléphone (changement par OTP)'),
      200,
    );
    expect(find.text('Téléphone (changement par OTP)'), findsOneWidget);
    expect(find.text('Changer mon mot de passe'), findsOneWidget);
  });
}
