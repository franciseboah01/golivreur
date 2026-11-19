import 'dart:math' as math;

import 'package:flutter/foundation.dart' show kIsWeb;
import 'package:geocoding/geocoding.dart' as geocoding;
import 'package:geolocator/geolocator.dart';
import 'package:url_launcher/url_launcher.dart';

/// Résultat structuré d'une lecture GPS locale (100 % gratuit via puce GPS native).
class GpsPositionResult {
  final double? lat;
  final double? lng;
  final String? formattedAddress;
  final String? neighborhoodHint;
  final String? errorMessage;
  final bool isServiceDisabled;
  final bool isPermissionDeniedForever;

  const GpsPositionResult({
    this.lat,
    this.lng,
    this.formattedAddress,
    this.neighborhoodHint,
    this.errorMessage,
    this.isServiceDisabled = false,
    this.isPermissionDeniedForever = false,
  });

  bool get isSuccess => lat != null && lng != null && errorMessage == null;
}

/// Service GPS gratuit (puce téléphone + géocodeur natif Android/iOS + Haversine).
class GpsLocationService {
  GpsLocationService._();

  /// Récupère la position GPS actuelle du téléphone (et optionnellement le nom de rue/quartier).
  static Future<GpsPositionResult> obtenirPositionActuelle({
    bool withAddressLookup = true,
  }) async {
    try {
      final serviceEnabled = await Geolocator.isLocationServiceEnabled();
      if (!serviceEnabled) {
        return const GpsPositionResult(
          errorMessage:
              'La localisation (GPS) est désactivée sur votre téléphone. Activez-la puis réessayez.',
          isServiceDisabled: true,
        );
      }

      var permission = await Geolocator.checkPermission();
      if (permission == LocationPermission.denied) {
        permission = await Geolocator.requestPermission();
      }

      if (permission == LocationPermission.denied) {
        return const GpsPositionResult(
          errorMessage:
              'Autorisation GPS refusée. Acceptez la localisation pour détecter votre position.',
        );
      }

      if (permission == LocationPermission.deniedForever) {
        return const GpsPositionResult(
          errorMessage:
              'Autorisation GPS bloquée dans les réglages. Ouvrez les paramètres de l\'application pour l\'activer.',
          isPermissionDeniedForever: true,
        );
      }

      Position? pos;
      try {
        pos = await Geolocator.getCurrentPosition(
          desiredAccuracy: LocationAccuracy.high,
          timeLimit: const Duration(seconds: 12),
        );
      } catch (_) {
        pos = await Geolocator.getLastKnownPosition();
      }

      if (pos == null) {
        return const GpsPositionResult(
          errorMessage:
              'Signal GPS indisponible pour le moment. Placez-vous près d\'une fenêtre ou choisissez sur la carte.',
        );
      }

      String? address;
      String? neighborhood;
      if (withAddressLookup) {
        final resolved = await resoudreAdresseDepuisCoords(
          pos.latitude,
          pos.longitude,
        );
        address = resolved.address;
        neighborhood = resolved.neighborhood;
      }

      return GpsPositionResult(
        lat: pos.latitude,
        lng: pos.longitude,
        formattedAddress: address,
        neighborhoodHint: neighborhood,
      );
    } catch (_) {
      return const GpsPositionResult(
        errorMessage:
            'Impossible de lire votre position GPS. Choisissez directement votre point sur la carte.',
      );
    }
  }

  /// Convertit (lat, lng) en adresse lisible via le géocodeur natif Android/iOS (gratuit).
  static Future<({String? address, String? neighborhood})>
      resoudreAdresseDepuisCoords(double lat, double lng) async {
    if (kIsWeb) return (address: null, neighborhood: null);
    try {
      final placemarks = await geocoding.placemarkFromCoordinates(lat, lng);
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

  static Future<void> ouvrirParametresLocalisation() async {
    try {
      await Geolocator.openLocationSettings();
    } catch (_) {}
  }

  static Future<void> ouvrirParametresApp() async {
    try {
      await Geolocator.openAppSettings();
    } catch (_) {}
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
