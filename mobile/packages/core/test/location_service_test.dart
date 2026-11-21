import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:golivreur_core/services/gps_location_service.dart';

void main() {
  test(
      'LocationService impose un timeout de 15s max et le message standardise non bloquant',
      () {
    expect(LocationService.maxGpsTimeout, const Duration(seconds: 15));
    expect(
      LocationService.standardUnavailableMessage,
      'Signal GPS indisponible. Veuillez activer la localisation ou saisir votre adresse manuellement.',
    );

    const failure = GpsPositionResult(
      errorMessage: LocationService.standardUnavailableMessage,
      errorType: GpsErrorType.serviceDisabled,
      isServiceDisabled: true,
    );
    expect(failure.isSuccess, isFalse);
    expect(failure.requiresManualFallback, isTrue);
  });

  testWidgets(
      'GpsFallbackBanner affiche le message non bloquant, Reessayer, Ouvrir les parametres et le bouton Plan B',
      (WidgetTester tester) async {
    var retried = false;
    var openedPlanB = false;

    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: GpsFallbackBanner(
            lastResult: const GpsPositionResult(
              errorMessage: LocationService.standardUnavailableMessage,
              errorType: GpsErrorType.timeout,
              isTimeout: true,
            ),
            onRetry: () => retried = true,
            onSelectNeighborhood: () => openedPlanB = true,
            manualActionLabel: 'Choisir un quartier (Plan B)',
            selectedManualLabel: 'Commerce',
          ),
        ),
      ),
    );

    expect(
      find.text(LocationService.standardUnavailableMessage),
      findsOneWidget,
    );
    expect(find.text('Réessayer'), findsOneWidget);
    expect(find.text('Ouvrir les paramètres'), findsOneWidget);
    expect(find.text('Choisir un quartier (Plan B)'), findsOneWidget);
    expect(find.text('Plan B actif : Commerce'), findsOneWidget);

    await tester.tap(find.text('Réessayer'));
    expect(retried, isTrue);

    await tester.tap(find.text('Choisir un quartier (Plan B)'));
    expect(openedPlanB, isTrue);
  });

  testWidgets(
      'NeighborhoodPlanBSheet permet de selectionner un quartier de la liste API en Plan B',
      (WidgetTester tester) async {
    FallbackNeighborhoodOption? selected;
    const sampleNeighborhoods = [
      FallbackNeighborhoodOption(
        id: 10,
        name: 'Commerce',
        cityName: 'Daloa',
        lat: 6.8810,
        lng: -6.4490,
      ),
      FallbackNeighborhoodOption(
        id: 11,
        name: 'Lobia',
        cityName: 'Daloa',
        lat: 6.8720,
        lng: -6.4580,
      ),
    ];

    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: Builder(
            builder: (ctx) => ElevatedButton(
              onPressed: () async {
                selected = await NeighborhoodPlanBSheet.show(
                  ctx,
                  initialNeighborhoods: sampleNeighborhoods,
                  defaultCityName: 'Daloa',
                );
              },
              child: const Text('Ouvrir Plan B'),
            ),
          ),
        ),
      ),
    );

    await tester.tap(find.text('Ouvrir Plan B'));
    await tester.pumpAndSettle();

    expect(find.text('Commerce'), findsOneWidget);
    expect(find.text('Lobia'), findsOneWidget);
    expect(find.text('Ouvrir les paramètres'), findsOneWidget);

    await tester.tap(find.text('Lobia'));
    await tester.pumpAndSettle();

    expect(selected, isNotNull);
    expect(selected!.name, 'Lobia');
    expect(selected!.lat, 6.8720);
  });
}
