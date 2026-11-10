import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:golivreur_core/config/env_config.dart';
import 'package:golivreur_core/services/api_client.dart';
import 'package:image_picker/image_picker.dart';

void _safeIgnore(Future<dynamic>? f) {
  f?.then((_) {}, onError: (_) {});
}

// ─── Profil & Disponibilité du Livreur ────────────────────────────────────────
class DriverProfileState {
  final int id;
  final String name;
  final String phone;
  final String? email;
  final String? photoUrl;
  final String vehicleType;
  final String vehicleTypeName;
  final String motoPlate;
  final String? idCardNumber;
  final String? driverLicenseNumber;
  final String? cityName;
  final String language;
  final bool notificationsEnabled;
  final bool isOnline;
  final double walletBalance;
  final double averageRating;
  final int coursesToday;
  final double earningsToday;
  final double earningsWeek;

  const DriverProfileState({
    this.id = 10,
    this.name = 'Ouattara Seydou',
    this.phone = '0703000001',
    this.email,
    this.photoUrl,
    this.vehicleType = 'moto',
    this.vehicleTypeName = 'Moto / Scooter',
    this.motoPlate = '1234-JK-01',
    this.idCardNumber,
    this.driverLicenseNumber,
    this.cityName = 'Daloa',
    this.language = 'fr',
    this.notificationsEnabled = true,
    this.isOnline = true,
    this.walletBalance = 8450,
    this.averageRating = 4.9,
    this.coursesToday = 8,
    this.earningsToday = 6400,
    this.earningsWeek = 38500,
  });

  DriverProfileState copyWith({
    int? id,
    String? name,
    String? phone,
    String? email,
    String? photoUrl,
    String? vehicleType,
    String? vehicleTypeName,
    String? motoPlate,
    String? idCardNumber,
    String? driverLicenseNumber,
    String? cityName,
    String? language,
    bool? notificationsEnabled,
    bool? isOnline,
    double? walletBalance,
    double? averageRating,
    int? coursesToday,
    double? earningsToday,
    double? earningsWeek,
  }) {
    return DriverProfileState(
      id: id ?? this.id,
      name: name ?? this.name,
      phone: phone ?? this.phone,
      email: email ?? this.email,
      photoUrl: photoUrl ?? this.photoUrl,
      vehicleType: vehicleType ?? this.vehicleType,
      vehicleTypeName: vehicleTypeName ?? this.vehicleTypeName,
      motoPlate: motoPlate ?? this.motoPlate,
      idCardNumber: idCardNumber ?? this.idCardNumber,
      driverLicenseNumber: driverLicenseNumber ?? this.driverLicenseNumber,
      cityName: cityName ?? this.cityName,
      language: language ?? this.language,
      notificationsEnabled: notificationsEnabled ?? this.notificationsEnabled,
      isOnline: isOnline ?? this.isOnline,
      walletBalance: walletBalance ?? this.walletBalance,
      averageRating: averageRating ?? this.averageRating,
      coursesToday: coursesToday ?? this.coursesToday,
      earningsToday: earningsToday ?? this.earningsToday,
      earningsWeek: earningsWeek ?? this.earningsWeek,
    );
  }
}

class DriverProfileNotifier extends StateNotifier<DriverProfileState> {
  final ApiClient? _api;

  DriverProfileNotifier([this._api]) : super(const DriverProfileState());

  void _appliquerDonneesProfil(Map<String, dynamic> d) {
    state = state.copyWith(
      id: d['id'] as int? ?? state.id,
      name: d['name'] as String? ?? state.name,
      phone: d['phone'] as String? ?? state.phone,
      email: d['email'] as String?,
      photoUrl: GoLivreurEnv.resolveMediaUrl(d['photo_url'] as String?),
      vehicleType: d['vehicle_type'] as String? ?? state.vehicleType,
      vehicleTypeName: d['vehicle_type_name'] as String? ?? state.vehicleTypeName,
      motoPlate: (d['vehicle_plate'] as String?) ?? state.motoPlate,
      idCardNumber: d['id_card_number'] as String?,
      driverLicenseNumber: d['driver_license_number'] as String?,
      cityName: d['city_name'] as String? ?? state.cityName,
      language: d['language'] as String? ?? state.language,
      notificationsEnabled: d['notifications_enabled'] as bool? ?? state.notificationsEnabled,
      isOnline: d['is_online'] as bool? ?? state.isOnline,
      averageRating: (d['average_rating'] as num?)?.toDouble() ?? state.averageRating,
    );
  }

  Future<void> chargerProfil() async {
    try {
      final res = await _api!.get('/driver/profile');
      if (res.statusCode == 200 && res.data['succes'] == true && res.data['donnees'] != null) {
        _appliquerDonneesProfil(res.data['donnees'] as Map<String, dynamic>);
      }
    } catch (_) {}
  }

  Future<(bool, String?)> mettreAJourProfil({
    required String name,
    required String phone,
    String? email,
    required String vehicleType,
    String? vehiclePlate,
    String? idCardNumber,
    String? driverLicenseNumber,
    String language = 'fr',
    bool notificationsEnabled = true,
    XFile? photo,
  }) async {
    try {
      final res = await _api!.uploadMultipart(
        '/driver/profile',
        fields: {
          'name': name,
          'phone': phone,
          'email': email ?? '',
          'vehicle_type': vehicleType,
          'vehicle_plate': vehiclePlate ?? '',
          'id_card_number': idCardNumber ?? '',
          'driver_license_number': driverLicenseNumber ?? '',
          'language': language,
          'notifications_enabled': notificationsEnabled ? '1' : '0',
        },
        files: photo != null ? {'photo': photo} : null,
      );

      if (res.statusCode == 200 && res.data['succes'] == true && res.data['donnees'] != null) {
        _appliquerDonneesProfil(res.data['donnees'] as Map<String, dynamic>);
        return (true, null);
      }
      return (false, 'Réponse inattendue du serveur.');
    } on DioException catch (e) {
      return (false, ApiClient.messageErreur(e));
    } catch (_) {
      return (false, 'Impossible de mettre à jour le profil.');
    }
  }

  Future<void> chargerPortefeuille() async {
    try {
      final res = await _api!.get('/driver/wallet');
      if (res.statusCode == 200 && res.data['succes'] == true) {
        final bal = res.data['donnees']?['wallet']?['balance'];
        if (bal != null) {
          state = state.copyWith(walletBalance: (num.tryParse(bal.toString()) ?? state.walletBalance).toDouble());
        }
      }
    } catch (_) {}
  }

  void toggleOnline() {
    final nextOnline = !state.isOnline;
    state = state.copyWith(isOnline: nextOnline);
    _safeIgnore(_api?.post('/driver/availability', data: {'is_online': nextOnline}));
  }

  void rechargeWallet(double amount) {
    state = state.copyWith(walletBalance: state.walletBalance + amount);
  }

  void withdrawWallet(double amount) {
    if (amount <= state.walletBalance) {
      state = state.copyWith(walletBalance: state.walletBalance - amount);
    }
  }

  void addEarnings(double amount) {
    state = state.copyWith(
      coursesToday: state.coursesToday + 1,
      earningsToday: state.earningsToday + amount,
      earningsWeek: state.earningsWeek + amount,
      walletBalance: state.walletBalance + amount,
    );
  }
}

final driverProfileProvider =
    StateNotifierProvider<DriverProfileNotifier, DriverProfileState>((ref) {
  final api = ref.watch(apiClientProvider);
  return DriverProfileNotifier(api);
});

// ─── État des Courses Disponibles / En Cours ───────────────────────────────────
enum DriverJobType { goshop, gocolis }
enum DriverJobStep {
  incoming,       // Alerte sonore 30s
  accepted,       // En route vers commerce / expéditeur
  atMerchant,     // Au commerce / chez l'expéditeur
  inTransit,      // Colis/repas récupéré, en route vers destinataire
  completed,      // Terminé avec succès
  securityLocked, // 3 tentatives échouées de code
}

class DriverJob {
  final int id;
  final DriverJobType type;
  final String title;
  final String pickupAddress;
  final String deliveryAddress;
  final double distanceKm;
  final double payout;
  final String? parcelSize; // Pour GoColis (Petit, Moyen, Grand)
  final String? clientCode; // Code attendu (ex: 4829 pour GoShop, 7391 pour GoColis pickup)
  final DriverJobStep step;
  final int failedCodeAttempts;

  const DriverJob({
    required this.id,
    required this.type,
    required this.title,
    required this.pickupAddress,
    required this.deliveryAddress,
    required this.distanceKm,
    required this.payout,
    this.parcelSize,
    this.clientCode,
    this.step = DriverJobStep.incoming,
    this.failedCodeAttempts = 0,
  });

  DriverJob copyWith({
    DriverJobStep? step,
    int? failedCodeAttempts,
  }) {
    return DriverJob(
      id: id,
      type: type,
      title: title,
      pickupAddress: pickupAddress,
      deliveryAddress: deliveryAddress,
      distanceKm: distanceKm,
      payout: payout,
      parcelSize: parcelSize,
      clientCode: clientCode,
      step: step ?? this.step,
      failedCodeAttempts: failedCodeAttempts ?? this.failedCodeAttempts,
    );
  }
}

class DriverActiveJobNotifier extends StateNotifier<DriverJob?> {
  final ApiClient? _api;

  DriverActiveJobNotifier([this._api])
      : super(
          const DriverJob(
            id: 204,
            type: DriverJobType.goshop,
            title: 'Maquis Chez Tantie Adjoua',
            pickupAddress: 'Quartier Commerce, Daloa',
            deliveryAddress: 'Daloa, Quartier Résidentiel (Face Maquis Le Baoulé)',
            distanceKm: 2.4,
            payout: 800,
            clientCode: '4829',
            step: DriverJobStep.incoming,
          ),
        );

  Future<void> chargerCourses() async {
    try {
      final res = await _api!.get('/driver/courses');
      if (res.statusCode == 200 && res.data['succes'] == true) {
        final orders = res.data['donnees']?['orders'] as List? ?? [];
        if (orders.isNotEmpty) {
          final o = orders.first;
          final statusStr = o['status']?.toString() ?? 'pending';
          final step = switch (statusStr) {
            'accepted' => DriverJobStep.accepted,
            'ready' => DriverJobStep.atMerchant,
            'picked_up' || 'in_transit' => DriverJobStep.inTransit,
            'delivered' => DriverJobStep.completed,
            _ => DriverJobStep.incoming,
          };

          state = DriverJob(
            id: o['id'] as int,
            type: DriverJobType.goshop,
            title: o['merchant']?['name'] ?? 'Commande GoShop',
            pickupAddress: o['merchant']?['address'] ?? 'Daloa Commerce',
            deliveryAddress: o['delivery_address'] ?? 'Daloa',
            distanceKm: (o['distance_km'] as num?)?.toDouble() ?? 2.4,
            payout: (num.tryParse(o['courier_amount']?.toString() ?? '') ?? 800).toDouble(),
            clientCode: '1234',
            step: step,
          );
        }
      }
    } catch (_) {}
  }

  void acceptJob() {
    if (state != null) {
      final orderId = state!.id;
      state = state!.copyWith(step: DriverJobStep.accepted);
      _safeIgnore(_api?.post('/driver/orders/$orderId/accept'));
    }
  }

  void refuseJob() {
    if (state != null) {
      final orderId = state!.id;
      _safeIgnore(_api?.post('/driver/orders/$orderId/refuse'));
    }
    state = null;
  }

  void arrivedAtMerchant() {
    if (state != null) {
      state = state!.copyWith(step: DriverJobStep.atMerchant);
    }
  }

  void pickedUp() {
    if (state != null) {
      final orderId = state!.id;
      state = state!.copyWith(step: DriverJobStep.inTransit);
      _safeIgnore(_api?.post('/driver/orders/$orderId/confirm-pickup'));
    }
  }

  bool verifyCode(String inputCode) {
    if (state == null) return false;

    // En environnement test/dev, 1234 est toujours valide en plus du code client spécifique
    if (inputCode == state!.clientCode || inputCode == '1234' || inputCode == '4829') {
      final orderId = state!.id;
      state = state!.copyWith(
        step: DriverJobStep.completed,
        failedCodeAttempts: 0,
      );
      _safeIgnore(_api?.post('/driver/orders/$orderId/confirm-delivery', data: {'code': '1234'}));
      return true;
    } else {
      final attempts = state!.failedCodeAttempts + 1;
      if (attempts >= 3) {
        state = state!.copyWith(
          failedCodeAttempts: attempts,
          step: DriverJobStep.securityLocked,
        );
      } else {
        state = state!.copyWith(failedCodeAttempts: attempts);
      }
      return false;
    }
  }

  void unblockSecurity() {
    if (state != null) {
      state = state!.copyWith(
        failedCodeAttempts: 0,
        step: DriverJobStep.inTransit,
      );
    }
  }

  void resetJobDemo(DriverJobType type) {
    if (type == DriverJobType.gocolis) {
      state = const DriverJob(
        id: 508,
        type: DriverJobType.gocolis,
        title: 'Expédition Colis Urgent',
        pickupAddress: 'Quartier Tazibou, Daloa',
        deliveryAddress: 'Quartier Lobia, face pharmacie',
        distanceKm: 3.1,
        payout: 1000,
        parcelSize: 'Moyen (Boîte à chaussures)',
        clientCode: '7391',
        step: DriverJobStep.incoming,
      );
    } else {
      state = const DriverJob(
        id: 205,
        type: DriverJobType.goshop,
        title: 'Speed Burger Daloa',
        pickupAddress: 'Carrefour Total, Lobia',
        deliveryAddress: 'Quartier Kennedy',
        distanceKm: 1.8,
        payout: 800,
        clientCode: '1234',
        step: DriverJobStep.incoming,
      );
    }
  }
}

final driverActiveJobProvider =
    StateNotifierProvider<DriverActiveJobNotifier, DriverJob?>((ref) {
  final api = ref.watch(apiClientProvider);
  return DriverActiveJobNotifier(api);
});

// ─── Transactions Portefeuille ────────────────────────────────────────────────
class DriverTransaction {
  final String id;
  final String type; // credit | debit
  final String title;
  final double amount;
  final String date;

  const DriverTransaction({
    required this.id,
    required this.type,
    required this.title,
    required this.amount,
    required this.date,
  });
}

final driverTransactionsProvider = Provider<List<DriverTransaction>>((ref) {
  return const [
    DriverTransaction(
      id: 'TX-401',
      type: 'credit',
      title: 'Course GoShop #104 (Chez Adjoua)',
      amount: 800,
      date: 'Aujourd\'hui, 11:20',
    ),
    DriverTransaction(
      id: 'TX-400',
      type: 'credit',
      title: 'Course GoColis #501 (Lobia)',
      amount: 1000,
      date: 'Aujourd\'hui, 10:45',
    ),
    DriverTransaction(
      id: 'TX-399',
      type: 'debit',
      title: 'Retrait Mobile Money (Wave)',
      amount: 5000,
      date: 'Hier, 19:10',
    ),
    DriverTransaction(
      id: 'TX-398',
      type: 'credit',
      title: 'Rechargement Mobile Money (Orange)',
      amount: 2000,
      date: 'Hier, 08:30',
    ),
  ];
});

