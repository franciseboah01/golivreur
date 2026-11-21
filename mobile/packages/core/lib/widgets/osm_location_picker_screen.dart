import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:latlong2/latlong.dart';

import '../config/app_theme.dart';
import '../services/gps_location_service.dart';

/// Point GPS confirmé par l'utilisateur sur la carte OpenStreetMap.
class PickedLocation {
  final double lat;
  final double lng;
  final String? addressLabel;
  final String? neighborhoodHint;

  const PickedLocation({
    required this.lat,
    required this.lng,
    this.addressLabel,
    this.neighborhoodHint,
  });
}

/// Sélecteur d'emplacement interactif 100 % gratuit basé sur OpenStreetMap (`flutter_map`)
/// et la puce GPS du téléphone (`GpsLocationService`).
class OsmLocationPickerScreen extends StatefulWidget {
  final double initialLat;
  final double initialLng;
  final String title;
  final String confirmLabel;

  const OsmLocationPickerScreen({
    super.key,
    required this.initialLat,
    required this.initialLng,
    this.title = 'Choisir sur la carte',
    this.confirmLabel = 'Confirmer cet emplacement',
  });

  static Future<PickedLocation?> pick(
    BuildContext context, {
    required double initialLat,
    required double initialLng,
    String title = 'Choisir sur la carte',
    String confirmLabel = 'Confirmer cet emplacement',
  }) {
    return Navigator.of(context).push<PickedLocation>(
      MaterialPageRoute(
        builder: (_) => OsmLocationPickerScreen(
          initialLat: initialLat,
          initialLng: initialLng,
          title: title,
          confirmLabel: confirmLabel,
        ),
      ),
    );
  }

  @override
  State<OsmLocationPickerScreen> createState() =>
      _OsmLocationPickerScreenState();
}

class _OsmLocationPickerScreenState extends State<OsmLocationPickerScreen> {
  final MapController _mapController = MapController();
  late LatLng _selected;
  bool _locatingGps = false;
  bool _resolvingAddress = false;
  String? _addressLabel;
  String? _neighborhoodHint;
  Timer? _debounceTimer;

  @override
  void initState() {
    super.initState();
    _selected = LatLng(widget.initialLat, widget.initialLng);
    _scheduleReverseGeocode(_selected);
  }

  @override
  void dispose() {
    _debounceTimer?.cancel();
    super.dispose();
  }

  void _scheduleReverseGeocode(LatLng point) {
    _debounceTimer?.cancel();
    _debounceTimer = Timer(const Duration(milliseconds: 450), () async {
      if (!mounted) return;
      setState(() => _resolvingAddress = true);
      final resolved = await GpsLocationService.resoudreAdresseDepuisCoords(
        point.latitude,
        point.longitude,
      );
      if (!mounted) return;
      setState(() {
        _resolvingAddress = false;
        _addressLabel = resolved.address;
        _neighborhoodHint = resolved.neighborhood;
      });
    });
  }

  Future<void> _locateMe() async {
    setState(() => _locatingGps = true);
    final res = await LocationService.obtenirPositionActuelle(
      withAddressLookup: true,
    );
    if (!mounted) return;
    setState(() => _locatingGps = false);

    if (res.isSuccess) {
      final target = LatLng(res.lat!, res.lng!);
      setState(() {
        _selected = target;
        _addressLabel = res.formattedAddress ?? _addressLabel;
        _neighborhoodHint = res.neighborhoodHint ?? _neighborhoodHint;
      });
      _mapController.move(target, 16.5);
    } else {
      LocationService.afficherAlerteNonBloquante(
        context,
        result: res,
        onRetry: _locateMe,
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Text(widget.title),
        backgroundColor: Colors.white,
        elevation: 0.5,
      ),
      body: Stack(
        children: [
          FlutterMap(
            mapController: _mapController,
            options: MapOptions(
              initialCenter: _selected,
              initialZoom: 15.5,
              onTap: (_, point) {
                setState(() => _selected = point);
                _mapController.move(point, _mapController.camera.zoom);
                _scheduleReverseGeocode(point);
              },
              onPositionChanged: (camera, hasGesture) {
                if (hasGesture) {
                  setState(() => _selected = camera.center);
                  _scheduleReverseGeocode(camera.center);
                }
              },
            ),
            children: [
              TileLayer(
                urlTemplate: 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
                userAgentPackageName: 'ci.golivreur.app',
              ),
            ],
          ),

          // Épingle centrale fixe (style Glovo/Yango : déplacez la carte ou touchez un point)
          const IgnorePointer(
            child: Center(
              child: Padding(
                padding: EdgeInsets.only(bottom: 36),
                child: Icon(
                  Icons.location_on,
                  size: 46,
                  color: GoLivreurTheme.primaire,
                  shadows: [
                    Shadow(
                      blurRadius: 8,
                      color: Colors.black38,
                      offset: Offset(0, 3),
                    ),
                  ],
                ),
              ),
            ),
          ),

          // Bandeau d'aide en haut
          Positioned(
            top: 12,
            left: 16,
            right: 16,
            child: Container(
              padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
              decoration: BoxDecoration(
                color: Colors.white.withValues(alpha: 0.95),
                borderRadius: BorderRadius.circular(12),
                boxShadow: const [
                  BoxShadow(blurRadius: 8, color: Colors.black12),
                ],
              ),
              child: const Row(
                children: [
                  Icon(Icons.touch_app_outlined,
                      size: 18, color: GoLivreurTheme.primaire),
                  SizedBox(width: 8),
                  Expanded(
                    child: Text(
                      'Déplacez la carte ou appuyez sur « Ma position » pour placer le repère exactement.',
                      style: TextStyle(fontSize: 12, fontWeight: FontWeight.w500),
                    ),
                  ),
                ],
              ),
            ),
          ),

          // Bouton flottant Ma position GPS
          Positioned(
            right: 16,
            bottom: 196,
            child: FloatingActionButton.extended(
              heroTag: 'osm_locate_me',
              backgroundColor: Colors.white,
              foregroundColor: GoLivreurTheme.secondaire,
              onPressed: _locatingGps ? null : _locateMe,
              icon: _locatingGps
                  ? const SizedBox(
                      width: 18,
                      height: 18,
                      child: CircularProgressIndicator(
                        strokeWidth: 2,
                        color: GoLivreurTheme.secondaire,
                      ),
                    )
                  : const Icon(Icons.my_location),
              label: const Text(
                'Ma position',
                style: TextStyle(fontWeight: FontWeight.w700),
              ),
            ),
          ),

          // Panneau de confirmation en bas
          Positioned(
            left: 0,
            right: 0,
            bottom: 0,
            child: Container(
              padding: const EdgeInsets.fromLTRB(20, 16, 20, 22),
              decoration: const BoxDecoration(
                color: Colors.white,
                borderRadius: BorderRadius.vertical(top: Radius.circular(22)),
                boxShadow: [
                  BoxShadow(blurRadius: 16, color: Colors.black12),
                ],
              ),
              child: SafeArea(
                top: false,
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Row(
                      children: [
                        Container(
                          padding: const EdgeInsets.all(8),
                          decoration: BoxDecoration(
                            color: GoLivreurTheme.primaire.withValues(alpha: 0.14),
                            borderRadius: BorderRadius.circular(10),
                          ),
                          child: const Icon(
                            Icons.gps_fixed,
                            color: GoLivreurTheme.primaire,
                            size: 20,
                          ),
                        ),
                        const SizedBox(width: 12),
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(
                                _resolvingAddress
                                    ? 'Recherche du repère...'
                                    : (_addressLabel ?? 'Point GPS sélectionné'),
                                maxLines: 2,
                                overflow: TextOverflow.ellipsis,
                                style: const TextStyle(
                                  fontWeight: FontWeight.w700,
                                  fontSize: 14,
                                ),
                              ),
                              const SizedBox(height: 2),
                              Text(
                                'GPS : ${_selected.latitude.toStringAsFixed(5)}, ${_selected.longitude.toStringAsFixed(5)} • © OpenStreetMap',
                                style: const TextStyle(
                                  fontSize: 11,
                                  color: GoLivreurTheme.texteSecondaire,
                                ),
                              ),
                            ],
                          ),
                        ),
                      ],
                    ),
                    const SizedBox(height: 14),
                    SizedBox(
                      height: 48,
                      child: FilledButton.icon(
                        style: FilledButton.styleFrom(
                          backgroundColor: GoLivreurTheme.primaire,
                          shape: RoundedRectangleBorder(
                            borderRadius: BorderRadius.circular(12),
                          ),
                        ),
                        onPressed: () {
                          Navigator.of(context).pop(
                            PickedLocation(
                              lat: _selected.latitude,
                              lng: _selected.longitude,
                              addressLabel: _addressLabel,
                              neighborhoodHint: _neighborhoodHint,
                            ),
                          );
                        },
                        icon: const Icon(Icons.check_circle_outline),
                        label: Text(
                          widget.confirmLabel,
                          style: const TextStyle(fontWeight: FontWeight.bold),
                        ),
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}
