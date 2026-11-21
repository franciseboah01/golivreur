import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:golivreur_core/config/app_theme.dart';
import 'package:golivreur_core/services/api_client.dart' hide apiClientProvider;
import 'package:golivreur_core/services/gps_location_service.dart';
import '../../auth/providers/auth_provider.dart';
import '../../profile/screens/client_addresses_screen.dart';
import '../providers/goshop_provider.dart';
import '../providers/order_provider.dart';
import 'suivi_gps_screen.dart';

class CartScreen extends ConsumerStatefulWidget {
  const CartScreen({super.key});

  @override
  ConsumerState<CartScreen> createState() => _CartScreenState();
}

class _CartScreenState extends ConsumerState<CartScreen> {
  final _instructionsController = TextEditingController();
  String _selectedPaymentMethod = 'cash'; // cash par défaut (D-PAIEMENT-1)
  bool _isSubmitting = false;
  bool _locatingGps = false;
  GpsPositionResult? _gpsFailure;
  double? _manualGpsLat;
  double? _manualGpsLng;
  String? _manualGpsAddress;

  @override
  void dispose() {
    _instructionsController.dispose();
    super.dispose();
  }

  Future<void> _detectCartGps() async {
    if (_locatingGps) return;
    setState(() => _locatingGps = true);

    final res = await LocationService.obtenirPositionActuelle(
      withAddressLookup: true,
    );
    if (!mounted) return;

    setState(() {
      _locatingGps = false;
      if (res.isSuccess) {
        _gpsFailure = null;
        _manualGpsLat = res.lat;
        _manualGpsLng = res.lng;
        _manualGpsAddress = res.formattedAddress;
        if (res.neighborhoodHint != null &&
            res.neighborhoodHint!.trim().isNotEmpty) {
          ref.read(selectedNeighborhoodProvider.notifier).state =
              res.neighborhoodHint!.trim();
        }
      } else {
        _gpsFailure = res;
      }
    });

    if (res.isSuccess) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('📍 Position GPS de livraison actualisée !'),
          backgroundColor: GoLivreurTheme.secondaire,
        ),
      );
    } else {
      LocationService.afficherAlerteNonBloquante(
        context,
        result: res,
        onRetry: _detectCartGps,
        onManualFallback: () => _showQuickAddressPicker(context),
      );
      if (mounted) {
        await _showQuickAddressPicker(context);
      }
    }
  }

  Future<void> _submitOrder() async {
    final cart = ref.read(cartProvider);
    if (cart.items.isEmpty || cart.merchant == null) return;

    final destination = ref.read(activeDeliveryDestinationProvider);
    final estimate = ref.read(cartDeliveryEstimateProvider);
    final weather = ref.read(weatherProvider);
    final merchant = cart.merchant!;

    setState(() => _isSubmitting = true);

    double deliveryLat = _manualGpsLat ?? destination.lat;
    double deliveryLng = _manualGpsLng ?? destination.lng;

    // 1. Si les coordonnées du quartier/adresse ne sont pas définies, tenter la position GPS puis le centre-ville / commerce
    if (deliveryLat.abs() < 0.0001 || deliveryLng.abs() < 0.0001) {
      final gps = await LocationService.obtenirPositionSilencieuse();
      if (gps != null &&
          gps.isSuccess &&
          gps.lat != null &&
          gps.lng != null &&
          gps.lat!.abs() > 0.0001 &&
          gps.lng!.abs() > 0.0001) {
        deliveryLat = gps.lat!;
        deliveryLng = gps.lng!;
      } else if (weather.centerLat != null &&
          weather.centerLng != null &&
          weather.centerLat!.abs() > 0.0001 &&
          weather.centerLng!.abs() > 0.0001) {
        deliveryLat = weather.centerLat!;
        deliveryLng = weather.centerLng!;
      } else if (merchant.lat.abs() > 0.0001 && merchant.lng.abs() > 0.0001) {
        deliveryLat = merchant.lat;
        deliveryLng = merchant.lng;
      } else {
        deliveryLat = 6.8770;
        deliveryLng = -6.4502;
      }
    }

    final deliveryAddress = destination.fullAddress.trim().isNotEmpty
        ? destination.fullAddress.trim()
        : (destination.neighborhood.trim().isNotEmpty
            ? destination.neighborhood.trim()
            : (destination.cityName.trim().isNotEmpty
                ? destination.cityName.trim()
                : merchant.address));

    final paymentConfig =
        ref.read(clientPaymentMethodsProvider).valueOrNull ??
            const ClientPaymentMethodsConfig();
    final effectivePaymentMethod =
        paymentConfig.activeModes.contains(_selectedPaymentMethod)
            ? _selectedPaymentMethod
            : paymentConfig.defaultMethod;

    final api = ref.read(apiClientProvider);

    try {
      final payload = {
        'merchant_id': merchant.id,
        'items': cart.items
            .map((item) => {
                  'product_id': item.product.id,
                  'quantity': item.quantity,
                })
            .toList(),
        'delivery_address':
            deliveryAddress.isNotEmpty ? deliveryAddress : 'Centre-ville',
        'delivery_lat': deliveryLat,
        'delivery_lng': deliveryLng,
        if (_instructionsController.text.trim().isNotEmpty)
          'special_instructions': _instructionsController.text.trim(),
        'payment_method': effectivePaymentMethod,
        'distance_km': estimate.distanceKm,
      };

      final response = await api.post('/client/orders', data: payload);
      final donnees = response.data is Map ? response.data['donnees'] : null;
      final createdId = donnees is Map
          ? int.tryParse('${donnees['id'] ?? ''}')
          : null;

      if ((response.statusCode == 201 || response.statusCode == 200) &&
          createdId != null &&
          createdId > 0) {
        if (!mounted) return;
        setState(() => _isSubmitting = false);
        ref.read(cartProvider.notifier).clear();
        ref.invalidate(ordersHistoryProvider);

        Navigator.of(context).pushReplacement(
          MaterialPageRoute(
            builder: (_) => SuiviGPSScreen(orderId: createdId),
          ),
        );
        return;
      }

      if (!mounted) return;
      setState(() => _isSubmitting = false);
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Impossible de valider la commande. Veuillez réessayer.'),
          backgroundColor: GoLivreurTheme.erreur,
        ),
      );
    } on DioException catch (e) {
      if (!mounted) return;
      setState(() => _isSubmitting = false);
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(ApiClient.messageErreur(e)),
          backgroundColor: GoLivreurTheme.erreur,
          duration: const Duration(seconds: 4),
        ),
      );
    } catch (_) {
      if (!mounted) return;
      setState(() => _isSubmitting = false);
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Erreur lors de l\'envoi de la commande. Vérifiez votre connexion.'),
          backgroundColor: GoLivreurTheme.erreur,
        ),
      );
    }
  }

  Future<void> _showQuickAddressPicker(BuildContext context) async {
    final addresses = ref.read(clientAddressesProvider).addresses;
    final quarters = ref.read(daloaNeighborhoodsProvider);
    final currentQuarter = ref.read(selectedNeighborhoodProvider);

    await showModalBottomSheet(
      context: context,
      backgroundColor: Colors.white,
      isScrollControlled: true,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
      builder: (sheetCtx) => SafeArea(
        child: SizedBox(
          height: MediaQuery.of(context).size.height * 0.65,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Padding(
                padding: const EdgeInsets.fromLTRB(20, 16, 12, 8),
                child: Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    const Text(
                      'Adresse & quartier de livraison',
                      style: TextStyle(fontSize: 17, fontWeight: FontWeight.bold),
                    ),
                    TextButton.icon(
                      onPressed: () {
                        Navigator.pop(sheetCtx);
                        Navigator.of(context).push(
                          MaterialPageRoute(builder: (_) => const ClientAddressesScreen()),
                        );
                      },
                      icon: const Icon(Icons.add, size: 18),
                      label: const Text('Gérer'),
                    ),
                  ],
                ),
              ),
              const Divider(height: 1),
              Expanded(
                child: ListView(
                  children: [
                    if (addresses.isNotEmpty) ...[
                      const Padding(
                        padding: EdgeInsets.fromLTRB(16, 12, 16, 4),
                        child: Text(
                          'MES ADRESSES ENREGISTRÉES',
                          style: TextStyle(
                            fontSize: 11,
                            fontWeight: FontWeight.bold,
                            color: GoLivreurTheme.texteSecondaire,
                          ),
                        ),
                      ),
                      ...addresses.map(
                        (addr) => ListTile(
                          leading: Icon(
                            Icons.bookmark,
                            color: addr.isDefault
                                ? GoLivreurTheme.secondaire
                                : GoLivreurTheme.primaire,
                          ),
                          title: Text('${addr.label} — ${addr.neighborhood}'),
                          subtitle: Text(addr.addressLine),
                          trailing: addr.isDefault
                              ? const Icon(Icons.check_circle, color: GoLivreurTheme.secondaire)
                              : null,
                          onTap: () async {
                            await ref.read(clientAddressesProvider.notifier).choisirParDefaut(addr);
                            if (sheetCtx.mounted) Navigator.pop(sheetCtx);
                          },
                        ),
                      ),
                    ],
                    if (quarters.isNotEmpty) ...[
                      const Padding(
                        padding: EdgeInsets.fromLTRB(16, 12, 16, 4),
                        child: Text(
                          'OU CHOISIR UN QUARTIER',
                          style: TextStyle(
                            fontSize: 11,
                            fontWeight: FontWeight.bold,
                            color: GoLivreurTheme.texteSecondaire,
                          ),
                        ),
                      ),
                      ...quarters.map(
                        (q) => ListTile(
                          leading: Icon(
                            Icons.location_on_outlined,
                            color: q == currentQuarter
                                ? GoLivreurTheme.primaire
                                : GoLivreurTheme.texteSecondaire,
                          ),
                          title: Text(q),
                          trailing: q == currentQuarter
                              ? const Icon(Icons.check, color: GoLivreurTheme.primaire)
                              : null,
                          onTap: () {
                            ref.read(selectedNeighborhoodProvider.notifier).state = q;
                            Navigator.pop(sheetCtx);
                          },
                        ),
                      ),
                    ] else if (addresses.isEmpty)
                      const Padding(
                        padding: EdgeInsets.all(20),
                        child: Text(
                          'Aucun quartier disponible pour le moment.',
                          style: TextStyle(color: GoLivreurTheme.texteSecondaire),
                        ),
                      ),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final cart = ref.watch(cartProvider);
    final destination = ref.watch(activeDeliveryDestinationProvider);
    final estimate = ref.watch(cartDeliveryEstimateProvider);
    final weather = ref.watch(weatherProvider);
    final paymentConfig =
        ref.watch(clientPaymentMethodsProvider).valueOrNull ??
            const ClientPaymentMethodsConfig();
    final activeModes = paymentConfig.activeModes;
    if (!activeModes.contains(_selectedPaymentMethod)) {
      _selectedPaymentMethod = paymentConfig.defaultMethod;
    }
    final merchant = cart.merchant;
    final total = cart.subtotal + estimate.deliveryFee;

    if (cart.items.isEmpty) {
      return Scaffold(
        backgroundColor: GoLivreurTheme.fond,
        appBar: AppBar(title: const Text('Mon Panier')),
        body: Center(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              const Icon(Icons.remove_shopping_cart_outlined, size: 64, color: GoLivreurTheme.texteSecondaire),
              const SizedBox(height: 16),
              const Text(
                'Votre panier est vide',
                style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold, color: GoLivreurTheme.textePrincipal),
              ),
              const SizedBox(height: 8),
              const Text(
                'Ajoutez des plats ou articles de vos commerces locaux !',
                style: TextStyle(color: GoLivreurTheme.texteSecondaire),
              ),
              const SizedBox(height: 24),
              ElevatedButton(
                style: ElevatedButton.styleFrom(
                  backgroundColor: GoLivreurTheme.primaire,
                  minimumSize: const Size(200, 48),
                ),
                onPressed: () => Navigator.pop(context),
                child: const Text('Découvrir les commerces'),
              ),
            ],
          ),
        ),
      );
    }

    return Scaffold(
      backgroundColor: GoLivreurTheme.fond,
      appBar: AppBar(
        title: Text(merchant != null ? 'Panier chez ${merchant.name}' : 'Mon Panier'),
        actions: [
          TextButton(
            onPressed: () {
              ref.read(cartProvider.notifier).clear();
            },
            child: const Text('Vider', style: TextStyle(color: GoLivreurTheme.erreur)),
          ),
        ],
      ),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            // ─── Articles commandés ─────────────────────────────────────────
            Container(
              decoration: BoxDecoration(
                color: Colors.white,
                borderRadius: BorderRadius.circular(16),
                border: Border.all(color: GoLivreurTheme.bordure),
              ),
              child: ListView.separated(
                shrinkWrap: true,
                physics: const NeverScrollableScrollPhysics(),
                itemCount: cart.items.length,
                separatorBuilder: (_, __) => const Divider(height: 1),
                itemBuilder: (context, index) {
                  final item = cart.items[index];
                  return Padding(
                    padding: const EdgeInsets.all(12),
                    child: Row(
                      children: [
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(
                                item.product.name,
                                style: const TextStyle(
                                  fontWeight: FontWeight.bold,
                                  fontSize: 14,
                                  color: GoLivreurTheme.textePrincipal,
                                ),
                              ),
                              const SizedBox(height: 4),
                              Text(
                                '${item.product.price.toInt()} F CFA',
                                style: const TextStyle(
                                  color: GoLivreurTheme.primaire,
                                  fontWeight: FontWeight.w600,
                                ),
                              ),
                            ],
                          ),
                        ),
                        // Contrôles quantité
                        Row(
                          children: [
                            IconButton(
                              icon: const Icon(Icons.remove_circle_outline, size: 22, color: GoLivreurTheme.texteSecondaire),
                              onPressed: () {
                                ref.read(cartProvider.notifier).updateQuantity(item.product.id, item.quantity - 1);
                              },
                            ),
                            Text(
                              '${item.quantity}',
                              style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold),
                            ),
                            IconButton(
                              icon: const Icon(Icons.add_circle, size: 22, color: GoLivreurTheme.primaire),
                              onPressed: () {
                                ref.read(cartProvider.notifier).updateQuantity(item.product.id, item.quantity + 1);
                              },
                            ),
                          ],
                        ),
                      ],
                    ),
                  );
                },
              ),
            ),
            const SizedBox(height: 20),

            // ─── Adresse & Quartier de livraison (+ GPS & Plan B) ──────────
            const Text(
              'Adresse de livraison',
              style: TextStyle(
                fontSize: 16,
                fontWeight: FontWeight.bold,
                color: GoLivreurTheme.textePrincipal,
              ),
            ),
            const SizedBox(height: 8),
            Container(
              padding: const EdgeInsets.all(14),
              decoration: BoxDecoration(
                color: Colors.white,
                borderRadius: BorderRadius.circular(14),
                border: Border.all(color: GoLivreurTheme.bordure),
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Row(
                    children: [
                      const Icon(Icons.location_on,
                          color: GoLivreurTheme.primaire),
                      const SizedBox(width: 10),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              destination.neighborhood.isNotEmpty
                                  ? destination.neighborhood
                                  : 'Quartier de livraison',
                              style: const TextStyle(
                                fontWeight: FontWeight.bold,
                                fontSize: 14,
                              ),
                            ),
                            const SizedBox(height: 2),
                            Text(
                              _manualGpsAddress?.trim().isNotEmpty == true
                                  ? _manualGpsAddress!.trim()
                                  : destination.fullAddress,
                              style: const TextStyle(
                                fontSize: 12,
                                color: GoLivreurTheme.texteSecondaire,
                              ),
                            ),
                            const SizedBox(height: 2),
                            Text(
                              'Distance estimée : ${estimate.distanceKm.toStringAsFixed(1)} km${weather.isRainMode ? " • 🌧️ Mode Pluie" : ""}',
                              style: const TextStyle(
                                fontSize: 11,
                                color: GoLivreurTheme.secondaire,
                                fontWeight: FontWeight.w600,
                              ),
                            ),
                          ],
                        ),
                      ),
                      TextButton.icon(
                        onPressed: _locatingGps ? null : _detectCartGps,
                        icon: _locatingGps
                            ? const SizedBox(
                                width: 14,
                                height: 14,
                                child:
                                    CircularProgressIndicator(strokeWidth: 2),
                              )
                            : const Icon(Icons.my_location, size: 16),
                        label: const Text('GPS'),
                      ),
                      TextButton(
                        onPressed: () => _showQuickAddressPicker(context),
                        child: const Text(
                          'Changer',
                          style: TextStyle(fontWeight: FontWeight.bold),
                        ),
                      ),
                    ],
                  ),
                  if (_gpsFailure != null) ...[
                    const SizedBox(height: 10),
                    GpsFallbackBanner(
                      lastResult: _gpsFailure,
                      isLoading: _locatingGps,
                      onRetry: _detectCartGps,
                      onSelectNeighborhood: () =>
                          _showQuickAddressPicker(context),
                      manualActionLabel: 'Choisir un quartier (Plan B)',
                      selectedManualLabel: destination.neighborhood.isNotEmpty
                          ? destination.neighborhood
                          : null,
                    ),
                  ],
                ],
              ),
            ),
            const SizedBox(height: 20),

            // ─── Repères & Instructions de livraison ────────────────────────
            const Text(
              'Instructions de livraison (Repères)',
              style: TextStyle(
                fontSize: 16,
                fontWeight: FontWeight.bold,
                color: GoLivreurTheme.textePrincipal,
              ),
            ),
            const SizedBox(height: 8),
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
              decoration: BoxDecoration(
                color: Colors.white,
                borderRadius: BorderRadius.circular(12),
                border: Border.all(color: GoLivreurTheme.bordure),
              ),
              child: TextField(
                controller: _instructionsController,
                maxLines: 2,
                decoration: const InputDecoration(
                  border: InputBorder.none,
                  enabledBorder: InputBorder.none,
                  focusedBorder: InputBorder.none,
                  filled: false,
                  contentPadding: EdgeInsets.zero,
                  hintText: 'Ex: En face du maquis Le Baoulé, portail bleu, appeler dès arrivée',
                  hintStyle: TextStyle(fontSize: 13, color: GoLivreurTheme.texteSecondaire),
                ),
              ),
            ),
            const SizedBox(height: 20),

            // ─── Mode de Paiement (synchronisé avec paiements.modes_actifs) ─
            const Text(
              'Moyen de paiement',
              style: TextStyle(
                fontSize: 16,
                fontWeight: FontWeight.bold,
                color: GoLivreurTheme.textePrincipal,
              ),
            ),
            const SizedBox(height: 10),
            if (activeModes.contains('cash')) ...[
              _buildPaymentOption(
                id: 'cash',
                title: 'Espèces à la livraison',
                subtitle: 'Payer directement au livreur à la réception',
                icon: Icons.payments_outlined,
                iconColor: GoLivreurTheme.secondaire,
              ),
              const SizedBox(height: 8),
            ],
            if (activeModes.contains('wave')) ...[
              _buildPaymentOption(
                id: 'wave',
                title: 'Wave Mobile Money',
                subtitle: 'Paiement instantané par QR ou code',
                icon: Icons.waves,
                iconColor: const Color(0xFF1EA8E7),
              ),
              const SizedBox(height: 8),
            ],
            if (activeModes.contains('orange_money')) ...[
              _buildPaymentOption(
                id: 'orange_money',
                title: 'Orange Money Côte d\'Ivoire',
                subtitle: 'Validation par code secret #144#',
                icon: Icons.phone_android,
                iconColor: const Color(0xFFFF6600),
              ),
              const SizedBox(height: 8),
            ],
            if (activeModes.contains('mtn_momo')) ...[
              _buildPaymentOption(
                id: 'mtn_momo',
                title: 'MTN Mobile Money',
                subtitle: 'Validation par push MoMo *133#',
                icon: Icons.flash_on,
                iconColor: const Color(0xFFFFCC00),
              ),
              const SizedBox(height: 8),
            ],
            if (activeModes.contains('moov')) ...[
              _buildPaymentOption(
                id: 'moov',
                title: 'Moov Money (Flooz)',
                subtitle: 'Paiement sécurisé via code Flooz',
                icon: Icons.account_balance_wallet,
                iconColor: const Color(0xFF005CA9),
              ),
              const SizedBox(height: 8),
            ],
            const SizedBox(height: 16),

            // ─── Récapitulatif Coûts ───────────────────────────────────────
            Container(
              padding: const EdgeInsets.all(16),
              decoration: BoxDecoration(
                color: Colors.white,
                borderRadius: BorderRadius.circular(16),
                border: Border.all(color: GoLivreurTheme.bordure),
              ),
              child: Column(
                children: [
                  Row(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      const Text('Sous-total produits', style: TextStyle(color: GoLivreurTheme.texteSecondaire)),
                      Text('${cart.subtotal.toInt()} F CFA', style: const TextStyle(fontWeight: FontWeight.w600)),
                    ],
                  ),
                  const SizedBox(height: 8),
                  Row(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      Text(
                        'Frais de livraison (${destination.neighborhood})',
                        style: const TextStyle(color: GoLivreurTheme.texteSecondaire),
                      ),
                      Text(
                        '${estimate.deliveryFee.toInt()} F CFA',
                        style: const TextStyle(fontWeight: FontWeight.w600),
                      ),
                    ],
                  ),
                  const Padding(
                    padding: EdgeInsets.symmetric(vertical: 10),
                    child: Divider(),
                  ),
                  Row(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      const Text(
                        'Total à payer',
                        style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold, color: GoLivreurTheme.textePrincipal),
                      ),
                      Text(
                        '${total.toInt()} F CFA',
                        style: const TextStyle(
                          fontSize: 18,
                          fontWeight: FontWeight.bold,
                          color: GoLivreurTheme.primaire,
                        ),
                      ),
                    ],
                  ),
                ],
              ),
            ),
            const SizedBox(height: 24),

            // ─── Bouton Validation Commande (52px Stitch) ───────────────────
            SizedBox(
              width: double.infinity,
              height: 52, // Zone tactile min 52px
              child: ElevatedButton(
                style: ElevatedButton.styleFrom(
                  backgroundColor: GoLivreurTheme.primaire,
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                  elevation: 2,
                ),
                onPressed: _isSubmitting ? null : _submitOrder,
                child: _isSubmitting
                    ? const SizedBox(
                        width: 24,
                        height: 24,
                        child: CircularProgressIndicator(color: Colors.white, strokeWidth: 2.5),
                      )
                    : Text(
                        'Confirmer la commande • ${total.toInt()} F CFA',
                        style: const TextStyle(
                          fontSize: 16,
                          fontWeight: FontWeight.bold,
                          color: Colors.white,
                        ),
                      ),
              ),
            ),
            const SizedBox(height: 24),
          ],
        ),
      ),
    );
  }

  Widget _buildPaymentOption({
    required String id,
    required String title,
    required String subtitle,
    required IconData icon,
    required Color iconColor,
  }) {
    final isSelected = _selectedPaymentMethod == id;

    return InkWell(
      onTap: () {
        setState(() => _selectedPaymentMethod = id);
      },
      borderRadius: BorderRadius.circular(12),
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(12),
          border: Border.all(
            color: isSelected ? GoLivreurTheme.primaire : GoLivreurTheme.bordure,
            width: isSelected ? 2 : 1,
          ),
        ),
        child: Row(
          children: [
            Container(
              width: 38,
              height: 38,
              decoration: BoxDecoration(
                color: iconColor.withValues(alpha: 0.15),
                borderRadius: BorderRadius.circular(8),
              ),
              child: Icon(icon, color: iconColor, size: 22),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    title,
                    style: TextStyle(
                      fontSize: 14,
                      fontWeight: isSelected ? FontWeight.bold : FontWeight.w500,
                      color: GoLivreurTheme.textePrincipal,
                    ),
                  ),
                  Text(
                    subtitle,
                    style: const TextStyle(fontSize: 12, color: GoLivreurTheme.texteSecondaire),
                  ),
                ],
              ),
            ),
            Icon(
              isSelected ? Icons.radio_button_checked : Icons.radio_button_off,
              color: isSelected ? GoLivreurTheme.primaire : GoLivreurTheme.bordure,
            ),
          ],
        ),
      ),
    );
  }
}
