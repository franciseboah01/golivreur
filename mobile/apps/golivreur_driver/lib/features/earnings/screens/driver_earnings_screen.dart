import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:golivreur_core/config/app_theme.dart';
import '../../driver/providers/driver_provider.dart';

class DriverEarningsScreen extends ConsumerWidget {
  const DriverEarningsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final profile = ref.watch(driverProfileProvider);
    final cityLabel = (profile.cityName != null && profile.cityName!.isNotEmpty)
        ? profile.cityName!
        : 'votre ville';
    final isTopDriver = profile.averageRating >= 4.5 && profile.totalRatings >= 5;

    return Scaffold(
      backgroundColor: GoLivreurTheme.fond,
      appBar: AppBar(
        title: const Text('Mes Gains & Performances'),
        backgroundColor: Colors.white,
        elevation: 0.5,
      ),
      body: RefreshIndicator(
        color: GoLivreurTheme.secondaire,
        onRefresh: () async {
          await Future.wait([
            ref.read(driverProfileProvider.notifier).chargerProfil(),
            ref.read(driverProfileProvider.notifier).chargerPortefeuille(),
          ]);
        },
        child: ListView(
          physics: const AlwaysScrollableScrollPhysics(),
          padding: const EdgeInsets.all(16),
          children: [
            // ─── En-tête Badge Livreur ────────────────────────────────────────
            Container(
              padding: const EdgeInsets.all(16),
              decoration: BoxDecoration(
                color: Colors.white,
                borderRadius: BorderRadius.circular(16),
                border: Border.all(color: GoLivreurTheme.bordure),
              ),
              child: Row(
                children: [
                  CircleAvatar(
                    radius: 28,
                    backgroundColor:
                        GoLivreurTheme.secondaire.withValues(alpha: 0.15),
                    child: const Icon(
                      Icons.person,
                      color: GoLivreurTheme.secondaire,
                      size: 32,
                    ),
                  ),
                  const SizedBox(width: 14),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Row(
                          children: [
                            Flexible(
                              child: Text(
                                profile.name.isNotEmpty
                                    ? profile.name
                                    : 'Livreur GoLivreur',
                                style: const TextStyle(
                                  fontWeight: FontWeight.bold,
                                  fontSize: 16,
                                ),
                                overflow: TextOverflow.ellipsis,
                              ),
                            ),
                            if (isTopDriver) ...[
                              const SizedBox(width: 6),
                              Container(
                                padding: const EdgeInsets.symmetric(
                                  horizontal: 6,
                                  vertical: 2,
                                ),
                                decoration: BoxDecoration(
                                  color: const Color(0xFFF9A825)
                                      .withValues(alpha: 0.15),
                                  borderRadius: BorderRadius.circular(4),
                                ),
                                child: const Text(
                                  '⭐ TOP LIVREUR',
                                  style: TextStyle(
                                    color: Color(0xFF835500),
                                    fontSize: 10,
                                    fontWeight: FontWeight.bold,
                                  ),
                                ),
                              ),
                            ],
                          ],
                        ),
                        const SizedBox(height: 2),
                        Text(
                          profile.motoPlate.isNotEmpty
                              ? '${profile.vehicleTypeName.isNotEmpty ? profile.vehicleTypeName : "Véhicule"} : ${profile.motoPlate}'
                              : (profile.vehicleTypeName.isNotEmpty
                                  ? profile.vehicleTypeName
                                  : 'Véhicule partenaire'),
                          style: const TextStyle(
                            color: GoLivreurTheme.texteSecondaire,
                            fontSize: 12,
                          ),
                        ),
                        const SizedBox(height: 2),
                        Row(
                          children: [
                            const Icon(
                              Icons.star,
                              color: Color(0xFFF9A825),
                              size: 16,
                            ),
                            const SizedBox(width: 4),
                            Text(
                              profile.totalRatings > 0
                                  ? '${profile.averageRating.toStringAsFixed(1)} (${profile.totalRatings} avis)'
                                  : 'Nouveau livreur • Aucun avis',
                              style: const TextStyle(
                                fontSize: 12,
                                fontWeight: FontWeight.bold,
                              ),
                            ),
                          ],
                        ),
                      ],
                    ),
                  ),
                ],
              ),
            ),
            const SizedBox(height: 20),

            // ─── Cartes Gains Clés ────────────────────────────────────────────
            const Text(
              'Gains d\'activité',
              style: TextStyle(fontWeight: FontWeight.bold, fontSize: 16),
            ),
            const SizedBox(height: 10),
            Row(
              children: [
                _buildStatCard(
                  'Aujourd\'hui',
                  '${profile.earningsToday.toInt()} F',
                  '${profile.coursesToday} course${profile.coursesToday > 1 ? "s" : ""}',
                  GoLivreurTheme.secondaire,
                ),
                const SizedBox(width: 10),
                _buildStatCard(
                  'Cette semaine',
                  '${profile.earningsWeek.toInt()} F',
                  '${profile.coursesWeek} course${profile.coursesWeek > 1 ? "s" : ""}',
                  GoLivreurTheme.primaire,
                ),
              ],
            ),
            const SizedBox(height: 20),

            // ─── Statut de conformité ─────────────────────────────────────────
            Container(
              padding: const EdgeInsets.all(14),
              decoration: BoxDecoration(
                color: Colors.white,
                borderRadius: BorderRadius.circular(14),
                border: Border.all(color: GoLivreurTheme.bordure),
              ),
              child: Row(
                children: [
                  const Icon(
                    Icons.verified,
                    color: GoLivreurTheme.succes,
                    size: 28,
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        const Text(
                          'Compte Livreur Validé',
                          style: TextStyle(
                            fontWeight: FontWeight.bold,
                            fontSize: 14,
                          ),
                        ),
                        Text(
                          'Dossier vérifié par l\'agence GoLivreur ($cityLabel).',
                          style: const TextStyle(
                            fontSize: 12,
                            color: GoLivreurTheme.texteSecondaire,
                          ),
                        ),
                      ],
                    ),
                  ),
                ],
              ),
            ),
            const SizedBox(height: 24),

            // ─── Historique des Courses Récentes ──────────────────────────────
            const Text(
              'Dernières courses effectuées',
              style: TextStyle(fontWeight: FontWeight.bold, fontSize: 16),
            ),
            const SizedBox(height: 10),
            if (profile.recentCourses.isEmpty)
              Container(
                padding: const EdgeInsets.all(24),
                decoration: BoxDecoration(
                  color: Colors.white,
                  borderRadius: BorderRadius.circular(14),
                  border: Border.all(color: GoLivreurTheme.bordure),
                ),
                child: const Column(
                  children: [
                    Icon(
                      Icons.local_shipping_outlined,
                      size: 40,
                      color: GoLivreurTheme.texteSecondaire,
                    ),
                    SizedBox(height: 10),
                    Text(
                      'Aucune course effectuée pour le moment',
                      style: TextStyle(
                        fontWeight: FontWeight.bold,
                        fontSize: 14,
                        color: GoLivreurTheme.textePrincipal,
                      ),
                    ),
                    SizedBox(height: 4),
                    Text(
                      'Passez en ligne dans l\'onglet Courses pour recevoir vos premières livraisons.',
                      textAlign: TextAlign.center,
                      style: TextStyle(
                        fontSize: 12,
                        color: GoLivreurTheme.texteSecondaire,
                      ),
                    ),
                  ],
                ),
              )
            else
              ...profile.recentCourses.map(
                (course) => _buildCourseHistoryItem(
                  course.type == 'gocolis'
                      ? 'GoColis — ${course.title}'
                      : 'GoShop — ${course.title}',
                  course.route,
                  '+${course.amount.toInt()} F CFA',
                  course.time,
                ),
              ),
          ],
        ),
      ),
    );
  }

  Widget _buildStatCard(String label, String value, String sub, Color color) {
    return Expanded(
      child: Container(
        padding: const EdgeInsets.all(14),
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(14),
          border: Border.all(color: GoLivreurTheme.bordure),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              label,
              style: const TextStyle(
                color: GoLivreurTheme.texteSecondaire,
                fontSize: 12,
              ),
            ),
            const SizedBox(height: 6),
            Text(
              value,
              style: TextStyle(
                fontSize: 20,
                fontWeight: FontWeight.bold,
                color: color,
              ),
            ),
            const SizedBox(height: 2),
            Text(
              sub,
              style: const TextStyle(
                fontSize: 11,
                color: GoLivreurTheme.texteSecondaire,
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildCourseHistoryItem(
    String title,
    String route,
    String payout,
    String time,
  ) {
    return Container(
      margin: const EdgeInsets.only(bottom: 8),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: GoLivreurTheme.bordure),
      ),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  title,
                  style: const TextStyle(
                    fontWeight: FontWeight.bold,
                    fontSize: 13,
                  ),
                ),
                if (route.isNotEmpty) ...[
                  const SizedBox(height: 2),
                  Text(
                    route,
                    style: const TextStyle(
                      color: GoLivreurTheme.texteSecondaire,
                      fontSize: 11,
                    ),
                  ),
                ],
              ],
            ),
          ),
          Column(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              Text(
                payout,
                style: const TextStyle(
                  fontWeight: FontWeight.bold,
                  color: GoLivreurTheme.secondaire,
                  fontSize: 13,
                ),
              ),
              Text(
                time,
                style: const TextStyle(
                  color: GoLivreurTheme.texteSecondaire,
                  fontSize: 11,
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }
}
