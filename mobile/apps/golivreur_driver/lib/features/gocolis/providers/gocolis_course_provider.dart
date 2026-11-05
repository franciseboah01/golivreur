import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:golivreur_core/models/modeles.dart';

class GoColisCourseNotifier extends StateNotifier<AsyncValue<void>> {
  GoColisCourseNotifier() : super(const AsyncValue.data(null));

  Future<void> prendrePhoto({required int parcelId, required String type}) async {}
  Future<void> validerCodeExpediteur({required int parcelId, required String code}) async {}
  Future<double> validerCodeDestinataire({required int parcelId, required String code}) async => 0.0;
}

final goColisCoursePovider =
    StateNotifierProvider<GoColisCourseNotifier, AsyncValue<void>>(
        (ref) => GoColisCourseNotifier());

final parcelDetailProvider =
    FutureProvider.family<ParcelModel, int>((ref, parcelId) async {
  throw UnimplementedError();
});
