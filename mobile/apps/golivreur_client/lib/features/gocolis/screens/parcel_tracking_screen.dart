import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:golivreur_core/config/app_theme.dart';
import 'package:golivreur_core/widgets/golivreur_widgets.dart';
import '../../goshop/providers/gps_tracking_provider.dart';
import '../../goshop/providers/goshop_provider.dart';
import '../providers/gocolis_provider.dart';

class ParcelTrackingScreen extends ConsumerWidget {
  final int parcelId;

  const ParcelTrackingScreen({super.key, required this.parcelId});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final parcelAsync = ref.watch(singleParcelProvider(parcelId));
    final senderCodeAsync = ref.watch(parcelSenderCodeProvider(parcelId));
    final courierLoc = ref.watch(parcelGpsTrackingProvider(parcelId)).valueOrNull;
    final weather = ref.watch(weatherProvider);

    return Scaffold(
      backgroundColor: GoLivreurTheme.fond,
      appBar: AppBar(
        title: Text('Expédition #$parcelId'),
        actions: [
          IconButton(
            icon: const Icon(Icons.refresh),
            onPressed: () => ref.invalidate(singleParcelProvider(parcelId)),
          ),
        ],
      ),
      body: parcelAsync.when(
        data: (parcel) => SingleChildScrollView(
          padding: const EdgeInsets.all(16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              // ─── Statut Global ───────────────────────────────────────────
              Container(
                width: double.infinity,
                padding: const EdgeInsets.all(16),
                decoration: BoxDecoration(
                  color: Colors.white,
                  borderRadius: BorderRadius.circular(16),
                  border: Border.all(color: GoLivreurTheme.bordure),
                ),
                child: Column(
                  children: [
                    Row(
                      children: [
                        Container(
                          padding: const EdgeInsets.all(10),
                          decoration: BoxDecoration(
                            color: GoLivreurTheme.secondaire.withValues(alpha: 0.1),
                            shape: BoxShape.circle,
                          ),
                          child: const Icon(Icons.moped, color: GoLivreurTheme.secondaire, size: 28),
                        ),
                        const SizedBox(width: 14),
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(
                                parcel.labelStatut,
                                style: const TextStyle(
                                  fontSize: 16,
                                  fontWeight: FontWeight.bold,
                                  color: GoLivreurTheme.textePrincipal,
                                ),
                              ),
                              const SizedBox(height: 4),
                              Text(
                                'Livreur : ${parcel.courier?.name ?? "Attribution en cours"}',
                                style: const TextStyle(fontSize: 13, color: GoLivreurTheme.texteSecondaire),
                              ),
                            ],
                          ),
                        ),
                        if (parcel.courier != null)
                          CircleAvatar(
                            backgroundColor: GoLivreurTheme.secondaire.withValues(alpha: 0.1),
                            child: IconButton(
                              icon: const Icon(Icons.phone, color: GoLivreurTheme.secondaire, size: 20),
                              onPressed: () {
                                ScaffoldMessenger.of(context).showSnackBar(
                                  const SnackBar(content: Text('Appel vers le coursier (Numéro sécurisé)')),
                                );
                              },
                            ),
                          ),
                      ],
                    ),
                  ],
                ),
              ),
              const SizedBox(height: 16),

              // ─── Carte GPS temps réel (OpenStreetMap 100 % gratuit) ──────
              Container(
                width: double.infinity,
                decoration: BoxDecoration(
                  color: Colors.white,
                  borderRadius: BorderRadius.circular(16),
                  border: Border.all(color: GoLivreurTheme.bordure),
                ),
                clipBehavior: Clip.antiAlias,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Padding(
                      padding: const EdgeInsets.fromLTRB(14, 12, 14, 8),
                      child: Row(
                        children: [
                          const Icon(Icons.map_outlined,
                              color: GoLivreurTheme.primaire, size: 18),
                          const SizedBox(width: 8),
                          const Expanded(
                            child: Text(
                              'Suivi GPS en direct',
                              style: TextStyle(
                                fontWeight: FontWeight.bold,
                                fontSize: 14,
                              ),
                            ),
                          ),
                          if (courierLoc != null)
                            Container(
                              padding: const EdgeInsets.symmetric(
                                  horizontal: 8, vertical: 3),
                              decoration: BoxDecoration(
                                color: GoLivreurTheme.secondaire
                                    .withValues(alpha: 0.12),
                                borderRadius: BorderRadius.circular(10),
                              ),
                              child: const Text(
                                'Signal actif',
                                style: TextStyle(
                                  fontSize: 11,
                                  fontWeight: FontWeight.w700,
                                  color: GoLivreurTheme.secondaire,
                                ),
                              ),
                            ),
                        ],
                      ),
                    ),
                    SizedBox(
                      height: 210,
                      child: OsmLiveTrackingMap(
                        courierLat: courierLoc?.lat,
                        courierLng: courierLoc?.lng,
                        pickupLat: parcel.pickupLat,
                        pickupLng: parcel.pickupLng,
                        destinationLat: parcel.deliveryLat,
                        destinationLng: parcel.deliveryLng,
                        fallbackLat: weather.centerLat ?? 6.8770,
                        fallbackLng: weather.centerLng ?? -6.4502,
                      ),
                    ),
                  ],
                ),
              ),
              const SizedBox(height: 16),

              // ─── Code de Sécurité Expéditeur ─────────────────────────────
              Container(
                width: double.infinity,
                padding: const EdgeInsets.all(16),
                decoration: BoxDecoration(
                  color: GoLivreurTheme.primaire.withValues(alpha: 0.08),
                  borderRadius: BorderRadius.circular(16),
                  border: Border.all(color: GoLivreurTheme.primaire.withValues(alpha: 0.4)),
                ),
                child: Column(
                  children: [
                    const Row(
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        Icon(Icons.lock_outline, color: Color(0xFFC47B00), size: 20),
                        SizedBox(width: 6),
                        Text(
                          'Code de sécurité Expéditeur',
                          style: TextStyle(
                            fontSize: 14,
                            fontWeight: FontWeight.bold,
                            color: Color(0xFF835500),
                          ),
                        ),
                      ],
                    ),
                    const SizedBox(height: 6),
                    const Text(
                      'Donnez ce code de 4 chiffres au livreur lorsqu\'il vient récupérer votre colis :',
                      textAlign: TextAlign.center,
                      style: TextStyle(fontSize: 12, color: GoLivreurTheme.texteSecondaire),
                    ),
                    const SizedBox(height: 12),
                    Builder(
                      builder: (_) {
                        final rawCode = senderCodeAsync.valueOrNull;
                        final digits = (rawCode != null && rawCode.length == 4)
                            ? rawCode.split('')
                            : const ['•', '•', '•', '•'];
                        return Row(
                          mainAxisAlignment: MainAxisAlignment.center,
                          children: digits.map((c) {
                            return Container(
                              margin: const EdgeInsets.symmetric(horizontal: 6),
                              width: 48,
                              height: 56,
                              decoration: BoxDecoration(
                                color: Colors.white,
                                borderRadius: BorderRadius.circular(10),
                                border: Border.all(
                                    color: GoLivreurTheme.primaire, width: 2),
                              ),
                              alignment: Alignment.center,
                              child: Text(
                                c,
                                style: const TextStyle(
                                  fontSize: 24,
                                  fontWeight: FontWeight.bold,
                                  color: GoLivreurTheme.textePrincipal,
                                ),
                              ),
                            );
                          }).toList(),
                        );
                      },
                    ),
                  ],
                ),
              ),
              const SizedBox(height: 16),

              // ─── Photo du colis (expéditeur ou preuve de ramassage) ───────
              Container(
                width: double.infinity,
                padding: const EdgeInsets.all(16),
                decoration: BoxDecoration(
                  color: Colors.white,
                  borderRadius: BorderRadius.circular(16),
                  border: Border.all(color: GoLivreurTheme.bordure),
                ),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        const Icon(Icons.photo_camera,
                            color: GoLivreurTheme.secondaire, size: 20),
                        const SizedBox(width: 8),
                        Text(
                          parcel.photoRamassage != null
                              ? 'Preuve de ramassage (Colis scellé)'
                              : (parcel.photoColis != null
                                  ? 'Photo du colis jointe'
                                  : 'Preuve de ramassage (Colis scellé)'),
                          style: const TextStyle(
                              fontSize: 14, fontWeight: FontWeight.bold),
                        ),
                      ],
                    ),
                    const SizedBox(height: 10),
                    if ((parcel.photoRamassage ?? parcel.photoColis) != null)
                      ClipRRect(
                        borderRadius: BorderRadius.circular(10),
                        child: Image.network(
                          (parcel.photoRamassage ?? parcel.photoColis)!.photoUrl,
                          height: 160,
                          width: double.infinity,
                          fit: BoxFit.cover,
                          errorBuilder: (_, __, ___) => _buildPlaceholderPhoto(),
                        ),
                      )
                    else
                      _buildPlaceholderPhoto(),
                  ],
                ),
              ),
              const SizedBox(height: 16),

              // ─── Détails Expédition ───────────────────────────────────────
              Container(
                width: double.infinity,
                padding: const EdgeInsets.all(16),
                decoration: BoxDecoration(
                  color: Colors.white,
                  borderRadius: BorderRadius.circular(16),
                  border: Border.all(color: GoLivreurTheme.bordure),
                ),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Text('Détails de l\'expédition', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 15)),
                    const Divider(height: 20),
                    _buildRow('Destinataire', parcel.recipientName),
                    const SizedBox(height: 8),
                    _buildRow('Téléphone', '+225 ${parcel.recipientPhone}'),
                    const SizedBox(height: 8),
                    _buildRow('Adresse de livraison', parcel.deliveryAddress),
                    const SizedBox(height: 8),
                    _buildRow('Format', parcel.sizeLabel),
                    const SizedBox(height: 8),
                    _buildRow('Tarif payé', '${parcel.shippingFee.toInt()} F CFA (${parcel.paymentMethod.toUpperCase()})'),
                  ],
                ),
              ),
              const SizedBox(height: 24),
            ],
          ),
        ),
        loading: () => const GoLivreurSpinner(message: 'Chargement des détails du colis...'),
        error: (err, _) => GoLivreurErrorView.fromException(
          error: err,
          onRetry: () => ref.invalidate(singleParcelProvider(parcelId)),
        ),
      ),
    );
  }

  Widget _buildRow(String label, String value) {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      mainAxisAlignment: MainAxisAlignment.spaceBetween,
      children: [
        Text(label, style: const TextStyle(color: GoLivreurTheme.texteSecondaire, fontSize: 13)),
        const SizedBox(width: 10),
        Flexible(
          child: Text(
            value,
            textAlign: TextAlign.end,
            style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13),
          ),
        ),
      ],
    );
  }

  Widget _buildPlaceholderPhoto() {
    return Container(
      height: 120,
      width: double.infinity,
      decoration: BoxDecoration(
        color: Colors.grey.shade100,
        borderRadius: BorderRadius.circular(10),
        border: Border.all(color: Colors.grey.shade300, style: BorderStyle.solid),
      ),
      child: const Column(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          Icon(Icons.inventory, size: 36, color: Colors.grey),
          SizedBox(height: 6),
          Text(
            'Photo obligatoire prise par le coursier à la réception',
            style: TextStyle(fontSize: 12, color: Colors.grey),
          ),
        ],
      ),
    );
  }
}
