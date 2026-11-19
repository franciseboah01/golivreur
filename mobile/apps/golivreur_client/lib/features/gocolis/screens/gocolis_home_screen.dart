import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:golivreur_core/config/app_theme.dart';
import 'package:golivreur_core/widgets/golivreur_widgets.dart';
import '../../goshop/providers/goshop_provider.dart';
import '../providers/gocolis_provider.dart';
import 'new_parcel_screen.dart';
import 'parcel_tracking_screen.dart';

class GoColisHomeScreen extends ConsumerWidget {
  const GoColisHomeScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final parcelsAsync = ref.watch(parcelsHistoryProvider);
    final destination = ref.watch(activeDeliveryDestinationProvider);
    final cityName = destination.cityName;

    return Scaffold(
      backgroundColor: GoLivreurTheme.fond,
      appBar: AppBar(
        backgroundColor: Colors.white,
        elevation: 0.5,
        title: Row(
          children: [
            const Icon(Icons.inventory_2, color: GoLivreurTheme.primaire),
            const SizedBox(width: 8),
            Text(
              cityName.isNotEmpty ? 'Colis $cityName' : 'Colis',
              style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 18),
            ),
          ],
        ),
      ),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            // ─── Bannière GoColis ──────────────────────────────────────────
            Container(
              width: double.infinity,
              padding: const EdgeInsets.all(20),
              decoration: BoxDecoration(
                gradient: const LinearGradient(
                  colors: [Color(0xFF1B5E20), Color(0xFF2E7D32)],
                  begin: Alignment.topLeft,
                  end: Alignment.bottomRight,
                ),
                borderRadius: BorderRadius.circular(16),
                boxShadow: [
                  BoxShadow(
                    color: GoLivreurTheme.secondaire.withValues(alpha: 0.3),
                    blurRadius: 10,
                    offset: const Offset(0, 4),
                  ),
                ],
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                    decoration: BoxDecoration(
                      color: Colors.white.withValues(alpha: 0.2),
                      borderRadius: BorderRadius.circular(6),
                    ),
                    child: Text(
                      cityName.isNotEmpty
                          ? '📦 COURSIER EXPRESS ${cityName.toUpperCase()}'
                          : '📦 COURSIER EXPRESS',
                      style: const TextStyle(
                        color: Colors.white,
                        fontWeight: FontWeight.bold,
                        fontSize: 11,
                      ),
                    ),
                  ),
                  const SizedBox(height: 8),
                  Text(
                    cityName.isNotEmpty
                        ? 'Expédiez un colis partout à $cityName en 30 minutes'
                        : 'Expédiez un colis dans votre ville en 30 minutes',
                    style: const TextStyle(
                      color: Colors.white,
                      fontSize: 18,
                      fontWeight: FontWeight.bold,
                    ),
                  ),
                  const SizedBox(height: 6),
                  const Text(
                    'Pli confidentiel, vêtements, pièces ou cartons. Prise en charge avec photo scellée et code de sécurité.',
                    style: TextStyle(color: Colors.white70, fontSize: 13),
                  ),
                  const SizedBox(height: 16),
                  SizedBox(
                    height: 48,
                    child: ElevatedButton.icon(
                      style: ElevatedButton.styleFrom(
                        backgroundColor: GoLivreurTheme.primaire,
                        foregroundColor: Colors.white,
                        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                        elevation: 2,
                      ),
                      onPressed: () {
                        Navigator.of(context).push(
                          MaterialPageRoute(builder: (_) => const NewParcelScreen()),
                        );
                      },
                      icon: const Icon(Icons.add_box, size: 20),
                      label: const Text(
                        'Nouvelle expédition',
                        style: TextStyle(fontWeight: FontWeight.bold, fontSize: 15),
                      ),
                    ),
                  ),
                ],
              ),
            ),
            const SizedBox(height: 24),

            // ─── Tarifs & Formats Transparents ──────────────────────────────
            const Text(
              'Nos Formats & Tarifs Fixes',
              style: TextStyle(
                fontSize: 16,
                fontWeight: FontWeight.bold,
                color: GoLivreurTheme.textePrincipal,
              ),
            ),
            const SizedBox(height: 10),
            Row(
              children: [
                _buildSizeCard('Petit', '500 F', 'Documents, clés, enveloppes', Icons.markunread_mailbox),
                const SizedBox(width: 8),
                _buildSizeCard('Moyen', '1 000 F', 'Boîte, chaussures, habits', Icons.inventory_2),
                const SizedBox(width: 8),
                _buildSizeCard('Grand', '1 500 F', 'Carton, électro, sac riz', Icons.all_inbox),
              ],
            ),
            const SizedBox(height: 24),

            // ─── Expéditions en cours & Historique ───────────────────────────
            const Text(
              'Mes Expéditions GoColis',
              style: TextStyle(
                fontSize: 16,
                fontWeight: FontWeight.bold,
                color: GoLivreurTheme.textePrincipal,
              ),
            ),
            const SizedBox(height: 12),
            parcelsAsync.when(
              data: (parcels) {
                if (parcels.isEmpty) {
                  return Container(
                    padding: const EdgeInsets.all(24),
                    alignment: Alignment.center,
                    decoration: BoxDecoration(
                      color: Colors.white,
                      borderRadius: BorderRadius.circular(12),
                    ),
                    child: const Text(
                      'Aucune expédition en cours.',
                      style: TextStyle(color: GoLivreurTheme.texteSecondaire),
                    ),
                  );
                }

                return ListView.separated(
                  shrinkWrap: true,
                  physics: const NeverScrollableScrollPhysics(),
                  itemCount: parcels.length,
                  separatorBuilder: (_, __) => const SizedBox(height: 10),
                  itemBuilder: (context, index) {
                    final parcel = parcels[index];
                    return InkWell(
                      onTap: () {
                        Navigator.of(context).push(
                          MaterialPageRoute(
                            builder: (_) => ParcelTrackingScreen(parcelId: parcel.id),
                          ),
                        );
                      },
                      borderRadius: BorderRadius.circular(14),
                      child: Container(
                        padding: const EdgeInsets.all(14),
                        decoration: BoxDecoration(
                          color: Colors.white,
                          borderRadius: BorderRadius.circular(14),
                          border: Border.all(color: GoLivreurTheme.bordure),
                        ),
                        child: Row(
                          children: [
                            Container(
                              width: 44,
                              height: 44,
                              decoration: BoxDecoration(
                                color: GoLivreurTheme.secondaire.withValues(alpha: 0.1),
                                borderRadius: BorderRadius.circular(10),
                              ),
                              child: const Icon(Icons.local_shipping, color: GoLivreurTheme.secondaire),
                            ),
                            const SizedBox(width: 12),
                            Expanded(
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Row(
                                    children: [
                                      Text(
                                        'Colis #${parcel.id}',
                                        style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14),
                                      ),
                                      const SizedBox(width: 8),
                                      Container(
                                        padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                                        decoration: BoxDecoration(
                                          color: const Color(0xFFE8F1F8),
                                          borderRadius: BorderRadius.circular(4),
                                        ),
                                        child: Text(
                                          parcel.labelStatut,
                                          style: const TextStyle(
                                            color: Color(0xFF2E75B6),
                                            fontSize: 11,
                                            fontWeight: FontWeight.bold,
                                          ),
                                        ),
                                      ),
                                    ],
                                  ),
                                  const SizedBox(height: 4),
                                  Text(
                                    'Pour : ${parcel.recipientName} (${parcel.deliveryAddress})',
                                    style: const TextStyle(fontSize: 12, color: GoLivreurTheme.texteSecondaire),
                                    maxLines: 1,
                                    overflow: TextOverflow.ellipsis,
                                  ),
                                ],
                              ),
                            ),
                            const Icon(Icons.chevron_right, color: GoLivreurTheme.texteSecondaire),
                          ],
                        ),
                      ),
                    );
                  },
                );
              },
              loading: () => const GoLivreurListSkeleton(count: 3, itemHeight: 88),
              error: (err, _) => GoLivreurErrorView.fromException(
                error: err,
                onRetry: () => ref.invalidate(parcelsHistoryProvider),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildSizeCard(String title, String price, String desc, IconData icon) {
    return Expanded(
      child: Container(
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(12),
          border: Border.all(color: GoLivreurTheme.bordure),
        ),
        child: Column(
          children: [
            Icon(icon, color: GoLivreurTheme.primaire, size: 28),
            const SizedBox(height: 6),
            Text(title, style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13)),
            const SizedBox(height: 2),
            Text(
              price,
              style: const TextStyle(
                fontWeight: FontWeight.bold,
                color: GoLivreurTheme.secondaire,
                fontSize: 14,
              ),
            ),
            const SizedBox(height: 4),
            Text(
              desc,
              textAlign: TextAlign.center,
              style: const TextStyle(fontSize: 10, color: GoLivreurTheme.texteSecondaire),
              maxLines: 2,
            ),
          ],
        ),
      ),
    );
  }
}

