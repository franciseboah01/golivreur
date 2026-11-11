import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../../main.dart';
import '../../gocolis/providers/gocolis_provider.dart';
import '../../goshop/providers/goshop_provider.dart';
import '../../goshop/providers/order_provider.dart';
import '../providers/auth_provider.dart';

/// Réinitialise les données mises en cache de session et redirige proprement vers l'écran de connexion.
Future<void> deconnecterEtReinitialiserClient(
  BuildContext context,
  WidgetRef ref,
) async {
  ref.read(cartProvider.notifier).clear();
  ref.read(searchQueryProvider.notifier).state = '';
  ref.read(selectedNeighborhoodProvider.notifier).state = '';
  await ref.read(authProvider.notifier).deconnecter();
  ref.invalidate(clientAddressesProvider);
  ref.invalidate(favoritesProvider);
  ref.invalidate(clientNotificationsProvider);
  ref.invalidate(ordersHistoryProvider);
  ref.invalidate(parcelsHistoryProvider);

  if (!context.mounted) return;
  Navigator.of(context, rootNavigator: true).pushAndRemoveUntil(
    MaterialPageRoute(builder: (_) => const ClientAuthGate()),
    (route) => false,
  );
}

