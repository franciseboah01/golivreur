/// Modèles de données partagés entre les 3 applications GoLivreur.
/// Correspondent exactement aux réponses JSON de l'API Laravel.
import '../config/env_config.dart';

// ═══════════════════════════════════════════════════════════════════════════════
// USER
// ═══════════════════════════════════════════════════════════════════════════════

class UserModel {
  final int id;
  final String name;
  final String phone;
  final String role;
  final int? cityId;
  final String? avatarUrl;
  final bool isActive;

  const UserModel({
    required this.id,
    required this.name,
    required this.phone,
    required this.role,
    this.cityId,
    this.avatarUrl,
    required this.isActive,
  });

  factory UserModel.fromJson(Map<String, dynamic> json) => UserModel(
        id: json['id'] as int,
        name: json['name'] as String,
        phone: json['phone'] as String,
        role: json['role'] as String,
        cityId: json['city_id'] as int?,
        avatarUrl: json['avatar_url'] as String?,
        isActive: json['is_active'] as bool? ?? true,
      );

  bool get estClient   => role == 'client';
  bool get estMerchant => role == 'merchant';
  bool get estDriver   => role == 'driver';
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

  factory OrderModel.fromJson(Map<String, dynamic> json) => OrderModel(
        id: json['id'] as int,
        status: json['status'] as String,
        productsTotal: (json['products_total'] as num).toDouble(),
        deliveryFee: (json['delivery_fee'] as num).toDouble(),
        paymentMethod: json['payment_method'] as String,
        paymentStatus: json['payment_status'] as String,
        confirmationCode: json['confirmation_code'] as String?,
        confirmationLocked: json['confirmation_locked'] as bool? ?? false,
        deliveryAddress: json['delivery_address'] as String,
        deliveryLat: (json['delivery_lat'] as num?)?.toDouble(),
        deliveryLng: (json['delivery_lng'] as num?)?.toDouble(),
        specialInstructions: json['special_instructions'] as String?,
        merchant: json['merchant'] != null
            ? MerchantModel.fromJson(json['merchant'] as Map<String, dynamic>)
            : null,
        courier: json['courier'] != null
            ? CourierModel.fromJson(json['courier'] as Map<String, dynamic>)
            : null,
        items: (json['items'] as List<dynamic>? ?? [])
            .map((e) => OrderItemModel.fromJson(e as Map<String, dynamic>))
            .toList(),
        createdAt: DateTime.parse(json['created_at'] as String),
        deliveredAt: json['delivered_at'] != null
            ? DateTime.parse(json['delivered_at'] as String)
            : null,
      );

  double get totalClient => productsTotal + deliveryFee;

  bool get estActif => [
        'pending', 'accepted', 'ready', 'picked_up', 'in_transit'
      ].contains(status);

  String get labelStatut => switch (status) {
        'pending'    => 'En attente du commerce',
        'accepted'   => 'Commande acceptée',
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

  factory OrderItemModel.fromJson(Map<String, dynamic> json) => OrderItemModel(
        id: json['id'] as int,
        productId: json['product_id'] as int,
        productNameSnapshot: json['product_name_snapshot'] as String,
        quantity: json['quantity'] as int,
        unitPrice: (json['unit_price'] as num).toDouble(),
      );

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
    required this.shippingFee,
    required this.paymentMethod,
    required this.senderCodeLocked,
    required this.recipientCodeLocked,
    this.courier,
    required this.photos,
    required this.createdAt,
    this.deliveredAt,
  });

  factory ParcelModel.fromJson(Map<String, dynamic> json) => ParcelModel(
        id: json['id'] as int,
        status: json['status'] as String,
        size: json['size'] as String,
        sizeLabel: json['size_label'] as String? ?? json['size'] as String,
        declaredValue: (json['declared_value'] as num).toDouble(),
        hasInsurance: json['has_insurance'] as bool? ?? false,
        insuranceAmount: (json['insurance_amount'] as num? ?? 0).toDouble(),
        recipientName: json['recipient_name'] as String,
        recipientPhone: json['recipient_phone'] as String,
        deliveryAddress: json['delivery_address'] as String,
        shippingFee: (json['shipping_fee'] as num).toDouble(),
        paymentMethod: json['payment_method'] as String,
        senderCodeLocked: json['sender_code_locked'] as bool? ?? false,
        recipientCodeLocked: json['recipient_code_locked'] as bool? ?? false,
        courier: json['courier'] != null
            ? CourierModel.fromJson(json['courier'] as Map<String, dynamic>)
            : null,
        photos: (json['photos'] as List<dynamic>? ?? [])
            .map((e) => ParcelPhotoModel.fromJson(e as Map<String, dynamic>))
            .toList(),
        createdAt: DateTime.parse(json['created_at'] as String),
        deliveredAt: json['delivered_at'] != null
            ? DateTime.parse(json['delivered_at'] as String)
            : null,
      );

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

  ParcelPhotoModel? get photoRamassage =>
      photos.where((p) => p.type == 'pickup').firstOrNull;

  ParcelPhotoModel? get photoLivraison =>
      photos.where((p) => p.type == 'delivery').firstOrNull;
}

class ParcelPhotoModel {
  final int id;
  final String type; // pickup | delivery
  final String photoUrl;

  const ParcelPhotoModel({
    required this.id,
    required this.type,
    required this.photoUrl,
  });

  factory ParcelPhotoModel.fromJson(Map<String, dynamic> json) =>
      ParcelPhotoModel(
        id: json['id'] as int,
        type: json['type'] as String,
        photoUrl: json['photo_url'] as String,
      );
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
        id: json['id'] as int? ?? 0,
        name: json['name'] as String? ?? '',
        slug: json['slug'] as String? ?? '',
        description: json['description'] as String?,
        icon: json['icon'] as String?,
        emoji: json['emoji'] as String?,
        logoUrl: GoLivreurEnv.resolveMediaUrl(json['logo_url'] as String?),
        isActive: json['is_active'] as bool? ?? true,
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

  factory MerchantModel.fromJson(Map<String, dynamic> json) => MerchantModel(
        id: json['id'] as int,
        name: json['name'] as String,
        category: json['category'] as String,
        categoryName: json['category_name'] as String?,
        emoji: json['emoji'] as String?,
        logoUrl: GoLivreurEnv.resolveMediaUrl(json['logo_url'] as String?),
        address: json['address'] as String,
        lat: (json['lat'] as num).toDouble(),
        lng: (json['lng'] as num).toDouble(),
        prepTimeMinutes: json['prep_time_minutes'] as int? ?? 15,
        isOpen: json['is_open'] as bool? ?? false,
        averageRating: (json['average_rating'] as num? ?? 0).toDouble(),
        photoUrl: GoLivreurEnv.resolveMediaUrl(json['photo_url'] as String?),
        products: (json['products'] as List<dynamic>? ?? [])
            .map((e) => ProductModel.fromJson(e as Map<String, dynamic>))
            .toList(),
      );
}

class ProductModel {
  final int id;
  final String name;
  final double price;
  final String? description;
  final String? photoUrl;
  final bool isAvailable;
  final String? category;

  const ProductModel({
    required this.id,
    required this.name,
    required this.price,
    this.description,
    this.photoUrl,
    required this.isAvailable,
    this.category,
  });

  factory ProductModel.fromJson(Map<String, dynamic> json) => ProductModel(
        id: json['id'] as int,
        name: json['name'] as String,
        price: (json['price'] as num).toDouble(),
        description: json['description'] as String?,
        photoUrl: json['photo_url'] as String?,
        isAvailable: json['is_available'] as bool? ?? true,
        category: json['category'] as String?,
      );
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

  factory CourierModel.fromJson(Map<String, dynamic> json) => CourierModel(
        id: json['id'] as int,
        name: json['name'] as String? ?? json['user']?['name'] as String? ?? '',
        photoUrl: json['photo_url'] as String?,
        currentLat: (json['current_lat'] as num?)?.toDouble(),
        currentLng: (json['current_lng'] as num?)?.toDouble(),
        averageRating: (json['average_rating'] as num? ?? 0).toDouble(),
        isOnline: json['is_online'] as bool? ?? false,
      );
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
        id: json['id'] as int,
        type: json['type'] as String,
        title: json['title'] as String,
        body: json['body'] as String,
        isRead: json['is_read'] as bool? ?? false,
        data: json['data'] as Map<String, dynamic>?,
        createdAt: DateTime.parse(json['created_at'] as String),
      );
}
