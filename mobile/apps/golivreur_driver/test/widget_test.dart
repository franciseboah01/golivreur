import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:golivreur_core/widgets/golivreur_widgets.dart';
import 'package:golivreur_driver/main.dart';
import 'package:golivreur_driver/features/navigation/screens/driver_main_navigation_screen.dart';

void main() {
  testWidgets('GoLivreur Driver - affiche AnimatedSplashScreen au démarrage',
      (WidgetTester tester) async {
    await tester.pumpWidget(const ProviderScope(child: GoLivreurDriverApp(showSplash: true)));
    expect(find.byType(AnimatedSplashScreen), findsOneWidget);
    expect(find.text('DRIVER'), findsOneWidget);
  });

  testWidgets('GoLivreur Driver smoke test - démarrage application',
      (WidgetTester tester) async {
    await tester.pumpWidget(const ProviderScope(child: GoLivreurDriverApp(showSplash: false)));
    expect(find.text('GoLivreur Driver'), findsOneWidget);
  });

  testWidgets('DriverMainNavigationScreen - affiche les 4 onglets avec Profil',
      (WidgetTester tester) async {
    await tester.pumpWidget(
      const ProviderScope(
        child: MaterialApp(
          home: DriverMainNavigationScreen(),
        ),
      ),
    );

    // Vérifie les 4 onglets : Courses, Portefeuille, Gains et Profil
    expect(find.text('Courses'), findsOneWidget);
    expect(find.text('Portefeuille'), findsOneWidget);
    expect(find.text('Gains'), findsOneWidget);
    expect(find.text('Profil'), findsOneWidget);
  });
}
