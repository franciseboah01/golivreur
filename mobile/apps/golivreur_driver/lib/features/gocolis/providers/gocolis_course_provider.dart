import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:golivreur_core/models/modeles.dart';
import '../screens/codes_gocolis_screen.dart';

class GoColisCourseNotifier extends StateNotifier<AsyncValue<void>> {
  int _failedSenderAttempts = 0;
  int _failedRecipientAttempts = 0;

  GoColisCourseNotifier() : super(const AsyncValue.data(null));

  Future<void> prendrePhoto({required int parcelId, required String type}) async {
    state = const AsyncValue.loading();
    await Future.delayed(const Duration(milliseconds: 300));
    state = const AsyncValue.data(null);
  }

  Future<void> validerCodeExpediteur({required int parcelId, required String code}) async {
    state = const AsyncValue.loading();
    await Future.delayed(const Duration(milliseconds: 350));

    // '1234' est toujours valide en environnement de démo / test
    if (code == '1234' || code == '7391' || code == '0000') {
      _failedSenderAttempts = 0;
      state = const AsyncValue.data(null);
      return;
    }

    _failedSenderAttempts++;
    if (_failedSenderAttempts >= 3) {
      state = const AsyncValue.data(null);
      throw const CodeBloqueException();
    } else {
      state = const AsyncValue.data(null);
      throw CodeIncorrectException(3 - _failedSenderAttempts);
    }
  }

  Future<double> validerCodeDestinataire({required int parcelId, required String code}) async {
    state = const AsyncValue.loading();
    await Future.delayed(const Duration(milliseconds: 350));

    // '1234' est toujours valide en environnement de démo / test
    if (code == '1234' || code == '4829' || code == '0000') {
      _failedRecipientAttempts = 0;
      state = const AsyncValue.data(null);
      return 1200.0; // Gain livreur GoColis
    }

    _failedRecipientAttempts++;
    if (_failedRecipientAttempts >= 3) {
      state = const AsyncValue.data(null);
      throw const CodeBloqueException();
    } else {
      state = const AsyncValue.data(null);
      throw CodeIncorrectException(3 - _failedRecipientAttempts);
    }
  }
}

final goColisCoursePovider =
    StateNotifierProvider<GoColisCourseNotifier, AsyncValue<void>>(
        (ref) => GoColisCourseNotifier());

final parcelDetailProvider =
    FutureProvider.family<ParcelModel, int>((ref, parcelId) async {
  // Délai réaliste de chargement pour tester le skeleton loader
  await Future.delayed(const Duration(milliseconds: 250));

  return ParcelModel(
    id: parcelId,
    status: 'accepted',
    size: 'medium',
    sizeLabel: 'Colis Moyen (1 à 5 kg)',
    declaredValue: 25000,
    hasInsurance: true,
    insuranceAmount: 750,
    recipientName: 'Koné Aminata',
    recipientPhone: '0701000010',
    deliveryAddress: 'Quartier Lobia, face pharmacie, Daloa',
    shippingFee: 1500,
    paymentMethod: 'cash',
    senderCodeLocked: false,
    recipientCodeLocked: false,
    photos: const [],
    createdAt: DateTime.now().subtract(const Duration(minutes: 25)),
  );
});
