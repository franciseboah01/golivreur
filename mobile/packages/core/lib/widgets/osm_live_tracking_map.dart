import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:latlong2/latlong.dart';

import '../config/app_theme.dart';
import '../services/gps_location_service.dart';

/// Carte OpenStreetMap en temps réel (100 % gratuite, sans clé Google Maps)
/// pour suivre la position du livreur, du commerce/ramassage et de la destination.
class OsmLiveTrackingMap extends StatefulWidget {
  final double? courierLat;
  final double? courierLng;
  final double? destinationLat;
  final double? destinationLng;
  final double? pickupLat;
  final double? pickupLng;
  final double fallbackLat;
  final double fallbackLng;
  final String destinationLabel;

  const OsmLiveTrackingMap({
    super.key,
    this.courierLat,
    this.courierLng,
    this.destinationLat,
    this.destinationLng,
    this.pickupLat,
    this.pickupLng,
    this.fallbackLat = 6.8770,
    this.fallbackLng = -6.4502,
    this.destinationLabel = 'Livraison',
  });

  @override
  State<OsmLiveTrackingMap> createState() => _OsmLiveTrackingMapState();
}

class _OsmLiveTrackingMapState extends State<OsmLiveTrackingMap> {
  final MapController _mapController = MapController();

  LatLng? get _courierPoint =>
      (widget.courierLat != null && widget.courierLng != null)
          ? LatLng(widget.courierLat!, widget.courierLng!)
          : null;

  LatLng? get _destPoint =>
      (widget.destinationLat != null && widget.destinationLng != null)
          ? LatLng(widget.destinationLat!, widget.destinationLng!)
          : null;

  LatLng? get _pickupPoint =>
      (widget.pickupLat != null && widget.pickupLng != null)
          ? LatLng(widget.pickupLat!, widget.pickupLng!)
          : null;

  LatLng get _initialCenter =>
      _courierPoint ??
      _destPoint ??
      _pickupPoint ??
      LatLng(widget.fallbackLat, widget.fallbackLng);

  @override
  void didUpdateWidget(covariant OsmLiveTrackingMap oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (widget.courierLat != null &&
        widget.courierLng != null &&
        (widget.courierLat != oldWidget.courierLat ||
            widget.courierLng != oldWidget.courierLng)) {
      try {
        _mapController.move(
          LatLng(widget.courierLat!, widget.courierLng!),
          _mapController.camera.zoom,
        );
      } catch (_) {}
    }
  }

  void _recenter() {
    try {
      _mapController.move(_initialCenter, 15.0);
    } catch (_) {}
  }

  @override
  Widget build(BuildContext context) {
    final courier = _courierPoint;
    final dest = _destPoint;
    final pickup = _pickupPoint;

    final markers = <Marker>[
      if (pickup != null)
        Marker(
          point: pickup,
          width: 44,
          height: 44,
          child: const _MapPinBadge(
            icon: Icons.storefront_rounded,
            color: Color(0xFF2E75B6),
          ),
        ),
      if (dest != null)
        Marker(
          point: dest,
          width: 44,
          height: 44,
          child: const _MapPinBadge(
            icon: Icons.home_rounded,
            color: GoLivreurTheme.secondaire,
          ),
        ),
      if (courier != null)
        Marker(
          point: courier,
          width: 50,
          height: 50,
          child: const _MapPinBadge(
            icon: Icons.delivery_dining_rounded,
            color: GoLivreurTheme.primaire,
            highlight: true,
          ),
        ),
    ];

    final polylines = <Polyline>[
      if (courier != null && dest != null)
        Polyline(
          points: [courier, dest],
          color: GoLivreurTheme.primaire,
          strokeWidth: 4.0,
        ),
    ];

    final distanceKm = (courier != null && dest != null)
        ? GpsLocationService.distanceKm(
            courier.latitude,
            courier.longitude,
            dest.latitude,
            dest.longitude,
          )
        : null;

    return Stack(
      children: [
        FlutterMap(
          mapController: _mapController,
          options: MapOptions(
            initialCenter: _initialCenter,
            initialZoom: 15.0,
          ),
          children: [
            TileLayer(
              urlTemplate: 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
              userAgentPackageName: 'ci.golivreur.app',
            ),
            if (polylines.isNotEmpty) PolylineLayer(polylines: polylines),
            MarkerLayer(markers: markers),
          ],
        ),

        // Distance en direct + bouton recentrer
        Positioned(
          right: 12,
          bottom: 12,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              if (distanceKm != null)
                Container(
                  margin: const EdgeInsets.only(bottom: 8),
                  padding:
                      const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                  decoration: BoxDecoration(
                    color: Colors.white,
                    borderRadius: BorderRadius.circular(20),
                    boxShadow: const [
                      BoxShadow(blurRadius: 8, color: Colors.black12),
                    ],
                  ),
                  child: Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      const Icon(Icons.near_me,
                          size: 14, color: GoLivreurTheme.primaire),
                      const SizedBox(width: 4),
                      Text(
                        '${distanceKm.toStringAsFixed(1)} km',
                        style: const TextStyle(
                          fontWeight: FontWeight.w700,
                          fontSize: 12,
                        ),
                      ),
                    ],
                  ),
                ),
              Material(
                color: Colors.white,
                shape: const CircleBorder(),
                elevation: 3,
                child: IconButton(
                  tooltip: 'Recentrer la carte',
                  onPressed: _recenter,
                  icon: const Icon(
                    Icons.my_location,
                    color: GoLivreurTheme.textePrincipal,
                    size: 20,
                  ),
                ),
              ),
            ],
          ),
        ),

        // Attribution OpenStreetMap
        Positioned(
          left: 10,
          bottom: 8,
          child: Container(
            padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
            decoration: BoxDecoration(
              color: Colors.white.withValues(alpha: 0.8),
              borderRadius: BorderRadius.circular(6),
            ),
            child: const Text(
              '© OpenStreetMap',
              style: TextStyle(fontSize: 9.5, color: Colors.black54),
            ),
          ),
        ),
      ],
    );
  }
}

class _MapPinBadge extends StatelessWidget {
  final IconData icon;
  final Color color;
  final bool highlight;

  const _MapPinBadge({
    required this.icon,
    required this.color,
    this.highlight = false,
  });

  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: BoxDecoration(
        color: color,
        shape: BoxShape.circle,
        border: Border.all(color: Colors.white, width: highlight ? 3 : 2.5),
        boxShadow: const [
          BoxShadow(blurRadius: 8, color: Colors.black26, offset: Offset(0, 3)),
        ],
      ),
      child: Icon(icon, color: Colors.white, size: highlight ? 24 : 20),
    );
  }
}
