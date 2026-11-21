import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:golivreur_core/config/app_theme.dart';
import 'package:golivreur_core/models/modeles.dart';
import 'package:golivreur_core/widgets/golivreur_widgets.dart';
import '../../auth/providers/auth_provider.dart';
import '../../goshop/providers/goshop_provider.dart';
import '../../goshop/providers/order_provider.dart';
import '../../goshop/screens/cart_screen.dart';
import '../../goshop/screens/suivi_gps_screen.dart';

class OrdersTrackingHistoryScreen extends ConsumerWidget {
  const OrdersTrackingHistoryScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final ordersAsync = ref.watch(ordersHistoryProvider);

    return Scaffold(
      backgroundColor: GoLivreurTheme.fond,
      appBar: AppBar(
        title: const Text('Mes Commandes & Suivis'),
        backgroundColor: Colors.white,
        elevation: 0.5,
      ),
      body: ordersAsync.when(
        data: (orders) {
          if (orders.isEmpty) {
            return const EtatVide(
              icone: Icons.receipt_long_outlined,
              message: 'Aucune commande enregistrée pour l\'instant.\nCommandez chez vos commerces partenaires !',
            );
          }

          final activeOrders = orders.where((o) => o.estActif).toList();
          final pastOrders = orders.where((o) => !o.estActif).toList();

          return ListView(
            padding: const EdgeInsets.all(16),
            children: [
              if (activeOrders.isNotEmpty) ...[
                const Text(
                  'En cours de livraison 🚴',
                  style: TextStyle(
                    fontSize: 16,
                    fontWeight: FontWeight.bold,
                    color: GoLivreurTheme.textePrincipal,
                  ),
                ),
                const SizedBox(height: 10),
                ...activeOrders.map((order) => _buildActiveOrderCard(context, order)),
                const SizedBox(height: 24),
              ],
              const Text(
                'Historique des commandes passées',
                style: TextStyle(
                  fontSize: 16,
                  fontWeight: FontWeight.bold,
                  color: GoLivreurTheme.textePrincipal,
                ),
              ),
              const SizedBox(height: 10),
              ...pastOrders.map((order) => _buildPastOrderCard(context, ref, order)),
            ],
          );
        },
        loading: () => const GoLivreurListSkeleton(count: 3, itemHeight: 110),
        error: (err, _) => GoLivreurErrorView.fromException(
          error: err,
          onRetry: () => ref.invalidate(ordersHistoryProvider),
        ),
      ),
    );
  }

  Widget _buildActiveOrderCard(BuildContext context, OrderModel order) {
    return Container(
      margin: const EdgeInsets.only(bottom: 12),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: GoLivreurTheme.primaire.withValues(alpha: 0.6), width: 1.5),
        boxShadow: [
          BoxShadow(
            color: GoLivreurTheme.primaire.withValues(alpha: 0.1),
            blurRadius: 8,
            offset: const Offset(0, 3),
          ),
        ],
      ),
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Text(
                'Commande #${order.id}',
                style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16),
              ),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                decoration: BoxDecoration(
                  color: const Color(0xFFE8F1F8),
                  borderRadius: BorderRadius.circular(8),
                ),
                child: Text(
                  order.labelStatut,
                  style: const TextStyle(
                    color: Color(0xFF2E75B6),
                    fontWeight: FontWeight.bold,
                    fontSize: 12,
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 8),
          Text(
            order.merchant?.name ?? 'Commerce GoShop',
            style: const TextStyle(fontSize: 14, color: GoLivreurTheme.textePrincipal),
          ),
          const SizedBox(height: 4),
          Text(
            'Total : ${order.totalClient.toInt()} F CFA • Paiement ${order.paymentMethod.toUpperCase()}',
            style: const TextStyle(fontSize: 13, color: GoLivreurTheme.texteSecondaire),
          ),
          if (order.confirmationCode != null) ...[
            const SizedBox(height: 12),
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
              decoration: BoxDecoration(
                color: GoLivreurTheme.fond,
                borderRadius: BorderRadius.circular(8),
              ),
              child: Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  const Text(
                    'Code à donner au livreur :',
                    style: TextStyle(fontSize: 13, fontWeight: FontWeight.w500),
                  ),
                  Text(
                    order.confirmationCode!,
                    style: const TextStyle(
                      fontSize: 18,
                      fontWeight: FontWeight.bold,
                      letterSpacing: 2,
                      color: GoLivreurTheme.secondaire,
                    ),
                  ),
                ],
              ),
            ),
          ],
          const SizedBox(height: 14),
          SizedBox(
            width: double.infinity,
            height: 48,
            child: ElevatedButton.icon(
              style: ElevatedButton.styleFrom(
                backgroundColor: GoLivreurTheme.secondaire,
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
              ),
              onPressed: () {
                Navigator.of(context).push(
                  MaterialPageRoute(builder: (_) => SuiviGPSScreen(orderId: order.id)),
                );
              },
              icon: const Icon(Icons.navigation, color: Colors.white, size: 20),
              label: const Text('Suivre en direct sur la carte GPS'),
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildPastOrderCard(BuildContext context, WidgetRef ref, OrderModel order) {
    return Container(
      margin: const EdgeInsets.only(bottom: 12),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: GoLivreurTheme.bordure),
      ),
      padding: const EdgeInsets.all(14),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  order.merchant?.name ?? 'Commerce',
                  style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 15),
                ),
                const SizedBox(height: 4),
                Text(
                  '${order.items.length} article(s) • ${order.totalClient.toInt()} F CFA',
                  style: const TextStyle(fontSize: 13, color: GoLivreurTheme.texteSecondaire),
                ),
                const SizedBox(height: 2),
                Text(
                  order.labelStatut,
                  style: const TextStyle(fontSize: 12, color: GoLivreurTheme.secondaire, fontWeight: FontWeight.w600),
                ),
              ],
            ),
          ),
          OutlinedButton(
            style: OutlinedButton.styleFrom(
              minimumSize: const Size(110, 40),
              side: const BorderSide(color: GoLivreurTheme.primaire),
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
            ),
            onPressed: () => _handleReorder(context, ref, order),
            child: const Text('Recommander', style: TextStyle(fontSize: 12)),
          ),
        ],
      ),
    );
  }

  Future<void> _handleReorder(
      BuildContext context, WidgetRef ref, OrderModel order) async {
    final messenger = ScaffoldMessenger.of(context);
    try {
      final api = ref.read(apiClientProvider);
      final res = await api.post('/client/orders/${order.id}/reorder');
      final data = res.data is Map && res.data['donnees'] is Map
          ? Map<String, dynamic>.from(res.data['donnees'] as Map)
          : <String, dynamic>{};

      final isOpen = data['merchant_is_open'] == true;
      final merchantId =
          (data['merchant_id'] as num?)?.toInt() ?? order.merchant?.id ?? 0;
      final merchantName =
          (data['merchant_name'] ?? order.merchant?.name ?? 'Commerce').toString();
      final items = (data['items'] is List)
          ? (data['items'] as List)
              .whereType<Map>()
              .map((e) => Map<String, dynamic>.from(e))
              .toList()
          : <Map<String, dynamic>>[];
      final unavailable = (data['unavailable_items'] is List)
          ? (data['unavailable_items'] as List)
              .whereType<Map>()
              .map((e) => Map<String, dynamic>.from(e))
              .toList()
          : <Map<String, dynamic>>[];

      if (!context.mounted) return;

      final confirm = await showModalBottomSheet<bool>(
        context: context,
        isScrollControlled: true,
        shape: const RoundedRectangleBorder(
          borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
        ),
        builder: (ctx) => Padding(
          padding: const EdgeInsets.fromLTRB(20, 20, 20, 28),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text(
                'Recommander chez $merchantName',
                style:
                    const TextStyle(fontSize: 17, fontWeight: FontWeight.bold),
              ),
              const SizedBox(height: 10),
              if (!isOpen)
                Container(
                  padding: const EdgeInsets.all(10),
                  margin: const EdgeInsets.only(bottom: 10),
                  decoration: BoxDecoration(
                    color: Colors.red.shade50,
                    borderRadius: BorderRadius.circular(10),
                  ),
                  child: const Text(
                    'Cette boutique est actuellement fermée.',
                    style: TextStyle(
                        color: Colors.red, fontWeight: FontWeight.w600),
                  ),
                ),
              if (items.isEmpty)
                const Text(
                  'Aucun article de cette commande n\'est disponible actuellement.',
                  style: TextStyle(color: GoLivreurTheme.texteSecondaire),
                )
              else
                ...items.map((it) {
                  final capped = it['capped'] == true;
                  return ListTile(
                    dense: true,
                    contentPadding: EdgeInsets.zero,
                    title: Text('${it['quantity']}× ${it['name']}'),
                    subtitle: capped
                        ? Text(
                            'Quantité plafonnée au stock disponible (${it['available_quantity']})',
                            style: const TextStyle(
                                color: Colors.orange, fontSize: 12),
                          )
                        : null,
                    trailing: Text(
                      '${it['line_total']} F',
                      style: const TextStyle(fontWeight: FontWeight.bold),
                    ),
                  );
                }),
              if (unavailable.isNotEmpty) ...[
                const Divider(),
                const Text(
                  'Articles indisponibles :',
                  style: TextStyle(
                      fontSize: 12.5,
                      fontWeight: FontWeight.w600,
                      color: Colors.red),
                ),
                ...unavailable.map(
                  (u) => Text(
                    '• ${u['name']} (${u['reason']})',
                    style: const TextStyle(fontSize: 12, color: Colors.red),
                  ),
                ),
              ],
              const SizedBox(height: 14),
              ElevatedButton(
                style: ElevatedButton.styleFrom(
                  backgroundColor: GoLivreurTheme.secondaire,
                  foregroundColor: Colors.white,
                  padding: const EdgeInsets.symmetric(vertical: 14),
                ),
                onPressed: (!isOpen || items.isEmpty)
                    ? null
                    : () => Navigator.of(ctx).pop(true),
                child: const Text('Charger dans mon panier'),
              ),
            ],
          ),
        ),
      );

      if (confirm != true || !context.mounted) return;

      final merchant = order.merchant ??
          MerchantModel(
            id: merchantId,
            name: merchantName,
            category: 'restaurant',
            address: '',
            lat: 0,
            lng: 0,
            prepTimeMinutes: 20,
            isOpen: isOpen,
            averageRating: 0,
            products: const [],
          );

      final cart = ref.read(cartProvider.notifier);
      cart.clear();
      for (final it in items) {
        final product = ProductModel(
          id: (it['product_id'] as num).toInt(),
          name: (it['name'] ?? 'Article').toString(),
          price: (it['unit_price'] as num?)?.toDouble() ?? 0,
          photoUrl: it['photo_url']?.toString(),
          isAvailable: true,
        );
        cart.addProduct(
          merchant,
          product,
          quantity: (it['quantity'] as num?)?.toInt() ?? 1,
        );
      }

      Navigator.of(context).push(
        MaterialPageRoute(builder: (_) => const CartScreen()),
      );
    } on DioException catch (e) {
      final msg = e.response?.data is Map && e.response!.data['message'] != null
          ? e.response!.data['message'].toString()
          : 'Impossible de préparer ce panier pour le moment.';
      messenger.showSnackBar(
        SnackBar(content: Text(msg), backgroundColor: Colors.red),
      );
    }
  }
}

