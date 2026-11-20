import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:golivreur_core/config/app_theme.dart';
import 'package:golivreur_core/widgets/golivreur_widgets.dart';
import '../../auth/providers/auth_provider.dart';
import '../../goshop/providers/goshop_provider.dart';
import '../../goshop/screens/goshop_home_screen.dart';
import '../../gocolis/providers/parcel_draft_recovery.dart';
import '../../gocolis/screens/gocolis_home_screen.dart';
import '../../gocolis/screens/new_parcel_screen.dart';
import '../../orders/screens/orders_tracking_history_screen.dart';
import '../../profile/screens/profile_screen.dart';

class MainNavigationScreen extends ConsumerStatefulWidget {
  final int initialIndex;

  const MainNavigationScreen({super.key, this.initialIndex = 0});

  @override
  ConsumerState<MainNavigationScreen> createState() =>
      _MainNavigationScreenState();
}

class _MainNavigationScreenState extends ConsumerState<MainNavigationScreen> {
  late int _currentIndex;
  StreamSubscription<InAppPushMessage>? _pushMsgSub;
  StreamSubscription<InAppPushMessage>? _pushTapSub;

  final List<Widget> _pages = const [
    GoShopHomeScreen(),
    GoColisHomeScreen(),
    OrdersTrackingHistoryScreen(),
    ProfileScreen(),
  ];

  @override
  void initState() {
    super.initState();
    _currentIndex = widget.initialIndex;

    WidgetsBinding.instance.addPostFrameCallback((_) {
      PushNotificationService.syncTokenWithBackend(ref.read(apiClientProvider));

      final recovery = ParcelDraftRecovery.takePendingRecovery();
      if (recovery != null && mounted) {
        setState(() => _currentIndex = 1);
        Navigator.of(context).push(
          MaterialPageRoute(
            builder: (_) => NewParcelScreen(
              recoveredDraft: recovery.draft,
              recoveredPhoto: recovery.photo,
            ),
          ),
        );
      }
    });

    _pushMsgSub = PushNotificationService.onForegroundMessage.listen((_) {
      if (!mounted) return;
      ref.invalidate(clientNotificationsProvider);
    });

    _pushTapSub = PushNotificationService.onNotificationTap.listen((msg) {
      if (!mounted) return;
      final t = msg.type.toLowerCase();
      if (t.contains('parcel')) {
        setState(() => _currentIndex = 1);
      } else if (t.contains('order')) {
        setState(() => _currentIndex = 2);
      }
      ref.invalidate(clientNotificationsProvider);
    });
  }

  @override
  void dispose() {
    _pushMsgSub?.cancel();
    _pushTapSub?.cancel();
    super.dispose();
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
          selectedItemColor: GoLivreurTheme.primaire,
          unselectedItemColor: GoLivreurTheme.texteSecondaire,
          selectedFontSize: 12,
          unselectedFontSize: 12,
          elevation: 0,
          items: const [
            BottomNavigationBarItem(
              icon: Icon(Icons.storefront_outlined),
              activeIcon: Icon(Icons.storefront),
              label: 'Accueil',
            ),
            BottomNavigationBarItem(
              icon: Icon(Icons.all_inbox_outlined),
              activeIcon: Icon(Icons.all_inbox),
              label: 'Colis',
            ),
            BottomNavigationBarItem(
              icon: Icon(Icons.receipt_long_outlined),
              activeIcon: Icon(Icons.receipt_long),
              label: 'Commandes',
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

