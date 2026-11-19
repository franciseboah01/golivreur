import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:golivreur_core/config/env_config.dart';
import 'package:golivreur_core/services/api_client.dart';
import 'package:image_picker/image_picker.dart';

void _safeIgnore(Future<dynamic>? f) {
  f?.then((_) {}, onError: (_) {});
}

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

  factory DriverTransaction.fromJson(Map<String, dynamic> json) {
    final rawType = (json['type'] ?? '').toString().toLowerCase();
    final isDebit = rawType == 'debit' ||
        rawType == 'withdrawal' ||
        rawType == 'payout' ||
        rawType == 'commission';
    final rawAmount = num.tryParse('${json['amount'] ?? 0}')?.toDouble() ?? 0;
    final createdAt = (json['created_at'] ?? json['date'] ?? '').toString();

    return DriverTransaction(
      id: (json['reference'] ?? json['id'] ?? '').toString(),
      type: isDebit ? 'debit' : 'credit',
      title: (json['description'] ?? json['title'] ?? 'Transaction').toString(),
      amount: rawAmount.abs(),
      date: _formatDateLabel(createdAt),
    );
  }
}

// ─── Course Terminée (Historique Gains) ───────────────────────────────────────
class DriverCompletedCourse {
  final String id;
  final String type; // goshop | gocolis
  final String title;
  final String route;
  final double amount;
  final String time;

  const DriverCompletedCourse({
    required this.id,
    required this.type,
    required this.title,
    required this.route,
    required this.amount,
    required this.time,
  });

  factory DriverCompletedCourse.fromJson(Map<String, dynamic> json) {
    return DriverCompletedCourse(
      id: (json['id'] ?? '').toString(),
      type: (json['type'] ?? 'goshop').toString(),
      title: (json['title'] ?? 'Course').toString(),
      route: (json['route'] ?? '').toString(),
      amount: num.tryParse('${json['amount'] ?? 0}')?.toDouble() ?? 0,
      time: (json['time'] ?? '').toString(),
    );
  }
}

String _formatDateLabel(String raw) {
  if (raw.isEmpty) return '';
  final dt = DateTime.tryParse(raw)?.toLocal();
  if (dt == null) return raw;
  final d = dt.day.toString().padLeft(2, '0');
  final m = dt.month.toString().padLeft(2, '0');
  final h = dt.hour.toString().padLeft(2, '0');
  final min = dt.minute.toString().padLeft(2, '0');
  return '$d/$m/${dt.year} $h:$min';
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
  final int totalRatings;
  final int coursesToday;
  final int coursesWeek;
  final double earningsToday;
  final double earningsWeek;
  final List<DriverTransaction> transactions;
  final List<DriverCompletedCourse> recentCourses;

  const DriverProfileState({
    this.id = 0,
    this.name = '',
    this.phone = '',
    this.email,
    this.photoUrl,
    this.vehicleType = '',
    this.vehicleTypeName = '',
    this.motoPlate = '',
    this.idCardNumber,
    this.driverLicenseNumber,
    this.cityName,
    this.language = 'fr',
    this.notificationsEnabled = true,
    this.isOnline = false,
    this.walletBalance = 0,
    this.averageRating = 0,
    this.totalRatings = 0,
    this.coursesToday = 0,
    this.coursesWeek = 0,
    this.earningsToday = 0,
    this.earningsWeek = 0,
    this.transactions = const [],
    this.recentCourses = const [],
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
    int? totalRatings,
    int? coursesToday,
    int? coursesWeek,
    double? earningsToday,
    double? earningsWeek,
    List<DriverTransaction>? transactions,
    List<DriverCompletedCourse>? recentCourses,
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
      totalRatings: totalRatings ?? this.totalRatings,
      coursesToday: coursesToday ?? this.coursesToday,
      coursesWeek: coursesWeek ?? this.coursesWeek,
      earningsToday: earningsToday ?? this.earningsToday,
      earningsWeek: earningsWeek ?? this.earningsWeek,
      transactions: transactions ?? this.transactions,
      recentCourses: recentCourses ?? this.recentCourses,
    );
  }
}

class DriverProfileNotifier extends StateNotifier<DriverProfileState> {
  final ApiClient? _api;

  DriverProfileNotifier([this._api]) : super(const DriverProfileState());

  void reinitialiser() {
    state = const DriverProfileState();
  }

  void _appliquerDonneesProfil(Map<String, dynamic> d) {
    state = state.copyWith(
      id: d['id'] as int? ?? state.id,
      name: d['name'] as String? ?? state.name,
      phone: d['phone'] as String? ?? state.phone,
      email: d['email'] as String?,
      photoUrl: GoLivreurEnv.resolveMediaUrl(d['photo_url'] as String?),
      vehicleType: d['vehicle_type'] as String? ?? state.vehicleType,
      vehicleTypeName:
          d['vehicle_type_name'] as String? ?? state.vehicleTypeName,
      motoPlate: (d['vehicle_plate'] as String?) ?? state.motoPlate,
      idCardNumber: d['id_card_number'] as String?,
      driverLicenseNumber: d['driver_license_number'] as String?,
      cityName: d['city_name'] as String? ?? state.cityName,
      language: d['language'] as String? ?? state.language,
      notificationsEnabled:
          d['notifications_enabled'] as bool? ?? state.notificationsEnabled,
      isOnline: d['is_online'] as bool? ?? state.isOnline,
      averageRating:
          (d['average_rating'] as num?)?.toDouble() ?? state.averageRating,
      totalRatings: (d['total_ratings'] as num?)?.toInt() ?? state.totalRatings,
    );
  }

  Future<void> chargerProfil() async {
    if (_api == null) return;
    try {
      final res = await _api.get('/driver/profile');
      if (res.statusCode == 200 &&
          res.data is Map &&
          res.data['succes'] == true &&
          res.data['donnees'] is Map) {
        _appliquerDonneesProfil(
          Map<String, dynamic>.from(res.data['donnees'] as Map),
        );
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
    if (_api == null) {
      return (false, 'Client API non initialisé.');
    }
    try {
      final res = await _api.uploadMultipart(
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

      if (res.statusCode == 200 &&
          res.data is Map &&
          res.data['succes'] == true &&
          res.data['donnees'] is Map) {
        _appliquerDonneesProfil(
          Map<String, dynamic>.from(res.data['donnees'] as Map),
        );
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
    if (_api == null) return;
    try {
      final res = await _api.get('/driver/wallet');
      if (res.statusCode == 200 &&
          res.data is Map &&
          res.data['succes'] == true &&
          res.data['donnees'] is Map) {
        final donnees = Map<String, dynamic>.from(res.data['donnees'] as Map);
        final wallet = donnees['wallet'] is Map
            ? Map<String, dynamic>.from(donnees['wallet'] as Map)
            : <String, dynamic>{};
        final stats = donnees['stats'] is Map
            ? Map<String, dynamic>.from(donnees['stats'] as Map)
            : <String, dynamic>{};

        final rawTxContainer = donnees['transactions'];
        final List rawTxList;
        if (rawTxContainer is Map && rawTxContainer['data'] is List) {
          rawTxList = rawTxContainer['data'] as List;
        } else if (rawTxContainer is List) {
          rawTxList = rawTxContainer;
        } else {
          rawTxList = const [];
        }

        final parsedTx = rawTxList
            .whereType<Map>()
            .map((e) => DriverTransaction.fromJson(Map<String, dynamic>.from(e)))
            .toList();

        final rawCourses = donnees['recent_courses'] is List
            ? donnees['recent_courses'] as List
            : const [];
        final parsedCourses = rawCourses
            .whereType<Map>()
            .map((e) =>
                DriverCompletedCourse.fromJson(Map<String, dynamic>.from(e)))
            .toList();

        state = state.copyWith(
          walletBalance:
              num.tryParse('${wallet['balance'] ?? 0}')?.toDouble() ?? 0,
          coursesToday: (stats['courses_today'] as num?)?.toInt() ?? 0,
          coursesWeek: (stats['courses_week'] as num?)?.toInt() ?? 0,
          earningsToday:
              num.tryParse('${stats['earnings_today'] ?? 0}')?.toDouble() ?? 0,
          earningsWeek:
              num.tryParse('${stats['earnings_week'] ?? 0}')?.toDouble() ?? 0,
          transactions: parsedTx,
          recentCourses: parsedCourses,
        );
      }
    } catch (_) {}
  }

  Future<(bool, String?)> toggleOnline() async {
    final nextOnline = !state.isOnline;
    if (_api == null) {
      state = state.copyWith(isOnline: nextOnline);
      return (true, null);
    }

    try {
      final res =
          await _api.post('/driver/availability', data: {'is_online': nextOnline});
      if (res.statusCode == 200 && res.data is Map && res.data['succes'] == true) {
        final serverOnline =
            res.data['donnees'] is Map && res.data['donnees']['is_online'] is bool
                ? res.data['donnees']['is_online'] as bool
                : nextOnline;
        state = state.copyWith(isOnline: serverOnline);
        return (true, res.data['message'] as String?);
      }
      return (false, 'Impossible de modifier votre disponibilité.');
    } on DioException catch (e) {
      return (false, ApiClient.messageErreur(e));
    } catch (_) {
      return (false, 'Erreur réseau lors du changement de statut.');
    }
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
  incoming, // Alerte sonore 30s
  accepted, // En route vers commerce / expéditeur
  atMerchant, // Au commerce / chez l'expéditeur
  inTransit, // Colis/repas récupéré, en route vers destinataire
  completed, // Terminé avec succès
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
      step: step ?? this.step,
      failedCodeAttempts: failedCodeAttempts ?? this.failedCodeAttempts,
    );
  }
}

class DriverActiveJobNotifier extends StateNotifier<DriverJob?> {
  final ApiClient? _api;

  DriverActiveJobNotifier([this._api]) : super(null);

  void reinitialiser() {
    state = null;
  }

  void clearCompletedJob() {
    state = null;
  }

  Future<void> chargerCourses() async {
    if (_api == null) return;
    try {
      final res = await _api.get('/driver/courses');
      if (res.statusCode == 200 &&
          res.data is Map &&
          res.data['succes'] == true &&
          res.data['donnees'] is Map) {
        final donnees = Map<String, dynamic>.from(res.data['donnees'] as Map);
        final orders = donnees['orders'] is List ? donnees['orders'] as List : const [];
        final parcels =
            donnees['parcels'] is List ? donnees['parcels'] as List : const [];

        if (orders.isNotEmpty && orders.first is Map) {
          final o = Map<String, dynamic>.from(orders.first as Map);
          final statusStr = o['status']?.toString() ?? 'pending';
          final step = switch (statusStr) {
            'accepted' => DriverJobStep.accepted,
            'ready' => DriverJobStep.atMerchant,
            'picked_up' || 'in_transit' => DriverJobStep.inTransit,
            'delivered' => DriverJobStep.completed,
            _ => DriverJobStep.incoming,
          };

          final merchant = o['merchant'] is Map
              ? Map<String, dynamic>.from(o['merchant'] as Map)
              : <String, dynamic>{};

          state = DriverJob(
            id: (o['id'] as num).toInt(),
            type: DriverJobType.goshop,
            title: (merchant['name'] ?? 'Commande #${o['id']}').toString(),
            pickupAddress: (merchant['address'] ?? 'Adresse commerce').toString(),
            deliveryAddress: (o['delivery_address'] ?? 'Adresse client').toString(),
            distanceKm: (o['distance_km'] as num?)?.toDouble() ?? 0,
            payout:
                num.tryParse('${o['courier_amount'] ?? 0}')?.toDouble() ?? 0,
            step: step,
          );
          return;
        }

        if (parcels.isNotEmpty && parcels.first is Map) {
          final p = Map<String, dynamic>.from(parcels.first as Map);
          final statusStr = p['status']?.toString() ?? 'pending';
          final step = switch (statusStr) {
            'accepted' => DriverJobStep.accepted,
            'at_sender' => DriverJobStep.atMerchant,
            'picked_up' || 'in_transit' || 'at_recipient' => DriverJobStep.inTransit,
            'delivered' => DriverJobStep.completed,
            _ => DriverJobStep.incoming,
          };

          state = DriverJob(
            id: (p['id'] as num).toInt(),
            type: DriverJobType.gocolis,
            title: 'Colis #${p['id']}',
            pickupAddress: (p['pickup_address'] ?? 'Adresse expéditeur').toString(),
            deliveryAddress:
                (p['delivery_address'] ?? 'Adresse destinataire').toString(),
            distanceKm: (p['distance_km'] as num?)?.toDouble() ?? 0,
            payout:
                num.tryParse('${p['courier_amount'] ?? 0}')?.toDouble() ?? 0,
            parcelSize: p['size']?.toString(),
            step: step,
          );
          return;
        }

        state = null;
      }
    } catch (_) {}
  }

  void acceptJob() {
    if (state == null) return;
    final current = state!;
    final endpoint = current.type == DriverJobType.gocolis
        ? '/driver/parcels/${current.id}/accept'
        : '/driver/orders/${current.id}/accept';
    state = current.copyWith(step: DriverJobStep.accepted);
    _safeIgnore(_api?.post(endpoint));
  }

  void refuseJob() {
    if (state == null) return;
    final current = state!;
    final endpoint = current.type == DriverJobType.gocolis
        ? '/driver/parcels/${current.id}/refuse'
        : '/driver/orders/${current.id}/refuse';
    _safeIgnore(_api?.post(endpoint));
    state = null;
  }

  void arrivedAtMerchant() {
    if (state == null) return;
    final current = state!;
    state = current.copyWith(step: DriverJobStep.atMerchant);
    if (current.type == DriverJobType.gocolis) {
      _safeIgnore(_api?.post('/driver/parcels/${current.id}/at-sender'));
    }
  }

  Future<(bool, String?)> pickedUp({String? senderCode}) async {
    if (state == null) return (false, 'Aucune course active.');
    final current = state!;
    if (_api == null) return (false, 'Client API non initialisé.');

    try {
      if (current.type == DriverJobType.gocolis) {
        final code = (senderCode ?? '').trim();
        if (code.length != 4) {
          return (false, 'Veuillez saisir le code expéditeur à 4 chiffres.');
        }
        final res = await _api.post(
          '/parcels/${current.id}/verify-sender-code',
          data: {'code': code},
        );
        if (res.statusCode == 200 && res.data is Map && res.data['succes'] == true) {
          state = current.copyWith(step: DriverJobStep.inTransit);
          return (true, null);
        }
        return (false, 'Code expéditeur invalide.');
      } else {
        final res = await _api.post('/driver/orders/${current.id}/confirm-pickup');
        if (res.statusCode == 200 && res.data is Map && res.data['succes'] == true) {
          state = current.copyWith(step: DriverJobStep.inTransit);
          return (true, null);
        }
        return (false, 'Impossible de confirmer le ramassage.');
      }
    } on DioException catch (e) {
      return (false, ApiClient.messageErreur(e));
    } catch (_) {
      return (false, 'Erreur lors de la validation du ramassage.');
    }
  }

  Future<(bool, String?)> verifyCode(String inputCode) async {
    if (state == null) return (false, 'Aucune course active.');
    final current = state!;
    final code = inputCode.trim();
    if (code.length != 4) {
      return (false, 'Le code doit contenir 4 chiffres.');
    }
    if (_api == null) return (false, 'Client API non initialisé.');

    final endpoint = current.type == DriverJobType.gocolis
        ? '/parcels/${current.id}/verify-recipient-code'
        : '/driver/orders/${current.id}/confirm-delivery';

    try {
      final res = await _api.post(endpoint, data: {'code': code});
      if (res.statusCode == 200 && res.data is Map && res.data['succes'] == true) {
        state = current.copyWith(
          step: DriverJobStep.completed,
          failedCodeAttempts: 0,
        );
        return (true, null);
      }
      return (false, 'Code de confirmation refusé.');
    } on DioException catch (e) {
      final status = e.response?.statusCode;
      final message = ApiClient.messageErreur(e);
      if (status == 423 || message.toLowerCase().contains('verrouill') || message.toLowerCase().contains('bloqu')) {
        state = current.copyWith(
          failedCodeAttempts: 3,
          step: DriverJobStep.securityLocked,
        );
      } else {
        final attempts = current.failedCodeAttempts + 1;
        state = current.copyWith(
          failedCodeAttempts: attempts,
          step: attempts >= 3 ? DriverJobStep.securityLocked : current.step,
        );
      }
      return (false, message);
    } catch (_) {
      return (false, 'Erreur réseau lors de la vérification du code.');
    }
  }
}

final driverActiveJobProvider =
    StateNotifierProvider<DriverActiveJobNotifier, DriverJob?>((ref) {
  final api = ref.watch(apiClientProvider);
  return DriverActiveJobNotifier(api);
});

final driverTransactionsProvider = Provider<List<DriverTransaction>>((ref) {
  return ref.watch(driverProfileProvider).transactions;
});
