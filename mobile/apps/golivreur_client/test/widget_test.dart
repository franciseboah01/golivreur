import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:golivreur_client/main.dart';
import 'package:golivreur_client/features/auth/screens/login_screen.dart';
import 'package:golivreur_client/features/navigation/screens/main_navigation_screen.dart';
import 'package:golivreur_client/features/goshop/providers/goshop_provider.dart';
import 'package:golivreur_client/features/goshop/providers/order_provider.dart';
import 'package:golivreur_client/features/gocolis/providers/gocolis_provider.dart';

void main() {
  testWidgets('GoLivreur Client - affiche LoginScreen par défaut', (WidgetTester tester) async {
    await tester.pumpWidget(
      const ProviderScope(
        child: GoLivreurClientApp(),
      ),
    );

    expect(find.byType(LoginScreen), findsOneWidget);
    expect(find.text('Connexion GoLivreur'), findsOneWidget);
  });

  testWidgets('MainNavigationScreen - affiche la barre avec 4 onglets', (WidgetTester tester) async {
    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          merchantsListProvider.overrideWith((ref) => Future.value([])),
          ordersHistoryProvider.overrideWith((ref) => Future.value([])),
          parcelsHistoryProvider.overrideWith((ref) => Future.value([])),
        ],
        child: const MaterialApp(
          home: MainNavigationScreen(),
        ),
      ),
    );

    await tester.pump();

    // Vérifie la présence des 4 onglets définis dans Stitch
    expect(find.text('Accueil'), findsOneWidget);
    expect(find.text('GoColis'), findsOneWidget);
    expect(find.text('Commandes'), findsOneWidget);
    expect(find.text('Profil'), findsOneWidget);
  });
}
