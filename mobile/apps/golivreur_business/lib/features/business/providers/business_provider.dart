import 'dart:convert';
import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:golivreur_core/config/env_config.dart';
import 'package:golivreur_core/models/modeles.dart';
import 'package:golivreur_core/services/api_client.dart';
import 'package:golivreur_core/services/secure_token_storage.dart';
import 'package:image_picker/image_picker.dart';
import '../../profile/models/opening_hours_model.dart';

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
  final String? supportPhone;
  final double? lat;
  final double? lng;
  final double? cityCenterLat;
  final double? cityCenterLng;
  final String? description;
  final String openHoursText;
  final ShopOpeningHours openHoursConfig;
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
  final List<String> roles;
  final bool mustChangePassword;
  final bool passwordSet;
  final String? pinResetRequiredAt;

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
    this.supportPhone,
    this.lat,
    this.lng,
    this.cityCenterLat,
    this.cityCenterLng,
    this.description,
    this.openHoursText = '',
    this.openHoursConfig = const ShopOpeningHours(is247: true),
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
    this.roles = const ['merchant'],
    this.mustChangePassword = false,
    this.passwordSet = true,
    this.pinResetRequiredAt,
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
    String? supportPhone,
    double? lat,
    double? lng,
    double? cityCenterLat,
    double? cityCenterLng,
    String? description,
    String? openHoursText,
    ShopOpeningHours? openHoursConfig,
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
    List<String>? roles,
    bool? mustChangePassword,
    bool? passwordSet,
    String? pinResetRequiredAt,
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
      supportPhone: supportPhone ?? this.supportPhone,
      lat: lat ?? this.lat,
      lng: lng ?? this.lng,
      cityCenterLat: cityCenterLat ?? this.cityCenterLat,
      cityCenterLng: cityCenterLng ?? this.cityCenterLng,
      description: description ?? this.description,
      openHoursText: openHoursText ?? this.openHoursText,
      openHoursConfig: openHoursConfig ?? this.openHoursConfig,
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
      roles: roles ?? this.roles,
      mustChangePassword: mustChangePassword ?? this.mustChangePassword,
      passwordSet: passwordSet ?? this.passwordSet,
      pinResetRequiredAt: pinResetRequiredAt ?? this.pinResetRequiredAt,
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
      supportPhone: d['support_phone'] as String? ?? state.supportPhone,
      lat: (d['lat'] as num?)?.toDouble() ?? state.lat,
      lng: (d['lng'] as num?)?.toDouble() ?? state.lng,
      cityCenterLat:
          (d['city_center_lat'] as num?)?.toDouble() ?? state.cityCenterLat,
      cityCenterLng:
          (d['city_center_lng'] as num?)?.toDouble() ?? state.cityCenterLng,
      description: d['description'] as String?,
      openHoursText: (d['open_hours_text'] as String?) ?? state.openHoursText,
      openHoursConfig: d['open_hours'] != null
          ? ShopOpeningHours.fromJson(d['open_hours'])
          : state.openHoursConfig,
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
      roles: d['roles'] is List
          ? (d['roles'] as List).map((e) => e.toString()).toList()
          : state.roles,
      mustChangePassword: d['must_change_password'] == true,
      passwordSet: d['password_set'] is bool
          ? d['password_set'] as bool
          : state.passwordSet,
      pinResetRequiredAt: d['pin_reset_required_at']?.toString(),
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

  /// Met à jour uniquement les champs autorisés côté commerçant (Lot 9A Point 1 & Lot 9C Point 1-2).
  /// Jamais de lat, lng, address, business_name, category, quartier ni phone dans cette requête.
  Future<(bool, String?)> mettreAJourProfil({
    String? emoji,
    String? description,
    ShopOpeningHours? openHoursConfig,
    String language = 'fr',
    bool notificationsEnabled = true,
    XFile? logoFile,
    XFile? merchantPhotoFile,
  }) async {
    if (_api == null) {
      return (false, 'Client API non initialisé.');
    }
    try {
      final files = <String, XFile>{
        if (logoFile != null) 'logo': logoFile,
        if (merchantPhotoFile != null) 'merchant_photo': merchantPhotoFile,
      };

      final Response<dynamic> res;
      if (files.isNotEmpty) {
        res = await _api.uploadMultipart(
          '/merchant/profile',
          fields: {
            if (emoji != null && emoji.isNotEmpty) 'emoji': emoji,
            'description': description ?? '',
            if (openHoursConfig != null)
              'open_hours': jsonEncode(openHoursConfig.toJson()),
            'language': language,
            'notifications_enabled': notificationsEnabled ? '1' : '0',
          },
          files: files,
        );
      } else {
        res = await _api.put(
          '/merchant/profile',
          data: {
            if (emoji != null && emoji.isNotEmpty) 'emoji': emoji,
            'description': description ?? '',
            if (openHoursConfig != null) 'open_hours': openHoursConfig.toJson(),
            'language': language,
            'notifications_enabled': notificationsEnabled,
          },
        );
      }

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

  Future<(bool, String?)> demanderChangementTelephone(String newPhone) async {
    if (_api == null) return (false, 'Client API non initialisé.');
    try {
      final res = await _api.post(
        '/me/phone/request',
        data: {'new_phone': newPhone.trim()},
      );
      if (res.statusCode == 200 && res.data is Map && res.data['succes'] == true) {
        return (true, res.data['message']?.toString());
      }
      return (false, 'Impossible d\'envoyer le code OTP.');
    } on DioException catch (e) {
      return (false, ApiClient.messageErreur(e));
    } catch (_) {
      return (false, 'Erreur réseau lors de la demande OTP.');
    }
  }

  Future<(bool, String?)> confirmerChangementTelephone(String code) async {
    if (_api == null) return (false, 'Client API non initialisé.');
    try {
      final res = await _api.post(
        '/me/phone/confirm',
        data: {'code': code.trim()},
      );
      if (res.statusCode == 200 && res.data is Map && res.data['succes'] == true) {
        final donnees = res.data['donnees'] is Map
            ? Map<String, dynamic>.from(res.data['donnees'] as Map)
            : const <String, dynamic>{};
        final accessToken = donnees['access_token']?.toString();
        final refreshToken = donnees['refresh_token']?.toString();
        if (accessToken != null && accessToken.isNotEmpty) {
          final storage = SecureTokenStorage.createStorage();
          await storage.write(key: 'access_token', value: accessToken);
          if (refreshToken != null && refreshToken.isNotEmpty) {
            await storage.write(key: 'refresh_token', value: refreshToken);
          }
        }
        await chargerProfil();
        return (true, res.data['message']?.toString() ?? 'Numéro mis à jour.');
      }
      return (false, 'Code OTP invalide.');
    } on DioException catch (e) {
      return (false, ApiClient.messageErreur(e));
    } catch (_) {
      return (false, 'Erreur réseau lors de la confirmation OTP.');
    }
  }

  Future<(bool, String?)> definirMotDePasse({
    String? currentPassword,
    required String password,
    required String passwordConfirmation,
  }) async {
    if (_api == null) return (false, 'Client API non initialisé.');
    try {
      final res = await _api.post(
        '/me/password',
        data: {
          'password': password,
          'password_confirmation': passwordConfirmation,
          if (currentPassword != null && currentPassword.isNotEmpty)
            'current_password': currentPassword,
        },
      );
      if (res.statusCode == 200 && res.data is Map && res.data['succes'] == true) {
        state = state.copyWith(passwordSet: true, mustChangePassword: false);
        return (true, res.data['message']?.toString() ?? 'Mot de passe mis à jour.');
      }
      return (false, 'Impossible de modifier le mot de passe.');
    } on DioException catch (e) {
      return (false, ApiClient.messageErreur(e));
    } catch (_) {
      return (false, 'Erreur réseau lors de la modification du mot de passe.');
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

// ─── Schéma Dynamique du Catalogue (piloté par le Dictionnaire Admin) ─────────
class CatalogFieldDefinition {
  final int id;
  final String code;
  final String label;
  final String fieldType; // text | textarea | currency | number | image | select | boolean
  final bool isCore;
  final bool isRequired;
  final int sortOrder;
  final String? placeholder;
  final List<String> options;

  const CatalogFieldDefinition({
    required this.id,
    required this.code,
    required this.label,
    required this.fieldType,
    this.isCore = false,
    this.isRequired = false,
    this.sortOrder = 100,
    this.placeholder,
    this.options = const [],
  });

  factory CatalogFieldDefinition.fromJson(Map<String, dynamic> json) {
    final rawOptions = json['options'] is List ? json['options'] as List : const [];
    final rawLabel = (json['label'] ?? '').toString().trim();
    final safeLabel = rawLabel.toLowerCase() == 'nom' ? 'Nom du produit' : rawLabel;

    return CatalogFieldDefinition(
      id: (json['id'] as num?)?.toInt() ?? 0,
      code: (json['code'] ?? '').toString(),
      label: safeLabel,
      fieldType: (json['field_type'] ?? 'text').toString(),
      isCore: json['is_core'] == true || json['is_core'] == 1,
      isRequired: json['is_required'] == true || json['is_required'] == 1,
      sortOrder: (json['sort_order'] as num?)?.toInt() ?? 100,
      placeholder: json['placeholder']?.toString(),
      options: rawOptions.map((e) => e.toString()).where((e) => e.isNotEmpty).toList(),
    );
  }
}

class MerchantCatalogSchema {
  final bool isLoading;
  final bool isLoaded;
  final String? errorMessage;
  final int? categoryId;
  final String categorySlug;
  final String categoryName;
  final bool isFood;
  final List<CatalogFieldDefinition> fields;

  const MerchantCatalogSchema({
    this.isLoading = false,
    this.isLoaded = false,
    this.errorMessage,
    this.categoryId,
    this.categorySlug = '',
    this.categoryName = '',
    this.isFood = false,
    this.fields = const [],
  });

  MerchantCatalogSchema copyWith({
    bool? isLoading,
    bool? isLoaded,
    String? errorMessage,
    bool clearError = false,
    int? categoryId,
    String? categorySlug,
    String? categoryName,
    bool? isFood,
    List<CatalogFieldDefinition>? fields,
  }) {
    return MerchantCatalogSchema(
      isLoading: isLoading ?? this.isLoading,
      isLoaded: isLoaded ?? this.isLoaded,
      errorMessage: clearError ? null : (errorMessage ?? this.errorMessage),
      categoryId: categoryId ?? this.categoryId,
      categorySlug: categorySlug ?? this.categorySlug,
      categoryName: categoryName ?? this.categoryName,
      isFood: isFood ?? this.isFood,
      fields: fields ?? this.fields,
    );
  }
}

class BusinessCatalogSchemaNotifier extends StateNotifier<MerchantCatalogSchema> {
  final ApiClient? _api;

  BusinessCatalogSchemaNotifier([this._api]) : super(const MerchantCatalogSchema());

  void reinitialiser() {
    state = const MerchantCatalogSchema();
  }

  Future<void> chargerSchema() async {
    if (_api == null) return;
    state = state.copyWith(isLoading: true, clearError: true);
    try {
      final res = await _api.get('/merchant/catalog-schema');
      if (res.statusCode == 200 &&
          res.data is Map &&
          res.data['succes'] == true &&
          res.data['donnees'] is Map) {
        final d = Map<String, dynamic>.from(res.data['donnees'] as Map);
        final cat = d['merchant_category'] is Map
            ? Map<String, dynamic>.from(d['merchant_category'] as Map)
            : const <String, dynamic>{};
        final rawFields = d['fields'] is List ? d['fields'] as List : const [];
        final parsedFields = rawFields
            .whereType<Map>()
            .map((e) => CatalogFieldDefinition.fromJson(Map<String, dynamic>.from(e)))
            .toList()
          ..sort((a, b) => a.sortOrder.compareTo(b.sortOrder));

        state = MerchantCatalogSchema(
          isLoading: false,
          isLoaded: true,
          categoryId: (cat['id'] as num?)?.toInt(),
          categorySlug: (cat['slug'] ?? '').toString(),
          categoryName: (cat['name'] ?? '').toString(),
          isFood: cat['is_food'] == true,
          fields: parsedFields,
        );
        return;
      }
      state = state.copyWith(
        isLoading: false,
        errorMessage: 'Impossible de charger la configuration du catalogue.',
      );
    } on DioException catch (e) {
      state = state.copyWith(
        isLoading: false,
        errorMessage: ApiClient.messageErreur(e),
      );
    } catch (_) {
      state = state.copyWith(
        isLoading: false,
        errorMessage: 'Impossible de charger les champs de votre type de commerce.',
      );
    }
  }
}

final businessCatalogSchemaProvider =
    StateNotifierProvider<BusinessCatalogSchemaNotifier, MerchantCatalogSchema>((ref) {
  final api = ref.watch(apiClientProvider);
  return BusinessCatalogSchemaNotifier(api);
});

// ─── Catalogue & Gestion des Stocks en direct ─────────────────────────────────
class BusinessProductAttributeDisplay {
  final String code;
  final String label;
  final String fieldType;
  final dynamic value;

  const BusinessProductAttributeDisplay({
    required this.code,
    required this.label,
    required this.fieldType,
    required this.value,
  });

  factory BusinessProductAttributeDisplay.fromJson(Map<String, dynamic> json) =>
      BusinessProductAttributeDisplay(
        code: (json['code'] ?? '').toString(),
        label: (json['label'] ?? json['code'] ?? '').toString(),
        fieldType: (json['field_type'] ?? 'text').toString(),
        value: json['value'],
      );
}

class BusinessProductItem {
  final int id;
  final String name;
  final double price;
  final String? category;
  final String? description;
  final String? photoUrl;
  final int? quantity;
  final bool isAvailable;
  final Map<String, dynamic> attributes;
  final List<BusinessProductAttributeDisplay> orderedAttributes;

  const BusinessProductItem({
    required this.id,
    required this.name,
    required this.price,
    this.category,
    this.description,
    this.photoUrl,
    this.quantity,
    this.isAvailable = true,
    this.attributes = const {},
    this.orderedAttributes = const [],
  });

  factory BusinessProductItem.fromJson(Map<String, dynamic> json) {
    final rawAttrs = json['attributes'] is Map
        ? Map<String, dynamic>.from(json['attributes'] as Map)
        : const <String, dynamic>{};
    final rawOrdered = json['ordered_attributes'] is List
        ? json['ordered_attributes'] as List
        : const [];

    return BusinessProductItem(
      id: (json['id'] as num).toInt(),
      name: (json['name'] ?? '').toString(),
      price: num.tryParse('${json['price'] ?? 0}')?.toDouble() ?? 0,
      category: json['category']?.toString(),
      description: json['description']?.toString(),
      photoUrl: GoLivreurEnv.resolveMediaUrl(json['photo_url']?.toString()),
      quantity: json['quantity'] != null ? (json['quantity'] as num?)?.toInt() : null,
      isAvailable: json['is_available'] == true || json['is_available'] == 1,
      attributes: rawAttrs,
      orderedAttributes: rawOrdered
          .whereType<Map>()
          .map((e) => BusinessProductAttributeDisplay.fromJson(Map<String, dynamic>.from(e)))
          .toList(),
    );
  }

  BusinessProductItem copyWith({
    String? name,
    double? price,
    String? category,
    String? description,
    String? photoUrl,
    int? quantity,
    bool? isAvailable,
    Map<String, dynamic>? attributes,
    List<BusinessProductAttributeDisplay>? orderedAttributes,
  }) {
    return BusinessProductItem(
      id: id,
      name: name ?? this.name,
      price: price ?? this.price,
      category: category ?? this.category,
      description: description ?? this.description,
      photoUrl: photoUrl ?? this.photoUrl,
      quantity: quantity ?? this.quantity,
      isAvailable: isAvailable ?? this.isAvailable,
      attributes: attributes ?? this.attributes,
      orderedAttributes: orderedAttributes ?? this.orderedAttributes,
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
            .map((raw) => BusinessProductItem.fromJson(Map<String, dynamic>.from(raw)))
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

  /// Crée ou met à jour un produit en envoyant STRICTEMENT les champs du schéma Admin.
  Future<(bool, String?)> saveDynamicProduct({
    int? productId,
    required Map<String, dynamic> coreFields,
    required Map<String, dynamic> dynamicAttributes,
    XFile? photoFile,
  }) async {
    if (_api == null) {
      return (false, 'Client API non initialisé.');
    }

    final isEdit = productId != null;
    final path = isEdit ? '/merchant/products/$productId' : '/merchant/products';

    try {
      final Response<dynamic> res;
      if (photoFile != null) {
        final multipartFields = <String, dynamic>{};
        for (final entry in coreFields.entries) {
          if (entry.value != null) {
            multipartFields[entry.key] = '${entry.value}';
          }
        }
        if (dynamicAttributes.isNotEmpty) {
          multipartFields['attributes'] = jsonEncode(dynamicAttributes);
        }
        if (!isEdit) {
          multipartFields['is_available'] = '1';
        }
        res = await _api.uploadMultipart(
          path,
          method: isEdit ? 'PUT' : 'POST',
          fields: multipartFields,
          files: {'photo': photoFile},
        );
      } else {
        final body = <String, dynamic>{
          ...coreFields,
          if (dynamicAttributes.isNotEmpty) 'attributes': dynamicAttributes,
          if (!isEdit) 'is_available': true,
        };
        res = isEdit
            ? await _api.put(path, data: body)
            : await _api.post(path, data: body);
      }

      if ((res.statusCode == 200 || res.statusCode == 201) &&
          res.data is Map &&
          res.data['succes'] == true &&
          res.data['donnees'] is Map) {
        final item = BusinessProductItem.fromJson(
          Map<String, dynamic>.from(res.data['donnees'] as Map),
        );
        if (isEdit) {
          state = state.map((p) => p.id == item.id ? item : p).toList();
        } else {
          state = [item, ...state];
        }
        return (true, null);
      }

      return (false, 'Réponse inattendue du serveur.');
    } on DioException catch (e) {
      return (false, ApiClient.messageErreur(e));
    } catch (_) {
      return (false, 'Impossible d\'enregistrer l\'article.');
    }
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
    final hasReadyAt =
        json['ready_at'] != null && '${json['ready_at']}'.trim().isNotEmpty;
    final status = switch (statusStr) {
      'pending' => BusinessOrderStatus.pending,
      'accepted' => BusinessOrderStatus.accepted,
      'waiting' =>
        hasReadyAt ? BusinessOrderStatus.ready : BusinessOrderStatus.accepted,
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

    final createdAt = json['created_at'] != null
        ? DateTime.tryParse('${json['created_at']}') ?? DateTime.now()
        : DateTime.now();
    final elapsedSec = DateTime.now().difference(createdAt).inSeconds;
    final remSec = (45 - elapsedSec).clamp(0, 45);

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
      createdAt: createdAt,
      secondsRemaining: remSec,
    );
  }

  Future<void> acceptOrder(int orderId) async {
    state = state.map((o) {
      if (o.id == orderId) {
        return o.copyWith(status: BusinessOrderStatus.accepted);
      }
      return o;
    }).toList();
    if (_api == null) return;
    try {
      await _api.post('/merchant/orders/$orderId/accept');
    } catch (_) {}
    await chargerCommandes();
  }

  Future<void> refuseOrder(int orderId) async {
    state = state.map((o) {
      if (o.id == orderId) {
        return o.copyWith(status: BusinessOrderStatus.refused);
      }
      return o;
    }).toList();
    if (_api == null) return;
    try {
      await _api.post(
        '/merchant/orders/$orderId/refuse',
        data: {'raison': 'Rupture de stock'},
      );
    } catch (_) {}
    await chargerCommandes();
  }

  Future<void> markOrderReady(int orderId) async {
    state = state.map((o) {
      if (o.id == orderId) {
        return o.copyWith(status: BusinessOrderStatus.ready);
      }
      return o;
    }).toList();
    if (_api == null) return;
    try {
      await _api.post('/merchant/orders/$orderId/ready');
    } catch (_) {}
    await chargerCommandes();
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
  final String type; // credit | debit
  final double amount;
  final String provider;
  final String date;
  final String status;

  const DisbursementItem({
    required this.id,
    this.type = 'debit',
    required this.amount,
    required this.provider,
    required this.date,
    required this.status,
  });

  factory DisbursementItem.fromJson(Map<String, dynamic> json) {
    final rawType = (json['type'] ?? '').toString().toLowerCase();
    final txType = (json['tx_type'] ?? '').toString().toLowerCase();
    final isCredit = rawType == 'credit' || txType == 'deposit';
    final rawStatus = (json['statut'] ?? json['status'] ?? 'Effectué').toString();
    final localizedStatus = switch (rawStatus.toLowerCase()) {
      'completed' => 'Validé',
      'pending' => 'En attente',
      'failed' => 'Échoué',
      _ => rawStatus,
    };

    return DisbursementItem(
      id: (json['id'] ?? '').toString(),
      type: isCredit ? 'credit' : 'debit',
      amount: num.tryParse('${json['montant'] ?? json['amount'] ?? 0}')
              ?.toDouble() ??
          0,
      provider: (json['description'] ??
              json['provider'] ??
              (isCredit ? 'Dépôt espèces' : 'Versement / Retrait'))
          .toString(),
      date: (json['date'] ?? '').toString(),
      status: localizedStatus,
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

// ─── Livreur Prioritaire de la Boutique (Lot 9A Point 8 / Lot 9C Point 3) ───
class PriorityCourierOption {
  final int id;
  final String name;
  final String phone;
  final String? vehicleType;
  final String? vehiclePlate;
  final bool isOnline;
  final bool isActive;
  final String validationStatus; // pending | approved | rejected
  final double averageRating;

  const PriorityCourierOption({
    required this.id,
    required this.name,
    required this.phone,
    this.vehicleType,
    this.vehiclePlate,
    this.isOnline = false,
    this.isActive = false,
    this.validationStatus = 'pending',
    this.averageRating = 0,
  });

  bool get isApproved => validationStatus == 'approved' && isActive;

  String get statusLabel => switch (validationStatus) {
        'approved' => isActive ? 'Validé' : 'Inactif',
        'rejected' => 'Refusé',
        _ => 'En attente de validation',
      };

  factory PriorityCourierOption.fromJson(Map<String, dynamic> json) =>
      PriorityCourierOption(
        id: (json['id'] as num?)?.toInt() ?? 0,
        name: json['name']?.toString() ?? '',
        phone: json['phone']?.toString() ?? '',
        vehicleType: json['vehicle_type']?.toString(),
        vehiclePlate: json['vehicle_plate']?.toString(),
        isOnline: json['is_online'] == true,
        isActive: json['is_active'] == true,
        validationStatus:
            (json['validation_status']?.toString() ?? 'pending').toLowerCase(),
        averageRating: (json['average_rating'] as num?)?.toDouble() ?? 0,
      );
}

class PriorityCourierState {
  final bool loading;
  final bool saving;
  final PriorityCourierOption? current;
  final List<PriorityCourierOption> available;
  final String? errorMessage;

  const PriorityCourierState({
    this.loading = false,
    this.saving = false,
    this.current,
    this.available = const [],
    this.errorMessage,
  });

  PriorityCourierState copyWith({
    bool? loading,
    bool? saving,
    PriorityCourierOption? current,
    bool clearCurrent = false,
    List<PriorityCourierOption>? available,
    String? errorMessage,
  }) {
    return PriorityCourierState(
      loading: loading ?? this.loading,
      saving: saving ?? this.saving,
      current: clearCurrent ? null : (current ?? this.current),
      available: available ?? this.available,
      errorMessage: errorMessage,
    );
  }
}

class PriorityCourierNotifier extends StateNotifier<PriorityCourierState> {
  final ApiClient? _api;

  PriorityCourierNotifier([this._api]) : super(const PriorityCourierState());

  void reinitialiser() {
    state = const PriorityCourierState();
  }

  Future<void> charger() async {
    if (_api == null) return;
    state = state.copyWith(loading: true, errorMessage: null);
    try {
      final res = await _api.get('/business/priority-drivers');
      if (res.statusCode == 200 &&
          res.data is Map &&
          res.data['succes'] == true &&
          res.data['donnees'] is Map) {
        final d = Map<String, dynamic>.from(res.data['donnees'] as Map);
        final selectedId = (d['selected_priority_courier_id'] as num?)?.toInt();
        final rawList = d['drivers'] is List ? d['drivers'] as List : const [];
        final drivers = rawList
            .whereType<Map>()
            .map((e) =>
                PriorityCourierOption.fromJson(Map<String, dynamic>.from(e)))
            .toList();
        PriorityCourierOption? current;
        if (selectedId != null) {
          for (final drv in drivers) {
            if (drv.id == selectedId) {
              current = drv;
              break;
            }
          }
        }
        state = PriorityCourierState(
          loading: false,
          current: current,
          available: drivers,
        );
        return;
      }
      state = state.copyWith(loading: false);
    } catch (_) {
      state = state.copyWith(loading: false);
    }
  }

  Future<(bool, String)> creerLivreur({
    required String name,
    required String phone,
    required String vehicleType,
    required String vehiclePlate,
    String? password,
  }) async {
    if (_api == null) return (false, 'Client API indisponible.');
    state = state.copyWith(saving: true, errorMessage: null);
    try {
      final res = await _api.post(
        '/business/priority-drivers',
        data: {
          'name': name.trim(),
          'phone': phone.trim(),
          'vehicle_type': vehicleType,
          'vehicle_plate': vehiclePlate.trim(),
          if (password != null && password.trim().isNotEmpty)
            'password': password.trim(),
        },
      );
      final msg = (res.data is Map ? res.data['message']?.toString() : null) ??
          'Livreur pré-inscrit (en attente de validation agence).';
      await charger();
      state = state.copyWith(saving: false);
      return (true, msg);
    } on DioException catch (e) {
      final msg = ApiClient.messageErreur(e);
      state = state.copyWith(saving: false, errorMessage: msg);
      return (false, msg);
    } catch (_) {
      state = state.copyWith(saving: false);
      return (false, 'Impossible d\'enregistrer ce livreur.');
    }
  }

  Future<(bool, String)> assigner(int? courierId) async {
    if (_api == null) return (false, 'Client API indisponible.');
    state = state.copyWith(saving: true, errorMessage: null);
    try {
      final res = courierId == null
          ? await _api.delete('/business/priority-drivers/select')
          : await _api.put('/business/priority-drivers/$courierId/select');
      final msg = (res.data is Map ? res.data['message']?.toString() : null) ??
          'Configuration mise à jour.';
      await charger();
      state = state.copyWith(saving: false);
      return (true, msg);
    } on DioException catch (e) {
      final msg = ApiClient.messageErreur(e);
      state = state.copyWith(saving: false, errorMessage: msg);
      return (false, msg);
    } catch (_) {
      state = state.copyWith(saving: false);
      return (false, 'Impossible de modifier le livreur prioritaire.');
    }
  }
}

final priorityCourierProvider =
    StateNotifierProvider<PriorityCourierNotifier, PriorityCourierState>((ref) {
  final api = ref.watch(apiClientProvider);
  return PriorityCourierNotifier(api);
});

// ─── Historique Jour / Semaine ISO (Lot 9A Point 11 / Lot 9C Point 6) ────────
class BusinessHistoryOrder {
  final int id;
  final String status;
  final String clientName;
  final double productsTotal;
  final double merchantAmount;
  final String paymentMethod;
  final String createdAt;

  const BusinessHistoryOrder({
    required this.id,
    required this.status,
    required this.clientName,
    required this.productsTotal,
    required this.merchantAmount,
    required this.paymentMethod,
    required this.createdAt,
  });

  factory BusinessHistoryOrder.fromJson(Map<String, dynamic> json) =>
      BusinessHistoryOrder(
        id: (json['id'] as num?)?.toInt() ?? 0,
        status: (json['status'] ?? '').toString(),
        clientName: (json['client_name'] ??
                (json['client'] is Map ? json['client']['name'] : null) ??
                'Client')
            .toString(),
        productsTotal:
            num.tryParse('${json['products_total'] ?? 0}')?.toDouble() ?? 0,
        merchantAmount:
            num.tryParse('${json['merchant_amount'] ?? 0}')?.toDouble() ?? 0,
        paymentMethod: (json['payment_method'] ?? 'cash').toString(),
        createdAt: (json['created_at'] ?? '').toString(),
      );
}

class BusinessHistoryBucket {
  final String scope; // today | week
  final String label;
  final String? weekKey;
  final String? previousWeek;
  final int ordersCount;
  final int deliveredCount;
  final double deliveredMerchantAmount;
  final List<BusinessHistoryOrder> orders;

  const BusinessHistoryBucket({
    required this.scope,
    required this.label,
    this.weekKey,
    this.previousWeek,
    this.ordersCount = 0,
    this.deliveredCount = 0,
    this.deliveredMerchantAmount = 0,
    this.orders = const [],
  });

  factory BusinessHistoryBucket.fromJson(Map<String, dynamic> d) {
    final summary = d['summary'] is Map
        ? Map<String, dynamic>.from(d['summary'] as Map)
        : const <String, dynamic>{};
    final rawOrders = d['orders'] is List ? d['orders'] as List : const [];
    final scope = (d['scope'] ?? 'today').toString();
    final week = d['week']?.toString();
    final startDate = (d['period_start'] ?? d['start_date'] ?? '').toString();
    final endDate = (d['period_end'] ?? d['end_date'] ?? '').toString();

    final label = scope == 'today'
        ? 'Aujourd\'hui${startDate.isNotEmpty ? ' ($startDate)' : ''}'
        : 'Semaine ${week ?? ''}${startDate.isNotEmpty && endDate.isNotEmpty ? ' ($startDate → $endDate)' : ''}';

    final ordersCount =
        (d['orders_count'] as num?)?.toInt() ??
            (summary['orders_count'] as num?)?.toInt() ??
            rawOrders.length;
    final deliveredCount =
        (d['delivered_count'] as num?)?.toInt() ??
            (summary['delivered_count'] as num?)?.toInt() ??
            0;
    final deliveredMerchantAmount = num.tryParse(
          '${d['delivered_merchant_amount'] ?? summary['delivered_merchant_amount'] ?? 0}',
        )?.toDouble() ??
        0;

    return BusinessHistoryBucket(
      scope: scope,
      label: label,
      weekKey: week,
      previousWeek: d['previous_week']?.toString(),
      ordersCount: ordersCount,
      deliveredCount: deliveredCount,
      deliveredMerchantAmount: deliveredMerchantAmount,
      orders: rawOrders
          .whereType<Map>()
          .map((e) => BusinessHistoryOrder.fromJson(Map<String, dynamic>.from(e)))
          .toList(),
    );
  }
}

class BusinessOrderHistoryState {
  final bool loading;
  final bool loadingMore;
  final List<BusinessHistoryBucket> buckets;
  final String? nextPreviousWeek;
  final String? errorMessage;

  const BusinessOrderHistoryState({
    this.loading = false,
    this.loadingMore = false,
    this.buckets = const [],
    this.nextPreviousWeek,
    this.errorMessage,
  });

  BusinessOrderHistoryState copyWith({
    bool? loading,
    bool? loadingMore,
    List<BusinessHistoryBucket>? buckets,
    String? nextPreviousWeek,
    String? errorMessage,
  }) {
    return BusinessOrderHistoryState(
      loading: loading ?? this.loading,
      loadingMore: loadingMore ?? this.loadingMore,
      buckets: buckets ?? this.buckets,
      nextPreviousWeek: nextPreviousWeek ?? this.nextPreviousWeek,
      errorMessage: errorMessage,
    );
  }
}

class BusinessOrderHistoryNotifier
    extends StateNotifier<BusinessOrderHistoryState> {
  final ApiClient? _api;

  BusinessOrderHistoryNotifier([this._api])
      : super(const BusinessOrderHistoryState());

  void reinitialiser() {
    state = const BusinessOrderHistoryState();
  }

  Future<void> chargerAujourdhui() async {
    if (_api == null) return;
    state = state.copyWith(loading: true, errorMessage: null);
    try {
      final res = await _api.get('/business/orders/history?scope=today');
      if (res.statusCode == 200 &&
          res.data is Map &&
          res.data['succes'] == true &&
          res.data['donnees'] is Map) {
        final bucket = BusinessHistoryBucket.fromJson(
          Map<String, dynamic>.from(res.data['donnees'] as Map),
        );
        // Depuis « Aujourd'hui », le 1er clic « Voir + » charge toute la semaine ISO en cours
        // (pour ne pas sauter lundi..hier), puis remonte semaine par semaine.
        final firstMoreWeek =
            (bucket.weekKey != null && bucket.weekKey!.isNotEmpty)
                ? bucket.weekKey
                : bucket.previousWeek;
        state = BusinessOrderHistoryState(
          loading: false,
          buckets: [bucket],
          nextPreviousWeek: firstMoreWeek,
        );
        return;
      }
      state = state.copyWith(
        loading: false,
        errorMessage: 'Impossible de charger l\'historique.',
      );
    } on DioException catch (e) {
      state = state.copyWith(
        loading: false,
        errorMessage: ApiClient.messageErreur(e),
      );
    } catch (_) {
      state = state.copyWith(
        loading: false,
        errorMessage: 'Erreur réseau lors du chargement de l\'historique.',
      );
    }
  }

  Future<void> chargerSemainePrecedente() async {
    if (_api == null || state.loadingMore) return;
    final week = state.nextPreviousWeek;
    if (week == null || week.isEmpty) return;

    state = state.copyWith(loadingMore: true);
    try {
      final res = await _api.get('/business/orders/history?week=$week');
      if (res.statusCode == 200 &&
          res.data is Map &&
          res.data['succes'] == true &&
          res.data['donnees'] is Map) {
        final bucket = BusinessHistoryBucket.fromJson(
          Map<String, dynamic>.from(res.data['donnees'] as Map),
        );
        state = BusinessOrderHistoryState(
          loading: false,
          loadingMore: false,
          buckets: [...state.buckets, bucket],
          nextPreviousWeek: bucket.previousWeek,
        );
        return;
      }
      state = state.copyWith(loadingMore: false);
    } catch (_) {
      state = state.copyWith(loadingMore: false);
    }
  }
}

final businessOrderHistoryProvider = StateNotifierProvider<
    BusinessOrderHistoryNotifier, BusinessOrderHistoryState>((ref) {
  final api = ref.watch(apiClientProvider);
  return BusinessOrderHistoryNotifier(api);
});
