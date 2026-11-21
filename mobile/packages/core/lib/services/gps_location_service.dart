import 'dart:async';
import 'dart:math' as math;

import 'package:flutter/foundation.dart' show kIsWeb;
import 'package:flutter/material.dart';
import 'package:geocoding/geocoding.dart' as geocoding;
import 'package:geolocator/geolocator.dart';
import 'package:url_launcher/url_launcher.dart';

import '../config/app_theme.dart';
import 'api_client.dart';

/// Catégorie précise d'erreur GPS pour piloter l'UX (Paramètres, Réessayer, Plan B).
enum GpsErrorType {
  none,
  serviceDisabled,
  permissionDenied,
  permissionDeniedForever,
  timeout,
  signalUnavailable,
}

/// Quartier / Commune récupéré depuis l'API backend pour le Plan B (saisie manuelle).
class FallbackNeighborhoodOption {
  final int? id;
  final String name;
  final String cityName;
  final double? lat;
  final double? lng;
  final double distanceKm;

  const FallbackNeighborhoodOption({
    this.id,
    required this.name,
    this.cityName = '',
    this.lat,
    this.lng,
    this.distanceKm = 2.0,
  });

  factory FallbackNeighborhoodOption.fromJson(
    Map<String, dynamic> json, {
    String defaultCityName = '',
    double? cityCenterLat,
    double? cityCenterLng,
  }) {
    final rawLat = num.tryParse('${json['lat'] ?? ''}')?.toDouble();
    final rawLng = num.tryParse('${json['lng'] ?? ''}')?.toDouble();
    final hasValidCoords = rawLat != null &&
        rawLng != null &&
        (rawLat.abs() > 0.0001 || rawLng.abs() > 0.0001);

    return FallbackNeighborhoodOption(
      id: num.tryParse('${json['id'] ?? ''}')?.toInt(),
      name: (json['name'] ?? '').toString().trim(),
      cityName: (json['city_name'] ?? defaultCityName).toString().trim(),
      lat: hasValidCoords ? rawLat : cityCenterLat,
      lng: hasValidCoords ? rawLng : cityCenterLng,
      distanceKm:
          num.tryParse('${json['distance_km'] ?? 2.0}')?.toDouble() ?? 2.0,
    );
  }
}

/// Résultat structuré d'une lecture GPS locale (100 % gratuit via puce GPS native).
class GpsPositionResult {
  final double? lat;
  final double? lng;
  final String? formattedAddress;
  final String? neighborhoodHint;
  final String? errorMessage;
  final GpsErrorType errorType;
  final bool isServiceDisabled;
  final bool isPermissionDenied;
  final bool isPermissionDeniedForever;
  final bool isTimeout;

  const GpsPositionResult({
    this.lat,
    this.lng,
    this.formattedAddress,
    this.neighborhoodHint,
    this.errorMessage,
    this.errorType = GpsErrorType.none,
    this.isServiceDisabled = false,
    this.isPermissionDenied = false,
    this.isPermissionDeniedForever = false,
    this.isTimeout = false,
  });

  bool get isSuccess => lat != null && lng != null && errorMessage == null;

  /// Vrai dès que la localisation GPS a échoué et que le Plan B (liste des quartiers) doit être proposé.
  bool get requiresManualFallback => !isSuccess;
}

/// Alias centralisé recommandé par l'architecture GoLivreur Core (`LocationService`).
typedef LocationService = GpsLocationService;

/// Service de localisation centralisé GoLivreur Core :
/// - Intercepte toutes les exceptions liées au GPS (désactivé, permission refusée, timeout 15s, signal absent).
/// - Ne bloque jamais l'utilisateur et bascule vers le Plan B (liste des quartiers/communes de l'API).
class GpsLocationService {
  GpsLocationService._();

  /// Délai d'attente maximal imposé (15 secondes) avant bascule automatique sur le Plan B.
  static const Duration maxGpsTimeout = Duration(seconds: 15);

  /// Message standardisé non bloquant affiché sur les 3 applications.
  static const String standardUnavailableMessage =
      'Signal GPS indisponible. Veuillez activer la localisation ou saisir votre adresse manuellement.';

  /// En environnement `flutter test` (binding de test sans canal natif Geolocator),
  /// le MethodChannel ne répond jamais si aucun mock n'est enregistré, ce qui laisserait
  /// des timers FakeAsync en attente à la fin du test.
  static bool get _isRunningInFlutterTest {
    if (kIsWeb) return false;
    final bindingName = WidgetsBinding.instance.runtimeType.toString();
    return bindingName.contains('TestWidgetsFlutterBinding') ||
        bindingName.contains('AutomatedTestWidgetsFlutterBinding') ||
        bindingName.contains('LiveTestWidgetsFlutterBinding');
  }

  /// Lit silencieusement la position GPS uniquement si le service GPS est actif
  /// ET l'autorisation a déjà été accordée (n'affiche aucune demande de permission).
  static Future<GpsPositionResult?> obtenirPositionSilencieuse({
    Duration timeout = const Duration(seconds: 8),
  }) async {
    if (_isRunningInFlutterTest) return null;
    try {
      final serviceEnabled = await Geolocator.isLocationServiceEnabled()
          .timeout(const Duration(seconds: 3), onTimeout: () => false);
      if (!serviceEnabled) return null;

      final permission = await Geolocator.checkPermission().timeout(
        const Duration(seconds: 3),
        onTimeout: () => LocationPermission.denied,
      );
      if (permission != LocationPermission.always &&
          permission != LocationPermission.whileInUse) {
        return null;
      }

      Position? pos;
      try {
        pos = await Geolocator.getCurrentPosition(
          desiredAccuracy: LocationAccuracy.medium,
          timeLimit: timeout,
        ).timeout(timeout);
      } catch (_) {
        try {
          pos = await Geolocator.getLastKnownPosition()
              .timeout(const Duration(seconds: 2));
        } catch (_) {
          pos = null;
        }
      }

      if (pos == null) return null;

      return GpsPositionResult(lat: pos.latitude, lng: pos.longitude);
    } catch (_) {
      return null;
    }
  }

  /// Récupère la position GPS actuelle avec un timeout strict de 15 secondes maximum.
  /// Intercepte toutes les exceptions et retourne un [GpsPositionResult] prêt pour le Plan B.
  static Future<GpsPositionResult> obtenirPositionActuelle({
    bool withAddressLookup = true,
    Duration timeout = maxGpsTimeout,
  }) async {
    if (_isRunningInFlutterTest) {
      return const GpsPositionResult(
        errorMessage: standardUnavailableMessage,
        errorType: GpsErrorType.signalUnavailable,
      );
    }

    final effectiveTimeout =
        timeout > maxGpsTimeout ? maxGpsTimeout : timeout;

    try {
      return await _acquirePositionInternal(
        withAddressLookup: withAddressLookup,
        timeout: effectiveTimeout,
      ).timeout(
        effectiveTimeout,
        onTimeout: () async {
          // Dernier essai rapide sur la dernière position connue en cache OS avant Plan B
          try {
            final lastKnown = await Geolocator.getLastKnownPosition()
                .timeout(const Duration(seconds: 1));
            if (lastKnown != null) {
              return GpsPositionResult(
                lat: lastKnown.latitude,
                lng: lastKnown.longitude,
              );
            }
          } catch (_) {}

          return const GpsPositionResult(
            errorMessage: standardUnavailableMessage,
            errorType: GpsErrorType.timeout,
            isTimeout: true,
          );
        },
      );
    } on TimeoutException {
      return const GpsPositionResult(
        errorMessage: standardUnavailableMessage,
        errorType: GpsErrorType.timeout,
        isTimeout: true,
      );
    } on LocationServiceDisabledException {
      return const GpsPositionResult(
        errorMessage: standardUnavailableMessage,
        errorType: GpsErrorType.serviceDisabled,
        isServiceDisabled: true,
      );
    } on PermissionDefinitionsNotFoundException {
      return const GpsPositionResult(
        errorMessage: standardUnavailableMessage,
        errorType: GpsErrorType.permissionDenied,
        isPermissionDenied: true,
      );
    } catch (_) {
      return const GpsPositionResult(
        errorMessage: standardUnavailableMessage,
        errorType: GpsErrorType.signalUnavailable,
      );
    }
  }

  static Future<GpsPositionResult> _acquirePositionInternal({
    required bool withAddressLookup,
    required Duration timeout,
  }) async {
    final serviceEnabled = await Geolocator.isLocationServiceEnabled()
        .timeout(const Duration(seconds: 3), onTimeout: () => false);
    if (!serviceEnabled) {
      return const GpsPositionResult(
        errorMessage: standardUnavailableMessage,
        errorType: GpsErrorType.serviceDisabled,
        isServiceDisabled: true,
      );
    }

    var permission = await Geolocator.checkPermission().timeout(
      const Duration(seconds: 3),
      onTimeout: () => LocationPermission.denied,
    );
    if (permission == LocationPermission.denied) {
      try {
        permission = await Geolocator.requestPermission().timeout(
          const Duration(seconds: 10),
          onTimeout: () => LocationPermission.denied,
        );
      } catch (_) {
        permission = LocationPermission.denied;
      }
    }

    if (permission == LocationPermission.denied) {
      return const GpsPositionResult(
        errorMessage: standardUnavailableMessage,
        errorType: GpsErrorType.permissionDenied,
        isPermissionDenied: true,
      );
    }

    if (permission == LocationPermission.deniedForever) {
      return const GpsPositionResult(
        errorMessage: standardUnavailableMessage,
        errorType: GpsErrorType.permissionDeniedForever,
        isPermissionDenied: true,
        isPermissionDeniedForever: true,
      );
    }

    Position? pos;
    var timedOut = false;
    final primaryLimit = Duration(
      seconds: math.max(4, timeout.inSeconds - 3),
    );

    try {
      pos = await Geolocator.getCurrentPosition(
        desiredAccuracy: LocationAccuracy.high,
        timeLimit: primaryLimit,
      ).timeout(primaryLimit);
    } on TimeoutException {
      timedOut = true;
    } catch (_) {}

    // Fallback rapide précision moyenne/basse (antennes/Wi-Fi) si la puce satellite tarde
    if (pos == null) {
      try {
        pos = await Geolocator.getCurrentPosition(
          desiredAccuracy: LocationAccuracy.low,
          timeLimit: const Duration(seconds: 3),
        ).timeout(const Duration(seconds: 3));
      } catch (_) {}
    }

    // Fallback sur la dernière position connue du système
    if (pos == null) {
      try {
        pos = await Geolocator.getLastKnownPosition()
            .timeout(const Duration(seconds: 2));
      } catch (_) {}
    }

    if (pos == null) {
      return GpsPositionResult(
        errorMessage: standardUnavailableMessage,
        errorType:
            timedOut ? GpsErrorType.timeout : GpsErrorType.signalUnavailable,
        isTimeout: timedOut,
      );
    }

    String? address;
    String? neighborhood;
    if (withAddressLookup) {
      try {
        final resolved = await resoudreAdresseDepuisCoords(
          pos.latitude,
          pos.longitude,
        ).timeout(
          const Duration(seconds: 3),
          onTimeout: () => (address: null, neighborhood: null),
        );
        address = resolved.address;
        neighborhood = resolved.neighborhood;
      } catch (_) {}
    }

    return GpsPositionResult(
      lat: pos.latitude,
      lng: pos.longitude,
      formattedAddress: address,
      neighborhoodHint: neighborhood,
    );
  }

  /// Convertit (lat, lng) en adresse lisible via le géocodeur natif Android/iOS (gratuit).
  static Future<({String? address, String? neighborhood})>
      resoudreAdresseDepuisCoords(double lat, double lng) async {
    if (kIsWeb) return (address: null, neighborhood: null);
    try {
      final placemarks = await geocoding
          .placemarkFromCoordinates(lat, lng)
          .timeout(const Duration(seconds: 3));
      if (placemarks.isEmpty) return (address: null, neighborhood: null);
      final p = placemarks.first;

      final neighborhood = _firstNonEmpty([
        p.subLocality,
        p.subAdministrativeArea,
        p.locality,
      ]);

      final parts = <String>[
        if ((p.street ?? '').trim().isNotEmpty &&
            !(p.street ?? '').contains('+'))
          p.street!.trim(),
        if ((p.subLocality ?? '').trim().isNotEmpty) p.subLocality!.trim(),
        if ((p.locality ?? '').trim().isNotEmpty) p.locality!.trim(),
      ].toSet().toList();

      return (
        address: parts.isNotEmpty ? parts.join(', ') : null,
        neighborhood: neighborhood,
      );
    } catch (_) {
      return (address: null, neighborhood: null);
    }
  }

  /// Redirige l'utilisateur en 1 clic vers les paramètres de localisation du téléphone.
  static Future<bool> ouvrirParametresLocalisation() async {
    try {
      return await Geolocator.openLocationSettings();
    } catch (_) {
      return false;
    }
  }

  /// Redirige l'utilisateur vers les permissions de l'application.
  static Future<bool> ouvrirParametresApp() async {
    try {
      return await Geolocator.openAppSettings();
    } catch (_) {
      return false;
    }
  }

  /// Ouvre les paramètres appropriés selon le type d'erreur GPS (localisation physique ou permissions app).
  static Future<bool> ouvrirParametresPourResultat(
    GpsPositionResult? result,
  ) async {
    if (result != null && result.isPermissionDeniedForever) {
      final openedApp = await ouvrirParametresApp();
      if (openedApp) return true;
    }
    final openedLoc = await ouvrirParametresLocalisation();
    if (openedLoc) return true;
    return ouvrirParametresApp();
  }

  /// Récupère dynamiquement la liste des quartiers/communes depuis l'API backend (`/client/city-status`)
  /// pour alimenter le Plan B sur les 3 applications (Client, Driver, Business).
  static Future<
      ({
        String cityName,
        double? centerLat,
        double? centerLng,
        List<FallbackNeighborhoodOption> neighborhoods,
      })> chargerQuartiersPlanB(
    ApiClient api, {
    int? cityId,
  }) async {
    try {
      final res = await api.get(
        '/client/city-status',
        queryParameters: cityId != null ? {'city_id': cityId} : null,
      );
      if (res.statusCode == 200 &&
          res.data is Map &&
          res.data['succes'] == true &&
          res.data['donnees'] is Map) {
        final d = Map<String, dynamic>.from(res.data['donnees'] as Map);
        final cityName = (d['city_name'] ?? '').toString().trim();
        final centerLat = num.tryParse('${d['center_lat'] ?? ''}')?.toDouble();
        final centerLng = num.tryParse('${d['center_lng'] ?? ''}')?.toDouble();
        final rawList = d['neighborhoods'] is List
            ? (d['neighborhoods'] as List)
            : const <dynamic>[];

        final options = rawList
            .whereType<Map>()
            .map(
              (item) => FallbackNeighborhoodOption.fromJson(
                Map<String, dynamic>.from(item),
                defaultCityName: cityName,
                cityCenterLat: centerLat,
                cityCenterLng: centerLng,
              ),
            )
            .where((q) => q.name.isNotEmpty)
            .toList();

        return (
          cityName: cityName,
          centerLat: centerLat,
          centerLng: centerLng,
          neighborhoods: options,
        );
      }
    } catch (_) {}

    return (
      cityName: '',
      centerLat: null,
      centerLng: null,
      neighborhoods: const <FallbackNeighborhoodOption>[],
    );
  }

  /// Affiche un SnackBar non bloquant avec le message officiel et un bouton d'action rapide
  /// (« Ouvrir les paramètres » si GPS désactivé/bloqué, sinon « Choisir quartier » ou « Réessayer »).
  static void afficherAlerteNonBloquante(
    BuildContext context, {
    GpsPositionResult? result,
    VoidCallback? onRetry,
    VoidCallback? onManualFallback,
  }) {
    if (!context.mounted) return;
    final messenger = ScaffoldMessenger.maybeOf(context);
    if (messenger == null) return;

    final needsSettings = result != null &&
        (result.isServiceDisabled ||
            result.isPermissionDeniedForever ||
            result.isPermissionDenied);

    SnackBarAction? action;
    if (needsSettings) {
      action = SnackBarAction(
        label: 'Ouvrir les paramètres',
        textColor: Colors.amberAccent,
        onPressed: () {
          ouvrirParametresPourResultat(result);
        },
      );
    } else if (onManualFallback != null) {
      action = SnackBarAction(
        label: 'Choisir quartier',
        textColor: Colors.amberAccent,
        onPressed: onManualFallback,
      );
    } else if (onRetry != null) {
      action = SnackBarAction(
        label: 'Réessayer',
        textColor: Colors.amberAccent,
        onPressed: onRetry,
      );
    }

    messenger
      ..hideCurrentSnackBar()
      ..showSnackBar(
        SnackBar(
          content: const Text(standardUnavailableMessage),
          backgroundColor: const Color(0xFF1F2937),
          duration: const Duration(seconds: 6),
          action: action,
        ),
      );
  }

  /// Distance Haversine (en km) entre deux coordonnées GPS.
  static double distanceKm(
    double lat1,
    double lng1,
    double lat2,
    double lng2,
  ) {
    const earthRadiusKm = 6371.0;
    final dLat = (lat2 - lat1) * math.pi / 180.0;
    final dLng = (lng2 - lng1) * math.pi / 180.0;
    final a = math.sin(dLat / 2) * math.sin(dLat / 2) +
        math.cos(lat1 * math.pi / 180.0) *
            math.cos(lat2 * math.pi / 180.0) *
            math.sin(dLng / 2) *
            math.sin(dLng / 2);
    final c = 2 * math.atan2(math.sqrt(a), math.sqrt(1 - a));
    return earthRadiusKm * c;
  }

  /// Ouvre gratuitement l'application de navigation GPS du téléphone (Google Maps / Waze).
  static Future<bool> ouvrirNavigationExterne(
    double lat,
    double lng, {
    String? label,
  }) async {
    final uri = Uri.parse(
      'https://www.google.com/maps/dir/?api=1&destination=$lat,$lng&travelmode=driving',
    );
    try {
      return await launchUrl(uri, mode: LaunchMode.externalApplication);
    } catch (_) {
      return false;
    }
  }

  static String? _firstNonEmpty(List<String?> values) {
    for (final v in values) {
      if (v != null && v.trim().isNotEmpty) return v.trim();
    }
    return null;
  }
}

/// Bandeau d'alerte GPS non bloquant réutilisable sur les 3 applications (Client, Driver, Business) :
/// - Affiche le message standardisé
/// - Bouton « Réessayer »
/// - Bouton « Ouvrir les paramètres » (via Geolocator.openLocationSettings / openAppSettings)
/// - Bouton / Sélecteur Plan B (liste des quartiers de la ville)
class GpsFallbackBanner extends StatelessWidget {
  final GpsPositionResult? lastResult;
  final bool isLoading;
  final VoidCallback onRetry;
  final VoidCallback? onSelectNeighborhood;
  final String manualActionLabel;
  final String? selectedManualLabel;

  const GpsFallbackBanner({
    super.key,
    this.lastResult,
    this.isLoading = false,
    required this.onRetry,
    this.onSelectNeighborhood,
    this.manualActionLabel = 'Choisir un quartier (Plan B)',
    this.selectedManualLabel,
  });

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: const Color(0xFFFFF8EB),
        borderRadius: BorderRadius.circular(12),
        border: Border.all(
          color: GoLivreurTheme.primaire.withValues(alpha: 0.55),
        ),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Icon(
                Icons.location_disabled_outlined,
                color: Color(0xFFB45309),
                size: 20,
              ),
              SizedBox(width: 8),
              Expanded(
                child: Text(
                  GpsLocationService.standardUnavailableMessage,
                  style: TextStyle(
                    fontSize: 12.5,
                    fontWeight: FontWeight.w600,
                    color: Color(0xFF78350F),
                    height: 1.3,
                  ),
                ),
              ),
            ],
          ),
          if (selectedManualLabel != null &&
              selectedManualLabel!.trim().isNotEmpty) ...[
            const SizedBox(height: 8),
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
              decoration: BoxDecoration(
                color: GoLivreurTheme.secondaire.withValues(alpha: 0.12),
                borderRadius: BorderRadius.circular(8),
              ),
              child: Row(
                children: [
                  const Icon(
                    Icons.check_circle,
                    size: 16,
                    color: GoLivreurTheme.secondaire,
                  ),
                  const SizedBox(width: 6),
                  Expanded(
                    child: Text(
                      'Plan B actif : $selectedManualLabel',
                      style: const TextStyle(
                        fontSize: 12,
                        fontWeight: FontWeight.bold,
                        color: GoLivreurTheme.secondaire,
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ],
          const SizedBox(height: 10),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              OutlinedButton.icon(
                style: OutlinedButton.styleFrom(
                  foregroundColor: GoLivreurTheme.textePrincipal,
                  side: const BorderSide(color: Color(0xFFD97706)),
                  padding:
                      const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                  visualDensity: VisualDensity.compact,
                ),
                onPressed: isLoading ? null : onRetry,
                icon: isLoading
                    ? const SizedBox(
                        width: 13,
                        height: 13,
                        child: CircularProgressIndicator(strokeWidth: 2),
                      )
                    : const Icon(Icons.refresh, size: 16),
                label: const Text(
                  'Réessayer',
                  style: TextStyle(fontSize: 12, fontWeight: FontWeight.bold),
                ),
              ),
              OutlinedButton.icon(
                style: OutlinedButton.styleFrom(
                  foregroundColor: const Color(0xFFB45309),
                  side: const BorderSide(color: Color(0xFFD97706)),
                  padding:
                      const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                  visualDensity: VisualDensity.compact,
                ),
                onPressed: () {
                  GpsLocationService.ouvrirParametresPourResultat(lastResult);
                },
                icon: const Icon(Icons.settings_outlined, size: 16),
                label: const Text(
                  'Ouvrir les paramètres',
                  style: TextStyle(fontSize: 12, fontWeight: FontWeight.bold),
                ),
              ),
              if (onSelectNeighborhood != null)
                ElevatedButton.icon(
                  style: ElevatedButton.styleFrom(
                    backgroundColor: GoLivreurTheme.secondaire,
                    foregroundColor: Colors.white,
                    padding:
                        const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                    visualDensity: VisualDensity.compact,
                  ),
                  onPressed: onSelectNeighborhood,
                  icon: const Icon(Icons.location_city_outlined, size: 16),
                  label: Text(
                    manualActionLabel,
                    style: const TextStyle(
                      fontSize: 12,
                      fontWeight: FontWeight.bold,
                    ),
                  ),
                ),
            ],
          ),
        ],
      ),
    );
  }
}

/// Feuille modale universelle « Plan B — Sélection manuelle du quartier / commune »
/// utilisable sur les 3 applications (Client, Driver, Business) lorsque le GPS échoue.
class NeighborhoodPlanBSheet extends StatefulWidget {
  final String title;
  final String subtitle;
  final List<FallbackNeighborhoodOption> initialNeighborhoods;
  final ApiClient? apiClient;
  final int? cityId;
  final String? defaultCityName;
  final double? fallbackLat;
  final double? fallbackLng;
  final Future<void> Function()? onRetryGps;

  const NeighborhoodPlanBSheet({
    super.key,
    this.title = 'Sélectionner votre quartier (Plan B)',
    this.subtitle =
        'Signal GPS indisponible. Choisissez votre quartier dans la liste ou saisissez votre adresse manuellement.',
    this.initialNeighborhoods = const [],
    this.apiClient,
    this.cityId,
    this.defaultCityName,
    this.fallbackLat,
    this.fallbackLng,
    this.onRetryGps,
  });

  static Future<FallbackNeighborhoodOption?> show(
    BuildContext context, {
    String title = 'Sélectionner votre quartier (Plan B)',
    String subtitle =
        'Signal GPS indisponible. Choisissez votre quartier dans la liste ou saisissez votre adresse manuellement.',
    List<FallbackNeighborhoodOption> initialNeighborhoods = const [],
    ApiClient? apiClient,
    int? cityId,
    String? defaultCityName,
    double? fallbackLat,
    double? fallbackLng,
    Future<void> Function()? onRetryGps,
  }) {
    return showModalBottomSheet<FallbackNeighborhoodOption>(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.white,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
      builder: (_) => NeighborhoodPlanBSheet(
        title: title,
        subtitle: subtitle,
        initialNeighborhoods: initialNeighborhoods,
        apiClient: apiClient,
        cityId: cityId,
        defaultCityName: defaultCityName,
        fallbackLat: fallbackLat,
        fallbackLng: fallbackLng,
        onRetryGps: onRetryGps,
      ),
    );
  }

  @override
  State<NeighborhoodPlanBSheet> createState() => _NeighborhoodPlanBSheetState();
}

class _NeighborhoodPlanBSheetState extends State<NeighborhoodPlanBSheet> {
  final TextEditingController _searchCtrl = TextEditingController();
  final TextEditingController _manualCtrl = TextEditingController();
  late List<FallbackNeighborhoodOption> _neighborhoods;
  String _cityName = '';
  double? _centerLat;
  double? _centerLng;
  bool _loading = false;
  bool _retryingGps = false;

  @override
  void initState() {
    super.initState();
    _neighborhoods = List.of(widget.initialNeighborhoods);
    _cityName = widget.defaultCityName ?? '';
    _centerLat = widget.fallbackLat;
    _centerLng = widget.fallbackLng;
    if (_neighborhoods.isEmpty && widget.apiClient != null) {
      _fetchFromApi();
    }
  }

  @override
  void dispose() {
    _searchCtrl.dispose();
    _manualCtrl.dispose();
    super.dispose();
  }

  Future<void> _fetchFromApi() async {
    if (widget.apiClient == null) return;
    setState(() => _loading = true);
    final loaded = await GpsLocationService.chargerQuartiersPlanB(
      widget.apiClient!,
      cityId: widget.cityId,
    );
    if (!mounted) return;
    setState(() {
      _loading = false;
      if (loaded.cityName.isNotEmpty) _cityName = loaded.cityName;
      if (loaded.centerLat != null) _centerLat = loaded.centerLat;
      if (loaded.centerLng != null) _centerLng = loaded.centerLng;
      if (loaded.neighborhoods.isNotEmpty) {
        _neighborhoods = loaded.neighborhoods;
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    final query = _searchCtrl.text.trim().toLowerCase();
    final filtered = query.isEmpty
        ? _neighborhoods
        : _neighborhoods
            .where((q) => q.name.toLowerCase().contains(query))
            .toList();

    return SafeArea(
      child: Padding(
        padding: EdgeInsets.only(
          left: 18,
          right: 18,
          top: 16,
          bottom: MediaQuery.of(context).viewInsets.bottom + 16,
        ),
        child: SizedBox(
          height: MediaQuery.of(context).size.height * 0.72,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Row(
                children: [
                  const Icon(
                    Icons.location_city,
                    color: GoLivreurTheme.primaire,
                  ),
                  const SizedBox(width: 8),
                  Expanded(
                    child: Text(
                      widget.title,
                      style: const TextStyle(
                        fontSize: 17,
                        fontWeight: FontWeight.bold,
                      ),
                    ),
                  ),
                  IconButton(
                    icon: const Icon(Icons.close),
                    onPressed: () => Navigator.of(context).pop(),
                  ),
                ],
              ),
              Text(
                widget.subtitle,
                style: const TextStyle(
                  fontSize: 12.5,
                  color: GoLivreurTheme.texteSecondaire,
                ),
              ),
              const SizedBox(height: 10),
              Wrap(
                spacing: 8,
                runSpacing: 8,
                children: [
                  OutlinedButton.icon(
                    onPressed: () {
                      GpsLocationService.ouvrirParametresLocalisation();
                    },
                    icon: const Icon(Icons.settings_outlined, size: 16),
                    label: const Text(
                      'Ouvrir les paramètres',
                      style: TextStyle(fontSize: 12),
                    ),
                  ),
                  if (widget.onRetryGps != null)
                    OutlinedButton.icon(
                      onPressed: _retryingGps
                          ? null
                          : () async {
                              setState(() => _retryingGps = true);
                              Navigator.of(context).pop();
                              await widget.onRetryGps!();
                            },
                      icon: const Icon(Icons.my_location, size: 16),
                      label: const Text(
                        'Réessayer le GPS',
                        style: TextStyle(fontSize: 12),
                      ),
                    ),
                ],
              ),
              const SizedBox(height: 12),
              TextField(
                controller: _searchCtrl,
                onChanged: (_) => setState(() {}),
                decoration: InputDecoration(
                  hintText: _cityName.isNotEmpty
                      ? 'Rechercher un quartier de $_cityName...'
                      : 'Rechercher un quartier ou une commune...',
                  prefixIcon: const Icon(Icons.search),
                  isDense: true,
                  border: OutlineInputBorder(
                    borderRadius: BorderRadius.circular(12),
                  ),
                ),
              ),
              const SizedBox(height: 10),
              Expanded(
                child: _loading
                    ? const Center(child: CircularProgressIndicator())
                    : filtered.isEmpty
                        ? Center(
                            child: Text(
                              _neighborhoods.isEmpty
                                  ? 'Aucun quartier pré-enregistré. Utilisez la saisie manuelle ci-dessous.'
                                  : 'Aucun quartier trouvé pour « ${_searchCtrl.text.trim()} ».',
                              textAlign: TextAlign.center,
                              style: const TextStyle(
                                color: GoLivreurTheme.texteSecondaire,
                              ),
                            ),
                          )
                        : ListView.separated(
                            itemCount: filtered.length,
                            separatorBuilder: (_, __) =>
                                const Divider(height: 1),
                            itemBuilder: (ctx, index) {
                              final item = filtered[index];
                              return ListTile(
                                leading: const Icon(
                                  Icons.place_outlined,
                                  color: GoLivreurTheme.secondaire,
                                ),
                                title: Text(
                                  item.name,
                                  style: const TextStyle(
                                    fontWeight: FontWeight.w600,
                                  ),
                                ),
                                subtitle: item.cityName.isNotEmpty
                                    ? Text(item.cityName)
                                    : null,
                                trailing: const Icon(
                                  Icons.chevron_right,
                                  color: GoLivreurTheme.primaire,
                                ),
                                onTap: () => Navigator.of(context).pop(item),
                              );
                            },
                          ),
              ),
              const Divider(height: 16),
              Row(
                children: [
                  Expanded(
                    child: TextField(
                      controller: _manualCtrl,
                      decoration: InputDecoration(
                        hintText: 'Ou saisir un quartier / repère manuellement',
                        isDense: true,
                        border: OutlineInputBorder(
                          borderRadius: BorderRadius.circular(10),
                        ),
                      ),
                    ),
                  ),
                  const SizedBox(width: 8),
                  ElevatedButton(
                    style: ElevatedButton.styleFrom(
                      backgroundColor: GoLivreurTheme.primaire,
                      foregroundColor: Colors.white,
                    ),
                    onPressed: () {
                      final text = _manualCtrl.text.trim().isNotEmpty
                          ? _manualCtrl.text.trim()
                          : _searchCtrl.text.trim();
                      if (text.isEmpty) return;
                      Navigator.of(context).pop(
                        FallbackNeighborhoodOption(
                          name: text,
                          cityName: _cityName,
                          lat: _centerLat ?? 6.8770,
                          lng: _centerLng ?? -6.4502,
                        ),
                      );
                    },
                    child: const Text('Valider'),
                  ),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }
}
