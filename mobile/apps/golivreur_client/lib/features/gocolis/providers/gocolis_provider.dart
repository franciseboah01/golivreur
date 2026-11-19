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
