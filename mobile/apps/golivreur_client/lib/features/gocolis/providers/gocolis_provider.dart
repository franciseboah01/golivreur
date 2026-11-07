import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:golivreur_core/models/modeles.dart';
import '../../auth/providers/auth_provider.dart';

final parcelsHistoryProvider = FutureProvider<List<ParcelModel>>((ref) async {
  final api = ref.watch(apiClientProvider);

  try {
    final response = await api.get('/client/parcels');
    if (response.statusCode == 200 && response.data['succes'] == true) {
      final List list = response.data['donnees'] as List;
      return list.map((json) => ParcelModel.fromJson(json)).toList();
    }
  } catch (_) {
    // Mode démo ou hors-ligne
  }

  // Fallback réaliste Daloa
  return [
    ParcelModel(
      id: 501,
      status: 'in_transit',
      size: 'moyen',
      sizeLabel: 'Moyen (Boîte à chaussures / Vêtements)',
      declaredValue: 15000,
      hasInsurance: true,
      insuranceAmount: 300,
      recipientName: 'Kouassi Yves',
      recipientPhone: '0701000008',
      deliveryAddress: 'Quartier Lobia, face pharmacie de garde',
      shippingFee: 1000,
      paymentMethod: 'wave',
      senderCodeLocked: false,
      recipientCodeLocked: false,
      courier: const CourierModel(
        id: 12,
        name: 'Bakary Soro',
        photoUrl: null,
        currentLat: 6.8780,
        currentLng: -6.4490,
        averageRating: 4.8,
        isOnline: true,
      ),
      photos: const [
        ParcelPhotoModel(
          id: 1,
          type: 'pickup',
          photoUrl: 'https://images.unsplash.com/photo-1549465220-1a8b9238cd48?w=300',
        ),
      ],
      createdAt: DateTime.now().subtract(const Duration(minutes: 25)),
    ),
  ];
});

final singleParcelProvider = FutureProvider.family<ParcelModel, int>((ref, parcelId) async {
  final api = ref.watch(apiClientProvider);

  try {
    final response = await api.get('/client/parcels/$parcelId');
    if (response.statusCode == 200 && response.data['succes'] == true) {
      return ParcelModel.fromJson(response.data['donnees'] as Map<String, dynamic>);
    }
  } catch (_) {
    // Fallback
  }

  return ParcelModel(
    id: parcelId,
    status: 'in_transit',
    size: 'moyen',
    sizeLabel: 'Moyen (Colis scellé)',
    declaredValue: 15000,
    hasInsurance: true,
    insuranceAmount: 300,
    recipientName: 'Kouassi Yves',
    recipientPhone: '0701000008',
    deliveryAddress: 'Quartier Lobia, face pharmacie de garde',
    shippingFee: 1000,
    paymentMethod: 'wave',
    senderCodeLocked: false,
    recipientCodeLocked: false,
    courier: const CourierModel(
      id: 12,
      name: 'Bakary Soro',
      photoUrl: null,
      currentLat: 6.8780,
      currentLng: -6.4490,
      averageRating: 4.8,
      isOnline: true,
    ),
    photos: const [
      ParcelPhotoModel(
        id: 1,
        type: 'pickup',
        photoUrl: 'https://images.unsplash.com/photo-1549465220-1a8b9238cd48?w=300',
      ),
    ],
    createdAt: DateTime.now().subtract(const Duration(minutes: 25)),
  );
});

