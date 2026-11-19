import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:golivreur_core/models/modeles.dart';
import 'package:golivreur_core/services/api_client.dart';
import '../screens/codes_gocolis_screen.dart';

class GoColisCourseNotifier extends StateNotifier<AsyncValue<void>> {
  final ApiClient _api;
  int _failedSenderAttempts = 0;
  int _failedRecipientAttempts = 0;

  GoColisCourseNotifier(this._api) : super(const AsyncValue.data(null));

  Future<void> prendrePhoto({required int parcelId, required String type}) async {
    state = const AsyncValue.loading();
    try {
      await _api.post('/driver/parcels/$parcelId/photos', data: {'type': type});
    } finally {
      state = const AsyncValue.data(null);
    }
  }

  Future<void> validerCodeExpediteur({
    required int parcelId,
    required String code,
  }) async {
    state = const AsyncValue.loading();
    try {
      final res = await _api.post(
        '/parcels/$parcelId/verify-sender-code',
        data: {'code': code.trim()},
      );
      if (res.statusCode == 200 && res.data is Map && res.data['succes'] == true) {
        _failedSenderAttempts = 0;
        state = const AsyncValue.data(null);
        return;
      }
      _failedSenderAttempts++;
      state = const AsyncValue.data(null);
      if (_failedSenderAttempts >= 3) {
        throw const CodeBloqueException();
      }
      throw CodeIncorrectException(3 - _failedSenderAttempts);
    } on DioException catch (e) {
      state = const AsyncValue.data(null);
      final status = e.response?.statusCode;
      if (status == 423) {
        throw const CodeBloqueException();
      }
      _failedSenderAttempts++;
      if (_failedSenderAttempts >= 3) {
        throw const CodeBloqueException();
      }
      throw CodeIncorrectException(3 - _failedSenderAttempts);
    }
  }

  Future<double> validerCodeDestinataire({
    required int parcelId,
    required String code,
  }) async {
    state = const AsyncValue.loading();
    try {
      final res = await _api.post(
        '/parcels/$parcelId/verify-recipient-code',
        data: {'code': code.trim()},
      );
      if (res.statusCode == 200 && res.data is Map && res.data['succes'] == true) {
        _failedRecipientAttempts = 0;
        state = const AsyncValue.data(null);
        final donnees = res.data['donnees'];
        final payout = donnees is Map
            ? num.tryParse('${donnees['courier_amount'] ?? 0}')?.toDouble() ?? 0
            : 0.0;
        return payout;
      }
      _failedRecipientAttempts++;
      state = const AsyncValue.data(null);
      if (_failedRecipientAttempts >= 3) {
        throw const CodeBloqueException();
      }
      throw CodeIncorrectException(3 - _failedRecipientAttempts);
    } on DioException catch (e) {
      state = const AsyncValue.data(null);
      final status = e.response?.statusCode;
      if (status == 423) {
        throw const CodeBloqueException();
      }
      _failedRecipientAttempts++;
      if (_failedRecipientAttempts >= 3) {
        throw const CodeBloqueException();
      }
      throw CodeIncorrectException(3 - _failedRecipientAttempts);
    }
  }
}

final goColisCoursePovider =
    StateNotifierProvider<GoColisCourseNotifier, AsyncValue<void>>((ref) {
  final api = ref.watch(apiClientProvider);
  return GoColisCourseNotifier(api);
});

final parcelDetailProvider =
    FutureProvider.family<ParcelModel, int>((ref, parcelId) async {
  final api = ref.watch(apiClientProvider);
  final res = await api.get('/driver/parcels/$parcelId');
  if (res.statusCode == 200 &&
      res.data is Map &&
      res.data['succes'] == true &&
      res.data['donnees'] is Map) {
    return ParcelModel.fromJson(
      Map<String, dynamic>.from(res.data['donnees'] as Map),
    );
  }
  throw Exception('Colis introuvable.');
});
