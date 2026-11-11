import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:golivreur_core/config/app_theme.dart';
import '../../auth/providers/auth_provider.dart';
import '../../profile/screens/client_addresses_screen.dart';
import '../providers/goshop_provider.dart';
import 'suivi_gps_screen.dart';

class CartScreen extends ConsumerStatefulWidget {
  const CartScreen({super.key});

  @override
  ConsumerState<CartScreen> createState() => _CartScreenState();
}

class _CartScreenState extends ConsumerState<CartScreen> {
  final _instructionsController = TextEditingController(
    text: 'Face au maquis, portail bleu, m\'appeler à l\'arrivée.',
  );
  String _selectedPaymentMethod = 'wave'; // wave, orange_money, mtn, moov, cash
  bool _isSubmitting = false;

  @override
  void dispose() {
    _instructionsController.dispose();
    super.dispose();
  }

  void _submitOrder() async {
    final cart = ref.read(cartProvider);
    if (cart.items.isEmpty || cart.merchant == null) return;

    final destination = ref.read(activeDeliveryDestinationProvider);
    final estimate = ref.read(cartDeliveryEstimateProvider);

    setState(() => _isSubmitting = true);

    final api = ref.read(apiClientProvider);
    int orderId = 1;

    try {
      final payload = {
        'merchant_id': cart.merchant!.id,
        'items': cart.items
            .map((item) => {
                  'product_id': item.product.id,
                  'quantity': item.quantity,
                })
            .toList(),
        'delivery_address': destination.fullAddress,
        'delivery_lat': destination.lat,
        'delivery_lng': destination.lng,
        'special_instructions': _instructionsController.text.trim(),
        'payment_method': _selectedPaymentMethod,
        'distance_km': estimate.distanceKm,
      };

      final response = await api.post('/client/orders', data: payload);
      if (response.statusCode == 201 && response.data['donnees'] != null) {
        orderId = response.data['donnees']['id'] as int;
      }
    } catch (_) {
      // Fallback hors-ligne si le serveur est injoignable
      orderId = 1;
    } finally {
      if (mounted) {
        setState(() => _isSubmitting = false);
        ref.read(cartProvider.notifier).clear();

        Navigator.of(context).pushReplacement(
          MaterialPageRoute(
            builder: (_) => SuiviGPSScreen(orderId: orderId),
          ),
        );
      }
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
                'Ajoutez des bons plats ou articles de Daloa !',
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

            // ─── Adresse & Quartier de livraison ───────────────────────────
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
              child: Row(
                children: [
                  const Icon(Icons.location_on, color: GoLivreurTheme.primaire),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          destination.neighborhood,
                          style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14),
                        ),
                        const SizedBox(height: 2),
                        Text(
                          destination.fullAddress,
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
                  TextButton(
                    onPressed: () => _showQuickAddressPicker(context),
                    child: const Text('Changer', style: TextStyle(fontWeight: FontWeight.bold)),
                  ),
                ],
              ),
            ),
            const SizedBox(height: 20),

            // ─── Repères & Instructions de livraison à Daloa ────────────────
            const Text(
              'Instructions de livraison (Repères à Daloa)',
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

            // ─── Mode de Paiement (5 options ivoiriennes validées) ──────────
            const Text(
              'Moyen de paiement',
              style: TextStyle(
                fontSize: 16,
                fontWeight: FontWeight.bold,
                color: GoLivreurTheme.textePrincipal,
              ),
            ),
            const SizedBox(height: 10),
            _buildPaymentOption(
              id: 'wave',
              title: 'Wave (1% de frais)',
              subtitle: 'Paiement instantané par QR ou code',
              icon: Icons.waves,
              iconColor: const Color(0xFF1EA8E7),
            ),
            const SizedBox(height: 8),
            _buildPaymentOption(
              id: 'orange_money',
              title: 'Orange Money Côte d\'Ivoire',
              subtitle: 'Validation par code secret #144#',
              icon: Icons.phone_android,
              iconColor: const Color(0xFFFF6600),
            ),
            const SizedBox(height: 8),
            _buildPaymentOption(
              id: 'mtn',
              title: 'MTN Mobile Money',
              subtitle: 'Validation par push MoMo *133#',
              icon: Icons.flash_on,
              iconColor: const Color(0xFFFFCC00),
            ),
            const SizedBox(height: 8),
            _buildPaymentOption(
              id: 'moov',
              title: 'Moov Money (Flooz)',
              subtitle: 'Paiement sécurisé via code Flooz',
              icon: Icons.account_balance_wallet,
              iconColor: const Color(0xFF005CA9),
            ),
            const SizedBox(height: 8),
            _buildPaymentOption(
              id: 'cash',
              title: 'Espèces à la livraison',
              subtitle: 'Payer directement au livreur à la réception',
              icon: Icons.payments_outlined,
              iconColor: GoLivreurTheme.secondaire,
            ),
            const SizedBox(height: 24),

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
