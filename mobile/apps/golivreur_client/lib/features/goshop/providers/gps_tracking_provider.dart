import 'dart:async';

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:golivreur_core/services/api_client.dart' hide apiClientProvider;

import '../../auth/providers/auth_provider.dart';

/// Coordonnées GPS temps réel du livreur (renvoyées par l'API Laravel).
class CourierLocationPoint {
  final double lat;
  final double lng;
  final DateTime? updatedAt;

  const CourierLocationPoint({
    required this.lat,
    required this.lng,
    this.updatedAt,
  });
}

Stream<CourierLocationPoint?> _pollCourierEndpoint(
  ApiClient api,
  String path,
  Ref ref,
) async* {
  var active = true;
  ref.onDispose(() => active = false);

  while (active) {
    try {
      final res = await api.get(path);
      if (res.statusCode == 200 &&
          res.data is Map &&
          res.data['succes'] == true &&
          res.data['donnees'] is Map) {
        final d = res.data['donnees'] as Map;
        final lat = (d['lat'] as num?)?.toDouble();
        final lng = (d['lng'] as num?)?.toDouble();
        final rawUpdated = d['updated_at']?.toString();
        if (lat != null && lng != null) {
          yield CourierLocationPoint(
            lat: lat,
            lng: lng,
            updatedAt: rawUpdated != null ? DateTime.tryParse(rawUpdated) : null,
          );
        } else {
          yield null;
        }
      }
    } catch (_) {
      // Silencieux en cas de commande non encore assignée (422) ou réseau faible
    }

    // Respecte la limite backend (max 1 req / 5s) avec marge de sécurité à 7s
    await Future<void>.delayed(const Duration(seconds: 7));
  }
}

/// Suivi GPS temps réel du livreur pour une commande Shop (`GET /client/orders/{id}/courier-location`).
final gpsTrackingProvider =
    StreamProvider.autoDispose.family<CourierLocationPoint?, int>((ref, orderId) {
  final api = ref.watch(apiClientProvider);
  return _pollCourierEndpoint(api, '/client/orders/$orderId/courier-location', ref);
});

/// Suivi GPS temps réel du livreur pour un colis (`GET /client/parcels/{id}/courier-location`).
final parcelGpsTrackingProvider =
    StreamProvider.autoDispose.family<CourierLocationPoint?, int>((ref, parcelId) {
  final api = ref.watch(apiClientProvider);
  return _pollCourierEndpoint(api, '/client/parcels/$parcelId/courier-location', ref);
});
