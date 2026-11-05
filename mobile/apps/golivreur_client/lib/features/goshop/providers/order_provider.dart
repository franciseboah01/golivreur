import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:golivreur_core/models/modeles.dart';

final orderDetailProvider =
    FutureProvider.family<OrderModel, int>((ref, orderId) async {
  throw UnimplementedError();
});

