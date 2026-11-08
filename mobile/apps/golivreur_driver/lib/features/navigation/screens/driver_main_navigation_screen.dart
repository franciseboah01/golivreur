import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:golivreur_core/config/app_theme.dart';
import '../../jobs/screens/driver_jobs_screen.dart';
import '../../wallet/screens/driver_wallet_screen.dart';
import '../../earnings/screens/driver_earnings_screen.dart';
import '../../profile/screens/driver_profile_screen.dart';

class DriverMainNavigationScreen extends ConsumerStatefulWidget {
  final int initialIndex;

  const DriverMainNavigationScreen({super.key, this.initialIndex = 0});

  @override
  ConsumerState<DriverMainNavigationScreen> createState() => _DriverMainNavigationScreenState();
}

class _DriverMainNavigationScreenState extends ConsumerState<DriverMainNavigationScreen> {
  late int _currentIndex;
  late final List<Widget> _pages;

  @override
  void initState() {
    super.initState();
    _currentIndex = widget.initialIndex;
    _pages = [
      const DriverJobsScreen(),
      const DriverWalletScreen(),
      const DriverEarningsScreen(),
      DriverProfileScreen(onSwitchTab: (tabIndex) {
        setState(() => _currentIndex = tabIndex);
      }),
    ];
  }

  @override
  Widget build(BuildContext context) {
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
          selectedItemColor: GoLivreurTheme.secondaire, // Vert dominant Driver Stitch
          unselectedItemColor: GoLivreurTheme.texteSecondaire,
          selectedFontSize: 12,
          unselectedFontSize: 12,
          elevation: 0,
          items: const [
            BottomNavigationBarItem(
              icon: Icon(Icons.two_wheeler_outlined),
              activeIcon: Icon(Icons.two_wheeler),
              label: 'Courses',
            ),
            BottomNavigationBarItem(
              icon: Icon(Icons.account_balance_wallet_outlined),
              activeIcon: Icon(Icons.account_balance_wallet),
              label: 'Portefeuille',
            ),
            BottomNavigationBarItem(
              icon: Icon(Icons.bar_chart_outlined),
              activeIcon: Icon(Icons.bar_chart),
              label: 'Gains',
            ),
            BottomNavigationBarItem(
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

