import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:golivreur_core/config/app_theme.dart';
import '../../orders/screens/business_orders_screen.dart';
import '../../catalog/screens/business_catalog_screen.dart';
import '../../revenue/screens/business_revenue_screen.dart';
import '../../profile/screens/business_profile_screen.dart';
import '../../business/providers/business_provider.dart';

class BusinessMainNavigationScreen extends ConsumerStatefulWidget {
  final int initialIndex;

  const BusinessMainNavigationScreen({super.key, this.initialIndex = 0});

  @override
  ConsumerState<BusinessMainNavigationScreen> createState() => _BusinessMainNavigationScreenState();
}

class _BusinessMainNavigationScreenState extends ConsumerState<BusinessMainNavigationScreen> {
  late int _currentIndex;
  late final List<Widget> _pages;

  @override
  void initState() {
    super.initState();
    _currentIndex = widget.initialIndex;
    _pages = [
      const BusinessOrdersScreen(),
      const BusinessCatalogScreen(),
      const BusinessRevenueScreen(),
      BusinessProfileScreen(onSwitchTab: (tabIndex) {
        setState(() => _currentIndex = tabIndex);
      }),
    ];
  }

  @override
  Widget build(BuildContext context) {
    final orders = ref.watch(businessOrdersProvider);
    final pendingCount = orders.where((o) => o.status == BusinessOrderStatus.pending).length;

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
          onTap: (index) {
            setState(() => _currentIndex = index);
          },
          type: BottomNavigationBarType.fixed,
          backgroundColor: Colors.white,
          selectedItemColor: const Color(0xFF2E75B6), // Accent d'identification Business Stitch
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
                          style: const TextStyle(color: Colors.white, fontSize: 10, fontWeight: FontWeight.bold),
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
              icon: Icon(Icons.person_outline),
              activeIcon: Icon(Icons.person),
              label: 'Profil',
            ),
          ],
        ),
      ),
    );
  }
}

