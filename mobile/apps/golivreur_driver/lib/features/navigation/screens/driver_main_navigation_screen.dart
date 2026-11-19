import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:golivreur_core/config/app_theme.dart';
import '../../driver/providers/driver_provider.dart';
import '../../jobs/screens/driver_jobs_screen.dart';
import '../../wallet/screens/driver_wallet_screen.dart';
import '../../earnings/screens/driver_earnings_screen.dart';
import '../../profile/screens/driver_profile_screen.dart';

class DriverMainNavigationScreen extends ConsumerStatefulWidget {
  final int initialIndex;

  const DriverMainNavigationScreen({super.key, this.initialIndex = 0});

  @override
  ConsumerState<DriverMainNavigationScreen> createState() =>
      _DriverMainNavigationScreenState();
}

class _DriverMainNavigationScreenState
    extends ConsumerState<DriverMainNavigationScreen> {
  late int _currentIndex;
  late final List<Widget> _pages;

  @override
  void initState() {
    super.initState();
    _currentIndex = widget.initialIndex;
    _pages = [
      DriverJobsScreen(onSwitchTab: _switchToTab),
      const DriverWalletScreen(),
      const DriverEarningsScreen(),
      DriverProfileScreen(onSwitchTab: _switchToTab),
    ];

    WidgetsBinding.instance.addPostFrameCallback((_) {
      _synchroniserDonneesServeur();
    });
  }

  void _switchToTab(int tabIndex) {
    setState(() => _currentIndex = tabIndex);
    _rafraichirOnglet(tabIndex);
  }

  Future<void> _synchroniserDonneesServeur() async {
    await Future.wait([
      ref.read(driverProfileProvider.notifier).chargerProfil(),
      ref.read(driverProfileProvider.notifier).chargerPortefeuille(),
      ref.read(driverActiveJobProvider.notifier).chargerCourses(),
    ]);
  }

  void _rafraichirOnglet(int index) {
    if (index == 0) {
      ref.read(driverActiveJobProvider.notifier).chargerCourses();
    } else if (index == 1 || index == 2) {
      ref.read(driverProfileProvider.notifier).chargerPortefeuille();
    } else if (index == 3) {
      ref.read(driverProfileProvider.notifier).chargerProfil();
    }
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
          onTap: _switchToTab,
          type: BottomNavigationBarType.fixed,
          backgroundColor: Colors.white,
          selectedItemColor: GoLivreurTheme.secondaire,
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
