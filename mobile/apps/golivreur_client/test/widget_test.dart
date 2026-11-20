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
import 'package:golivreur_client/features/gocolis/providers/parcel_draft_recovery.dart';
import 'package:golivreur_client/features/gocolis/screens/new_parcel_screen.dart';
import 'package:golivreur_client/features/goshop/providers/goshop_provider.dart';
import 'package:golivreur_client/features/goshop/providers/order_provider.dart';
import 'package:golivreur_client/features/goshop/screens/goshop_home_screen.dart';
import 'package:golivreur_client/features/navigation/screens/main_navigation_screen.dart';
import 'package:golivreur_client/main.dart';
import 'package:golivreur_core/models/modeles.dart';
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
  Future<void> delete({
    required String key,
    IOSOptions? iOptions,
    AndroidOptions? aOptions,
    LinuxOptions? lOptions,
    WebOptions? webOptions,
    MacOsOptions? mOptions,
    WindowsOptions? wOptions,
  }) async {
    _data.remove(key);
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
          'neighborhoods': <dynamic>[
            {
              'id': 10,
              'name': 'Commerce',
              'distance_km': 2.5,
              'lat': 6.8810,
              'lng': -6.4490,
            },
            {
              'id': 11,
              'name': 'Lobia',
              'distance_km': 3.2,
              'lat': 6.8720,
              'lng': -6.4580,
            },
          ],
          'support_phone': '+2250700000002',
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
    expect(find.text('Mot de passe'), findsOneWidget);
    expect(find.text('Se connecter'), findsOneWidget);
    expect(find.text('Mot de passe oublié ?'), findsOneWidget);
    expect(find.text('Se connecter par code SMS (OTP)'), findsNothing);
    expect(find.text('Remplir'), findsNothing);

    // Clic sur "Mot de passe oublié ?" ouvre le flux de réinitialisation par SMS
    await tester.tap(find.text('Mot de passe oublié ?'));
    await tester.pumpAndSettle();
    expect(find.text('Mot de passe oublié'), findsOneWidget);
    expect(find.text('Recevoir le code par SMS'), findsOneWidget);
  });

  testWidgets(
      'NewParcelScreen - affiche Dropdown quartiers (sans GPS destinataire), section Informations sur le colis, photo et bouton désactivé par défaut',
      (WidgetTester tester) async {
    await tester.pumpWidget(
      ProviderScope(
        overrides: _fakeClientOverrides(),
        child: const MaterialApp(
          home: NewParcelScreen(),
        ),
      ),
    );
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 300));

    expect(find.text('Nouvelle Expédition'), findsOneWidget);
    expect(find.text('Position GPS de ramassage'), findsOneWidget);
    expect(find.text('Informations du destinataire'), findsOneWidget);
    expect(find.text('Pointer sur carte'), findsNothing);
    expect(find.text('Sélectionnez un quartier'), findsOneWidget);
    expect(find.text('Informations sur le colis'), findsOneWidget);
    expect(find.text('Ajouter une photo du colis'), findsOneWidget);
    expect(find.text('Format du colis *'), findsOneWidget);

    // Bouton "Valider l'expédition" doit être désactivé tant que les champs obligatoires sont vides
    final submitBtnFinder = find.widgetWithText(ElevatedButton, 'Valider l\'expédition');
    await tester.ensureVisible(submitBtnFinder);
    await tester.pump();
    final ElevatedButton submitBtn = tester.widget(submitBtnFinder);
    expect(submitBtn.onPressed, isNull);
  });

  testWidgets(
      'NewParcelScreen - restaure intégralement le brouillon après recréation d\'activité Android (caméra)',
      (WidgetTester tester) async {
    final draft = ParcelDraftData(
      recipientName: 'Koné Mariam',
      recipientPhone: '0709000001',
      neighborhoodId: 10,
      neighborhoodName: 'Commerce',
      description: 'Chaussures neuves',
      declaredValue: '25000',
      selectedSize: 'moyen',
      selectedPaymentMethod: 'wave',
      pickupLat: 6.8780,
      pickupLng: -6.4500,
      pickupAddressText: 'Tazibouo, Daloa',
      savedAtMs: DateTime.now().millisecondsSinceEpoch,
    );

    await tester.pumpWidget(
      ProviderScope(
        overrides: _fakeClientOverrides(),
        child: MaterialApp(
          home: NewParcelScreen(recoveredDraft: draft),
        ),
      ),
    );
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 300));

    expect(find.text('Koné Mariam'), findsOneWidget);
    expect(find.text('0709000001'), findsOneWidget);
    expect(find.text('Commerce'), findsOneWidget);
    expect(find.text('Chaussures neuves'), findsOneWidget);
    // Tous les champs obligatoires étant restaurés, le bouton de validation doit être actif !
    final submitBtnFinder = find.byType(ElevatedButton);
    await tester.ensureVisible(submitBtnFinder.last);
    await tester.pump();
    final ElevatedButton submitBtn = tester.widget(submitBtnFinder.last);
    expect(submitBtn.onPressed, isNotNull);
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

    // Vérifie la présence des 4 onglets de navigation client dans la BottomNavigationBar
    final navBar = find.byType(BottomNavigationBar);
    expect(find.descendant(of: navBar, matching: find.text('Accueil')), findsOneWidget);
    expect(find.descendant(of: navBar, matching: find.text('Colis')), findsOneWidget);
    expect(find.descendant(of: navBar, matching: find.text('Commandes')), findsOneWidget);
    expect(find.descendant(of: navBar, matching: find.text('Profil')), findsOneWidget);
  });

  testWidgets(
      'GoShopHomeScreen - affiche les produits en grille sous Catégories par défaut et permet de visiter les boutiques',
      (WidgetTester tester) async {
    final sampleMerchants = [
      const MerchantModel(
        id: 101,
        name: 'Maquis Le Baobab',
        category: 'restaurant',
        categoryName: 'Repas',
        address: 'Quartier Commerce, Daloa',
        lat: 6.877,
        lng: -6.450,
        isOpen: true,
        prepTimeMinutes: 20,
        averageRating: 4.8,
        products: [
          ProductModel(
            id: 1,
            name: 'Attiéké Poisson Braisé',
            description: 'Servi chaud avec alloco et piment maison',
            price: 2500,
            category: 'Repas',
            isAvailable: true,
          ),
        ],
      ),
      const MerchantModel(
        id: 102,
        name: 'Maison Karité Daloa',
        category: 'cosmetique',
        categoryName: 'Cosmétiques',
        address: 'Quartier Lobia, Daloa',
        lat: 6.872,
        lng: -6.458,
        isOpen: true,
        prepTimeMinutes: 25,
        averageRating: 4.9,
        products: [
          ProductModel(
            id: 2,
            name: 'Soin Karité Éclat',
            description: 'Huile hydratante naturelle 250 ml',
            price: 6500,
            category: 'Cosmétiques',
            isAvailable: true,
          ),
        ],
      ),
    ];

    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          ..._fakeClientOverrides(),
          merchantsListProvider.overrideWith((ref) => Future.value(sampleMerchants)),
          promoBannersProvider.overrideWith((ref) => Future.value(const [])),
        ],
        child: const MaterialApp(
          home: GoShopHomeScreen(),
        ),
      ),
    );
    await tester.pumpAndSettle();

    // Section Catégories + grille de produits par défaut
    expect(find.text('Catégories'), findsOneWidget);
    expect(find.text('Populaires à Daloa'), findsOneWidget);
    expect(find.text('Attiéké Poisson Braisé'), findsOneWidget);
    expect(find.text('2 500 FCFA'), findsOneWidget);
    expect(find.text('Soin Karité Éclat'), findsOneWidget);
    expect(find.text('6 500 FCFA'), findsOneWidget);

    // Accès rapide aux boutiques en dessous des produits + bascule Boutiques
    expect(find.text('Visiter les boutiques'), findsOneWidget);
    expect(find.text('Boutiques'), findsOneWidget);

    // Clic sur un produit -> fiche aperçu avec bouton "Visiter la boutique"
    await tester.tap(find.text('Attiéké Poisson Braisé'));
    await tester.pumpAndSettle();
    expect(find.text('Visiter la boutique « Maquis Le Baobab »'), findsOneWidget);

    // Fermer la fiche puis basculer sur l'onglet "Boutiques"
    Navigator.of(tester.element(find.text('Visiter la boutique « Maquis Le Baobab »'))).pop();
    await tester.pumpAndSettle();

    await tester.tap(find.text('Boutiques'));
    await tester.pumpAndSettle();
    expect(find.text('Boutiques à Daloa'), findsOneWidget);
    expect(find.text('Quartier Commerce, Daloa'), findsOneWidget);
  });

  testWidgets(
      'GoLivreur Client - ouvre directement Accueil si le client est déjà connecté (sans flash LoginScreen)',
      (WidgetTester tester) async {
    final storage = _FakeSecureStorage();
    await storage.write(key: 'access_token', value: 'valid-jwt-token');
    await storage.write(
      key: auth_api.AuthNotifier.cachedUserKey,
      value: jsonEncode({
        'id': 42,
        'name': 'Awa Koné',
        'phone': '0701000042',
        'role': 'client',
        'roles': ['client'],
        'city_id': 1,
        'city_name': 'Daloa',
        'is_active': true,
      }),
    );

    final fakeApi = core_api.ApiClient(
      baseUrl: 'http://localhost/api/v1',
      storage: storage,
      onDeconnexionForcee: () {},
      httpClientAdapter: _FakeHttpClientAdapter(),
    );

    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          auth_api.secureStorageProvider.overrideWithValue(storage),
          auth_api.apiClientProvider.overrideWithValue(fakeApi),
          core_api.apiClientProvider.overrideWithValue(fakeApi),
          merchantsListProvider.overrideWith((ref) => Future.value([])),
          ordersHistoryProvider.overrideWith((ref) => Future.value([])),
          parcelsHistoryProvider.overrideWith((ref) => Future.value([])),
        ],
        child: const GoLivreurClientApp(showSplash: false),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.byType(LoginScreen), findsNothing);
    expect(find.byType(MainNavigationScreen), findsOneWidget);
    expect(find.text('Accueil'), findsOneWidget);
  });
}
