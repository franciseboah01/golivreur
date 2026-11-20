import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:golivreur_core/config/app_theme.dart';
import 'package:golivreur_core/services/api_client.dart';
import 'package:golivreur_core/widgets/golivreur_widgets.dart';
import '../../orders/screens/business_orders_screen.dart';
import '../../catalog/screens/business_catalog_screen.dart';
import '../../revenue/screens/business_revenue_screen.dart';
import '../../profile/screens/business_profile_screen.dart';
import '../../business/providers/business_provider.dart';

class BusinessMainNavigationScreen extends ConsumerStatefulWidget {
  final int initialIndex;

  const BusinessMainNavigationScreen({super.key, this.initialIndex = 0});

  @override
  ConsumerState<BusinessMainNavigationScreen> createState() =>
      _BusinessMainNavigationScreenState();
}

class _BusinessMainNavigationScreenState
    extends ConsumerState<BusinessMainNavigationScreen>
    with WidgetsBindingObserver {
  late int _currentIndex;
  late final List<Widget> _pages;
  StreamSubscription<InAppPushMessage>? _pushMsgSub;
  StreamSubscription<InAppPushMessage>? _pushTapSub;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _currentIndex = widget.initialIndex;
    _pages = [
      BusinessOrdersScreen(onSwitchTab: _switchToTab),
      const BusinessCatalogScreen(),
      const BusinessRevenueScreen(),
      BusinessProfileScreen(onSwitchTab: _switchToTab),
    ];

    WidgetsBinding.instance.addPostFrameCallback((_) {
      _synchroniserDonneesServeur();
      PushNotificationService.syncTokenWithBackend(ref.read(apiClientProvider));
    });

    _pushMsgSub = PushNotificationService.onForegroundMessage.listen((_) {
      if (!mounted) return;
      ref.read(businessOrdersProvider.notifier).chargerCommandes();
      ref.read(revenueStatsProvider.notifier).chargerRevenus();
      ref.read(businessProfileProvider.notifier).chargerProfil();
    });

    _pushTapSub = PushNotificationService.onNotificationTap.listen((msg) {
      if (!mounted) return;
      final t = msg.type.toLowerCase();
      if (t.contains('wallet_cash')) {
        _switchToTab(2);
      } else if (t.contains('order')) {
        _switchToTab(0);
      }
    });
  }

  @override
  void dispose() {
    _pushMsgSub?.cancel();
    _pushTapSub?.cancel();
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) {
      ref.read(revenueStatsProvider.notifier).chargerRevenus();
      ref.read(businessOrdersProvider.notifier).chargerCommandes();
    }
  }

  void _switchToTab(int tabIndex) {
    setState(() => _currentIndex = tabIndex);
    _rafraichirOnglet(tabIndex);
  }

  Future<void> _synchroniserDonneesServeur() async {
    await Future.wait([
      ref.read(businessProfileProvider.notifier).chargerProfil(),
      ref.read(businessCatalogSchemaProvider.notifier).chargerSchema(),
      ref.read(businessCatalogProvider.notifier).chargerCatalogue(),
      ref.read(businessOrdersProvider.notifier).chargerCommandes(),
      ref.read(revenueStatsProvider.notifier).chargerRevenus(),
    ]);
  }

  void _rafraichirOnglet(int index) {
    if (index == 0) {
      ref.read(businessOrdersProvider.notifier).chargerCommandes();
    } else if (index == 1) {
      ref.read(businessCatalogSchemaProvider.notifier).chargerSchema();
      ref.read(businessCatalogProvider.notifier).chargerCatalogue();
    } else if (index == 2) {
      ref.read(revenueStatsProvider.notifier).chargerRevenus();
    } else if (index == 3) {
      ref.read(businessProfileProvider.notifier).chargerProfil();
    }
  }

  @override
  Widget build(BuildContext context) {
    final orders = ref.watch(businessOrdersProvider);
    final pendingCount =
        orders.where((o) => o.status == BusinessOrderStatus.pending).length;

    return Scaffold(
      body: IndexedStack(
        index: _currentIndex,
        children: _pages,
      ),
      bottomNavigationBar: Container(
        decoration: BoxDecoration(
          color: Colors.white,
          boxShadow: [
            BoxShadow(
              color: Colors.black.withValues(alpha: 0.06),
              blurRadius: 10,
              offset: const Offset(0, -2),
            ),
          ],
        ),
        child: BottomNavigationBar(
          currentIndex: _currentIndex,
          onTap: _switchToTab,
          type: BottomNavigationBarType.fixed,
          backgroundColor: Colors.white,
          selectedItemColor: const Color(0xFF2E75B6),
          unselectedItemColor: GoLivreurTheme.texteSecondaire,
          selectedFontSize: 12,
          unselectedFontSize: 12,
          elevation: 0,
          items: [
            BottomNavigationBarItem(
              icon: Stack(
                clipBehavior: Clip.none,
                children: [
                  const Icon(Icons.receipt_long_outlined),
                  if (pendingCount > 0)
                    Positioned(
                      top: -4,
                      right: -6,
                      child: Container(
                        padding: const EdgeInsets.all(4),
                        decoration: const BoxDecoration(
                          color: GoLivreurTheme.erreur,
                          shape: BoxShape.circle,
                        ),
                        child: Text(
                          '$pendingCount',
                          style: const TextStyle(
                            color: Colors.white,
                            fontSize: 10,
                            fontWeight: FontWeight.bold,
                          ),
                        ),
                      ),
                    ),
                ],
              ),
              activeIcon: const Icon(Icons.receipt_long),
              label: 'Commandes',
            ),
            const BottomNavigationBarItem(
              icon: Icon(Icons.restaurant_menu_outlined),
              activeIcon: Icon(Icons.restaurant_menu),
              label: 'Catalogue',
            ),
            const BottomNavigationBarItem(
              icon: Icon(Icons.account_balance_wallet_outlined),
              activeIcon: Icon(Icons.account_balance_wallet),
              label: 'Revenus',
            ),
            const BottomNavigationBarItem(
              icon: Icon(Icons.storefront_outlined),
              activeIcon: Icon(Icons.storefront),
              label: 'Profil',
            ),
          ],
        ),
      ),
    );
  }
}
