import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:golivreur_core/config/app_theme.dart';
import '../../driver/providers/driver_provider.dart';

class DriverEarningsScreen extends ConsumerWidget {
  const DriverEarningsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final profile = ref.watch(driverProfileProvider);

    return Scaffold(
      backgroundColor: GoLivreurTheme.fond,
      appBar: AppBar(
        title: const Text('Mes Gains & Performances'),
        backgroundColor: Colors.white,
        elevation: 0.5,
      ),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          // ─── En-tête Badge Livreur Daloa ──────────────────────────────────
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
                  backgroundColor: GoLivreurTheme.secondaire.withValues(alpha: 0.15),
                  child: const Icon(Icons.person, color: GoLivreurTheme.secondaire, size: 32),
                ),
                const SizedBox(width: 14),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        children: [
                          Text(profile.name, style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16)),
                          const SizedBox(width: 6),
                          Container(
                            padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                            decoration: BoxDecoration(
                              color: const Color(0xFFF9A825).withValues(alpha: 0.15),
                              borderRadius: BorderRadius.circular(4),
                            ),
                            child: const Text('⭐ TOP LIVREUR', style: TextStyle(color: Color(0xFF835500), fontSize: 10, fontWeight: FontWeight.bold)),
                          ),
                        ],
                      ),
                      const SizedBox(height: 2),
                      Text('Moto : ${profile.motoPlate}', style: const TextStyle(color: GoLivreurTheme.texteSecondaire, fontSize: 12)),
                      const SizedBox(height: 2),
                      Row(
                        children: [
                          const Icon(Icons.star, color: Color(0xFFF9A825), size: 16),
                          const SizedBox(width: 4),
                          Text('${profile.averageRating} (98% satisfaction client)', style: const TextStyle(fontSize: 12, fontWeight: FontWeight.bold)),
                        ],
                      ),
                    ],
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(height: 20),

          // ─── Cartes Gains Clés ───────────────────────────────────────────
          const Text('Gains d\'activité', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 16)),
          const SizedBox(height: 10),
          Row(
            children: [
              _buildStatCard('Aujourd\'hui', '${profile.earningsToday.toInt()} F', '${profile.coursesToday} courses', GoLivreurTheme.secondaire),
              const SizedBox(width: 10),
              _buildStatCard('Cette semaine', '${profile.earningsWeek.toInt()} F', '42 courses', GoLivreurTheme.primaire),
            ],
          ),
          const SizedBox(height: 20),

          // ─── Formation & Conformité Daloa ─────────────────────────────────
          Container(
            padding: const EdgeInsets.all(14),
            decoration: BoxDecoration(
              color: Colors.white,
              borderRadius: BorderRadius.circular(14),
              border: Border.all(color: GoLivreurTheme.bordure),
            ),
            child: const Row(
              children: [
                Icon(Icons.verified, color: GoLivreurTheme.succes, size: 28),
                SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text('Formation & CNI Validées', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 14)),
                      Text('Formation présentielle 30 min effectuée au bureau de Daloa.', style: TextStyle(fontSize: 12, color: GoLivreurTheme.texteSecondaire)),
                    ],
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(height: 24),

          // ─── Historique des Courses Récentes ─────────────────────────────
          const Text('Dernières courses effectuées', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 16)),
          const SizedBox(height: 10),
          _buildCourseHistoryItem('Course GoShop — Chez Tantie Adjoua', 'Quartier Résidentiel', '+800 F CFA', '11:20'),
          _buildCourseHistoryItem('Course GoColis — Enveloppe Urgente', 'Lobia -> Kennedy', '+1 000 F CFA', '10:45'),
          _buildCourseHistoryItem('Course GoShop — Speed Burger', 'Carrefour Total', '+800 F CFA', '09:30'),
          _buildCourseHistoryItem('Course GoColis — Carton Vêtements', 'Tazibou -> Commerce', '+1 500 F CFA', 'Hier'),
        ],
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
            Text(label, style: const TextStyle(color: GoLivreurTheme.texteSecondaire, fontSize: 12)),
            const SizedBox(height: 6),
            Text(value, style: TextStyle(fontSize: 20, fontWeight: FontWeight.bold, color: color)),
            const SizedBox(height: 2),
            Text(sub, style: const TextStyle(fontSize: 11, color: GoLivreurTheme.texteSecondaire)),
          ],
        ),
      ),
    );
  }

  Widget _buildCourseHistoryItem(String title, String route, String payout, String time) {
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
                Text(title, style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13)),
                const SizedBox(height: 2),
                Text(route, style: const TextStyle(color: GoLivreurTheme.texteSecondaire, fontSize: 11)),
              ],
            ),
          ),
          Column(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              Text(payout, style: const TextStyle(fontWeight: FontWeight.bold, color: GoLivreurTheme.secondaire, fontSize: 13)),
              Text(time, style: const TextStyle(color: GoLivreurTheme.texteSecondaire, fontSize: 11)),
            ],
          ),
        ],
      ),
    );
  }
}

