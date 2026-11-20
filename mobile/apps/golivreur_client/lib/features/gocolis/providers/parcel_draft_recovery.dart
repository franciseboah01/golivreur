import 'dart:convert';
import 'package:flutter/foundation.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:image_picker/image_picker.dart';

/// Brouillon temporaire de l'écran "Nouvelle Expédition" persisté juste avant l'ouverture
/// de l'appareil photo Android afin de survivre à une destruction d'activité par l'OS
/// (Low Memory Killer sur les téléphones Android à RAM limitée).
class ParcelDraftData {
  final String recipientName;
  final String recipientPhone;
  final int? neighborhoodId;
  final String? neighborhoodName;
  final String description;
  final String declaredValue;
  final String? selectedSize;
  final String selectedPaymentMethod;
  final double? pickupLat;
  final double? pickupLng;
  final String? pickupAddressText;
  final int savedAtMs;

  const ParcelDraftData({
    required this.recipientName,
    required this.recipientPhone,
    this.neighborhoodId,
    this.neighborhoodName,
    required this.description,
    required this.declaredValue,
    this.selectedSize,
    required this.selectedPaymentMethod,
    this.pickupLat,
    this.pickupLng,
    this.pickupAddressText,
    required this.savedAtMs,
  });

  Map<String, dynamic> toJson() => {
        'recipient_name': recipientName,
        'recipient_phone': recipientPhone,
        'neighborhood_id': neighborhoodId,
        'neighborhood_name': neighborhoodName,
        'description': description,
        'declared_value': declaredValue,
        'selected_size': selectedSize,
        'selected_payment_method': selectedPaymentMethod,
        'pickup_lat': pickupLat,
        'pickup_lng': pickupLng,
        'pickup_address_text': pickupAddressText,
        'saved_at_ms': savedAtMs,
      };

  factory ParcelDraftData.fromJson(Map<String, dynamic> json) =>
      ParcelDraftData(
        recipientName: json['recipient_name'] as String? ?? '',
        recipientPhone: json['recipient_phone'] as String? ?? '',
        neighborhoodId: (json['neighborhood_id'] as num?)?.toInt(),
        neighborhoodName: json['neighborhood_name'] as String?,
        description: json['description'] as String? ?? '',
        declaredValue: json['declared_value'] as String? ?? '',
        selectedSize: json['selected_size'] as String?,
        selectedPaymentMethod:
            json['selected_payment_method'] as String? ?? 'cash',
        pickupLat: (json['pickup_lat'] as num?)?.toDouble(),
        pickupLng: (json['pickup_lng'] as num?)?.toDouble(),
        pickupAddressText: json['pickup_address_text'] as String?,
        savedAtMs: (json['saved_at_ms'] as num?)?.toInt() ?? 0,
      );
}

class PendingParcelRecovery {
  final ParcelDraftData draft;
  final XFile? photo;

  const PendingParcelRecovery({
    required this.draft,
    this.photo,
  });
}

/// Gère la sauvegarde du brouillon GoColis avant ouverture de la caméra
/// et la récupération automatique (`ImagePicker.retrieveLostData`) si Android
/// a recréé `MainActivity` après la prise de photo.
class ParcelDraftRecovery {
  static const String _storageKey = 'pending_new_parcel_draft_v1';
  static const int _maxDraftAgeMs = 15 * 60 * 1000; // 15 minutes

  static PendingParcelRecovery? _pending;

  static PendingParcelRecovery? takePendingRecovery() {
    final current = _pending;
    _pending = null;
    return current;
  }

  static Future<void> saveDraft(
    FlutterSecureStorage storage,
    ParcelDraftData draft,
  ) async {
    try {
      await storage.write(
        key: _storageKey,
        value: jsonEncode(draft.toJson()),
      );
    } catch (_) {}
  }

  static Future<void> clearDraft(FlutterSecureStorage storage) async {
    _pending = null;
    try {
      await storage.delete(key: _storageKey);
    } catch (_) {}
  }

  static Future<ParcelDraftData?> readActiveDraft(
    FlutterSecureStorage storage,
  ) async {
    try {
      final raw = await storage.read(key: _storageKey);
      if (raw == null || raw.isEmpty) return null;

      final decoded = jsonDecode(raw);
      if (decoded is! Map<String, dynamic>) {
        await storage.delete(key: _storageKey);
        return null;
      }

      final draft = ParcelDraftData.fromJson(decoded);
      final ageMs = DateTime.now().millisecondsSinceEpoch - draft.savedAtMs;
      if (draft.savedAtMs <= 0 || ageMs < 0 || ageMs > _maxDraftAgeMs) {
        await storage.delete(key: _storageKey);
        return null;
      }
      return draft;
    } catch (_) {
      return null;
    }
  }

  static Future<XFile?> retrieveLostCameraPhoto() async {
    if (kIsWeb || defaultTargetPlatform != TargetPlatform.android) {
      return null;
    }
    try {
      final picker = ImagePicker();
      final LostDataResponse response = await picker.retrieveLostData();
      if (response.isEmpty) return null;
      if (response.file != null) return response.file;
      if (response.files != null && response.files!.isNotEmpty) {
        return response.files!.first;
      }
    } catch (_) {}
    return null;
  }

  /// Vérifie au démarrage si l'app revient d'une prise de photo tuée par Android.
  /// Si oui, stocke l'état à restaurer dans `_pending` et retourne non-null afin
  /// de sauter le Splash Screen et rouvrir immédiatement `NewParcelScreen`.
  static Future<PendingParcelRecovery?> checkPendingRecovery(
    FlutterSecureStorage storage,
  ) async {
    final draft = await readActiveDraft(storage);
    if (draft == null) {
      return null;
    }

    final photo = await retrieveLostCameraPhoto();
    final recovery = PendingParcelRecovery(draft: draft, photo: photo);
    _pending = recovery;
    return recovery;
  }
}
