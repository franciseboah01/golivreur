import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:golivreur_core/config/app_theme.dart';
import 'package:golivreur_core/models/modeles.dart';
import '../../goshop/providers/order_provider.dart';
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
            return const Center(
              child: Text(
                'Aucune commande enregistrée pour l\'instant.',
                style: TextStyle(color: GoLivreurTheme.texteSecondaire),
              ),
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
              ...pastOrders.map((order) => _buildPastOrderCard(context, order)),
            ],
          );
        },
        loading: () => const Center(child: CircularProgressIndicator(color: GoLivreurTheme.primaire)),
        error: (err, _) => Center(child: Text('Erreur: $err')),
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

  Widget _buildPastOrderCard(BuildContext context, OrderModel order) {
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
            onPressed: () {
              ScaffoldMessenger.of(context).showSnackBar(
                const SnackBar(content: Text('Articles ajoutés à votre panier !')),
              );
            },
            child: const Text('Recommander', style: TextStyle(fontSize: 12)),
          ),
        ],
      ),
    );
  }
}
