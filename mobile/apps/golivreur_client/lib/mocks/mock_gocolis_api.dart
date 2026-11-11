import 'package:golivreur_core/models/modeles.dart';

/// Mock API GoColis — données statiques pour le renfort Flutter (S7).
///
/// INSTRUCTIONS POUR LE RENFORT FLUTTER :
///   1. Ce fichier simule toutes les réponses API GoColis
///   2. Configurer USE_MOCK = true dans env.dart pour l'activer
///   3. Quand la vraie API est prête, mettre USE_MOCK = false
///   4. Tous les endpoints GoColis sont documentés dans :
///      - http://localhost:8000/docs (Scramble OpenAPI)
///      - golivreur_api_tools/bruno/gocolis/ (collection Bruno)
///
/// Contrats API figés en S5 — ne pas modifier sans consulter le Lead Dev.

class MockGoColisApi {
  MockGoColisApi._();

  // ── Délai simulé réseau (100ms pour que les états de chargement soient visibles)
  static const Duration _delaiReseau = Duration(milliseconds: 100);

  // ─── Liste des expéditions de l'expéditeur ──────────────────────────────────

  static Future<List<ParcelModel>> listerExpeditions() async {
    await Future.delayed(_delaiReseau);
    return [_parcelLivre, _parcelEnTransit, _parcelEnAttente];
  }

  // ─── Détail d'une expédition ─────────────────────────────────────────────────

  static Future<ParcelModel> detailExpedition(int id) async {
    await Future.delayed(_delaiReseau);
    return switch (id) {
      1 => _parcelLivre,
      2 => _parcelEnTransit,
      3 => _parcelEnAttente,
      _ => _parcelEnAttente,
    };
  }

  // ─── Créer une expédition ────────────────────────────────────────────────────
  /// Retourne l'expédition créée AVEC le sender_code en clair (une seule fois).
  static Future<Map<String, dynamic>> creerExpedition({
    required String recipientName,
    required String recipientPhone,
    required String deliveryAddress,
    required String size,
    required double declaredValue,
    required String paymentMethod,
    required double distanceKm,
  }) async {
    await Future.delayed(_delaiReseau);

    final frais = _calculerFrais(size, distanceKm);
    final assurance = declaredValue > 20000 ? (declaredValue * 0.03).round() : 0;

    return {
      'succes': true,
      'message': 'Expédition créée avec succès.',
      'donnees': {
        'id': 99,
        'sender_code_plain': '7291', // Code en clair — UNE SEULE FOIS
        'size': size,
        'size_label': _labelTaille(size),
        'shipping_fee': frais,
        'has_insurance': declaredValue > 20000,
        'insurance_amount': assurance,
        'recipient_name': recipientName,
        'recipient_phone': recipientPhone,
        'status': 'pending',
        'payment_method': paymentMethod,
        'created_at': DateTime.now().toIso8601String(),
      },
    };
  }

  // ─── Endpoints Driver GoColis ────────────────────────────────────────────────

  static Future<Map<String, dynamic>> accepterCourse(int parcelId) async {
    await Future.delayed(_delaiReseau);
    return {'succes': true, 'message': 'Course acceptée.'};
  }

  static Future<Map<String, dynamic>> arriverExpediteur(int parcelId) async {
    await Future.delayed(_delaiReseau);
    return {'succes': true, 'message': 'Statut mis à jour : chez l\'expéditeur.'};
  }

  static Future<Map<String, dynamic>> validerCodeExpediteur(
    int parcelId,
    String code,
  ) async {
    await Future.delayed(_delaiReseau);
    // Mock : seul '7291' est accepté
    if (code == '7291') {
      return {
        'succes': true,
        'message': 'Code expéditeur validé. Colis récupéré.',
        'donnees': {'status': 'picked_up'},
      };
    }
    // Simuler une erreur de code
    return {
      'succes': false,
      'message': 'Code incorrect. 2 tentative(s) restante(s).',
      'erreurs': {'code': ['Code incorrect.']},
    };
  }

  static Future<Map<String, dynamic>> arriverDestinataire(int parcelId) async {
    await Future.delayed(_delaiReseau);
    return {'succes': true, 'message': 'Statut mis à jour : chez le destinataire.'};
  }

  static Future<Map<String, dynamic>> validerCodeDestinataire(
    int parcelId,
    String code,
  ) async {
    await Future.delayed(_delaiReseau);
    // Mock : seul '3847' est accepté
    if (code == '3847') {
      return {
        'succes': true,
        'message': 'Livraison confirmée. Gain crédité sur votre wallet.',
        'donnees': {
          'status': 'delivered',
          'gain': 1125, // 75% des frais d'expédition
        },
      };
    }
    return {
      'succes': false,
      'message': 'Code incorrect. 2 tentative(s) restante(s).',
    };
  }

  static Future<Map<String, dynamic>> uploadPhoto(
    int parcelId,
    String type, // pickup | delivery
  ) async {
    await Future.delayed(const Duration(milliseconds: 200));
    return {
      'succes': true,
      'message': 'Photo sauvegardée.',
      'donnees': {
        'photo_url': 'https://via.placeholder.com/400x300/F4A623/FFFFFF?text=Photo+$type',
        'type': type,
      },
    };
  }

  // ─── Données de test ────────────────────────────────────────────────────────

  static final ParcelModel _parcelLivre = ParcelModel(
    id: 1,
    status: 'delivered',
    size: 'petit',
    sizeLabel: 'Petit (max 1 kg)',
    declaredValue: 5000,
    hasInsurance: false,
    insuranceAmount: 0,
    recipientName: 'Koné Mariam',
    recipientPhone: '0709000001',
    deliveryAddress: 'Quartier Résidentiel, Daloa',
    shippingFee: 1200,
    paymentMethod: 'orange_money',
    senderCodeLocked: false,
    recipientCodeLocked: false,
    courier: const CourierModel(
      id: 1,
      name: 'Ouattara Seydou',
      photoUrl: null,
      currentLat: 6.8815,
      currentLng: -6.4488,
      averageRating: 4.8,
      isOnline: true,
    ),
    photos: [
      const ParcelPhotoModel(
        id: 1,
        type: 'pickup',
        photoUrl: 'https://via.placeholder.com/400x300/1B5E20/FFFFFF?text=Ramassage',
      ),
      const ParcelPhotoModel(
        id: 2,
        type: 'delivery',
        photoUrl: 'https://via.placeholder.com/400x300/4CAF50/FFFFFF?text=Livraison',
      ),
    ],
    createdAt: DateTime.now().subtract(const Duration(hours: 2)),
    deliveredAt: DateTime.now().subtract(const Duration(minutes: 30)),
  );

  static final ParcelModel _parcelEnTransit = ParcelModel(
    id: 2,
    status: 'in_transit',
    size: 'moyen',
    sizeLabel: 'Moyen (max 5 kg)',
    declaredValue: 25000,
    hasInsurance: true,
    insuranceAmount: 750,
    recipientName: 'Brou Evelyne',
    recipientPhone: '0709000002',
    deliveryAddress: 'Rue du Marché, Daloa',
    shippingFee: 1500,
    paymentMethod: 'cash',
    senderCodeLocked: false,
    recipientCodeLocked: false,
    courier: const CourierModel(
      id: 2,
      name: 'Coulibaly Issouf',
      photoUrl: null,
      currentLat: 6.8800,
      currentLng: -6.4510,
      averageRating: 4.5,
      isOnline: true,
    ),
    photos: [
      const ParcelPhotoModel(
        id: 3,
        type: 'pickup',
        photoUrl: 'https://via.placeholder.com/400x300/F4A623/FFFFFF?text=Ramassage',
      ),
    ],
    createdAt: DateTime.now().subtract(const Duration(minutes: 45)),
    deliveredAt: null,
  );

  static final ParcelModel _parcelEnAttente = ParcelModel(
    id: 3,
    status: 'pending',
    size: 'grand',
    sizeLabel: 'Grand (max 15 kg)',
    declaredValue: 10000,
    hasInsurance: false,
    insuranceAmount: 0,
    recipientName: 'Traoré Mamadou',
    recipientPhone: '0709000003',
    deliveryAddress: 'Avenue principale, Daloa',
    shippingFee: 2000,
    paymentMethod: 'orange_money',
    senderCodeLocked: false,
    recipientCodeLocked: false,
    courier: null,
    photos: [],
    createdAt: DateTime.now().subtract(const Duration(minutes: 5)),
    deliveredAt: null,
  );

  // ─── Méthodes privées ───────────────────────────────────────────────────────

  static double _calculerFrais(String taille, double distanceKm) {
    final base = switch (taille) {
      'petit' => 800.0,
      'moyen' => 1200.0,
      'grand' => 2000.0,
      _       => 1200.0,
    };
    final parKm = switch (taille) {
      'petit' => 200.0,
      'moyen' => 300.0,
      'grand' => 400.0,
      _       => 300.0,
    };
    final kmSup = (distanceKm - 2).clamp(0, double.infinity);
    return base + (kmSup * parKm);
  }

  static String _labelTaille(String taille) => switch (taille) {
        'petit' => 'Petit (max 1 kg)',
        'moyen' => 'Moyen (max 5 kg)',
        'grand' => 'Grand (max 15 kg)',
        _       => taille,
      };
}
