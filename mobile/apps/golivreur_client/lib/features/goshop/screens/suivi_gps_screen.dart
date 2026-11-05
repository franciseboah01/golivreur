import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:google_maps_flutter/google_maps_flutter.dart';
import 'package:golivreur_core/config/app_theme.dart';
import 'package:golivreur_core/models/modeles.dart';
import 'package:golivreur_core/widgets/atoms/widgets_atomiques.dart';
import '../providers/order_provider.dart';
import '../providers/gps_tracking_provider.dart';

/// Écran de suivi GPS en temps réel — GoShop.
///
/// Affiche :
///   - Carte Google Maps avec marqueur du livreur qui se déplace
///   - Statut de la commande en temps réel (WebSocket Reverb)
///   - Nom et photo du livreur
///   - Code de confirmation à 4 chiffres (à donner au livreur)
///   - Temps estimé d'arrivée
///
/// Le marqueur de la moto se déplace en douceur à chaque mise à jour GPS.
class SuiviGPSScreen extends ConsumerStatefulWidget {
  final int orderId;

  const SuiviGPSScreen({super.key, required this.orderId});

  @override
  ConsumerState<SuiviGPSScreen> createState() => _SuiviGPSScreenState();
}

class _SuiviGPSScreenState extends ConsumerState<SuiviGPSScreen>
    with TickerProviderStateMixin {
  // Contrôleur de la carte Google Maps
  GoogleMapController? _mapController;

  // Ensemble des marqueurs affichés sur la carte
  final Set<Marker> _marqueurs = {};

  // Position actuelle du livreur
  LatLng? _positionLivreur;

  // Animation de déplacement fluide du marqueur
  late AnimationController _animController;
  late Animation<double> _animLat;
  late Animation<double> _animLng;

  // Timer de timeout 8 secondes
  Timer? _timerTimeout;
  bool _afficherBanniereTimeout = false;

  @override
  void initState() {
    super.initState();

    // Animation pour déplacement fluide du marqueur (500ms)
    _animController = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 500),
    );

    // Démarrer le timeout de 8 secondes pour le chargement initial
    _timerTimeout = Timer(
      const Duration(seconds: 8),
      () {
        if (mounted && _positionLivreur == null) {
          setState(() => _afficherBanniereTimeout = true);
        }
      },
    );
  }

  @override
  void dispose() {
    _mapController?.dispose();
    _animController.dispose();
    _timerTimeout?.cancel();
    super.dispose();
  }

  /// Déplace le marqueur du livreur en douceur vers la nouvelle position.
  /// Appelé à chaque mise à jour GPS reçue via WebSocket Reverb.
  void _deplacerMarqueur(LatLng ancienne, LatLng nouvelle) {
    _animLat = Tween<double>(begin: ancienne.latitude, end: nouvelle.latitude)
        .animate(CurvedAnimation(parent: _animController, curve: Curves.easeInOut));
    _animLng = Tween<double>(begin: ancienne.longitude, end: nouvelle.longitude)
        .animate(CurvedAnimation(parent: _animController, curve: Curves.easeInOut));

    _animController.forward(from: 0).then((_) {
      if (mounted) {
        setState(() {
          _positionLivreur = nouvelle;
          _majMarqueurs(nouvelle);
        });
      }
    });

    _animLat.addListener(() {
      if (mounted && _mapController != null) {
        final positionInterp = LatLng(_animLat.value, _animLng.value);
        setState(() => _majMarqueurs(positionInterp));
      }
    });
  }

  void _majMarqueurs(LatLng position) {
    _marqueurs
      ..removeWhere((m) => m.markerId.value == 'livreur')
      ..add(
        Marker(
          markerId: const MarkerId('livreur'),
          position: position,
          icon: BitmapDescriptor.defaultMarkerWithHue(BitmapDescriptor.hueOrange),
          infoWindow: const InfoWindow(title: 'Votre livreur'),
        ),
      );
  }

  @override
  Widget build(BuildContext context) {
    // Écoute les mises à jour en temps réel (statut + position GPS via Reverb)
    final trackingAsync = ref.watch(gpsTrackingProvider(widget.orderId));
    final orderAsync    = ref.watch(orderDetailProvider(widget.orderId));

    // Quand une nouvelle position GPS arrive, animer le déplacement
    ref.listen<AsyncValue<LatLng?>>(
      gpsTrackingProvider(widget.orderId),
      (previous, next) {
        next.whenData((nouvellePosition) {
          if (nouvellePosition != null) {
            // Cacher la bannière timeout si visible
            if (_afficherBanniereTimeout) {
              setState(() => _afficherBanniereTimeout = false);
            }
            final ancienne = _positionLivreur ?? nouvellePosition;
            _deplacerMarqueur(ancienne, nouvellePosition);

            // Centrer la carte sur le livreur
            _mapController?.animateCamera(
              CameraUpdate.newLatLng(nouvellePosition),
            );
          }
        });
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
            // ── Carte Google Maps ────────────────────────────────────────────
            GoogleMap(
              initialCameraPosition: CameraPosition(
                target: order.deliveryLat != null && order.deliveryLng != null
                    ? LatLng(order.deliveryLat!, order.deliveryLng!)
                    : const LatLng(6.8770, -6.4502), // Centre Daloa par défaut
                zoom: 15,
              ),
              markers: _marqueurs,
              myLocationEnabled: true,
              myLocationButtonEnabled: false,
              zoomControlsEnabled: false,
              onMapCreated: (controller) {
                _mapController = controller;
                // Ajouter le marqueur de destination (adresse de livraison)
                if (order.deliveryLat != null && order.deliveryLng != null) {
                  setState(() {
                    _marqueurs.add(
                      Marker(
                        markerId: const MarkerId('destination'),
                        position: LatLng(order.deliveryLat!, order.deliveryLng!),
                        icon: BitmapDescriptor.defaultMarkerWithHue(
                            BitmapDescriptor.hueGreen),
                        infoWindow: const InfoWindow(title: 'Votre adresse'),
                      ),
                    );
                  });
                }
              },
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
                        icon: const Icon(Icons.arrow_back, color: GoLivreurTheme.textePrincipal),
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
                          borderRadius: BorderRadius.circular(GoLivreurTheme.rayonL),
                        ),
                        child: Text(
                          order.labelStatut,
                          style: const TextStyle(
                            fontFamily: 'Inter',
                            fontWeight: FontWeight.w600,
                            fontSize: 14,
                          ),
                        ),
                      ),
                    ),
                  ],
                ),
              ),
            ),

            // ── Bannière connexion lente (timeout 8s) ────────────────────────
            if (_afficherBanniereTimeout)
              Positioned(
                top: MediaQuery.of(context).padding.top + 80,
                left: 0,
                right: 0,
                child: BanniereConnexionLente(
                  onAnnuler: () => setState(() => _afficherBanniereTimeout = false),
                ),
              ),

            // ── Panneau bas — infos livreur + code confirmation ───────────────
            Positioned(
              bottom: 0,
              left: 0,
              right: 0,
              child: _PanneauInfosLivraison(order: order),
            ),
          ],
        ),
      ),
    );
  }
}

/// Panneau d'informations de livraison affiché en bas de l'écran.
class _PanneauInfosLivraison extends StatelessWidget {
  final OrderModel order;

  const _PanneauInfosLivraison({required this.order});

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
          // Indicateur de glissement
          Container(
            width: 40, height: 4,
            decoration: BoxDecoration(
              color: GoLivreurTheme.bordure,
              borderRadius: BorderRadius.circular(2),
            ),
          ),
          const SizedBox(height: GoLivreurTheme.espacementM),

          // Infos livreur
          if (order.courier != null) ...[
            Row(
              children: [
                // Photo du livreur
                CircleAvatar(
                  radius: 28,
                  backgroundColor: GoLivreurTheme.primaire.withOpacity(0.1),
                  backgroundImage: order.courier!.photoUrl != null
                      ? NetworkImage(order.courier!.photoUrl!)
                      : null,
                  child: order.courier!.photoUrl == null
                      ? const Icon(Icons.person, color: GoLivreurTheme.primaire, size: 28)
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
                          const Icon(Icons.star, color: GoLivreurTheme.primaire, size: 14),
                          const SizedBox(width: 4),
                          Text(
                            order.courier!.averageRating.toStringAsFixed(1),
                            style: const TextStyle(
                              fontFamily: 'Inter', fontSize: 13,
                              color: GoLivreurTheme.texteSecondaire,
                            ),
                          ),
                        ],
                      ),
                    ],
                  ),
                ),
                // Bouton appel (placeholder — numéro masqué)
                CircleAvatar(
                  backgroundColor: GoLivreurTheme.secondaire.withOpacity(0.1),
                  child: const Icon(Icons.phone, color: GoLivreurTheme.secondaire),
                ),
              ],
            ),
            const Divider(height: GoLivreurTheme.espacementXL),
          ],

          // Code de confirmation
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
                    color: GoLivreurTheme.primaire.withOpacity(0.08),
                    border: Border.all(color: GoLivreurTheme.primaire, width: 2),
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

          // Code bloqué
          if (order.confirmationLocked) ...[
            Container(
              padding: const EdgeInsets.all(GoLivreurTheme.espacementM),
              decoration: BoxDecoration(
                color: GoLivreurTheme.erreur.withOpacity(0.08),
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
