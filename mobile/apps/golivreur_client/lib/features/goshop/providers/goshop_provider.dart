import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:golivreur_core/models/modeles.dart';
import '../../auth/providers/auth_provider.dart';

// ─── État Météo / Mode Pluie à Daloa ──────────────────────────────────────────
class WeatherState {
  final bool isRainMode;
  final String weatherAlertMessage;
  final int rainDelayMinutes;

  const WeatherState({
    this.isRainMode = true, // Actif par défaut selon les maquettes Daloa
    this.weatherAlertMessage = '🌧️ Mode Pluie actif à Daloa : Délais adaptés et livreurs prudents.',
    this.rainDelayMinutes = 15,
  });
}

class WeatherNotifier extends StateNotifier<WeatherState> {
  WeatherNotifier() : super(const WeatherState());

  void toggleRainMode() {
    state = WeatherState(
      isRainMode: !state.isRainMode,
      weatherAlertMessage: !state.isRainMode
          ? '🌧️ Mode Pluie actif à Daloa : Délais adaptés et livreurs prudents.'
          : '☀️ Météo dégagée sur Daloa.',
    );
  }
}

final weatherProvider = StateNotifierProvider<WeatherNotifier, WeatherState>((ref) {
  return WeatherNotifier();
});

// ─── Quartiers de Daloa ───────────────────────────────────────────────────────
final daloaNeighborhoodsProvider = Provider<List<String>>((ref) {
  return [
    'Quartier Résidentiel',
    'Lobia',
    'Tazibou',
    'Quartier Commerce',
    'Kennedy',
    'Hublot',
    'Quartier Soleil',
    'Quartier Marais',
    'Gbeuliville',
    'Abattoir',
  ];
});

final selectedNeighborhoodProvider = StateProvider<String>((ref) {
  return 'Quartier Résidentiel';
});

// ─── Catégories GoShop (chargées dynamiquement depuis la BDD) ────────────────
class CategoryItem {
  final String id;
  final String name;
  final String? emoji;
  final String? logoUrl;
  final String iconName;

  const CategoryItem({
    required this.id,
    required this.name,
    this.emoji,
    this.logoUrl,
    this.iconName = 'storefront',
  });

  factory CategoryItem.fromModel(CategoryModel model) => CategoryItem(
        id: model.slug,
        name: model.name,
        emoji: model.emoji,
        logoUrl: model.logoUrl,
        iconName: model.icon ?? model.slug,
      );
}

const List<CategoryItem> fallbackCategoriesList = [
  CategoryItem(id: 'all', name: 'Tous', emoji: '🏬', iconName: 'apps'),
  CategoryItem(id: 'restaurant', name: 'Restaurants', emoji: '🍽️', iconName: 'restaurant'),
  CategoryItem(id: 'maquis', name: 'Maquis & Grillades', emoji: '🍖', iconName: 'outdoor_grill'),
  CategoryItem(id: 'fast_food', name: 'Fast-Foods', emoji: '🍔', iconName: 'lunch_dining'),
  CategoryItem(id: 'pharmacie', name: 'Pharmacies', emoji: '💊', iconName: 'local_pharmacy'),
  CategoryItem(id: 'boulangerie', name: 'Boulangeries', emoji: '🥐', iconName: 'bakery_dining'),
  CategoryItem(id: 'supermarche', name: 'Supermarchés', emoji: '🛒', iconName: 'shopping_cart'),
  CategoryItem(id: 'boissons', name: 'Boissons & Vins', emoji: '🥤', iconName: 'liquor'),
  CategoryItem(id: 'boutique', name: 'Mode & Tissus', emoji: '👕', iconName: 'checkroom'),
  CategoryItem(id: 'high_tech', name: 'High-Tech', emoji: '📱', iconName: 'devices'),
];

final categoriesProvider = FutureProvider<List<CategoryItem>>((ref) async {
  final api = ref.watch(apiClientProvider);

  try {
    final response = await api.get('/client/categories');
    if (response.statusCode == 200 && response.data['succes'] == true) {
      final List data = response.data['donnees'] as List;
      final dynamicCategories = data
          .map((json) => CategoryItem.fromModel(
                CategoryModel.fromJson(json as Map<String, dynamic>),
              ))
          .toList();

      return [
        const CategoryItem(id: 'all', name: 'Tous', emoji: '🏬', iconName: 'apps'),
        ...dynamicCategories,
      ];
    }
  } catch (_) {
    // Repli local uniquement si l'API est inaccessible
  }

  return fallbackCategoriesList;
});

final selectedCategoryProvider = StateProvider<String>((ref) => 'all');

// ─── Provider Commerces de Daloa ──────────────────────────────────────────────
final merchantsListProvider = FutureProvider<List<MerchantModel>>((ref) async {
  final api = ref.watch(apiClientProvider);

  try {
    final response = await api.get('/client/merchants');
    if (response.statusCode == 200 && response.data['succes'] == true) {
      final List data = response.data['donnees'] as List;
      return data.map((json) => MerchantModel.fromJson(json)).toList();
    }
  } catch (_) {
    // Si l'API renvoie une erreur ou en mode hors-ligne, fallback sur les données locales réalistes de Daloa
  }

  // Fallback données réalistes Daloa validées
  return [
    const MerchantModel(
      id: 1,
      name: 'Maquis Chez Tantie Adjoua',
      category: 'maquis',
      categoryName: 'Maquis & Grillades',
      emoji: '🍖',
      address: 'Quartier Commerce, Daloa',
      lat: 6.8821,
      lng: -6.4478,
      prepTimeMinutes: 20,
      isOpen: true,
      averageRating: 4.8,
      photoUrl: 'https://images.unsplash.com/photo-1555396273-367ea4eb4db5?w=500',
      products: [
        ProductModel(
          id: 101,
          name: 'Garba Royal (Attiéké + Poisson Thon)',
          price: 1500,
          description: 'Attiéké frais de Daloa avec pavé de thon frit assaisonné, piment et tomates.',
          isAvailable: true,
          category: 'Plats du jour',
        ),
        ProductModel(
          id: 102,
          name: 'Poulet Braisé Maison (Demi)',
          price: 3000,
          description: 'Poulet fermier braisé au feu de bois avec oignons caramélisés et alloco.',
          isAvailable: true,
          category: 'Grillades',
        ),
        ProductModel(
          id: 103,
          name: 'Alloco Croustillant',
          price: 500,
          description: 'Bananes plantains mûres frites dans de l\'huile végétale propre.',
          isAvailable: true,
          category: 'Accompagnements',
        ),
        ProductModel(
          id: 104,
          name: 'Jus de Bissap Maison Glacé',
          price: 500,
          description: 'Jus naturel d\'hibiscus parfumé à la menthe et vanille (500ml).',
          isAvailable: true,
          category: 'Boissons',
        ),
      ],
    ),
    const MerchantModel(
      id: 2,
      name: 'Pharmacie Centrale Daloa',
      category: 'pharmacie',
      categoryName: 'Pharmacies',
      emoji: '💊',
      address: 'Place de la Mairie, Daloa',
      lat: 6.8770,
      lng: -6.4502,
      prepTimeMinutes: 10,
      isOpen: true,
      averageRating: 4.9,
      photoUrl: 'https://images.unsplash.com/photo-1586015555751-63bb77f4322a?w=500',
      products: [
        ProductModel(
          id: 201,
          name: 'Paracétamol 1000mg (Boîte de 16)',
          price: 1200,
          description: 'Antalgique et antipyrétique de première intention.',
          isAvailable: true,
          category: 'Médicaments',
        ),
        ProductModel(
          id: 202,
          name: 'Thermomètre Digital Précision',
          price: 2500,
          description: 'Mesure rapide en 10 secondes avec alerte sonore de fièvre.',
          isAvailable: true,
          category: 'Matériel médical',
        ),
        ProductModel(
          id: 203,
          name: 'Sérum Physiologique (Pack de 10)',
          price: 1500,
          description: 'Pour lavage nasal et oculaire nourrissons et adultes.',
          isAvailable: true,
          category: 'Soins',
        ),
      ],
    ),
    const MerchantModel(
      id: 3,
      name: 'Fast Food Speed Burger Daloa',
      category: 'fast_food',
      categoryName: 'Fast-Foods',
      emoji: '🍔',
      address: 'Rue du Commerce, Daloa',
      lat: 6.8795,
      lng: -6.4521,
      prepTimeMinutes: 15,
      isOpen: true,
      averageRating: 4.5,
      photoUrl: 'https://images.unsplash.com/photo-1568901346375-23c9450c58cd?w=500',
      products: [
        ProductModel(
          id: 301,
          name: 'Burger Double Cheese Bacon',
          price: 2500,
          description: 'Double steak haché pur bœuf, cheddar fondant, sauce maison et frites.',
          isAvailable: true,
          category: 'Burgers',
        ),
        ProductModel(
          id: 302,
          name: 'Sandwich Chawarma Poulet',
          price: 1500,
          description: 'Pain pita frais garni de poulet mariné, crudités et sauce blanche.',
          isAvailable: true,
          category: 'Chawarma',
        ),
        ProductModel(
          id: 303,
          name: 'Canette Coca-Cola 33cl',
          price: 500,
          description: 'Boisson rafraîchissante servie très fraîche.',
          isAvailable: true,
          category: 'Boissons',
        ),
      ],
    ),
    const MerchantModel(
      id: 4,
      name: 'Boulangerie La Centrale Daloa',
      category: 'boulangerie',
      categoryName: 'Boulangeries & Pâtisseries',
      emoji: '🥐',
      address: 'Avenue Houphouët-Boigny, face Gare UTB, Daloa',
      lat: 6.8745,
      lng: -6.4580,
      prepTimeMinutes: 10,
      isOpen: true,
      averageRating: 4.7,
      photoUrl: 'https://images.unsplash.com/photo-1509440159596-0249088772ff?w=500',
      products: [
        ProductModel(
          id: 401,
          name: 'Croissant Pur Beurre Doré',
          price: 400,
          description: 'Feuilletage croustillant au beurre fin, cuit du matin.',
          isAvailable: true,
          category: 'Viennoiseries',
        ),
        ProductModel(
          id: 402,
          name: 'Baguette Traditionnelle',
          price: 200,
          description: 'Baguette croustillante à la mie aérée.',
          isAvailable: true,
          category: 'Pains',
        ),
      ],
    ),
  ];
});

// ─── Gestion du Panier (Mono-commerce comme spécifié dans Stitch) ──────────────
class CartItem {
  final ProductModel product;
  final int quantity;
  final String? instructions;

  const CartItem({
    required this.product,
    this.quantity = 1,
    this.instructions,
  });

  CartItem copyWith({int? quantity, String? instructions}) {
    return CartItem(
      product: product,
      quantity: quantity ?? this.quantity,
      instructions: instructions ?? this.instructions,
    );
  }

  double get total => product.price * quantity;
}

class CartState {
  final MerchantModel? merchant;
  final List<CartItem> items;
  final double deliveryFee;

  const CartState({
    this.merchant,
    this.items = const [],
    this.deliveryFee = 800, // Tarif standard Daloa
  });

  int get totalItemCount => items.fold(0, (sum, i) => sum + i.quantity);
  double get subtotal => items.fold(0.0, (sum, i) => sum + i.total);
  double get total => subtotal + (items.isEmpty ? 0 : deliveryFee);

  CartState copyWith({
    MerchantModel? merchant,
    List<CartItem>? items,
    double? deliveryFee,
  }) {
    return CartState(
      merchant: merchant ?? this.merchant,
      items: items ?? this.items,
      deliveryFee: deliveryFee ?? this.deliveryFee,
    );
  }
}

class CartNotifier extends StateNotifier<CartState> {
  CartNotifier() : super(const CartState());

  void addProduct(MerchantModel merchant, ProductModel product, {int quantity = 1}) {
    // Si le panier contient déjà des produits d'un autre commerce, on réinitialise pour ce nouveau commerce
    if (state.merchant != null && state.merchant!.id != merchant.id) {
      state = CartState(
        merchant: merchant,
        items: [CartItem(product: product, quantity: quantity)],
      );
      return;
    }

    final existingIndex = state.items.indexWhere((i) => i.product.id == product.id);
    if (existingIndex >= 0) {
      final updatedList = List<CartItem>.from(state.items);
      final current = updatedList[existingIndex];
      updatedList[existingIndex] = current.copyWith(quantity: current.quantity + quantity);
      state = state.copyWith(merchant: merchant, items: updatedList);
    } else {
      state = state.copyWith(
        merchant: merchant,
        items: [...state.items, CartItem(product: product, quantity: quantity)],
      );
    }
  }

  void updateQuantity(int productId, int newQuantity) {
    if (newQuantity <= 0) {
      removeItem(productId);
      return;
    }
    final updatedList = state.items.map((item) {
      if (item.product.id == productId) {
        return item.copyWith(quantity: newQuantity);
      }
      return item;
    }).toList();
    state = state.copyWith(items: updatedList);
  }

  void removeItem(int productId) {
    final updatedList = state.items.where((i) => i.product.id != productId).toList();
    if (updatedList.isEmpty) {
      state = const CartState();
    } else {
      state = state.copyWith(items: updatedList);
    }
  }

  void clear() {
    state = const CartState();
  }
}

final cartProvider = StateNotifierProvider<CartNotifier, CartState>((ref) {
  return CartNotifier();
});

