import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:golivreur_core/widgets/golivreur_widgets.dart';
import 'package:golivreur_business/main.dart';
import 'package:golivreur_business/features/navigation/screens/business_main_navigation_screen.dart';

void main() {
  testWidgets('GoLivreur Business - affiche AnimatedSplashScreen au démarrage', (WidgetTester tester) async {
    await tester.pumpWidget(const ProviderScope(child: GoLivreurBusinessApp(showSplash: true)));
    expect(find.byType(AnimatedSplashScreen), findsOneWidget);
    expect(find.text('BUSINESS'), findsOneWidget);
  });

  testWidgets('GoLivreur Business smoke test - démarrage application', (WidgetTester tester) async {
    await tester.pumpWidget(const ProviderScope(child: GoLivreurBusinessApp(showSplash: false)));
    expect(find.text('GoLivreur Business'), findsOneWidget);
  });

  testWidgets('BusinessMainNavigationScreen - affiche les 4 onglets avec Profil', (WidgetTester tester) async {
    await tester.pumpWidget(
      const ProviderScope(
        child: MaterialApp(
          home: BusinessMainNavigationScreen(),
        ),
      ),
    );

    // Vérifie les 4 onglets : Commandes, Catalogue, Revenus et Profil
    expect(find.text('Commandes'), findsOneWidget);
    expect(find.text('Catalogue'), findsOneWidget);
    expect(find.text('Revenus'), findsOneWidget);
    expect(find.text('Profil'), findsOneWidget);
  });
}
