import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:golivreur_core/config/app_theme.dart';
import '../../auth/screens/business_login_screen.dart';
import '../../business/providers/business_provider.dart';

class BusinessOrdersScreen extends ConsumerStatefulWidget {
  final void Function(int)? onSwitchTab;

  const BusinessOrdersScreen({super.key, this.onSwitchTab});

  @override
  ConsumerState<BusinessOrdersScreen> createState() => _BusinessOrdersScreenState();
}

class _BusinessOrdersScreenState extends ConsumerState<BusinessOrdersScreen> {
  Timer? _countdownTimer;
  Timer? _pollTimer;
  int _rebours = 45;
  final Set<int> _knownPendingIds = <int>{};

  @override
  void initState() {
    super.initState();
    _startTimer();
    _startPolling();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      ref.read(businessOrderHistoryProvider.notifier).chargerAujourdhui();
    });
  }

  void _startPolling() {
    _pollTimer?.cancel();
    _pollTimer = Timer.periodic(const Duration(seconds: 6), (_) async {
      if (!mounted) return;
      await ref.read(businessOrdersProvider.notifier).chargerCommandes();
      if (!mounted) return;
      final pendingNow = ref
          .read(businessOrdersProvider)
          .where((o) => o.status == BusinessOrderStatus.pending)
          .map((o) => o.id)
          .toSet();
      final hasNew = pendingNow.any((id) => !_knownPendingIds.contains(id));
      _knownPendingIds
        ..clear()
        ..addAll(pendingNow);
      if (hasNew) {
        HapticFeedback.heavyImpact();
      }
    });
  }

  void _startTimer() {
    _countdownTimer?.cancel();
    _countdownTimer = Timer.periodic(const Duration(seconds: 1), (timer) {
      if (!mounted) {
        timer.cancel();
        return;
      }
      final pending = ref
          .read(businessOrdersProvider)
          .where((o) => o.status == BusinessOrderStatus.pending)
          .toList();
      if (pending.isEmpty) {
        if (_rebours != 45) {
          setState(() => _rebours = 45);
        }
        return;
      }
      final elapsed =
          DateTime.now().difference(pending.first.createdAt).inSeconds;
      final remaining = (45 - elapsed).clamp(0, 45);
      setState(() => _rebours = remaining);
      if (remaining == 0) {
        ref.read(businessOrdersProvider.notifier).chargerCommandes();
      }
    });
  }

  @override
  void dispose() {
    _countdownTimer?.cancel();
    _pollTimer?.cancel();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final profile = ref.watch(businessProfileProvider);
    final orders = ref.watch(businessOrdersProvider);
    final historyState = ref.watch(businessOrderHistoryProvider);

    final pendingOrders = orders.where((o) => o.status == BusinessOrderStatus.pending).toList();
    final acceptedOrders = orders.where((o) => o.status == BusinessOrderStatus.accepted).toList();
    final readyOrders = orders.where((o) => o.status == BusinessOrderStatus.ready).toList();

    return Scaffold(
      backgroundColor: GoLivreurTheme.fond,
      drawer: _buildBusinessDrawer(context, ref, profile),
      appBar: AppBar(
        backgroundColor: Colors.white,
        elevation: 0.5,
        leading: Builder(
          builder: (drawerCtx) => IconButton(
            icon: const Icon(Icons.menu, color: GoLivreurTheme.textePrincipal),
            tooltip: 'Menu rapide & Profil',
            onPressed: () => Scaffold.of(drawerCtx).openDrawer(),
          ),
        ),
        title: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                const Text(
                  'GoLivreur Business',
                  style: TextStyle(fontWeight: FontWeight.bold, fontSize: 16, color: GoLivreurTheme.textePrincipal),
                ),
                if (profile.cityName != null && profile.cityName!.isNotEmpty) ...[
                  const SizedBox(width: 6),
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                    decoration: BoxDecoration(
                      color: const Color(0xFF2E75B6).withValues(alpha: 0.15),
                      borderRadius: BorderRadius.circular(4),
                    ),
                    child: Text(
                      profile.cityName!.toUpperCase(),
                      style: const TextStyle(fontSize: 10, fontWeight: FontWeight.bold, color: Color(0xFF2E75B6)),
                    ),
                  ),
                ],
              ],
            ),
            Text(
              profile.name.isNotEmpty ? profile.name : 'Mon commerce',
              style: const TextStyle(fontSize: 12, color: GoLivreurTheme.texteSecondaire),
            ),
          ],
        ),
        actions: [
          // Commutateur Rapide Ouvert / Fermé dans l'AppBar
          InkWell(
            onTap: () {
              ref.read(businessProfileProvider.notifier).toggleOpenStatus();
              ScaffoldMessenger.of(context).showSnackBar(
                SnackBar(
                  content: Text(profile.isOpen ? 'Boutique fermée pour les commandes.' : 'Boutique ouverte et visible par les clients !'),
                  backgroundColor: profile.isOpen ? GoLivreurTheme.erreur : GoLivreurTheme.succes,
                  duration: const Duration(seconds: 2),
                ),
              );
            },
            borderRadius: BorderRadius.circular(20),
            child: Container(
              margin: const EdgeInsets.symmetric(vertical: 12, horizontal: 12),
              padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
              decoration: BoxDecoration(
                color: profile.isOpen
                    ? GoLivreurTheme.succes.withValues(alpha: 0.15)
                    : GoLivreurTheme.erreur.withValues(alpha: 0.15),
                borderRadius: BorderRadius.circular(20),
                border: Border.all(
                  color: profile.isOpen ? GoLivreurTheme.succes : GoLivreurTheme.erreur,
                ),
              ),
              child: Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Container(
                    width: 8,
                    height: 8,
                    decoration: BoxDecoration(
                      color: profile.isOpen ? GoLivreurTheme.succes : GoLivreurTheme.erreur,
                      shape: BoxShape.circle,
                    ),
                  ),
                  const SizedBox(width: 6),
                  Text(
                    profile.isOpen ? 'Ouvert' : 'Fermé',
                    style: TextStyle(
                      fontSize: 12,
                      fontWeight: FontWeight.bold,
                      color: profile.isOpen ? GoLivreurTheme.succes : GoLivreurTheme.erreur,
                    ),
                  ),
                ],
              ),
            ),
          ),
        ],
      ),
      body: RefreshIndicator(
        color: GoLivreurTheme.secondaire,
        onRefresh: () async {
          await Future.wait([
            ref.read(businessOrdersProvider.notifier).chargerCommandes(),
            ref.read(businessOrderHistoryProvider.notifier).chargerAujourdhui(),
          ]);
        },
        child: ListView(
          physics: const AlwaysScrollableScrollPhysics(),
          padding: const EdgeInsets.all(16),
          children: [
          // ─── Commandes En Attente (Compte à rebours 45s) ──────────────────
          if (pendingOrders.isNotEmpty) ...[
            Row(
              children: [
                const Icon(Icons.notifications_active, color: Color(0xFFF9A825), size: 22),
                const SizedBox(width: 8),
                const Text(
                  'Nouvelle commande reçue !',
                  style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold, color: GoLivreurTheme.textePrincipal),
                ),
                const Spacer(),
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                  decoration: BoxDecoration(
                    color: _rebours <= 15 ? GoLivreurTheme.erreur : const Color(0xFFF9A825),
                    borderRadius: BorderRadius.circular(20),
                  ),
                  child: Text(
                    '${_rebours}s',
                    style: const TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 13),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 10),
            ...pendingOrders.map((order) => _buildPendingOrderCard(context, ref, order)),
            const SizedBox(height: 24),
          ],

          // ─── Commandes En Préparation ─────────────────────────────────────
          if (acceptedOrders.isNotEmpty) ...[
            const Row(
              children: [
                Icon(Icons.outdoor_grill, color: GoLivreurTheme.primaire, size: 22),
                SizedBox(width: 8),
                Text(
                  'En préparation en cuisine',
                  style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold, color: GoLivreurTheme.textePrincipal),
                ),
              ],
            ),
            const SizedBox(height: 10),
            ...acceptedOrders.map((order) => _buildInPrepOrderCard(context, ref, order)),
            const SizedBox(height: 24),
          ],

          // ─── Commandes Prêtes pour le Livreur ─────────────────────────────
          if (readyOrders.isNotEmpty) ...[
            const Row(
              children: [
                Icon(Icons.check_circle, color: GoLivreurTheme.succes, size: 22),
                SizedBox(width: 8),
                Text(
                  'Prêtes pour le coursier',
                  style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold, color: GoLivreurTheme.textePrincipal),
                ),
              ],
            ),
            const SizedBox(height: 10),
            ...readyOrders.map((order) => _buildReadyOrderCard(context, order)),
            const SizedBox(height: 24),
          ],

          // ─── Historique Jour / Semaines ISO (Lot 9C Point 6) ──────────────
          const Text(
            'Historique des commandes',
            style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold, color: GoLivreurTheme.textePrincipal),
          ),
          const SizedBox(height: 10),
          if (historyState.loading && historyState.buckets.isEmpty)
            const Padding(
              padding: EdgeInsets.all(16),
              child: Center(child: CircularProgressIndicator()),
            )
          else if (historyState.buckets.isEmpty)
            const Padding(
              padding: EdgeInsets.all(16),
              child: Center(
                child: Text(
                  'Aucune commande dans l\'historique aujourd\'hui',
                  style: TextStyle(color: GoLivreurTheme.texteSecondaire),
                ),
              ),
            )
          else ...[
            ...historyState.buckets.map(_buildHistoryBucketCard),
            if (historyState.nextPreviousWeek != null &&
                historyState.nextPreviousWeek!.isNotEmpty)
              Padding(
                padding: const EdgeInsets.only(top: 8, bottom: 16),
                child: OutlinedButton.icon(
                  onPressed: historyState.loadingMore
                      ? null
                      : () => ref
                          .read(businessOrderHistoryProvider.notifier)
                          .chargerSemainePrecedente(),
                  icon: historyState.loadingMore
                      ? const SizedBox(
                          width: 16,
                          height: 16,
                          child: CircularProgressIndicator(strokeWidth: 2),
                        )
                      : const Icon(Icons.history),
                  label: Text(
                    historyState.loadingMore
                        ? 'Chargement...'
                        : 'Voir + (semaine ${historyState.nextPreviousWeek})',
                  ),
                ),
              ),
          ],
        ],
        ),
      ),
    );
  }

  Widget _buildPendingOrderCard(BuildContext context, WidgetRef ref, BusinessOrder order) {
    return Container(
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: const Color(0xFFF9A825), width: 2),
        boxShadow: [
          BoxShadow(
            color: const Color(0xFFF9A825).withValues(alpha: 0.2),
            blurRadius: 10,
            offset: const Offset(0, 4),
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
              Text('Commande #${order.id}', style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16)),
              Text(
                '${order.totalAmount.toInt()} F CFA',
                style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16, color: GoLivreurTheme.primaire),
              ),
            ],
          ),
          const SizedBox(height: 6),
          Text('Client : ${order.clientName} (+225 ${order.clientPhone})', style: const TextStyle(fontSize: 13, color: GoLivreurTheme.texteSecondaire)),
          Text('Adresse : ${order.deliveryAddress}', style: const TextStyle(fontSize: 13, color: GoLivreurTheme.texteSecondaire)),
          const Divider(height: 20),
          ...order.items.map((item) => Padding(
                padding: const EdgeInsets.symmetric(vertical: 2),
                child: Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Text('${item.quantity}x ${item.productNameSnapshot}', style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 14)),
                    Text('${item.sousTotal.toInt()} F CFA', style: const TextStyle(fontSize: 13)),
                  ],
                ),
              )),
          const SizedBox(height: 16),
          Row(
            children: [
              // Bouton Refuser (Rouge)
              Expanded(
                child: SizedBox(
                  height: 52, // Zone tactile min 52px
                  child: OutlinedButton(
                    style: OutlinedButton.styleFrom(
                      foregroundColor: GoLivreurTheme.erreur,
                      side: const BorderSide(color: GoLivreurTheme.erreur, width: 1.5),
                      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                    ),
                    onPressed: () {
                      ref.read(businessOrdersProvider.notifier).refuseOrder(order.id);
                    },
                    child: const Text('Refuser', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 15)),
                  ),
                ),
              ),
              const SizedBox(width: 12),
              // Bouton Accepter (Vert)
              Expanded(
                child: SizedBox(
                  height: 52, // Zone tactile min 52px
                  child: ElevatedButton(
                    style: ElevatedButton.styleFrom(
                      backgroundColor: GoLivreurTheme.secondaire,
                      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                    ),
                    onPressed: () {
                      ref.read(businessOrdersProvider.notifier).acceptOrder(order.id);
                      ScaffoldMessenger.of(context).showSnackBar(
                        const SnackBar(content: Text('Commande acceptée ! Préparation en cours.')),
                      );
                    },
                    child: const Text('Accepter', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 15, color: Colors.white)),
                  ),
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }

  Widget _buildInPrepOrderCard(BuildContext context, WidgetRef ref, BusinessOrder order) {
    return Container(
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: GoLivreurTheme.bordure),
      ),
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Text('Commande #${order.id}', style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16)),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                decoration: BoxDecoration(
                  color: GoLivreurTheme.primaire.withValues(alpha: 0.15),
                  borderRadius: BorderRadius.circular(6),
                ),
                child: const Text(
                  'En préparation',
                  style: TextStyle(color: Color(0xFF835500), fontWeight: FontWeight.bold, fontSize: 12),
                ),
              ),
            ],
          ),
          const SizedBox(height: 8),
          ...order.items.map((item) => Text('• ${item.quantity}x ${item.productNameSnapshot}', style: const TextStyle(fontSize: 14))),
          const SizedBox(height: 14),
          // Bouton Tactile "Commande Prête" (52px Stitch)
          SizedBox(
            width: double.infinity,
            height: 52,
            child: ElevatedButton.icon(
              style: ElevatedButton.styleFrom(
                backgroundColor: GoLivreurTheme.secondaire,
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
              ),
              onPressed: () {
                ref.read(businessOrdersProvider.notifier).markOrderReady(order.id);
                ScaffoldMessenger.of(context).showSnackBar(
                  const SnackBar(
                    content: Text('✅ Commande prête ! Le livreur est alerté pour la récupération.'),
                    backgroundColor: GoLivreurTheme.succes,
                  ),
                );
              },
              icon: const Icon(Icons.check, color: Colors.white),
              label: const Text(
                'Commande prête (Emballée)',
                style: TextStyle(fontWeight: FontWeight.bold, fontSize: 15, color: Colors.white),
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildReadyOrderCard(BuildContext context, BusinessOrder order) {
    return Container(
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: GoLivreurTheme.succes.withValues(alpha: 0.5)),
      ),
      padding: const EdgeInsets.all(16),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text('Commande #${order.id}', style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 15)),
              const SizedBox(height: 4),
              const Text('En attente du coursier GoLivreur...', style: TextStyle(color: GoLivreurTheme.succes, fontSize: 13, fontWeight: FontWeight.w600)),
            ],
          ),
          const Icon(Icons.moped, color: GoLivreurTheme.secondaire, size: 28),
        ],
      ),
    );
  }

  Widget _buildHistoryBucketCard(BusinessHistoryBucket bucket) {
    return Container(
      margin: const EdgeInsets.only(bottom: 12),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: GoLivreurTheme.bordure),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  bucket.label,
                  style: const TextStyle(
                    fontWeight: FontWeight.bold,
                    fontSize: 14.5,
                    color: GoLivreurTheme.textePrincipal,
                  ),
                ),
              ),
              Text(
                '${bucket.deliveredMerchantAmount.toInt()} F CFA',
                style: const TextStyle(
                  fontWeight: FontWeight.bold,
                  fontSize: 14,
                  color: GoLivreurTheme.secondaire,
                ),
              ),
            ],
          ),
          const SizedBox(height: 4),
          Text(
            '${bucket.ordersCount} commande${bucket.ordersCount > 1 ? 's' : ''} • ${bucket.deliveredCount} livrée${bucket.deliveredCount > 1 ? 's' : ''}',
            style: const TextStyle(
              fontSize: 12,
              color: GoLivreurTheme.texteSecondaire,
            ),
          ),
          if (bucket.orders.isNotEmpty) ...[
            const Divider(height: 16),
            ...bucket.orders.map((o) {
              final isDelivered = o.status == 'delivered';
              return Padding(
                padding: const EdgeInsets.symmetric(vertical: 4),
                child: Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Expanded(
                      child: Text(
                        '#${o.id} • ${o.clientName} (${o.productsTotal.toInt()} F)',
                        style: const TextStyle(fontSize: 13),
                        overflow: TextOverflow.ellipsis,
                      ),
                    ),
                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 2),
                      decoration: BoxDecoration(
                        color: isDelivered
                            ? GoLivreurTheme.succes.withValues(alpha: 0.12)
                            : Colors.grey.shade200,
                        borderRadius: BorderRadius.circular(6),
                      ),
                      child: Text(
                        isDelivered ? 'Livrée ✓' : o.status,
                        style: TextStyle(
                          fontSize: 11,
                          fontWeight: FontWeight.bold,
                          color: isDelivered
                              ? GoLivreurTheme.succes
                              : GoLivreurTheme.texteSecondaire,
                        ),
                      ),
                    ),
                  ],
                ),
              );
            }),
          ],
        ],
      ),
    );
  }

  Widget _buildBusinessDrawer(BuildContext context, WidgetRef ref, BusinessProfileState profile) {
    return Drawer(
      child: SafeArea(
        child: Column(
          children: [
            Container(
              width: double.infinity,
              padding: const EdgeInsets.all(20),
              decoration: const BoxDecoration(
                color: Color(0xFF2E75B6),
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      Container(
                        width: 44,
                        height: 44,
                        alignment: Alignment.center,
                        decoration: BoxDecoration(
                          color: Colors.white,
                          borderRadius: BorderRadius.circular(12),
                        ),
                        clipBehavior: Clip.antiAlias,
                        child: profile.logoUrl != null && profile.logoUrl!.isNotEmpty
                            ? Image.network(
                                profile.logoUrl!,
                                width: 44,
                                height: 44,
                                fit: BoxFit.cover,
                                errorBuilder: (_, __, ___) => profile.emoji != null
                                    ? Text(profile.emoji!, style: const TextStyle(fontSize: 24))
                                    : const Icon(Icons.storefront, color: Color(0xFF2E75B6), size: 28),
                              )
                            : (profile.emoji != null && profile.emoji!.trim().isNotEmpty
                                ? Text(profile.emoji!, style: const TextStyle(fontSize: 24))
                                : const Icon(Icons.storefront, color: Color(0xFF2E75B6), size: 28)),
                      ),
                      const SizedBox(width: 12),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              profile.name,
                              style: const TextStyle(color: Colors.white, fontSize: 16, fontWeight: FontWeight.bold),
                            ),
                            Text(
                              profile.quarter,
                              style: const TextStyle(color: Colors.white70, fontSize: 12),
                            ),
                          ],
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 12),
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                    decoration: BoxDecoration(
                      color: Colors.black.withValues(alpha: 0.15),
                      borderRadius: BorderRadius.circular(20),
                    ),
                    child: Text(
                      'Solde : ${profile.walletBalance.toInt()} F CFA',
                      style: const TextStyle(color: Colors.white, fontSize: 13, fontWeight: FontWeight.bold),
                    ),
                  ),
                ],
              ),
            ),
            ListTile(
              leading: const Icon(Icons.person, color: Color(0xFF2E75B6)),
              title: const Text('Mon Profil Commerce', style: TextStyle(fontWeight: FontWeight.bold)),
              subtitle: const Text('Horaires, temps de prépa, coordonnées'),
              onTap: () {
                Navigator.pop(context);
                widget.onSwitchTab?.call(3);
              },
            ),
            const Divider(height: 1),
            ListTile(
              leading: const Icon(Icons.restaurant_menu, color: Color(0xFF2E75B6)),
              title: const Text('Gestion du Catalogue & Plats'),
              subtitle: const Text('Mettre en rupture, modifier prix'),
              onTap: () {
                Navigator.pop(context);
                widget.onSwitchTab?.call(1);
              },
            ),
            ListTile(
              leading: const Icon(Icons.payments_outlined, color: Color(0xFF2E75B6)),
              title: const Text('Revenus & Versements'),
              subtitle: const Text('Demande de retrait Orange / Wave'),
              onTap: () {
                Navigator.pop(context);
                widget.onSwitchTab?.call(2);
              },
            ),
            const Divider(height: 1),
            ListTile(
              leading: const Icon(Icons.phone_in_talk, color: GoLivreurTheme.primaire),
              title: Text(
                (profile.cityName != null && profile.cityName!.isNotEmpty)
                    ? 'Support Partenaires ${profile.cityName}'
                    : 'Support Partenaires GoLivreur',
              ),
              subtitle: const Text('Assistance commerçants'),
              onTap: () {
                Navigator.pop(context);
                final cityLabel = (profile.cityName != null && profile.cityName!.isNotEmpty)
                    ? ' (${profile.cityName})'
                    : '';
                ScaffoldMessenger.of(context).showSnackBar(
                  SnackBar(content: Text('Contactez votre agence Support Partenaires GoLivreur$cityLabel.')),
                );
              },
            ),
            const Spacer(),
            const Divider(height: 1),
            ListTile(
              leading: const Icon(Icons.exit_to_app, color: GoLivreurTheme.erreur),
              title: const Text('Fermer la session', style: TextStyle(color: GoLivreurTheme.erreur, fontWeight: FontWeight.bold)),
              onTap: () async {
                Navigator.pop(context);
                const storage = FlutterSecureStorage();
                await storage.deleteAll();
                ref.read(businessProfileProvider.notifier).reinitialiser();
                ref.read(businessCatalogProvider.notifier).reinitialiser();
                ref.read(businessOrdersProvider.notifier).reinitialiser();
                ref.read(revenueStatsProvider.notifier).reinitialiser();
                if (context.mounted) {
                  Navigator.of(context).pushAndRemoveUntil(
                    MaterialPageRoute(builder: (_) => const BusinessLoginScreen()),
                    (route) => false,
                  );
                }
              },
            ),
          ],
        ),
      ),
    );
  }
}

