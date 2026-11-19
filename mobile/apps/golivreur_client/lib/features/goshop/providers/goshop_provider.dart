import 'dart:math' as math;
import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:golivreur_core/models/modeles.dart';
import 'package:golivreur_core/providers/branding_provider.dart';
import 'package:golivreur_core/services/api_client.dart' hide apiClientProvider;
import '../../auth/providers/auth_provider.dart';

export 'package:golivreur_core/providers/branding_provider.dart';

// ─── Quartiers & Tarification proportionnelle ────────────────────────────────
class DeliveryNeighborhood {
  final String name;
  final double lat;
  final double lng;
  final double defaultDistanceKm;

  const DeliveryNeighborhood({
    required this.name,
    required this.lat,
    required this.lng,
    required this.defaultDistanceKm,
  });

  factory DeliveryNeighborhood.fromJson(Map<String, dynamic> json) =>
      DeliveryNeighborhood(
        name: json['name'] as String? ?? '',
        lat: (json['lat'] as num?)?.toDouble() ?? 0.0,
        lng: (json['lng'] as num?)?.toDouble() ?? 0.0,
        defaultDistanceKm: (json['distance_km'] as num?)?.toDouble() ?? 2.0,
      );
}

class DeliveryTariffTier {
  final double maxKm;
  final double normal;
  final double pointe;
  final double pluie;

  const DeliveryTariffTier({
    required this.maxKm,
    required this.normal,
    required this.pointe,
    required this.pluie,
  });

  factory DeliveryTariffTier.fromJson(Map<String, dynamic> json) =>
      DeliveryTariffTier(
        maxKm: (json['max_km'] as num?)?.toDouble() ?? 99,
        normal: (json['normal'] as num?)?.toDouble() ?? 800,
        pointe: (json['pointe'] as num?)?.toDouble() ?? 1000,
        pluie: (json['pluie'] as num?)?.toDouble() ?? 1300,
      );
}

const List<DeliveryTariffTier> defaultGoShopBareme = [
  DeliveryTariffTier(maxKm: 2, normal: 500, pointe: 700, pluie: 1000),
  DeliveryTariffTier(maxKm: 4, normal: 800, pointe: 1000, pluie: 1300),
  DeliveryTariffTier(maxKm: 7, normal: 1200, pointe: 1500, pluie: 1800),
  DeliveryTariffTier(maxKm: 99, normal: 1500, pointe: 1800, pluie: 2000),
];

// ─── État Météo / Mode Pluie synchronisé avec le Backend ──────────────────────
class WeatherState {
  final int? cityId;
  final String cityName;
  final double? centerLat;
  final double? centerLng;
  final bool isRainMode;
  final bool emergencyModeActive;
  final String? emergencyMessage;
  final bool bannerDismissed;
  final String weatherAlertMessage;
  final int rainDelayMinutes;
  final bool isLoaded;
  final List<DeliveryTariffTier> bareme;
  final List<DeliveryNeighborhood> neighborhoods;

  const WeatherState({
    this.cityId,
    this.cityName = '',
    this.centerLat,
    this.centerLng,
    this.isRainMode = false,
    this.emergencyModeActive = false,
    this.emergencyMessage,
    this.bannerDismissed = false,
    this.weatherAlertMessage = '',
    this.rainDelayMinutes = 0,
    this.isLoaded = false,
    this.bareme = defaultGoShopBareme,
    this.neighborhoods = const [],
  });

  WeatherState copyWith({
    int? cityId,
    String? cityName,
    double? centerLat,
    double? centerLng,
    bool? isRainMode,
    bool? emergencyModeActive,
    String? emergencyMessage,
    bool? bannerDismissed,
    String? weatherAlertMessage,
    int? rainDelayMinutes,
    bool? isLoaded,
    List<DeliveryTariffTier>? bareme,
    List<DeliveryNeighborhood>? neighborhoods,
  }) {
    return WeatherState(
      cityId: cityId ?? this.cityId,
      cityName: cityName ?? this.cityName,
      centerLat: centerLat ?? this.centerLat,
      centerLng: centerLng ?? this.centerLng,
      isRainMode: isRainMode ?? this.isRainMode,
      emergencyModeActive: emergencyModeActive ?? this.emergencyModeActive,
      emergencyMessage: emergencyMessage ?? this.emergencyMessage,
      bannerDismissed: bannerDismissed ?? this.bannerDismissed,
      weatherAlertMessage: weatherAlertMessage ?? this.weatherAlertMessage,
      rainDelayMinutes: rainDelayMinutes ?? this.rainDelayMinutes,
      isLoaded: isLoaded ?? this.isLoaded,
      bareme: bareme ?? this.bareme,
      neighborhoods: neighborhoods ?? this.neighborhoods,
    );
  }

  DeliveryNeighborhood findNeighborhood(String name) {
    final normalized = name.trim().toLowerCase();
    return neighborhoods.firstWhere(
      (n) => n.name.toLowerCase() == normalized,
      orElse: () => DeliveryNeighborhood(
        name: name,
        lat: 0.0,
        lng: 0.0,
        defaultDistanceKm: 2.0,
      ),
    );
  }

  /// Distance Haversine (en km) entre un commerce et un quartier de livraison.
  double distancePourQuartier(String neighborhoodName, {MerchantModel? merchant}) {
    final q = findNeighborhood(neighborhoodName);
    if (merchant == null ||
        (merchant.lat == 0 && merchant.lng == 0) ||
        (q.lat == 0 && q.lng == 0)) {
      return q.defaultDistanceKm;
    }

    const earthRadiusKm = 6371.0;
    final dLat = (q.lat - merchant.lat) * math.pi / 180.0;
    final dLng = (q.lng - merchant.lng) * math.pi / 180.0;
    final a = math.sin(dLat / 2) * math.sin(dLat / 2) +
        math.cos(merchant.lat * math.pi / 180.0) *
            math.cos(q.lat * math.pi / 180.0) *
            math.sin(dLng / 2) *
            math.sin(dLng / 2);
    final c = 2 * math.atan2(math.sqrt(a), math.sqrt(1 - a));
    final km = earthRadiusKm * c;
    // Distance routière réelle (~1.35x vol d'oiseau en zone urbaine) avec minimum 0.8 km
    return math.max(0.8, double.parse((km * 1.35).toStringAsFixed(1)));
  }

  /// Frais de livraison proportionnels à la distance et au Mode Pluie réel en base.
  double calculerFraisLivraison(double distanceKm, {bool isPharmacy = false}) {
    final tiers = bareme.isNotEmpty ? bareme : defaultGoShopBareme;
    final selectedTier = tiers.firstWhere(
      (t) => distanceKm <= t.maxKm,
      orElse: () => tiers.last,
    );
    if (isRainMode) {
      return selectedTier.pluie;
    }
    if (isPharmacy && distanceKm <= 2.2) {
      return tiers.first.normal;
    }
    return selectedTier.normal;
  }
}

class WeatherNotifier extends StateNotifier<WeatherState> {
  final ApiClient _apiClient;
  final int? _initialCityId;
  final void Function(String? logoUrl, String? defaultEmoji)? _onBranding;

  WeatherNotifier(
    this._apiClient, {
    int? initialCityId,
    void Function(String?, String?)? onBranding,
  })  : _initialCityId = initialCityId,
        _onBranding = onBranding,
        super(const WeatherState()) {
    chargerStatutVille(cityId: _initialCityId);
  }

  Future<void> chargerStatutVille({int? cityId}) async {
    final targetCityId = cityId ?? state.cityId ?? _initialCityId;
    try {
      final res = await _apiClient.get(
        '/client/city-status',
        queryParameters: targetCityId != null ? {'city_id': targetCityId} : null,
      );
      if (res.statusCode == 200 && res.data['succes'] == true && res.data['donnees'] != null) {
        final d = res.data['donnees'] as Map<String, dynamic>;
        final rainActive = d['rain_mode_active'] as bool? ?? false;
        final cName = (d['city_name'] as String? ?? '').trim();
        final rawBareme = d['bareme'] as List<dynamic>?;
        final rawNeighborhoods = d['neighborhoods'] as List<dynamic>? ?? const [];

        _onBranding?.call(
          d['app_logo_url'] as String?,
          d['app_default_emoji'] as String?,
        );

        state = state.copyWith(
          cityId: d['city_id'] as int?,
          cityName: cName,
          centerLat: (d['center_lat'] as num?)?.toDouble(),
          centerLng: (d['center_lng'] as num?)?.toDouble(),
          isRainMode: rainActive,
          rainDelayMinutes: d['rain_delay_minutes'] as int? ?? (rainActive ? 15 : 0),
          emergencyModeActive: d['emergency_mode_active'] as bool? ?? false,
          emergencyMessage: d['emergency_mode_message'] as String?,
          bannerDismissed: false,
          weatherAlertMessage: cName.isNotEmpty
              ? '🌧️ Mode Pluie actif à $cName : Délais adaptés (+15 min) et majoration intempéries.'
              : '🌧️ Mode Pluie actif : Délais adaptés (+15 min) et majoration intempéries.',
          isLoaded: true,
          bareme: rawBareme != null && rawBareme.isNotEmpty
              ? rawBareme
                  .map((e) => DeliveryTariffTier.fromJson(e as Map<String, dynamic>))
                  .toList()
              : null,
          neighborhoods: rawNeighborhoods
              .map((e) => DeliveryNeighborhood.fromJson(e as Map<String, dynamic>))
              .toList(),
        );
      }
    } catch (_) {
      state = state.copyWith(isLoaded: true);
    }
  }

  void dismissBanner() {
    state = state.copyWith(bannerDismissed: true);
  }
}

final weatherProvider = StateNotifierProvider<WeatherNotifier, WeatherState>((ref) {
  final api = ref.watch(apiClientProvider);
  final initialCityId = ref.read(authProvider).user?.cityId;
  final notifier = WeatherNotifier(
    api,
    initialCityId: initialCityId,
    onBranding: (logoUrl, emoji) {
      ref.read(appBrandingProvider.notifier).updateFromValues(logoUrl, emoji);
    },
  );

  ref.listen<int?>(
    authProvider.select((s) => s.user?.cityId),
    (prev, next) {
      if (next != null && next != prev) {
        notifier.chargerStatutVille(cityId: next);
      }
    },
  );

  return notifier;
});

// ─── Quartiers (chargés depuis l'API, aucune liste codée en dur) & Adresses ──
final daloaNeighborhoodsProvider = Provider<List<String>>((ref) {
  final weather = ref.watch(weatherProvider);
  return weather.neighborhoods.map((n) => n.name).where((n) => n.isNotEmpty).toList();
});

final selectedNeighborhoodProvider = StateProvider<String>((ref) {
  return '';
});

class ClientAddressModel {
  final int id;
  final int? cityId;
  final String cityName;
  final String label;
  final String neighborhood;
  final String addressLine;
  final String formattedAddress;
  final double? lat;
  final double? lng;
  final bool isDefault;

  const ClientAddressModel({
    required this.id,
    this.cityId,
    required this.cityName,
    required this.label,
    required this.neighborhood,
    required this.addressLine,
    required this.formattedAddress,
    this.lat,
    this.lng,
    required this.isDefault,
  });

  factory ClientAddressModel.fromJson(Map<String, dynamic> json) =>
      ClientAddressModel(
        id: json['id'] as int,
        cityId: json['city_id'] as int?,
        cityName: json['city_name'] as String? ?? '',
        label: json['label'] as String? ?? '',
        neighborhood: json['neighborhood'] as String? ?? '',
        addressLine: json['address_line'] as String? ?? '',
        formattedAddress: json['formatted_address'] as String? ?? '',
        lat: (json['lat'] as num?)?.toDouble(),
        lng: (json['lng'] as num?)?.toDouble(),
        isDefault: json['is_default'] as bool? ?? false,
      );
}

class ClientAddressesState {
  final bool isLoading;
  final List<ClientAddressModel> addresses;
  final String? errorMessage;

  const ClientAddressesState({
    this.isLoading = false,
    this.addresses = const [],
    this.errorMessage,
  });

  ClientAddressModel? get defaultAddress {
    if (addresses.isEmpty) return null;
    return addresses.firstWhere((a) => a.isDefault, orElse: () => addresses.first);
  }

  ClientAddressesState copyWith({
    bool? isLoading,
    List<ClientAddressModel>? addresses,
    String? errorMessage,
  }) {
    return ClientAddressesState(
      isLoading: isLoading ?? this.isLoading,
      addresses: addresses ?? this.addresses,
      errorMessage: errorMessage,
    );
  }
}

class ClientAddressesNotifier extends StateNotifier<ClientAddressesState> {
  final Ref _ref;
  final ApiClient _apiClient;

  ClientAddressesNotifier(this._ref, this._apiClient)
      : super(const ClientAddressesState());

  Future<void> chargerAdresses({bool syncNeighborhood = true}) async {
    if (_ref.read(authProvider).status != AuthStatus.authenticated) return;

    state = state.copyWith(isLoading: true, errorMessage: null);
    try {
      final res = await _apiClient.get('/client/addresses');
      if (res.statusCode == 200 && res.data['succes'] == true) {
        final raw = res.data['donnees'] as List<dynamic>? ?? [];
        final list = raw
            .map((e) => ClientAddressModel.fromJson(e as Map<String, dynamic>))
            .toList();

        state = ClientAddressesState(isLoading: false, addresses: list);

        final def = state.defaultAddress;
        if (syncNeighborhood && def != null) {
          _ref.read(selectedNeighborhoodProvider.notifier).state = def.neighborhood;
        }
        return;
      }
      state = state.copyWith(isLoading: false, errorMessage: 'Impossible de charger vos adresses.');
    } on DioException catch (e) {
      state = state.copyWith(isLoading: false, errorMessage: ApiClient.messageErreur(e));
    } catch (_) {
      state = state.copyWith(isLoading: false, errorMessage: 'Impossible de charger vos adresses.');
    }
  }

  Future<(bool, String?)> ajouterAdresse({
    required String label,
    required String neighborhood,
    required String addressLine,
    bool isDefault = false,
  }) async {
    try {
      final weather = _ref.read(weatherProvider);
      final q = weather.findNeighborhood(neighborhood);
      final res = await _apiClient.post('/client/addresses', data: {
        'label': label.trim(),
        'neighborhood': neighborhood.trim(),
        'address_line': addressLine.trim(),
        'city_id': weather.cityId,
        'lat': q.lat,
        'lng': q.lng,
        'is_default': isDefault,
      });

      if (res.statusCode == 201 || res.statusCode == 200) {
        await chargerAdresses(syncNeighborhood: isDefault || state.addresses.isEmpty);
        await _ref.read(authProvider.notifier).chargerProfil();
        return (true, null);
      }
      return (false, 'Impossible d\'enregistrer l\'adresse.');
    } on DioException catch (e) {
      return (false, ApiClient.messageErreur(e));
    } catch (_) {
      return (false, 'Impossible d\'enregistrer l\'adresse.');
    }
  }

  Future<(bool, String?)> modifierAdresse({
    required int id,
    required String label,
    required String neighborhood,
    required String addressLine,
    bool isDefault = false,
  }) async {
    try {
      final weather = _ref.read(weatherProvider);
      final q = weather.findNeighborhood(neighborhood);
      final res = await _apiClient.put('/client/addresses/$id', data: {
        'label': label.trim(),
        'neighborhood': neighborhood.trim(),
        'address_line': addressLine.trim(),
        'lat': q.lat,
        'lng': q.lng,
        'is_default': isDefault,
      });

      if (res.statusCode == 200) {
        await chargerAdresses(syncNeighborhood: isDefault);
        await _ref.read(authProvider.notifier).chargerProfil();
        return (true, null);
      }
      return (false, 'Impossible de modifier l\'adresse.');
    } on DioException catch (e) {
      return (false, ApiClient.messageErreur(e));
    } catch (_) {
      return (false, 'Impossible de modifier l\'adresse.');
    }
  }

  Future<(bool, String?)> choisirParDefaut(ClientAddressModel address) async {
    try {
      final res = await _apiClient.post('/client/addresses/${address.id}/default');
      if (res.statusCode == 200) {
        _ref.read(selectedNeighborhoodProvider.notifier).state = address.neighborhood;
        await chargerAdresses(syncNeighborhood: true);
        await _ref.read(authProvider.notifier).chargerProfil();
        return (true, null);
      }
      return (false, 'Impossible de définir l\'adresse par défaut.');
    } on DioException catch (e) {
      return (false, ApiClient.messageErreur(e));
    } catch (_) {
      return (false, 'Impossible de définir l\'adresse par défaut.');
    }
  }

  Future<(bool, String?)> supprimerAdresse(int id) async {
    try {
      final res = await _apiClient.delete('/client/addresses/$id');
      if (res.statusCode == 200) {
        await chargerAdresses(syncNeighborhood: true);
        await _ref.read(authProvider.notifier).chargerProfil();
        return (true, null);
      }
      return (false, 'Impossible de supprimer l\'adresse.');
    } on DioException catch (e) {
      return (false, ApiClient.messageErreur(e));
    } catch (_) {
      return (false, 'Impossible de supprimer l\'adresse.');
    }
  }
}

final clientAddressesProvider =
    StateNotifierProvider<ClientAddressesNotifier, ClientAddressesState>((ref) {
  final api = ref.watch(apiClientProvider);
  return ClientAddressesNotifier(ref, api);
});

/// Résumé calculé de la destination active pour que l'adresse, le quartier et les frais restent toujours cohérents.
class ActiveDeliveryDestination {
  final String cityName;
  final String neighborhood;
  final ClientAddressModel? matchedAddress;
  final String fullAddress;
  final double lat;
  final double lng;

  const ActiveDeliveryDestination({
    required this.cityName,
    required this.neighborhood,
    this.matchedAddress,
    required this.fullAddress,
    required this.lat,
    required this.lng,
  });
}

final activeDeliveryDestinationProvider = Provider<ActiveDeliveryDestination>((ref) {
  final weather = ref.watch(weatherProvider);
  final neighborhood = ref.watch(selectedNeighborhoodProvider);
  final addressesState = ref.watch(clientAddressesProvider);
  final user = ref.watch(authProvider).user;

  final activeCityName = (user?.cityName?.trim().isNotEmpty == true)
      ? user!.cityName!.trim()
      : weather.cityName.trim();

  final q = weather.findNeighborhood(neighborhood);
  final matching = neighborhood.isEmpty
      ? <ClientAddressModel>[]
      : addressesState.addresses
          .where((a) => a.neighborhood.toLowerCase() == neighborhood.toLowerCase())
          .toList();

  ClientAddressModel? matched;
  if (matching.isNotEmpty) {
    matched = matching.firstWhere((a) => a.isDefault, orElse: () => matching.first);
  } else if (neighborhood.isEmpty) {
    matched = addressesState.defaultAddress;
  }

  String fullAddress;
  if (matched != null && matched.formattedAddress.isNotEmpty) {
    fullAddress = matched.formattedAddress;
  } else if (neighborhood.isNotEmpty &&
      user?.defaultAddress != null &&
      user!.defaultAddress!.toLowerCase().contains(neighborhood.toLowerCase())) {
    fullAddress = user.defaultAddress!;
  } else if (neighborhood.isNotEmpty && activeCityName.isNotEmpty) {
    fullAddress = '$activeCityName, $neighborhood';
  } else if (neighborhood.isNotEmpty) {
    fullAddress = neighborhood;
  } else if (user?.defaultAddress?.trim().isNotEmpty == true) {
    fullAddress = user!.defaultAddress!.trim();
  } else {
    fullAddress = activeCityName;
  }

  return ActiveDeliveryDestination(
    cityName: activeCityName,
    neighborhood: neighborhood,
    matchedAddress: matched,
    fullAddress: fullAddress,
    lat: matched?.lat ?? q.lat,
    lng: matched?.lng ?? q.lng,
  );
});

// ─── Recherche & Favoris Commerces ────────────────────────────────────────────
final searchQueryProvider = StateProvider<String>((ref) => '');

class ClientFavoritesNotifier extends StateNotifier<Set<int>> {
  final Ref _ref;
  final ApiClient _apiClient;

  ClientFavoritesNotifier(this._ref, this._apiClient) : super(const <int>{});

  Future<void> chargerFavoris() async {
    if (_ref.read(authProvider).status != AuthStatus.authenticated) return;
    try {
      final res = await _apiClient.get('/client/favorites');
      if (res.statusCode == 200 && res.data['succes'] == true) {
        final raw = res.data['donnees'] as List<dynamic>? ?? [];
        state = raw.map((e) => (e as Map<String, dynamic>)['id'] as int).toSet();
      }
    } catch (_) {}
  }

  Future<bool> toggleFavori(MerchantModel merchant) async {
    final wasFavorite = state.contains(merchant.id);
    final updated = Set<int>.from(state);
    if (wasFavorite) {
      updated.remove(merchant.id);
    } else {
      updated.add(merchant.id);
    }
    state = updated;

    if (_ref.read(authProvider).status == AuthStatus.authenticated) {
      try {
        final res = await _apiClient.post('/client/favorites/${merchant.id}/toggle');
        if (res.statusCode == 200 && res.data['succes'] == true && res.data['donnees'] != null) {
          final ids = (res.data['donnees']['favorite_ids'] as List<dynamic>? ?? [])
              .map((e) => e as int)
              .toSet();
          state = ids;
          return res.data['donnees']['is_favorite'] as bool? ?? !wasFavorite;
        }
      } catch (_) {}
    }

    return !wasFavorite;
  }
}

final favoritesProvider =
    StateNotifierProvider<ClientFavoritesNotifier, Set<int>>((ref) {
  final api = ref.watch(apiClientProvider);
  return ClientFavoritesNotifier(ref, api);
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

// ─── Provider Commerces de la ville active du client ──────────────────────────
final merchantsListProvider = FutureProvider<List<MerchantModel>>((ref) async {
  final api = ref.watch(apiClientProvider);
  final userCityId = ref.watch(authProvider.select((s) => s.user?.cityId));

  try {
    final response = await api.get(
      '/client/merchants',
      queryParameters: userCityId != null ? {'city_id': userCityId} : null,
    );
    if (response.statusCode == 200 &&
        response.data is Map &&
        response.data['succes'] == true &&
        response.data['donnees'] is List) {
      final List data = response.data['donnees'] as List;
      return data
          .whereType<Map>()
          .map((json) => MerchantModel.fromJson(Map<String, dynamic>.from(json)))
          .toList();
    }
  } catch (_) {}

  return const [];
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

class DeliveryEstimate {
  final double distanceKm;
  final double deliveryFee;

  const DeliveryEstimate({
    required this.distanceKm,
    required this.deliveryFee,
  });
}

final cartDeliveryEstimateProvider = Provider<DeliveryEstimate>((ref) {
  final cart = ref.watch(cartProvider);
  final weather = ref.watch(weatherProvider);
  final destination = ref.watch(activeDeliveryDestinationProvider);
  final merchant = cart.merchant;

  final distanceKm = weather.distancePourQuartier(
    destination.neighborhood,
    merchant: merchant,
  );
  final fee = weather.calculerFraisLivraison(
    distanceKm,
    isPharmacy: merchant?.category == 'pharmacie',
  );
  return DeliveryEstimate(distanceKm: distanceKm, deliveryFee: fee);
});

class ClientNotificationItem {
  final int? id;
  final String type;
  final String title;
  final String body;
  final bool isRead;

  const ClientNotificationItem({
    this.id,
    required this.type,
    required this.title,
    required this.body,
    this.isRead = false,
  });

  factory ClientNotificationItem.fromJson(Map<String, dynamic> json) =>
      ClientNotificationItem(
        id: json['id'] as int?,
        type: json['type'] as String? ?? 'system',
        title: json['title'] as String? ?? 'Notification',
        body: json['body'] as String? ?? '',
        isRead: json['is_read'] as bool? ?? false,
      );
}

final clientNotificationsProvider = FutureProvider<List<ClientNotificationItem>>((ref) async {
  final api = ref.watch(apiClientProvider);
  final auth = ref.watch(authProvider);
  final weather = ref.watch(weatherProvider);
  final items = <ClientNotificationItem>[];

  if (weather.isRainMode) {
    items.add(
      ClientNotificationItem(
        type: 'rain_mode',
        title: '🌧️ Mode Pluie actif à ${weather.cityName}',
        body: 'Les délais sont adaptés (+${weather.rainDelayMinutes} min) et les tarifs intempéries s\'appliquent.',
      ),
    );
  }

  if (weather.emergencyModeActive) {
    items.add(
      ClientNotificationItem(
        type: 'emergency',
        title: '⚠️ Alerte Urgence à ${weather.cityName}',
        body: weather.emergencyMessage ?? 'Service temporairement perturbé.',
      ),
    );
  }

  if (auth.status == AuthStatus.authenticated) {
    try {
      final res = await api.get('/client/notifications');
      if (res.statusCode == 200 && res.data['succes'] == true) {
        final raw = res.data['donnees'] as List<dynamic>? ?? [];
        items.addAll(
          raw.map((e) => ClientNotificationItem.fromJson(e as Map<String, dynamic>)),
        );
      }
    } catch (_) {}
  }

  return items;
});

// ─── Bannières Promotionnelles Dynamiques (auto-expirables côté API) ──────────
class PromoBannerModel {
  final String id;
  final String title;
  final String subtitle;
  final String? imageUrl;
  final String? deepLink;
  final String? startsAt;
  final String? endsAt;

  const PromoBannerModel({
    required this.id,
    required this.title,
    this.subtitle = '',
    this.imageUrl,
    this.deepLink,
    this.startsAt,
    this.endsAt,
  });

  factory PromoBannerModel.fromJson(Map<String, dynamic> json) =>
      PromoBannerModel(
        id: json['id']?.toString() ?? '',
        title: json['title']?.toString() ?? '',
        subtitle: json['subtitle']?.toString() ?? '',
        imageUrl: json['image_url']?.toString(),
        deepLink: json['deep_link']?.toString(),
        startsAt: json['starts_at']?.toString(),
        endsAt: json['ends_at']?.toString(),
      );
}

final promoBannersProvider = FutureProvider<List<PromoBannerModel>>((ref) async {
  final api = ref.watch(apiClientProvider);
  final auth = ref.watch(authProvider);
  final weather = ref.watch(weatherProvider);
  final cityId = auth.user?.cityId ?? weather.cityId;

  try {
    final res = await api.get(
      '/client/banners',
      queryParameters: cityId != null ? {'city_id': cityId} : null,
    );
    if (res.statusCode == 200 && res.data['succes'] == true) {
      final raw = res.data['donnees'] as List<dynamic>? ?? const [];
      return raw
          .whereType<Map<String, dynamic>>()
          .map(PromoBannerModel.fromJson)
          .where((b) => b.title.isNotEmpty)
          .toList();
    }
  } catch (_) {}
  return const [];
});


