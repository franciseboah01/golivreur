import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:golivreur_core/config/app_theme.dart';

void main() {
  group('GoLivreurTheme', () {
    test('definit les couleurs officielles #F4A623 et #1B5E20', () {
      expect(GoLivreurTheme.primaire, const Color(0xFFF4A623));
      expect(GoLivreurTheme.secondaire, const Color(0xFF1B5E20));

      final theme = GoLivreurTheme.theme;
      expect(theme.colorScheme.primary, const Color(0xFFF4A623));
      expect(theme.colorScheme.secondary, const Color(0xFF1B5E20));
    });

    test('utilise la police Inter sur le theme et tous les styles de texte', () {
      final theme = GoLivreurTheme.theme;
      final styles = [
        theme.textTheme.headlineLarge,
        theme.textTheme.headlineMedium,
        theme.textTheme.titleLarge,
        theme.textTheme.titleMedium,
        theme.textTheme.bodyLarge,
        theme.textTheme.bodyMedium,
        theme.textTheme.bodySmall,
        theme.textTheme.labelLarge,
        theme.appBarTheme.titleTextStyle,
        theme.snackBarTheme.contentTextStyle,
      ];

      for (final style in styles) {
        expect(style, isNotNull);
        expect(style?.fontFamily, 'Inter');
      }
    });

    testWidgets('applique le theme GoLivreur dans MaterialApp', (WidgetTester tester) async {
      ThemeData? resolved;

      await tester.pumpWidget(
        MaterialApp(
          theme: GoLivreurTheme.theme,
          home: Builder(
            builder: (context) {
              resolved = Theme.of(context);
              return const Scaffold(body: Text('GoLivreur'));
            },
          ),
        ),
      );

      expect(resolved?.colorScheme.primary, const Color(0xFFF4A623));
      expect(resolved?.colorScheme.secondary, const Color(0xFF1B5E20));
      expect(resolved?.textTheme.bodyLarge?.fontFamily, 'Inter');
    });
  });
}

