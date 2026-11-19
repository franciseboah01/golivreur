import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:golivreur_core/config/env_config.dart';
import 'package:golivreur_core/models/modeles.dart';
import 'package:golivreur_core/services/api_client.dart';
import 'package:image_picker/image_picker.dart';

void _safeIgnore(Future<dynamic>? f) {
  f?.then((_) {}, onError: (_) {});
}

// ─── Informations du Commerce ────────────────────────────────────────────────
class BusinessProfileState {
  final int id;
  final String name;
  final String category;
  final String categorySlug;
  final String? emoji;
  final String? logoUrl;
  final String? photoUrl;
  final String quarter;
  final String? cityName;
  final String? description;
  final String openHoursText;
  final String ownerName;
  final String ownerPhone;
  final String? ownerEmail;
  final String? ownerPhotoUrl;
  final String language;
  final bool notificationsEnabled;
  final bool isOpen;
  final int prepTimeMinutes;
  final double rating;
  final int reviewCount;
  final double walletBalance;

  const BusinessProfileState({
    this.id = 0,
    this.name = '',
    this.category = '',
    this.categorySlug = '',
    this.emoji,
    this.logoUrl,
    this.photoUrl,
    this.quarter = '',
    this.cityName,
    this.description,
    this.openHoursText = '',
    this.ownerName = '',
    this.ownerPhone = '',
    this.ownerEmail,
    this.ownerPhotoUrl,
    this.language = 'fr',
    this.notificationsEnabled = true,
    this.isOpen = false,
    this.prepTimeMinutes = 20,
    this.rating = 0,
    this.reviewCount = 0,
    this.walletBalance = 0,
  });

  BusinessProfileState copyWith({
    int? id,
    String? name,
    String? category,
    String? categorySlug,
    String? emoji,
    String? logoUrl,
    String? photoUrl,
    String? quarter,
    String? cityName,
    String? description,
    String? openHoursText,
    String? ownerName,
    String? ownerPhone,
    String? ownerEmail,
    String? ownerPhotoUrl,
    String? language,
    bool? notificationsEnabled,
    bool? isOpen,
    int? prepTimeMinutes,
    double? rating,
    int? reviewCount,
    double? walletBalance,
  }) {
    return BusinessProfileState(
      id: id ?? this.id,
      name: name ?? this.name,
      category: category ?? this.category,
      categorySlug: categorySlug ?? this.categorySlug,
      emoji: emoji ?? this.emoji,
      logoUrl: logoUrl ?? this.logoUrl,
      photoUrl: photoUrl ?? this.photoUrl,
      quarter: quarter ?? this.quarter,
      cityName: cityName ?? this.cityName,
      description: description ?? this.description,
      openHoursText: openHoursText ?? this.openHoursText,
      ownerName: ownerName ?? this.ownerName,
      ownerPhone: ownerPhone ?? this.ownerPhone,
      ownerEmail: ownerEmail ?? this.ownerEmail,
      ownerPhotoUrl: ownerPhotoUrl ?? this.ownerPhotoUrl,
      language: language ?? this.language,
      notificationsEnabled: notificationsEnabled ?? this.notificationsEnabled,
      isOpen: isOpen ?? this.isOpen,
      prepTimeMinutes: prepTimeMinutes ?? this.prepTimeMinutes,
      rating: rating ?? this.rating,
      reviewCount: reviewCount ?? this.reviewCount,
      walletBalance: walletBalance ?? this.walletBalance,
    );
  }
}

class BusinessProfileNotifier extends StateNotifier<BusinessProfileState> {
  final ApiClient? _api;

  BusinessProfileNotifier([this._api]) : super(const BusinessProfileState());

  void reinitialiser() {
    state = const BusinessProfileState();
  }

  void setWalletBalance(double balance) {
    state = state.copyWith(walletBalance: balance);
  }

  void _appliquerDonneesProfil(Map<String, dynamic> d) {
    state = state.copyWith(
      id: (d['merchant_id'] ?? d['id']) as int? ?? state.id,
      name: (d['business_name'] ?? d['name']) as String? ?? state.name,
      categorySlug: d['category'] as String? ?? state.categorySlug,
      category: d['category_name'] as String? ??
          d['category'] as String? ??
          state.category,
      emoji: d['emoji'] as String? ?? state.emoji,
      logoUrl: GoLivreurEnv.resolveMediaUrl(d['logo_url'] as String?),
      photoUrl: GoLivreurEnv.resolveMediaUrl(
        (d['merchant_photo_url'] ?? d['photo_url']) as String?,
      ),
      quarter: d['address'] as String? ?? state.quarter,
      cityName: d['city_name'] as String? ?? state.cityName,
      description: d['description'] as String?,
      openHoursText: (d['open_hours_text'] as String?) ?? state.openHoursText,
      ownerName: d['name'] as String? ?? state.ownerName,
      ownerPhone: d['phone'] as String? ?? state.ownerPhone,
      ownerEmail: d['email'] as String?,
      ownerPhotoUrl: GoLivreurEnv.resolveMediaUrl(d['photo_url'] as String?),
      language: d['language'] as String? ?? state.language,
      notificationsEnabled:
          d['notifications_enabled'] as bool? ?? state.notificationsEnabled,
      isOpen: d['is_open'] == true,
      prepTimeMinutes:
          (d['prep_time_minutes'] as num?)?.toInt() ?? state.prepTimeMinutes,
      rating: (d['average_rating'] as num?)?.toDouble() ?? state.rating,
      reviewCount: (d['total_ratings'] as num?)?.toInt() ?? state.reviewCount,
    );
  }

  Future<void> chargerProfil() async {
    if (_api == null) return;
    try {
      final res = await _api.get('/merchant/profile');
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
    required String ownerName,
    required String ownerPhone,
    String? ownerEmail,
    required String businessName,
    required String categorySlug,
    String? emoji,
    required String address,
    String? description,
    String? openHoursText,
    String language = 'fr',
    bool notificationsEnabled = true,
    XFile? logoFile,
    XFile? merchantPhotoFile,
    XFile? ownerPhotoFile,
  }) async {
    if (_api == null) {
      return (false, 'Client API non initialisé.');
    }
    try {
      final files = <String, XFile>{
        if (logoFile != null) 'logo': logoFile,
        if (merchantPhotoFile != null) 'merchant_photo': merchantPhotoFile,
        if (ownerPhotoFile != null) 'photo': ownerPhotoFile,
      };

      final res = await _api.uploadMultipart(
        '/merchant/profile',
        fields: {
          'name': ownerName,
          'phone': ownerPhone,
          'email': ownerEmail ?? '',
          'business_name': businessName,
          'category': categorySlug,
          'emoji': emoji ?? '',
          'address': address,
          'description': description ?? '',
          'open_hours': openHoursText ?? '',
          'language': language,
          'notifications_enabled': notificationsEnabled ? '1' : '0',
        },
        files: files.isNotEmpty ? files : null,
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
      return (false, 'Impossible de mettre à jour le profil commerce.');
    }
  }

  void toggleOpenStatus() {
    final nextState = !state.isOpen;
    state = state.copyWith(isOpen: nextState);
    _safeIgnore(_api?.post('/merchant/status', data: {'is_open': nextState}));
  }

  void updatePrepTime(int minutes) {
    state = state.copyWith(prepTimeMinutes: minutes);
    _safeIgnore(
      _api?.put('/merchant/profile', data: {'prep_time_minutes': minutes}),
    );
  }
}

final businessProfileProvider =
    StateNotifierProvider<BusinessProfileNotifier, BusinessProfileState>((ref) {
  final api = ref.watch(apiClientProvider);
  return BusinessProfileNotifier(api);
});

// ─── Catalogue & Gestion des Stocks en direct ─────────────────────────────────
class BusinessProductItem {
  final int id;
  final String name;
  final double price;
  final String category;
  final String? description;
  final bool isAvailable;

  const BusinessProductItem({
    required this.id,
    required this.name,
    required this.price,
    required this.category,
    this.description,
    this.isAvailable = true,
  });

  BusinessProductItem copyWith({
    String? name,
    double? price,
    String? category,
    String? description,
    bool? isAvailable,
  }) {
    return BusinessProductItem(
      id: id,
      name: name ?? this.name,
      price: price ?? this.price,
      category: category ?? this.category,
      description: description ?? this.description,
      isAvailable: isAvailable ?? this.isAvailable,
    );
  }
}

class BusinessCatalogNotifier extends StateNotifier<List<BusinessProductItem>> {
  final ApiClient? _api;

  BusinessCatalogNotifier([this._api]) : super(const []);

  void reinitialiser() {
    state = const [];
  }

  Future<void> chargerCatalogue() async {
    if (_api == null) return;
    try {
      final res = await _api.get('/merchant/products');
      if (res.statusCode == 200 &&
          res.data is Map &&
          res.data['succes'] == true &&
          res.data['donnees'] is List) {
        final list = res.data['donnees'] as List;
        state = list
            .whereType<Map>()
            .map((raw) {
              final json = Map<String, dynamic>.from(raw);
              return BusinessProductItem(
                id: (json['id'] as num).toInt(),
                name: (json['name'] ?? '').toString(),
                price: num.tryParse('${json['price'] ?? 0}')?.toDouble() ?? 0,
                category: (json['category'] ?? 'Catalogue').toString(),
                description: json['description']?.toString(),
                isAvailable:
                    json['is_available'] == true || json['is_available'] == 1,
              );
            })
            .toList();
      }
    } catch (_) {}
  }

  void toggleStock(int productId) {
    state = state.map((p) {
      if (p.id == productId) {
        final nextAvail = !p.isAvailable;
        _safeIgnore(
          _api?.put('/merchant/products/$productId', data: {
            'is_available': nextAvail,
          }),
        );
        return p.copyWith(isAvailable: nextAvail);
      }
      return p;
    }).toList();
  }

  Future<void> addProduct({
    required String name,
    required double price,
    required String category,
    String? description,
  }) async {
    if (_api == null) return;
    try {
      final res = await _api.post('/merchant/products', data: {
        'name': name,
        'price': price,
        'category': category,
        'description': description,
        'is_available': true,
      });
      if ((res.statusCode == 201 || res.statusCode == 200) &&
          res.data is Map &&
          res.data['donnees'] is Map) {
        final json = Map<String, dynamic>.from(res.data['donnees'] as Map);
        final created = BusinessProductItem(
          id: (json['id'] as num).toInt(),
          name: (json['name'] ?? name).toString(),
          price: num.tryParse('${json['price'] ?? price}')?.toDouble() ?? price,
          category: (json['category'] ?? category).toString(),
          description: json['description']?.toString() ?? description,
          isAvailable:
              json['is_available'] == true || json['is_available'] == 1,
        );
        state = [...state, created];
        return;
      }
    } catch (_) {}
    await chargerCatalogue();
  }

  void updateProduct(
    int id, {
    required String name,
    required double price,
    required String category,
    String? description,
  }) {
    state = state.map((p) {
      if (p.id == id) {
        return p.copyWith(
          name: name,
          price: price,
          category: category,
          description: description,
        );
      }
      return p;
    }).toList();
    _safeIgnore(
      _api?.put('/merchant/products/$id', data: {
        'name': name,
        'price': price,
        'category': category,
        'description': description,
      }),
    );
  }

  void deleteProduct(int id) {
    state = state.where((p) => p.id != id).toList();
    _safeIgnore(_api?.delete('/merchant/products/$id'));
  }
}

final businessCatalogProvider =
    StateNotifierProvider<BusinessCatalogNotifier, List<BusinessProductItem>>(
        (ref) {
  final api = ref.watch(apiClientProvider);
  return BusinessCatalogNotifier(api);
});

// ─── Commandes Business ───────────────────────────────────────────────────────
enum BusinessOrderStatus {
  pending,
  accepted,
  ready,
  pickedUp,
  delivered,
  refused,
}

class BusinessOrder {
  final int id;
  final String clientName;
  final String clientPhone;
  final String deliveryAddress;
  final List<OrderItemModel> items;
  final double totalAmount;
  final String paymentMethod;
  final BusinessOrderStatus status;
  final DateTime createdAt;
  final int secondsRemaining;

  const BusinessOrder({
    required this.id,
    required this.clientName,
    required this.clientPhone,
    required this.deliveryAddress,
    required this.items,
    required this.totalAmount,
    required this.paymentMethod,
    this.status = BusinessOrderStatus.pending,
    required this.createdAt,
    this.secondsRemaining = 45,
  });

  BusinessOrder copyWith({
    BusinessOrderStatus? status,
    int? secondsRemaining,
  }) {
    return BusinessOrder(
      id: id,
      clientName: clientName,
      clientPhone: clientPhone,
      deliveryAddress: deliveryAddress,
      items: items,
      totalAmount: totalAmount,
      paymentMethod: paymentMethod,
      status: status ?? this.status,
      createdAt: createdAt,
      secondsRemaining: secondsRemaining ?? this.secondsRemaining,
    );
  }
}

class BusinessOrdersNotifier extends StateNotifier<List<BusinessOrder>> {
  final ApiClient? _api;

  BusinessOrdersNotifier([this._api]) : super(const []);

  void reinitialiser() {
    state = const [];
  }

  Future<void> chargerCommandes() async {
    if (_api == null) return;
    try {
      final res = await _api.get('/merchant/orders');
      if (res.statusCode == 200 &&
          res.data is Map &&
          res.data['succes'] == true &&
          res.data['donnees'] is List) {
        final list = res.data['donnees'] as List;
        state = list
            .whereType<Map>()
            .map((json) =>
                _mapJsonToBusinessOrder(Map<String, dynamic>.from(json)))
            .toList();
      }
    } catch (_) {}
  }

  static BusinessOrder _mapJsonToBusinessOrder(Map<String, dynamic> json) {
    final statusStr = json['status']?.toString() ?? 'pending';
    final status = switch (statusStr) {
      'pending' => BusinessOrderStatus.pending,
      'accepted' => BusinessOrderStatus.accepted,
      'ready' => BusinessOrderStatus.ready,
      'picked_up' || 'in_transit' => BusinessOrderStatus.pickedUp,
      'delivered' => BusinessOrderStatus.delivered,
      _ => BusinessOrderStatus.refused,
    };

    final rawItems = json['items'] is List ? json['items'] as List : const [];
    final items = rawItems.whereType<Map>().map((raw) {
      final item = Map<String, dynamic>.from(raw);
      return OrderItemModel(
        id: (item['id'] as num?)?.toInt() ?? 0,
        productId: (item['product_id'] as num?)?.toInt() ?? 0,
        productNameSnapshot: (item['product_name_snapshot'] ??
                (item['product'] is Map ? item['product']['name'] : null) ??
                'Article')
            .toString(),
        quantity: (item['quantity'] as num?)?.toInt() ?? 1,
        unitPrice: num.tryParse('${item['unit_price'] ?? 0}')?.toDouble() ?? 0,
      );
    }).toList();

    final client = json['client'] is Map
        ? Map<String, dynamic>.from(json['client'] as Map)
        : <String, dynamic>{};

    return BusinessOrder(
      id: (json['id'] as num).toInt(),
      clientName: (client['name'] ?? 'Client').toString(),
      clientPhone: (client['phone'] ?? '').toString(),
      deliveryAddress: (json['delivery_address'] ?? '').toString(),
      items: items,
      totalAmount:
          num.tryParse('${json['products_total'] ?? 0}')?.toDouble() ?? 0,
      paymentMethod: (json['payment_method'] ?? 'cash').toString(),
      status: status,
      createdAt: json['created_at'] != null
          ? DateTime.tryParse('${json['created_at']}') ?? DateTime.now()
          : DateTime.now(),
      secondsRemaining: 45,
    );
  }

  void acceptOrder(int orderId) {
    state = state.map((o) {
      if (o.id == orderId) {
        return o.copyWith(status: BusinessOrderStatus.accepted);
      }
      return o;
    }).toList();
    _safeIgnore(_api?.post('/merchant/orders/$orderId/accept'));
  }

  void refuseOrder(int orderId) {
    state = state.map((o) {
      if (o.id == orderId) {
        return o.copyWith(status: BusinessOrderStatus.refused);
      }
      return o;
    }).toList();
    _safeIgnore(
      _api?.post(
        '/merchant/orders/$orderId/refuse',
        data: {'raison': 'Rupture de stock'},
      ),
    );
  }

  void markOrderReady(int orderId) {
    state = state.map((o) {
      if (o.id == orderId) {
        return o.copyWith(status: BusinessOrderStatus.ready);
      }
      return o;
    }).toList();
    _safeIgnore(_api?.post('/merchant/orders/$orderId/ready'));
  }
}

final businessOrdersProvider =
    StateNotifierProvider<BusinessOrdersNotifier, List<BusinessOrder>>((ref) {
  final api = ref.watch(apiClientProvider);
  return BusinessOrdersNotifier(api);
});

// ─── Données Statistiques Revenus ─────────────────────────────────────────────
class DisbursementItem {
  final String id;
  final double amount;
  final String provider;
  final String date;
  final String status;

  const DisbursementItem({
    required this.id,
    required this.amount,
    required this.provider,
    required this.date,
    required this.status,
  });

  factory DisbursementItem.fromJson(Map<String, dynamic> json) {
    return DisbursementItem(
      id: (json['id'] ?? '').toString(),
      amount: num.tryParse('${json['montant'] ?? json['amount'] ?? 0}')
              ?.toDouble() ??
          0,
      provider: (json['description'] ?? json['provider'] ?? 'Versement')
          .toString(),
      date: (json['date'] ?? '').toString(),
      status: (json['statut'] ?? json['status'] ?? 'Effectué').toString(),
    );
  }
}

class RevenueStats {
  final double caAujourdhui;
  final int commandesAujourdhui;
  final double caSemaine;
  final int commandesSemaine;
  final double caMois;
  final int commandesMois;
  final List<DisbursementItem> versementsRecents;

  const RevenueStats({
    this.caAujourdhui = 0,
    this.commandesAujourdhui = 0,
    this.caSemaine = 0,
    this.commandesSemaine = 0,
    this.caMois = 0,
    this.commandesMois = 0,
    this.versementsRecents = const [],
  });
}

class RevenueStatsNotifier extends StateNotifier<RevenueStats> {
  final ApiClient? _api;
  final Ref _ref;

  RevenueStatsNotifier(this._api, this._ref) : super(const RevenueStats());

  void reinitialiser() {
    state = const RevenueStats();
  }

  Future<void> chargerRevenus() async {
    if (_api == null) return;
    try {
      final res = await _api.get('/merchant/wallet');
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

        final balance =
            num.tryParse('${wallet['balance'] ?? 0}')?.toDouble() ?? 0;
        _ref.read(businessProfileProvider.notifier).setWalletBalance(balance);

        final rawVersements = donnees['versements_recents'] is List
            ? donnees['versements_recents'] as List
            : const [];
        final versements = rawVersements
            .whereType<Map>()
            .map((e) => DisbursementItem.fromJson(Map<String, dynamic>.from(e)))
            .toList();

        state = RevenueStats(
          caAujourdhui:
              num.tryParse('${stats['ca_aujourdhui'] ?? 0}')?.toDouble() ?? 0,
          commandesAujourdhui:
              (stats['commandes_aujourdhui'] as num?)?.toInt() ?? 0,
          caSemaine:
              num.tryParse('${stats['ca_semaine'] ?? 0}')?.toDouble() ?? 0,
          commandesSemaine: (stats['commandes_semaine'] as num?)?.toInt() ?? 0,
          caMois: num.tryParse('${stats['ca_mois'] ?? 0}')?.toDouble() ?? 0,
          commandesMois: (stats['commandes_mois'] as num?)?.toInt() ?? 0,
          versementsRecents: versements,
        );
      }
    } catch (_) {}
  }
}

final revenueStatsProvider =
    StateNotifierProvider<RevenueStatsNotifier, RevenueStats>((ref) {
  final api = ref.watch(apiClientProvider);
  return RevenueStatsNotifier(api, ref);
});
