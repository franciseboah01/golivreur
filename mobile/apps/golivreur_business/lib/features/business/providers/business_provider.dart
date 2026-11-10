import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:golivreur_core/config/env_config.dart';
import 'package:golivreur_core/models/modeles.dart';
import 'package:golivreur_core/services/api_client.dart';

void _safeIgnore(Future<dynamic>? f) {
  f?.then((_) {}, onError: (_) {});
}

// ─── Informations du Commerce ────────────────────────────────────────────────
class BusinessProfileState {
  final int id;
  final String name;
  final String category;
  final String? emoji;
  final String? logoUrl;
  final String? photoUrl;
  final String quarter;
  final bool isOpen;
  final int prepTimeMinutes;
  final double rating;
  final int reviewCount;
  final double walletBalance;

  const BusinessProfileState({
    this.id = 1,
    this.name = 'Maquis Chez Tantie Adjoua',
    this.category = 'Maquis & Grillades',
    this.emoji = '🍖',
    this.logoUrl,
    this.photoUrl,
    this.quarter = 'Quartier Commerce, Daloa',
    this.isOpen = true,
    this.prepTimeMinutes = 20,
    this.rating = 4.8,
    this.reviewCount = 142,
    this.walletBalance = 148500,
  });

  BusinessProfileState copyWith({
    int? id,
    String? name,
    String? category,
    String? emoji,
    String? logoUrl,
    String? photoUrl,
    bool? isOpen,
    int? prepTimeMinutes,
    double? walletBalance,
  }) {
    return BusinessProfileState(
      id: id ?? this.id,
      name: name ?? this.name,
      category: category ?? this.category,
      emoji: emoji ?? this.emoji,
      logoUrl: logoUrl ?? this.logoUrl,
      photoUrl: photoUrl ?? this.photoUrl,
      quarter: quarter,
      isOpen: isOpen ?? this.isOpen,
      prepTimeMinutes: prepTimeMinutes ?? this.prepTimeMinutes,
      rating: rating,
      reviewCount: reviewCount,
      walletBalance: walletBalance ?? this.walletBalance,
    );
  }
}

class BusinessProfileNotifier extends StateNotifier<BusinessProfileState> {
  final ApiClient? _api;

  BusinessProfileNotifier([this._api]) : super(const BusinessProfileState());

  Future<void> chargerProfil() async {
    try {
      final res = await _api!.get('/merchant/status');
      if (res.statusCode == 200 && res.data['succes'] == true) {
        final d = res.data['donnees'];
        state = state.copyWith(
          id: d['id'] ?? state.id,
          name: d['name'] ?? state.name,
          category: d['category_name'] ?? d['category'] ?? state.category,
          emoji: d['emoji'] as String?,
          logoUrl: GoLivreurEnv.resolveMediaUrl(d['logo_url'] as String?),
          photoUrl: GoLivreurEnv.resolveMediaUrl(d['photo_url'] as String?),
          isOpen: d['is_open'] == true,
          prepTimeMinutes: d['prep_time_minutes'] ?? state.prepTimeMinutes,
        );
      }
    } catch (_) {}
  }

  void toggleOpenStatus() {
    final nextState = !state.isOpen;
    state = state.copyWith(isOpen: nextState);
    _safeIgnore(_api?.post('/merchant/status', data: {'is_open': nextState}));
  }

  void updatePrepTime(int minutes) {
    state = state.copyWith(prepTimeMinutes: minutes);
  }

  void withdrawFunds(double amount) {
    if (amount <= state.walletBalance) {
      state = state.copyWith(walletBalance: state.walletBalance - amount);
    }
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

  BusinessCatalogNotifier([this._api])
      : super([
          const BusinessProductItem(
            id: 1,
            name: 'Poulet braisé (quart)',
            price: 1500,
            category: 'Plats',
            description: 'Poulet fermier braisé au feu de bois avec alloco.',
            isAvailable: true,
          ),
          const BusinessProductItem(
            id: 2,
            name: 'Attiéké + Poisson braisé',
            price: 1000,
            category: 'Plats',
            description: 'Attiéké frais de Daloa avec poisson braisé assaisonné.',
            isAvailable: true,
          ),
          const BusinessProductItem(
            id: 3,
            name: 'Alloco + Œuf',
            price: 500,
            category: 'Accompagnements',
            description: 'Bananes plantains frites bien dorées avec œuf.',
            isAvailable: true,
          ),
          const BusinessProductItem(
            id: 4,
            name: 'Garba thon rouge (Épuisé)',
            price: 800,
            category: 'Plats',
            description: 'Garba classique avec pavé de thon.',
            isAvailable: false,
          ),
          const BusinessProductItem(
            id: 5,
            name: 'Jus de bissap (500ml)',
            price: 300,
            category: 'Boissons',
            description: 'Jus naturel d\'hibiscus parfumé à la menthe.',
            isAvailable: true,
          ),
        ]);

  Future<void> chargerCatalogue() async {
    try {
      final res = await _api!.get('/merchant/products');
      if (res.statusCode == 200 && res.data['succes'] == true) {
        final List list = res.data['donnees'] as List;
        if (list.isNotEmpty) {
          state = list.map((json) => BusinessProductItem(
            id: json['id'] as int,
            name: json['name'] as String,
            price: (num.tryParse(json['price'].toString()) ?? 0).toDouble(),
            category: json['category'] ?? 'Plats',
            description: json['description'],
            isAvailable: json['is_available'] == true || json['is_available'] == 1,
          )).toList();
        }
      }
    } catch (_) {}
  }

  void toggleStock(int productId) {
    state = state.map((p) {
      if (p.id == productId) {
        final nextAvail = !p.isAvailable;
        _safeIgnore(_api?.put('/merchant/products/$productId', data: {
          'is_available': nextAvail,
        }));
        return p.copyWith(isAvailable: nextAvail);
      }
      return p;
    }).toList();
  }

  void addProduct({
    required String name,
    required double price,
    required String category,
    String? description,
  }) {
    final nextId = state.isEmpty ? 1 : state.map((p) => p.id).reduce((a, b) => a > b ? a : b) + 1;
    final newItem = BusinessProductItem(
      id: nextId,
      name: name,
      price: price,
      category: category,
      description: description,
      isAvailable: true,
    );
    state = [...state, newItem];
    _safeIgnore(_api?.post('/merchant/products', data: {
      'name': name,
      'price': price,
      'category': category,
      'description': description,
    }));
  }

  void updateProduct(int id, {required String name, required double price, required String category, String? description}) {
    state = state.map((p) {
      if (p.id == id) {
        return p.copyWith(name: name, price: price, category: category, description: description);
      }
      return p;
    }).toList();
    _safeIgnore(_api?.put('/merchant/products/$id', data: {
      'name': name,
      'price': price,
      'category': category,
      'description': description,
    }));
  }

  void deleteProduct(int id) {
    state = state.where((p) => p.id != id).toList();
    _safeIgnore(_api?.delete('/merchant/products/$id'));
  }
}

final businessCatalogProvider =
    StateNotifierProvider<BusinessCatalogNotifier, List<BusinessProductItem>>((ref) {
  final api = ref.watch(apiClientProvider);
  return BusinessCatalogNotifier(api);
});

// ─── Commandes Business ───────────────────────────────────────────────────────
enum BusinessOrderStatus {
  pending,    // Nouvelle commande reçue (45s chrono)
  accepted,   // En préparation en cuisine
  ready,      // Commande prête pour le coursier
  pickedUp,   // Récupérée par le livreur
  delivered,  // Livrée
  refused,    // Refusée
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

  BusinessOrdersNotifier([this._api])
      : super([
          BusinessOrder(
            id: 108,
            clientName: 'Ama Kouassi',
            clientPhone: '0701000001',
            deliveryAddress: 'Daloa, Quartier Résidentiel (Face Maquis Le Baoulé)',
            items: const [
              OrderItemModel(
                id: 1,
                productId: 1,
                productNameSnapshot: 'Poulet braisé (quart)',
                quantity: 2,
                unitPrice: 1500,
              ),
              OrderItemModel(
                id: 2,
                productId: 3,
                productNameSnapshot: 'Alloco + Œuf',
                quantity: 1,
                unitPrice: 500,
              ),
            ],
            totalAmount: 3500,
            paymentMethod: 'wave',
            status: BusinessOrderStatus.pending,
            createdAt: DateTime.now(),
            secondsRemaining: 42,
          ),
          BusinessOrder(
            id: 107,
            clientName: 'Diallo Ibrahim',
            clientPhone: '0701000004',
            deliveryAddress: 'Carrefour Total, Quartier Lobia',
            items: const [
              OrderItemModel(
                id: 3,
                productId: 2,
                productNameSnapshot: 'Attiéké + Poisson braisé',
                quantity: 1,
                unitPrice: 3000,
              ),
            ],
            totalAmount: 3000,
            paymentMethod: 'orange_money',
            status: BusinessOrderStatus.accepted,
            createdAt: DateTime.now().subtract(const Duration(minutes: 8)),
          ),
        ]);

  Future<void> chargerCommandes() async {
    try {
      final res = await _api!.get('/merchant/orders');
      if (res.statusCode == 200 && res.data['succes'] == true) {
        final List list = res.data['donnees'] as List;
        if (list.isNotEmpty) {
          state = list.map((json) => _mapJsonToBusinessOrder(json as Map<String, dynamic>)).toList();
        }
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

    final rawItems = json['items'] as List? ?? [];
    final items = rawItems.map((item) => OrderItemModel(
      id: item['id'] as int? ?? 1,
      productId: item['product_id'] as int? ?? 1,
      productNameSnapshot: item['product_name_snapshot'] ?? item['product']?['name'] ?? 'Article',
      quantity: item['quantity'] as int? ?? 1,
      unitPrice: (num.tryParse(item['unit_price']?.toString() ?? '') ?? 1000).toDouble(),
    )).toList();

    return BusinessOrder(
      id: json['id'] as int,
      clientName: json['client']?['name'] ?? 'Client Daloa',
      clientPhone: json['client']?['phone'] ?? '0701000000',
      deliveryAddress: json['delivery_address'] ?? 'Daloa',
      items: items,
      totalAmount: (num.tryParse(json['products_total']?.toString() ?? '') ?? 2000).toDouble(),
      paymentMethod: json['payment_method'] ?? 'cash',
      status: status,
      createdAt: json['created_at'] != null ? DateTime.tryParse(json['created_at']) ?? DateTime.now() : DateTime.now(),
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
    _safeIgnore(_api?.post('/merchant/orders/$orderId/refuse', data: {'raison': 'Rupture de stock'}));
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
class RevenueStats {
  final double caAujourdhui;
  final int commandesAujourdhui;
  final double caSemaine;
  final int commandesSemaine;
  final double caMois;
  final int commandesMois;
  final List<DisbursementItem> versementsRecents;

  const RevenueStats({
    this.caAujourdhui = 42500,
    this.commandesAujourdhui = 12,
    this.caSemaine = 285000,
    this.commandesSemaine = 84,
    this.caMois = 950000,
    this.commandesMois = 310,
    this.versementsRecents = const [
      DisbursementItem(
        id: 'VER-091',
        amount: 85000,
        provider: 'Wave Côte d\'Ivoire',
        date: '06 Nov 2026, 18:30',
        status: 'Succès',
      ),
      DisbursementItem(
        id: 'VER-090',
        amount: 110000,
        provider: 'Orange Money Daloa',
        date: '03 Nov 2026, 17:15',
        status: 'Succès',
      ),
    ],
  });
}

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
}

final revenueStatsProvider = Provider<RevenueStats>((ref) {
  return const RevenueStats();
});

