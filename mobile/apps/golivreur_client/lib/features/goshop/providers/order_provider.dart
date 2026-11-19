import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:golivreur_core/models/modeles.dart';
import '../../auth/providers/auth_provider.dart';

final orderDetailProvider =
    FutureProvider.family<OrderModel, int>((ref, orderId) async {
  final api = ref.watch(apiClientProvider);

  final response = await api.get('/client/orders/$orderId');
  if (response.statusCode == 200 &&
      response.data is Map &&
      response.data['succes'] == true &&
      response.data['donnees'] is Map) {
    return OrderModel.fromJson(
      Map<String, dynamic>.from(response.data['donnees'] as Map),
    );
  }

  throw Exception('Commande #$orderId introuvable.');
});

final ordersHistoryProvider = FutureProvider<List<OrderModel>>((ref) async {
  final api = ref.watch(apiClientProvider);
  final userId = ref.watch(authProvider.select((s) => s.user?.id));
  if (userId == null) return const [];

  try {
    final response = await api.get('/client/orders');
    if (response.statusCode == 200 &&
        response.data is Map &&
        response.data['succes'] == true) {
      final raw = response.data['donnees'];
      final List list = (raw is Map && raw['data'] is List)
          ? (raw['data'] as List)
          : (raw is List ? raw : const []);
      return list
          .whereType<Map>()
          .map((json) => OrderModel.fromJson(Map<String, dynamic>.from(json)))
          .toList();
    }
  } catch (_) {}

  return const [];
});
