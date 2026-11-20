import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:golivreur_core/models/modeles.dart';
import '../../auth/providers/auth_provider.dart';

final parcelsHistoryProvider = FutureProvider<List<ParcelModel>>((ref) async {
  final api = ref.watch(apiClientProvider);
  final userId = ref.watch(authProvider.select((s) => s.user?.id));
  if (userId == null) return const [];

  try {
    final response = await api.get('/client/parcels');
    if (response.statusCode == 200 &&
        response.data is Map &&
        response.data['succes'] == true &&
        response.data['donnees'] is List) {
      final List list = response.data['donnees'] as List;
      return list
          .whereType<Map>()
          .map((json) => ParcelModel.fromJson(Map<String, dynamic>.from(json)))
          .toList();
    }
  } catch (_) {}

  return const [];
});

final singleParcelProvider =
    FutureProvider.family<ParcelModel, int>((ref, parcelId) async {
  final api = ref.watch(apiClientProvider);

  final response = await api.get('/client/parcels/$parcelId');
  if (response.statusCode == 200 &&
      response.data is Map &&
      response.data['succes'] == true &&
      response.data['donnees'] is Map) {
    return ParcelModel.fromJson(
      Map<String, dynamic>.from(response.data['donnees'] as Map),
    );
  }

  throw Exception('Colis #$parcelId introuvable.');
});

/// Récupère le véritable code expéditeur à 4 chiffres (D-CODE-1).
final parcelSenderCodeProvider =
    FutureProvider.family<String?, int>((ref, parcelId) async {
  final api = ref.watch(apiClientProvider);
  try {
    final res = await api.get('/client/parcels/$parcelId/sender-code');
    if (res.statusCode == 200 &&
        res.data is Map &&
        res.data['succes'] == true &&
        res.data['donnees'] is Map) {
      final code = res.data['donnees']['sender_code']?.toString().trim();
      if (code != null && code.isNotEmpty) return code;
    }
  } catch (_) {}
  return null;
});
