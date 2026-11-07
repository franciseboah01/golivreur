import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:golivreur_core/models/modeles.dart';

// ─── Informations du Commerce ────────────────────────────────────────────────
class BusinessProfileState {
  final int id;
  final String name;
  final String category;
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
    this.quarter = 'Quartier Commerce, Daloa',
    this.isOpen = true,
    this.prepTimeMinutes = 20,
    this.rating = 4.8,
    this.reviewCount = 142,
    this.walletBalance = 148500,
  });

  BusinessProfileState copyWith({
    bool? isOpen,
    int? prepTimeMinutes,
    double? walletBalance,
  }) {
    return BusinessProfileState(
      id: id,
      name: name,
      category: category,
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
  BusinessProfileNotifier() : super(const BusinessProfileState());

  void toggleOpenStatus() {
    state = state.copyWith(isOpen: !state.isOpen);
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
  return BusinessProfileNotifier();
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
  BusinessCatalogNotifier()
      : super([
          const BusinessProductItem(
            id: 1,
            name: 'Garba Royal (Attiéké + Poisson Thon)',
            price: 1500,
            category: 'Plats',
            description: 'Attiéké frais de Daloa avec pavé de thon frit assaisonné, piment et tomates.',
            isAvailable: true,
          ),
          const BusinessProductItem(
            id: 2,
            name: 'Poulet Braisé Maison (Demi)',
            price: 3000,
            category: 'Grillades',
            description: 'Poulet fermier braisé au feu de bois avec oignons caramélisés et alloco.',
            isAvailable: true,
          ),
          const BusinessProductItem(
            id: 3,
            name: 'Alloco Croustillant',
            price: 500,
            category: 'Accompagnements',
            description: 'Bananes plantains frites dans une huile végétale propre.',
            isAvailable: true,
          ),
          const BusinessProductItem(
            id: 4,
            name: 'Jus de Bissap Maison Glacé',
            price: 500,
            category: 'Boissons',
            description: 'Jus naturel d\'hibiscus parfumé à la menthe (500ml).',
            isAvailable: true,
          ),
          const BusinessProductItem(
            id: 5,
            name: 'Sauce Graine + Foutou Banane',
            price: 2000,
            category: 'Plats',
            description: 'Recette traditionnelle ivoirienne servie chaude.',
            isAvailable: false, // Épuisé pour tester le switch
          ),
        ]);

  void toggleStock(int productId) {
    state = state.map((p) {
      if (p.id == productId) {
        return p.copyWith(isAvailable: !p.isAvailable);
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
  }

  void updateProduct(int id, {required String name, required double price, required String category, String? description}) {
    state = state.map((p) {
      if (p.id == id) {
        return p.copyWith(name: name, price: price, category: category, description: description);
      }
      return p;
    }).toList();
  }

  void deleteProduct(int id) {
    state = state.where((p) => p.id != id).toList();
  }
}

final businessCatalogProvider =
    StateNotifierProvider<BusinessCatalogNotifier, List<BusinessProductItem>>((ref) {
  return BusinessCatalogNotifier();
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
  BusinessOrdersNotifier()
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
                productNameSnapshot: 'Garba Royal',
                quantity: 2,
                unitPrice: 1500,
              ),
              OrderItemModel(
                id: 2,
                productId: 3,
                productNameSnapshot: 'Alloco Croustillant',
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
                productNameSnapshot: 'Poulet Braisé Maison (Demi)',
                quantity: 1,
                unitPrice: 3000,
              ),
            ],
            totalAmount: 3000,
            paymentMethod: 'orange_money',
            status: BusinessOrderStatus.accepted,
            createdAt: DateTime.now().subtract(const Duration(minutes: 8)),
          ),
          BusinessOrder(
            id: 105,
            clientName: 'Koffi Jean-Marc',
            clientPhone: '0701000002',
            deliveryAddress: 'Quartier Kennedy',
            items: const [
              OrderItemModel(
                id: 4,
                productId: 1,
                productNameSnapshot: 'Garba Royal',
                quantity: 1,
                unitPrice: 1500,
              ),
            ],
            totalAmount: 1500,
            paymentMethod: 'cash',
            status: BusinessOrderStatus.delivered,
            createdAt: DateTime.now().subtract(const Duration(hours: 1)),
          ),
        ]);

  void acceptOrder(int orderId) {
    state = state.map((o) {
      if (o.id == orderId) {
        return o.copyWith(status: BusinessOrderStatus.accepted);
      }
      return o;
    }).toList();
  }

  void refuseOrder(int orderId) {
    state = state.map((o) {
      if (o.id == orderId) {
        return o.copyWith(status: BusinessOrderStatus.refused);
      }
      return o;
    }).toList();
  }

  void markOrderReady(int orderId) {
    state = state.map((o) {
      if (o.id == orderId) {
        return o.copyWith(status: BusinessOrderStatus.ready);
      }
      return o;
    }).toList();
  }
}

final businessOrdersProvider =
    StateNotifierProvider<BusinessOrdersNotifier, List<BusinessOrder>>((ref) {
  return BusinessOrdersNotifier();
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

