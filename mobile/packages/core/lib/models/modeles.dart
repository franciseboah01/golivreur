/// Modèles de données partagés entre les 3 applications GoLivreur.
/// Correspondent exactement aux réponses JSON de l'API Laravel (tolérants aux DECIMAL MySQL/PostgreSQL).
import '../config/env_config.dart';

// ─── Helpers de parsing résilients (évitent tout crash sur String DECIMAL / 0-1 bool) ──

double _toDouble(dynamic value, [double fallback = 0.0]) {
  if (value is num) return value.toDouble();
  if (value is String) {
    final parsed = double.tryParse(value.trim().replaceAll(',', '.'));
    if (parsed != null) return parsed;
  }
  return fallback;
}

double? _toNullableDouble(dynamic value) {
  if (value == null) return null;
  if (value is num) return value.toDouble();
  if (value is String) {
    final trimmed = value.trim();
    if (trimmed.isEmpty) return null;
    return double.tryParse(trimmed.replaceAll(',', '.'));
  }
  return null;
}

int _toInt(dynamic value, [int fallback = 0]) {
  if (value is int) return value;
  if (value is num) return value.toInt();
  if (value is String) {
    final trimmed = value.trim();
    final asInt = int.tryParse(trimmed);
    if (asInt != null) return asInt;
    final asDouble = double.tryParse(trimmed.replaceAll(',', '.'));
    if (asDouble != null) return asDouble.toInt();
  }
  return fallback;
}

int? _toNullableInt(dynamic value) {
  if (value == null) return null;
  if (value is int) return value;
  if (value is num) return value.toInt();
  if (value is String) {
    final trimmed = value.trim();
    if (trimmed.isEmpty) return null;
    return int.tryParse(trimmed) ?? double.tryParse(trimmed)?.toInt();
  }
  return null;
}

bool _toBool(dynamic value, [bool fallback = false]) {
  if (value is bool) return value;
  if (value is num) return value != 0;
  if (value is String) {
    final lower = value.trim().toLowerCase();
    if (lower == 'true' || lower == '1' || lower == 'yes') return true;
    if (lower == 'false' || lower == '0' || lower == 'no') return false;
  }
  return fallback;
}

DateTime _toDateTime(dynamic value) {
  if (value is DateTime) return value;
  if (value is String && value.trim().isNotEmpty) {
    return DateTime.tryParse(value.trim()) ?? DateTime.now();
  }
  return DateTime.now();
}

DateTime? _toNullableDateTime(dynamic value) {
  if (value == null) return null;
  if (value is DateTime) return value;
  if (value is String && value.trim().isNotEmpty) {
    return DateTime.tryParse(value.trim());
  }
  return null;
}

Map<String, dynamic>? _toMap(dynamic value) {
  if (value is Map<String, dynamic>) return value;
  if (value is Map) return Map<String, dynamic>.from(value);
  return null;
}

// ═══════════════════════════════════════════════════════════════════════════════
// USER
// ═══════════════════════════════════════════════════════════════════════════════

class UserModel {
  final int id;
  final String name;
  final String phone;
  final String? email;
  final String role;
  final List<String> roles;
  final int? cityId;
  final String? cityName;
  final String? supportPhone;
  final String? defaultAddress;
  final String language;
  final bool notificationsEnabled;
  final String? avatarUrl;
  final String? photoUrl;
  final bool isActive;
  final bool mustChangePassword;
  final String? pinResetRequiredAt;

  const UserModel({
    required this.id,
    required this.name,
    required this.phone,
    this.email,
    required this.role,
    this.roles = const [],
    this.cityId,
    this.cityName,
    this.supportPhone,
    this.defaultAddress,
    this.language = 'fr',
    this.notificationsEnabled = true,
    this.avatarUrl,
    this.photoUrl,
    required this.isActive,
    this.mustChangePassword = false,
    this.pinResetRequiredAt,
  });

  bool hasRole(String target) {
    final normalized = target.toLowerCase().trim();
    if (roles.isNotEmpty) {
      if (normalized == 'driver' || normalized == 'courier') {
        return roles.contains('driver') || roles.contains('courier');
      }
      return roles.contains(normalized);
    }
    if (normalized == 'driver' || normalized == 'courier') {
      return role == 'driver' || role == 'courier';
    }
    return role == normalized;
  }

  factory UserModel.fromJson(Map<String, dynamic> json) {
    final resolvedPhoto = GoLivreurEnv.resolveMediaUrl(
      (json['photo_url']?.toString()) ?? (json['avatar_url']?.toString()),
    );
    final rawRoles = json['roles'];
    final parsedRoles = rawRoles is List
        ? rawRoles.map((e) => e.toString()).where((e) => e.isNotEmpty).toList()
        : <String>[if (json['role'] != null) json['role'].toString()];

    return UserModel(
      id: _toInt(json['id']),
      name: json['name']?.toString() ?? '',
      phone: json['phone']?.toString() ?? '',
      email: json['email']?.toString(),
      role: json['role']?.toString() ?? 'client',
      roles: parsedRoles,
      cityId: _toNullableInt(json['city_id']),
      cityName: json['city_name']?.toString(),
      supportPhone: json['support_phone']?.toString(),
      defaultAddress: json['default_address']?.toString(),
      language: json['language']?.toString() ?? 'fr',
      notificationsEnabled: _toBool(json['notifications_enabled'], true),
      avatarUrl: resolvedPhoto,
      photoUrl: resolvedPhoto,
      isActive: _toBool(json['is_active'], true),
      mustChangePassword: _toBool(json['must_change_password'], false),
      pinResetRequiredAt: json['pin_reset_required_at']?.toString(),
    );
  }

  UserModel copyWith({
    String? name,
    String? phone,
    String? email,
    List<String>? roles,
    int? cityId,
    String? cityName,
    String? supportPhone,
    String? defaultAddress,
    String? language,
    bool? notificationsEnabled,
    String? avatarUrl,
    String? photoUrl,
    bool? isActive,
    bool? mustChangePassword,
    String? pinResetRequiredAt,
  }) {
    return UserModel(
      id: id,
      name: name ?? this.name,
      phone: phone ?? this.phone,
      email: email ?? this.email,
      role: role,
      roles: roles ?? this.roles,
      cityId: cityId ?? this.cityId,
      cityName: cityName ?? this.cityName,
      supportPhone: supportPhone ?? this.supportPhone,
      defaultAddress: defaultAddress ?? this.defaultAddress,
      language: language ?? this.language,
      notificationsEnabled: notificationsEnabled ?? this.notificationsEnabled,
      avatarUrl: avatarUrl ?? this.avatarUrl,
      photoUrl: photoUrl ?? this.photoUrl,
      isActive: isActive ?? this.isActive,
      mustChangePassword: mustChangePassword ?? this.mustChangePassword,
      pinResetRequiredAt: pinResetRequiredAt ?? this.pinResetRequiredAt,
    );
  }

  Map<String, dynamic> toJson() => {
        'id': id,
        'name': name,
        'phone': phone,
        'email': email,
        'role': role,
        'roles': roles,
        'city_id': cityId,
        'city_name': cityName,
        'support_phone': supportPhone,
        'default_address': defaultAddress,
        'language': language,
        'notifications_enabled': notificationsEnabled,
        'avatar_url': avatarUrl,
        'photo_url': photoUrl,
        'is_active': isActive,
        'must_change_password': mustChangePassword,
        'pin_reset_required_at': pinResetRequiredAt,
      };

  bool get estClient   => role == 'client';
  bool get estMerchant => role == 'merchant';
  bool get estDriver   => role == 'driver' || role == 'courier';
}

// ═══════════════════════════════════════════════════════════════════════════════
// ORDER (GoShop)
// ═══════════════════════════════════════════════════════════════════════════════

class OrderModel {
  final int id;
  final String status;
  final double productsTotal;
  final double deliveryFee;
  final String paymentMethod;
  final String paymentStatus;
  final String? confirmationCode; // Visible côté client uniquement
  final bool confirmationLocked;
  final String deliveryAddress;
  final double? deliveryLat;
  final double? deliveryLng;
  final String? specialInstructions;
  final MerchantModel? merchant;
  final CourierModel? courier;
  final List<OrderItemModel> items;
  final DateTime createdAt;
  final DateTime? deliveredAt;

  const OrderModel({
    required this.id,
    required this.status,
    required this.productsTotal,
    required this.deliveryFee,
    required this.paymentMethod,
    required this.paymentStatus,
    this.confirmationCode,
    required this.confirmationLocked,
    required this.deliveryAddress,
    this.deliveryLat,
    this.deliveryLng,
    this.specialInstructions,
    this.merchant,
    this.courier,
    required this.items,
    required this.createdAt,
    this.deliveredAt,
  });

  factory OrderModel.fromJson(Map<String, dynamic> json) {
    final merchantMap = _toMap(json['merchant']);
    final courierMap = _toMap(json['courier']);
    final rawItems = json['items'] is List ? json['items'] as List : const [];

    return OrderModel(
      id: _toInt(json['id']),
      status: json['status']?.toString() ?? 'pending',
      productsTotal: _toDouble(json['products_total']),
      deliveryFee: _toDouble(json['delivery_fee']),
      paymentMethod: json['payment_method']?.toString() ?? 'cash',
      paymentStatus: json['payment_status']?.toString() ?? 'pending',
      confirmationCode: json['confirmation_code']?.toString(),
      confirmationLocked: _toBool(json['confirmation_locked'], false),
      deliveryAddress: json['delivery_address']?.toString() ?? '',
      deliveryLat: _toNullableDouble(json['delivery_lat']),
      deliveryLng: _toNullableDouble(json['delivery_lng']),
      specialInstructions: json['special_instructions']?.toString(),
      merchant: merchantMap != null ? MerchantModel.fromJson(merchantMap) : null,
      courier: courierMap != null ? CourierModel.fromJson(courierMap) : null,
      items: rawItems
          .whereType<Map>()
          .map((e) => OrderItemModel.fromJson(Map<String, dynamic>.from(e)))
          .toList(),
      createdAt: _toDateTime(json['created_at']),
      deliveredAt: _toNullableDateTime(json['delivered_at']),
    );
  }

  double get totalClient => productsTotal + deliveryFee;

  bool get estActif => [
        'pending', 'accepted', 'waiting', 'ready', 'picked_up', 'in_transit'
      ].contains(status);

  String get labelStatut => switch (status) {
        'pending'    => 'En attente du commerce',
        'accepted'   => 'Commande acceptée',
        'waiting'    => 'Recherche d\'un livreur',
        'refused'    => 'Commande refusée',
        'ready'      => 'Commande prête',
        'picked_up'  => 'Livreur en route',
        'in_transit' => 'En cours de livraison',
        'delivered'  => 'Livrée ✓',
        'cancelled'  => 'Annulée',
        _            => status,
      };
}

class OrderItemModel {
  final int id;
  final int productId;
  final String productNameSnapshot;
  final int quantity;
  final double unitPrice;

  const OrderItemModel({
    required this.id,
    required this.productId,
    required this.productNameSnapshot,
    required this.quantity,
    required this.unitPrice,
  });

  factory OrderItemModel.fromJson(Map<String, dynamic> json) {
    final prodMap = _toMap(json['product']);
    return OrderItemModel(
      id: _toInt(json['id']),
      productId: _toInt(json['product_id']),
      productNameSnapshot: json['product_name_snapshot']?.toString() ??
          prodMap?['name']?.toString() ??
          'Article',
      quantity: _toInt(json['quantity'], 1),
      unitPrice: _toDouble(json['unit_price']),
    );
  }

  double get sousTotal => unitPrice * quantity;
}

// ═══════════════════════════════════════════════════════════════════════════════
// PARCEL (GoColis)
// ═══════════════════════════════════════════════════════════════════════════════

class ParcelModel {
  final int id;
  final String status;
  final String size;
  final String sizeLabel;
  final double declaredValue;
  final bool hasInsurance;
  final double insuranceAmount;
  final String recipientName;
  final String recipientPhone;
  final String deliveryAddress;
  final double? pickupLat;
  final double? pickupLng;
  final double? deliveryLat;
  final double? deliveryLng;
  final double shippingFee;
  final String paymentMethod;
  final bool senderCodeLocked;
  final bool recipientCodeLocked;
  final CourierModel? courier;
  final List<ParcelPhotoModel> photos;
  final DateTime createdAt;
  final DateTime? deliveredAt;

  const ParcelModel({
    required this.id,
    required this.status,
    required this.size,
    required this.sizeLabel,
    required this.declaredValue,
    required this.hasInsurance,
    required this.insuranceAmount,
    required this.recipientName,
    required this.recipientPhone,
    required this.deliveryAddress,
    this.pickupLat,
    this.pickupLng,
    this.deliveryLat,
    this.deliveryLng,
    required this.shippingFee,
    required this.paymentMethod,
    required this.senderCodeLocked,
    required this.recipientCodeLocked,
    this.courier,
    required this.photos,
    required this.createdAt,
    this.deliveredAt,
  });

  factory ParcelModel.fromJson(Map<String, dynamic> json) {
    final courierMap = _toMap(json['courier']);
    final rawPhotos = json['photos'] is List ? json['photos'] as List : const [];
    final rawSize = json['size']?.toString() ?? 'moyen';
    final defaultSizeLabel = switch (rawSize) {
      'petit' => 'Petit (max 1 kg)',
      'moyen' => 'Moyen (max 5 kg)',
      'grand' => 'Grand (max 15 kg)',
      _ => rawSize,
    };

    return ParcelModel(
      id: _toInt(json['id']),
      status: json['status']?.toString() ?? 'pending',
      size: rawSize,
      sizeLabel: json['size_label']?.toString() ?? defaultSizeLabel,
      declaredValue: _toDouble(json['declared_value']),
      hasInsurance: _toBool(json['has_insurance'], false),
      insuranceAmount: _toDouble(json['insurance_amount']),
      recipientName: json['recipient_name']?.toString() ?? '',
      recipientPhone: json['recipient_phone']?.toString() ?? '',
      deliveryAddress: json['delivery_address']?.toString() ?? '',
      pickupLat: _toNullableDouble(json['pickup_lat']),
      pickupLng: _toNullableDouble(json['pickup_lng']),
      deliveryLat: _toNullableDouble(json['delivery_lat']),
      deliveryLng: _toNullableDouble(json['delivery_lng']),
      shippingFee: _toDouble(json['shipping_fee']),
      paymentMethod: json['payment_method']?.toString() ?? 'cash',
      senderCodeLocked: _toBool(json['sender_code_locked'], false),
      recipientCodeLocked: _toBool(json['recipient_code_locked'], false),
      courier: courierMap != null ? CourierModel.fromJson(courierMap) : null,
      photos: rawPhotos
          .whereType<Map>()
          .map((e) => ParcelPhotoModel.fromJson(Map<String, dynamic>.from(e)))
          .toList(),
      createdAt: _toDateTime(json['created_at']),
      deliveredAt: _toNullableDateTime(json['delivered_at']),
    );
  }

  String get labelStatut => switch (status) {
        'pending'      => 'Recherche livreur',
        'waiting'      => 'En attente — support alerté',
        'accepted'     => 'Livreur en route vers vous',
        'at_sender'    => 'Livreur chez vous',
        'picked_up'    => 'Colis récupéré',
        'in_transit'   => 'En cours de livraison',
        'at_recipient' => 'Livreur chez le destinataire',
        'delivered'    => 'Livré ✓',
        'refused'      => 'Refusé par le livreur',
        'cancelled'    => 'Annulé',
        _              => status,
      };

  ParcelPhotoModel? get photoColis =>
      photos.where((p) => p.type == 'parcel' && p.photoUrl.isNotEmpty).firstOrNull;

  ParcelPhotoModel? get photoRamassage =>
      photos.where((p) => p.type == 'pickup' && p.photoUrl.isNotEmpty).firstOrNull;

  ParcelPhotoModel? get photoLivraison =>
      photos.where((p) => p.type == 'delivery' && p.photoUrl.isNotEmpty).firstOrNull;
}

class ParcelPhotoModel {
  final int id;
  final String type; // parcel | pickup | delivery
  final String photoUrl;

  const ParcelPhotoModel({
    required this.id,
    required this.type,
    required this.photoUrl,
  });

  factory ParcelPhotoModel.fromJson(Map<String, dynamic> json) {
    final signed = json['signed_url']?.toString().trim() ?? '';
    final legacy = json['photo_url']?.toString().trim() ?? '';
    final chosen = signed.isNotEmpty ? signed : legacy;
    final resolved = GoLivreurEnv.resolveMediaUrl(chosen) ?? chosen;

    return ParcelPhotoModel(
      id: _toInt(json['id']),
      type: json['type']?.toString() ?? 'parcel',
      photoUrl: resolved,
    );
  }
}

// ═══════════════════════════════════════════════════════════════════════════════
// CATEGORY & MERCHANT
// ═══════════════════════════════════════════════════════════════════════════════

class CategoryModel {
  final int id;
  final String name;
  final String slug;
  final String? description;
  final String? icon;
  final String? emoji;
  final String? logoUrl;
  final bool isActive;

  const CategoryModel({
    required this.id,
    required this.name,
    required this.slug,
    this.description,
    this.icon,
    this.emoji,
    this.logoUrl,
    this.isActive = true,
  });

  factory CategoryModel.fromJson(Map<String, dynamic> json) => CategoryModel(
        id: _toInt(json['id']),
        name: json['name']?.toString() ?? '',
        slug: json['slug']?.toString() ?? '',
        description: json['description']?.toString(),
        icon: json['icon']?.toString(),
        emoji: json['emoji']?.toString(),
        logoUrl: GoLivreurEnv.resolveMediaUrl(json['logo_url']?.toString()),
        isActive: _toBool(json['is_active'], true),
      );
}

class MerchantModel {
  final int id;
  final String name;
  final String category;
  final String? categoryName;
  final String? emoji;
  final String? logoUrl;
  final String address;
  final double lat;
  final double lng;
  final int prepTimeMinutes;
  final bool isOpen;
  final double averageRating;
  final String? photoUrl;
  final List<ProductModel> products;

  const MerchantModel({
    required this.id,
    required this.name,
    required this.category,
    this.categoryName,
    this.emoji,
    this.logoUrl,
    required this.address,
    required this.lat,
    required this.lng,
    required this.prepTimeMinutes,
    required this.isOpen,
    required this.averageRating,
    this.photoUrl,
    required this.products,
  });

  factory MerchantModel.fromJson(Map<String, dynamic> json) {
    final rawProducts = json['products'] is List ? json['products'] as List : const [];
    final catModel = _toMap(json['category_model']);

    return MerchantModel(
      id: _toInt(json['id']),
      name: json['name']?.toString() ?? '',
      category: json['category']?.toString() ?? 'autre',
      categoryName: json['category_name']?.toString() ?? catModel?['name']?.toString(),
      emoji: json['emoji']?.toString() ?? catModel?['emoji']?.toString(),
      logoUrl: GoLivreurEnv.resolveMediaUrl(json['logo_url']?.toString()),
      address: json['address']?.toString() ?? '',
      lat: _toDouble(json['lat']),
      lng: _toDouble(json['lng']),
      prepTimeMinutes: _toInt(json['prep_time_minutes'], 15),
      isOpen: _toBool(json['is_open'], false),
      averageRating: _toDouble(json['average_rating']),
      photoUrl: GoLivreurEnv.resolveMediaUrl(json['photo_url']?.toString()),
      products: rawProducts
          .whereType<Map>()
          .map((e) => ProductModel.fromJson(Map<String, dynamic>.from(e)))
          .toList(),
    );
  }
}

class ProductModel {
  final int id;
  final String name;
  final double price;
  final String? description;
  final String? photoUrl;
  final bool isAvailable;
  final int? quantity;
  final String? category;
  final Map<String, dynamic> attributes;

  const ProductModel({
    required this.id,
    required this.name,
    required this.price,
    this.description,
    this.photoUrl,
    required this.isAvailable,
    this.quantity,
    this.category,
    this.attributes = const {},
  });

  factory ProductModel.fromJson(Map<String, dynamic> json) {
    final rawAttrs = _toMap(json['attributes']) ??
        _toMap(json['custom_attributes']) ??
        const <String, dynamic>{};

    return ProductModel(
      id: _toInt(json['id']),
      name: json['name']?.toString() ?? '',
      price: _toDouble(json['price']),
      description: json['description']?.toString(),
      photoUrl: GoLivreurEnv.resolveMediaUrl(json['photo_url']?.toString()),
      isAvailable: _toBool(json['is_available'], true),
      quantity: _toNullableInt(json['quantity']),
      category: json['category']?.toString(),
      attributes: rawAttrs,
    );
  }
}

// ═══════════════════════════════════════════════════════════════════════════════
// COURIER
// ═══════════════════════════════════════════════════════════════════════════════

class CourierModel {
  final int id;
  final String name;
  final String? photoUrl;
  final double? currentLat;
  final double? currentLng;
  final double averageRating;
  final bool isOnline;

  const CourierModel({
    required this.id,
    required this.name,
    this.photoUrl,
    this.currentLat,
    this.currentLng,
    required this.averageRating,
    required this.isOnline,
  });

  factory CourierModel.fromJson(Map<String, dynamic> json) {
    final userMap = _toMap(json['user']);
    return CourierModel(
      id: _toInt(json['id']),
      name: json['name']?.toString() ?? userMap?['name']?.toString() ?? '',
      photoUrl: GoLivreurEnv.resolveMediaUrl(json['photo_url']?.toString()),
      currentLat: _toNullableDouble(json['current_lat']),
      currentLng: _toNullableDouble(json['current_lng']),
      averageRating: _toDouble(json['average_rating']),
      isOnline: _toBool(json['is_online'], false),
    );
  }
}

// ═══════════════════════════════════════════════════════════════════════════════
// NOTIFICATION (in-app Client)
// ═══════════════════════════════════════════════════════════════════════════════

class NotificationModel {
  final int id;
  final String type;
  final String title;
  final String body;
  final bool isRead;
  final Map<String, dynamic>? data;
  final DateTime createdAt;

  const NotificationModel({
    required this.id,
    required this.type,
    required this.title,
    required this.body,
    required this.isRead,
    this.data,
    required this.createdAt,
  });

  factory NotificationModel.fromJson(Map<String, dynamic> json) =>
      NotificationModel(
        id: _toInt(json['id']),
        type: json['type']?.toString() ?? 'system',
        title: json['title']?.toString() ?? '',
        body: json['body']?.toString() ?? '',
        isRead: _toBool(json['is_read'], false),
        data: _toMap(json['data']),
        createdAt: _toDateTime(json['created_at']),
      );
}
