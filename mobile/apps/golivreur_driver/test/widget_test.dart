import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:golivreur_driver/main.dart';
import 'package:golivreur_driver/features/navigation/screens/driver_main_navigation_screen.dart';

void main() {
  testWidgets('GoLivreur Driver smoke test - démarrage application', (WidgetTester tester) async {
    await tester.pumpWidget(const ProviderScope(child: GoLivreurDriverApp()));
    expect(find.text('GoLivreur Driver'), findsOneWidget);
  });

  testWidgets('DriverMainNavigationScreen - affiche les 3 onglets Stitch', (WidgetTester tester) async {
    await tester.pumpWidget(
      const ProviderScope(
        child: MaterialApp(
          home: DriverMainNavigationScreen(),
        ),
      ),
    );

    // Vérifie les 3 onglets définis dans Stitch Driver
    expect(find.text('Courses'), findsOneWidget);
    expect(find.text('Portefeuille'), findsOneWidget);
    expect(find.text('Gains'), findsOneWidget);
  });
}
