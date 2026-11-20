import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:golivreur_core/config/app_theme.dart';
import 'package:golivreur_core/models/modeles.dart';
import 'package:golivreur_core/widgets/golivreur_widgets.dart';
import '../providers/goshop_provider.dart';
import '../providers/order_provider.dart';
import '../providers/gps_tracking_provider.dart';

/// Écran de suivi GPS en temps réel — GL Client (100 % gratuit via OpenStreetMap).
///
/// Affiche :
///   - Carte OpenStreetMap interactive avec marqueur du livreur en mouvement,
///     destination et tracé en direct
///   - Statut de la commande + infos livreur
///   - Code de confirmation à 4 chiffres (à donner au livreur)
class SuiviGPSScreen extends ConsumerStatefulWidget {
  final int orderId;

  const SuiviGPSScreen({super.key, required this.orderId});

  @override
  ConsumerState<SuiviGPSScreen> createState() => _SuiviGPSScreenState();
}

class _SuiviGPSScreenState extends ConsumerState<SuiviGPSScreen> {
  Timer? _timerTimeout;
  bool _afficherBanniereTimeout = false;

  @override
  void initState() {
    super.initState();
    _timerTimeout = Timer(
      const Duration(seconds: 8),
      () {
        if (mounted) {
          final currentLoc =
              ref.read(gpsTrackingProvider(widget.orderId)).valueOrNull;
          if (currentLoc == null) {
            setState(() => _afficherBanniereTimeout = true);
          }
        }
      },
    );
  }

  @override
  void dispose() {
    _timerTimeout?.cancel();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final gpsAsync = ref.watch(gpsTrackingProvider(widget.orderId));
    final orderAsync = ref.watch(orderDetailProvider(widget.orderId));
    final weather = ref.watch(weatherProvider);
    final courierLoc = gpsAsync.valueOrNull;

    ref.listen<AsyncValue<CourierLocationPoint?>>(
      gpsTrackingProvider(widget.orderId),
      (_, next) {
        if (next.valueOrNull != null && _afficherBanniereTimeout && mounted) {
          setState(() => _afficherBanniereTimeout = false);
        }
      },
    );

    return Scaffold(
      body: orderAsync.when(
        loading: () => const EtatChargement(nombreLignes: 3),
        error: (err, _) => EtatErreur(
          message: 'Impossible de charger le suivi.',
          onReessayer: () => ref.invalidate(orderDetailProvider(widget.orderId)),
        ),
        data: (order) => Stack(
          children: [
            // ── Carte OpenStreetMap gratuite en direct ───────────────────────
            Positioned.fill(
              bottom: 220,
              child: OsmLiveTrackingMap(
                courierLat: courierLoc?.lat,
                courierLng: courierLoc?.lng,
                destinationLat: order.deliveryLat,
                destinationLng: order.deliveryLng,
                fallbackLat: weather.centerLat ?? 6.8770,
                fallbackLng: weather.centerLng ?? -6.4502,
              ),
            ),

            // ── AppBar superposée ────────────────────────────────────────────
            SafeArea(
              child: Padding(
                padding: const EdgeInsets.all(GoLivreurTheme.espacementM),
                child: Row(
                  children: [
                    CircleAvatar(
                      backgroundColor: Colors.white,
                      child: IconButton(
                        icon: const Icon(Icons.arrow_back,
                            color: GoLivreurTheme.textePrincipal),
                        onPressed: () => Navigator.of(context).pop(),
                      ),
                    ),
                    const SizedBox(width: 12),
                    Expanded(
                      child: Container(
                        padding: const EdgeInsets.symmetric(
                          horizontal: GoLivreurTheme.espacementM,
                          vertical: GoLivreurTheme.espacementS,
                        ),
                        decoration: BoxDecoration(
                          color: Colors.white,
                          borderRadius:
                              BorderRadius.circular(GoLivreurTheme.rayonL),
                          boxShadow: const [
                            BoxShadow(blurRadius: 8, color: Colors.black12),
                          ],
                        ),
                        child: Row(
                          children: [
                            Expanded(
                              child: Text(
                                order.labelStatut,
                                style: const TextStyle(
                                  fontFamily: 'Inter',
                                  fontWeight: FontWeight.w600,
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
                                  borderRadius: BorderRadius.circular(12),
                                ),
                                child: const Row(
                                  mainAxisSize: MainAxisSize.min,
                                  children: [
                                    Icon(Icons.gps_fixed,
                                        size: 12,
                                        color: GoLivreurTheme.secondaire),
                                    SizedBox(width: 4),
                                    Text(
                                      'En direct',
                                      style: TextStyle(
                                        fontSize: 11,
                                        fontWeight: FontWeight.w700,
                                        color: GoLivreurTheme.secondaire,
                                      ),
                                    ),
                                  ],
                                ),
                              ),
                          ],
                        ),
                      ),
                    ),
                    const SizedBox(width: 8),
                    CircleAvatar(
                      backgroundColor: Colors.white,
                      child: IconButton(
                        icon: const Icon(Icons.refresh,
                            color: GoLivreurTheme.textePrincipal),
                        onPressed: () {
                          ref.invalidate(orderDetailProvider(widget.orderId));
                          ref.invalidate(gpsTrackingProvider(widget.orderId));
                        },
                      ),
                    ),
                  ],
                ),
              ),
            ),

            // ── Bannière attente signal GPS ──────────────────────────────────
            if (_afficherBanniereTimeout && courierLoc == null)
              Positioned(
                top: MediaQuery.of(context).padding.top + 76,
                left: 16,
                right: 16,
                child: Material(
                  color: Colors.white,
                  borderRadius: BorderRadius.circular(12),
                  elevation: 3,
                  child: Padding(
                    padding: const EdgeInsets.symmetric(
                        horizontal: 12, vertical: 10),
                    child: Row(
                      children: [
                        const Icon(Icons.gps_not_fixed,
                            color: GoLivreurTheme.primaire, size: 18),
                        const SizedBox(width: 8),
                        const Expanded(
                          child: Text(
                            'En attente du signal GPS du livreur...',
                            style: TextStyle(fontSize: 12),
                          ),
                        ),
                        IconButton(
                          icon: const Icon(Icons.close, size: 16),
                          onPressed: () =>
                              setState(() => _afficherBanniereTimeout = false),
                        ),
                      ],
                    ),
                  ),
                ),
              ),

            // ── Panneau bas — infos livreur + code confirmation ───────────────
            Positioned(
              bottom: 0,
              left: 0,
              right: 0,
              child: _PanneauInfosLivraison(
                order: order,
                isRainMode: weather.isRainMode,
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _PanneauInfosLivraison extends StatelessWidget {
  final OrderModel order;
  final bool isRainMode;

  const _PanneauInfosLivraison({
    required this.order,
    required this.isRainMode,
  });

  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: const BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.vertical(
          top: Radius.circular(GoLivreurTheme.rayonXL),
        ),
        boxShadow: [BoxShadow(blurRadius: 20, color: Colors.black12)],
      ),
      padding: const EdgeInsets.all(GoLivreurTheme.espacementL),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Container(
            width: 40,
            height: 4,
            decoration: BoxDecoration(
              color: GoLivreurTheme.bordure,
              borderRadius: BorderRadius.circular(2),
            ),
          ),
          const SizedBox(height: GoLivreurTheme.espacementM),
          if (order.courier == null) ...[
            Row(
              children: [
                CircleAvatar(
                  radius: 24,
                  backgroundColor: GoLivreurTheme.primaire.withValues(alpha: 0.14),
                  child: const Icon(Icons.storefront,
                      color: GoLivreurTheme.primaire, size: 24),
                ),
                const SizedBox(width: GoLivreurTheme.espacementM),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        order.merchant?.name ?? 'Commande #${order.id}',
                        style: const TextStyle(
                          fontFamily: 'Inter',
                          fontWeight: FontWeight.w700,
                          fontSize: 15,
                        ),
                      ),
                      const SizedBox(height: 2),
                      Text(
                        '${order.items.length} article(s) • ${order.totalClient.toInt()} F CFA',
                        style: const TextStyle(
                          fontFamily: 'Inter',
                          fontSize: 13,
                          color: GoLivreurTheme.texteSecondaire,
                        ),
                      ),
                    ],
                  ),
                ),
              ],
            ),
            const SizedBox(height: GoLivreurTheme.espacementM),
          ],
          if (order.courier != null) ...[
            Row(
              children: [
                CircleAvatar(
                  radius: 28,
                  backgroundColor: GoLivreurTheme.primaire.withValues(alpha: 0.1),
                  backgroundImage: order.courier!.photoUrl != null
                      ? NetworkImage(order.courier!.photoUrl!)
                      : null,
                  child: order.courier!.photoUrl == null
                      ? const Icon(Icons.person,
                          color: GoLivreurTheme.primaire, size: 28)
                      : null,
                ),
                const SizedBox(width: GoLivreurTheme.espacementM),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        order.courier!.name,
                        style: const TextStyle(
                          fontFamily: 'Inter',
                          fontWeight: FontWeight.w600,
                          fontSize: 16,
                        ),
                      ),
                      Row(
                        children: [
                          const Icon(Icons.star,
                              color: GoLivreurTheme.primaire, size: 14),
                          const SizedBox(width: 4),
                          Text(
                            order.courier!.averageRating.toStringAsFixed(1),
                            style: const TextStyle(
                              fontFamily: 'Inter',
                              fontSize: 13,
                              color: GoLivreurTheme.texteSecondaire,
                            ),
                          ),
                        ],
                      ),
                    ],
                  ),
                ),
                CircleAvatar(
                  backgroundColor:
                      GoLivreurTheme.secondaire.withValues(alpha: 0.1),
                  child: const Icon(Icons.phone, color: GoLivreurTheme.secondaire),
                ),
              ],
            ),
            const Divider(height: GoLivreurTheme.espacementXL),
          ],
          if (isRainMode)
            Container(
              margin: const EdgeInsets.only(bottom: GoLivreurTheme.espacementM),
              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
              decoration: BoxDecoration(
                color: const Color(0xFFE8F1F8),
                borderRadius: BorderRadius.circular(10),
                border: Border.all(
                    color: const Color(0xFF2E75B6).withValues(alpha: 0.3)),
              ),
              child: const Row(
                children: [
                  Icon(Icons.shield_outlined,
                      color: Color(0xFF2E75B6), size: 18),
                  SizedBox(width: 8),
                  Expanded(
                    child: Text(
                      '🌧️ Mode Pluie actif : Vitesse réduite pour la sécurité du livreur. Votre commande est protégée au sec !',
                      style: TextStyle(
                        fontSize: 11,
                        color: Color(0xFF1B4D7E),
                        fontWeight: FontWeight.w600,
                      ),
                    ),
                  ),
                ],
              ),
            ),
          if (order.confirmationCode != null && !order.confirmationLocked) ...[
            const Text(
              'Code à donner au livreur',
              style: TextStyle(
                fontFamily: 'Inter',
                fontSize: 13,
                color: GoLivreurTheme.texteSecondaire,
              ),
            ),
            const SizedBox(height: GoLivreurTheme.espacementS),
            Row(
              mainAxisAlignment: MainAxisAlignment.center,
              children: order.confirmationCode!.split('').map((chiffre) {
                return Container(
                  margin: const EdgeInsets.symmetric(horizontal: 6),
                  width: 56,
                  height: 64,
                  decoration: BoxDecoration(
                    color: GoLivreurTheme.primaire.withValues(alpha: 0.08),
                    border:
                        Border.all(color: GoLivreurTheme.primaire, width: 2),
                    borderRadius: BorderRadius.circular(GoLivreurTheme.rayonM),
                  ),
                  child: Center(
                    child: Text(
                      chiffre,
                      style: const TextStyle(
                        fontFamily: 'Inter',
                        fontSize: 28,
                        fontWeight: FontWeight.w700,
                        color: GoLivreurTheme.primaire,
                      ),
                    ),
                  ),
                );
              }).toList(),
            ),
            const SizedBox(height: GoLivreurTheme.espacementS),
            const Text(
              '⚠️ Ne donnez ce code qu\'au livreur, en main propre',
              style: TextStyle(
                fontFamily: 'Inter',
                fontSize: 11,
                color: GoLivreurTheme.texteSecondaire,
              ),
              textAlign: TextAlign.center,
            ),
          ],
          if (order.confirmationLocked) ...[
            Container(
              padding: const EdgeInsets.all(GoLivreurTheme.espacementM),
              decoration: BoxDecoration(
                color: GoLivreurTheme.erreur.withValues(alpha: 0.08),
                borderRadius: BorderRadius.circular(GoLivreurTheme.rayonM),
              ),
              child: const Row(
                children: [
                  Icon(Icons.lock, color: GoLivreurTheme.erreur),
                  SizedBox(width: 8),
                  Expanded(
                    child: Text(
                      'Code bloqué — Notre support vous contacte.',
                      style: TextStyle(
                        color: GoLivreurTheme.erreur,
                        fontFamily: 'Inter',
                        fontWeight: FontWeight.w500,
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ],
          const SizedBox(height: GoLivreurTheme.espacementM),
        ],
      ),
    );
  }
}
