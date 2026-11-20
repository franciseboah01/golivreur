import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:golivreur_business/features/auth/screens/business_login_screen.dart';
import 'package:golivreur_business/features/catalog/screens/business_catalog_screen.dart';
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
    if (options.path == '/auth/register-merchant' && options.method == 'POST') {
      return ResponseBody.fromString(
        jsonEncode({
          'succes': true,
          'message':
              'Pré-inscription enregistrée. Passez en agence GoLivreur ou avec un agent terrain pour valider et compléter votre dossier.',
          'donnees': {
            'merchant_id': 15,
            'status': 'pending_validation',
            'is_active': false,
          },
        }),
        201,
        headers: {
          Headers.contentTypeHeader: [Headers.jsonContentType],
        },
      );
    }

    final dynamic payload = switch (options.path) {
      '/cities' => [
          {'id': 1, 'name': 'Daloa'},
        ],
      '/categories' => [
          {'slug': 'maquis', 'name': 'Maquis & Grillades'},
        ],
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
      '/merchant/catalog-schema' => {
          'merchant_category': {
            'id': 1,
            'slug': 'restaurant',
            'name': 'Restauration & Maquis',
            'is_food': true,
          },
          'fields': [
            {
              'id': 1,
              'code': 'name',
              'label': 'Nom du plat',
              'field_type': 'text',
              'is_core': true,
              'is_required': true,
              'sort_order': 10,
              'placeholder': 'Ex: Garba Thon VIP',
              'options': [],
            },
            {
              'id': 2,
              'code': 'price',
              'label': 'Prix du plat (F CFA)',
              'field_type': 'currency',
              'is_core': true,
              'is_required': true,
              'sort_order': 20,
              'placeholder': 'Ex: 1500',
              'options': [],
            },
            {
              'id': 3,
              'code': 'ingredients',
              'label': 'Ingrédients',
              'field_type': 'textarea',
              'is_core': false,
              'is_required': false,
              'sort_order': 30,
              'placeholder': 'Attiéké, thon...',
              'options': [],
            },
            {
              'id': 4,
              'code': 'quantity',
              'label': 'Portions disponibles',
              'field_type': 'number',
              'is_core': true,
              'is_required': false,
              'sort_order': 90,
              'placeholder': 'Ex: 20',
              'options': [],
            },
          ],
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
    expect(find.text('Vendez en toute confiance.'), findsOneWidget);
    expect(find.text('Se connecter en tant que commerçant'), findsOneWidget);
    expect(find.text('Remplir'), findsNothing);
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

  testWidgets('BusinessLoginScreen permet la pré-inscription commerçant (agence ou agent terrain)',
      (WidgetTester tester) async {
    final fakeApi = ApiClient(
      baseUrl: 'http://localhost/api/v1',
      storage: _FakeSecureStorage(),
      onDeconnexionForcee: () {},
      httpClientAdapter: _FakeHttpClientAdapter(),
    );

    await tester.pumpWidget(
      MaterialApp(
        home: BusinessLoginScreen(apiClientOverride: fakeApi),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('Devenir commerçant partenaire'), findsOneWidget);

    await tester.ensureVisible(find.text('Devenir commerçant partenaire'));
    await tester.tap(find.text('Devenir commerçant partenaire'));
    await tester.pumpAndSettle();

    expect(find.text('Devenir commerçant GoLivreur'), findsOneWidget);

    await tester.enterText(
      find.widgetWithText(TextFormField, 'Nom du commerce / restaurant'),
      'Maquis Le Palmier',
    );
    await tester.enterText(
      find.widgetWithText(TextFormField, 'Nom et prénom du gérant'),
      'Kouassi Adjoua',
    );
    await tester.enterText(
      find.widgetWithText(TextFormField, 'Numéro de téléphone gérant'),
      '0708445566',
    );
    await tester.enterText(
      find.widgetWithText(TextFormField, 'Mot de passe (min. 6 caractères)'),
      'MerchantPass2026',
    );
    await tester.enterText(
      find.widgetWithText(
          TextFormField, 'Adresse / Quartier (ex: Quartier Commerce)'),
      'Quartier Commerce, Bouaké',
    );

    await tester.ensureVisible(find.text('Envoyer ma pré-inscription'));
    await tester.tap(find.text('Envoyer ma pré-inscription'));
    await tester.pumpAndSettle();

    expect(
      find.text(
        'Pré-inscription enregistrée. Passez en agence GoLivreur ou avec un agent terrain pour valider et compléter votre dossier.',
      ),
      findsOneWidget,
    );
  });

  testWidgets(
      'BusinessCatalogScreen génère les champs dynamiques dans l\'ordre exact de l\'Admin sans jamais afficher un champ non coché',
      (WidgetTester tester) async {
    await tester.pumpWidget(
      ProviderScope(
        overrides: _fakeBusinessOverrides(),
        child: const MaterialApp(
          home: BusinessCatalogScreen(),
        ),
      ),
    );
    await tester.pumpAndSettle();

    // Bouton adapté à la restauration
    expect(find.text('Ajouter un plat'), findsOneWidget);

    await tester.tap(find.text('Ajouter un plat'));
    await tester.pumpAndSettle();

    // Libellés précis présents (jamais juste "Nom")
    final nomPlatFinder = find.text('Nom du plat *');
    final prixFinder = find.text('Prix du plat (F CFA) *');
    final ingredientsFinder = find.text('Ingrédients');
    final quantiteFinder = find.text('Portions disponibles');

    expect(nomPlatFinder, findsOneWidget);
    expect(prixFinder, findsOneWidget);
    expect(ingredientsFinder, findsOneWidget);
    expect(quantiteFinder, findsOneWidget);

    // Champs NON cochés pour la restauration strictement absents
    expect(find.text('Marque'), findsNothing);
    expect(find.text('Contenance'), findsNothing);
    expect(find.text('Taille'), findsNothing);
    expect(find.text('Nom'), findsNothing);

    // Vérifie l'ordre vertical strict défini par l'Admin : Nom du plat < Prix < Ingrédients < Quantité
    final yNom = tester.getTopLeft(nomPlatFinder).dy;
    final yPrix = tester.getTopLeft(prixFinder).dy;
    final yIngredients = tester.getTopLeft(ingredientsFinder).dy;
    final yQuantite = tester.getTopLeft(quantiteFinder).dy;

    expect(yNom, lessThan(yPrix));
    expect(yPrix, lessThan(yIngredients));
    expect(yIngredients, lessThan(yQuantite));
  });
}
