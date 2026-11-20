import 'dart:async';

import 'package:flutter/material.dart';

import '../config/app_theme.dart';
import '../services/push_notification_service.dart';

/// Hôte global de bannière **Push In-App** : affiche une notification flottante
/// animée en haut de l'écran lorsqu'un Push FCM arrive pendant que l'application est ouverte.
class InAppPushOverlay extends StatefulWidget {
  final Widget child;
  final void Function(InAppPushMessage message)? onMessageReceived;
  final void Function(InAppPushMessage message)? onMessageTapped;

  const InAppPushOverlay({
    super.key,
    required this.child,
    this.onMessageReceived,
    this.onMessageTapped,
  });

  @override
  State<InAppPushOverlay> createState() => _InAppPushOverlayState();
}

class _InAppPushOverlayState extends State<InAppPushOverlay> {
  StreamSubscription<InAppPushMessage>? _foregroundSub;
  StreamSubscription<InAppPushMessage>? _tapSub;
  Timer? _dismissTimer;
  InAppPushMessage? _current;

  @override
  void initState() {
    super.initState();
    _foregroundSub = PushNotificationService.onForegroundMessage.listen(
      _handleForegroundMessage,
    );
    _tapSub = PushNotificationService.onNotificationTap.listen((msg) {
      widget.onMessageTapped?.call(msg);
    });
  }

  @override
  void dispose() {
    _dismissTimer?.cancel();
    _foregroundSub?.cancel();
    _tapSub?.cancel();
    super.dispose();
  }

  void _handleForegroundMessage(InAppPushMessage message) {
    widget.onMessageReceived?.call(message);

    _dismissTimer?.cancel();
    if (!mounted) return;
    setState(() => _current = message);

    _dismissTimer = Timer(const Duration(seconds: 5), () {
      if (mounted) {
        setState(() => _current = null);
      }
    });
  }

  void _dismiss() {
    _dismissTimer?.cancel();
    if (mounted) {
      setState(() => _current = null);
    }
  }

  (Color, IconData) _styleForType(String type, String channelId) {
    final lower = type.toLowerCase();
    if (lower.contains('wallet_cash_deposit') || lower.contains('payment_success')) {
      return (GoLivreurTheme.succes, Icons.account_balance_wallet);
    }
    if (lower.contains('wallet_cash_withdrawal') ||
        lower.contains('refused') ||
        lower.contains('failed')) {
      return (GoLivreurTheme.erreur, Icons.payments_outlined);
    }
    if (lower.contains('new_order') || channelId == 'orders_new') {
      return (const Color(0xFF2E75B6), Icons.shopping_bag);
    }
    if (lower.contains('course_offer') || channelId == 'courses_new') {
      return (GoLivreurTheme.secondaire, Icons.two_wheeler);
    }
    if (lower.contains('parcel')) {
      return (const Color(0xFF1B5E20), Icons.local_shipping);
    }
    return (GoLivreurTheme.primaire, Icons.notifications_active);
  }

  @override
  Widget build(BuildContext context) {
    final msg = _current;

    return Stack(
      children: [
        widget.child,
        if (msg != null)
          Positioned(
            top: 0,
            left: 0,
            right: 0,
            child: SafeArea(
              bottom: false,
              child: Padding(
                padding: const EdgeInsets.fromLTRB(12, 8, 12, 0),
                child: Dismissible(
                  key: ValueKey(
                    'inapp_${msg.receivedAt.microsecondsSinceEpoch}',
                  ),
                  direction: DismissDirection.up,
                  onDismissed: (_) => _dismiss(),
                  child: Material(
                    elevation: 10,
                    shadowColor: Colors.black38,
                    borderRadius: BorderRadius.circular(16),
                    color: Colors.white,
                    child: InkWell(
                      borderRadius: BorderRadius.circular(16),
                      onTap: () {
                        final tapped = msg;
                        _dismiss();
                        widget.onMessageTapped?.call(tapped);
                      },
                      child: Builder(
                        builder: (_) {
                          final (accent, icon) =
                              _styleForType(msg.type, msg.channelId);
                          return Container(
                            padding: const EdgeInsets.symmetric(
                              horizontal: 14,
                              vertical: 12,
                            ),
                            decoration: BoxDecoration(
                              borderRadius: BorderRadius.circular(16),
                              border: Border.all(
                                color: accent.withValues(alpha: 0.45),
                                width: 1.5,
                              ),
                            ),
                            child: Row(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Container(
                                  width: 40,
                                  height: 40,
                                  decoration: BoxDecoration(
                                    color: accent.withValues(alpha: 0.14),
                                    shape: BoxShape.circle,
                                  ),
                                  child: Icon(icon, color: accent, size: 22),
                                ),
                                const SizedBox(width: 12),
                                Expanded(
                                  child: Column(
                                    crossAxisAlignment:
                                        CrossAxisAlignment.start,
                                    mainAxisSize: MainAxisSize.min,
                                    children: [
                                      Text(
                                        msg.title,
                                        maxLines: 1,
                                        overflow: TextOverflow.ellipsis,
                                        style: const TextStyle(
                                          fontWeight: FontWeight.bold,
                                          fontSize: 14,
                                          color: GoLivreurTheme.textePrincipal,
                                        ),
                                      ),
                                      if (msg.body.isNotEmpty) ...[
                                        const SizedBox(height: 3),
                                        Text(
                                          msg.body,
                                          maxLines: 3,
                                          overflow: TextOverflow.ellipsis,
                                          style: const TextStyle(
                                            fontSize: 12.5,
                                            height: 1.3,
                                            color:
                                                GoLivreurTheme.texteSecondaire,
                                          ),
                                        ),
                                      ],
                                    ],
                                  ),
                                ),
                                InkWell(
                                  borderRadius: BorderRadius.circular(20),
                                  onTap: _dismiss,
                                  child: const Padding(
                                    padding: EdgeInsets.all(4),
                                    child: Icon(
                                      Icons.close,
                                      size: 18,
                                      color: GoLivreurTheme.texteSecondaire,
                                    ),
                                  ),
                                ),
                              ],
                            ),
                          );
                        },
                      ),
                    ),
                  ),
                ),
              ),
            ),
          ),
      ],
    );
  }
}
