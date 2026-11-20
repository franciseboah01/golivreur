import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:golivreur_core/config/app_theme.dart';
import '../../business/providers/business_provider.dart';

class BusinessRevenueScreen extends ConsumerWidget {
  const BusinessRevenueScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final profile = ref.watch(businessProfileProvider);
    final stats = ref.watch(revenueStatsProvider);
    final citySuffix = (profile.cityName != null && profile.cityName!.isNotEmpty)
        ? ' à ${profile.cityName}'
        : '';

    return Scaffold(
      backgroundColor: GoLivreurTheme.fond,
      appBar: AppBar(
        title: const Text('Revenus & Performances'),
        backgroundColor: Colors.white,
        elevation: 0.5,
        actions: [
          IconButton(
            tooltip: 'Actualiser le solde',
            icon: const Icon(Icons.refresh, color: Color(0xFF2E75B6)),
            onPressed: () async {
              await Future.wait([
                ref.read(businessProfileProvider.notifier).chargerProfil(),
                ref.read(revenueStatsProvider.notifier).chargerRevenus(),
              ]);
            },
          ),
        ],
      ),
      body: RefreshIndicator(
        color: const Color(0xFF2E75B6),
        onRefresh: () async {
          await Future.wait([
            ref.read(businessProfileProvider.notifier).chargerProfil(),
            ref.read(revenueStatsProvider.notifier).chargerRevenus(),
          ]);
        },
        child: ListView(
          physics: const AlwaysScrollableScrollPhysics(),
          padding: const EdgeInsets.all(16),
          children: [
            Container(
              padding: const EdgeInsets.all(18),
              decoration: BoxDecoration(
                gradient: const LinearGradient(
                  colors: [Color(0xFF2E75B6), Color(0xFF1B4D7E)],
                  begin: Alignment.topLeft,
                  end: Alignment.bottomRight,
                ),
                borderRadius: BorderRadius.circular(16),
                boxShadow: [
                  BoxShadow(
                    color: const Color(0xFF2E75B6).withValues(alpha: 0.3),
                    blurRadius: 10,
                    offset: const Offset(0, 4),
                  ),
                ],
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      const Row(
                        children: [
                          Icon(
                            Icons.account_balance_wallet,
                            color: Colors.white70,
                            size: 20,
                          ),
                          SizedBox(width: 8),
                          Text(
                            'Solde disponible',
                            style: TextStyle(
                              color: Colors.white70,
                              fontSize: 13,
                              fontWeight: FontWeight.w500,
                            ),
                          ),
                        ],
                      ),
                      Container(
                        padding: const EdgeInsets.symmetric(
                          horizontal: 8,
                          vertical: 3,
                        ),
                        decoration: BoxDecoration(
                          color: Colors.white.withValues(alpha: 0.2),
                          borderRadius: BorderRadius.circular(6),
                        ),
                        child: const Text(
                          '⚡ En temps réel',
                          style: TextStyle(
                            color: Colors.white,
                            fontSize: 11,
                            fontWeight: FontWeight.bold,
                          ),
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 10),
                  Text(
                    '${profile.walletBalance.toInt()} F CFA',
                    style: const TextStyle(
                      color: Colors.white,
                      fontSize: 28,
                      fontWeight: FontWeight.bold,
                      letterSpacing: 0.5,
                    ),
                  ),
                  const SizedBox(height: 8),
                  Container(
                    padding: const EdgeInsets.symmetric(
                      horizontal: 10,
                      vertical: 6,
                    ),
                    decoration: BoxDecoration(
                      color: Colors.black.withValues(alpha: 0.15),
                      borderRadius: BorderRadius.circular(8),
                    ),
                    child: const Row(
                      children: [
                        Icon(
                          Icons.phone_android,
                          color: Colors.white70,
                          size: 16,
                        ),
                        SizedBox(width: 6),
                        Expanded(
                          child: Text(
                            'Espèces en agence, Wave, Orange Money & MTN MoMo',
                            style: TextStyle(
                              color: Colors.white70,
                              fontSize: 12,
                            ),
                          ),
                        ),
                      ],
                    ),
                  ),
                  const SizedBox(height: 16),
                  SizedBox(
                    width: double.infinity,
                    height: 52,
                    child: ElevatedButton.icon(
                      style: ElevatedButton.styleFrom(
                        backgroundColor: GoLivreurTheme.primaire,
                        foregroundColor: Colors.white,
                        shape: RoundedRectangleBorder(
                          borderRadius: BorderRadius.circular(12),
                        ),
                        elevation: 2,
                      ),
                      onPressed: () =>
                          _showDisbursementInfo(context, profile),
                      icon: const Icon(Icons.payments, size: 20),
                      label: const Text(
                        'Demander un versement Mobile Money',
                        style: TextStyle(
                          fontWeight: FontWeight.bold,
                          fontSize: 15,
                        ),
                      ),
                    ),
                  ),
                ],
              ),
            ),
            const SizedBox(height: 20),
            Container(
              padding: const EdgeInsets.all(14),
              decoration: BoxDecoration(
                color: Colors.white,
                borderRadius: BorderRadius.circular(14),
                border: Border.all(color: GoLivreurTheme.bordure),
              ),
              child: Row(
                children: [
                  Container(
                    padding: const EdgeInsets.all(10),
                    decoration: BoxDecoration(
                      color: GoLivreurTheme.primaire.withValues(alpha: 0.12),
                      borderRadius: BorderRadius.circular(10),
                    ),
                    child: const Icon(
                      Icons.star,
                      color: Color(0xFFF9A825),
                      size: 28,
                    ),
                  ),
                  const SizedBox(width: 14),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          profile.reviewCount > 0
                              ? '${profile.rating.toStringAsFixed(1)} / 5'
                              : 'Nouveau commerce',
                          style: const TextStyle(
                            fontWeight: FontWeight.bold,
                            fontSize: 17,
                          ),
                        ),
                        const SizedBox(height: 2),
                        Text(
                          profile.reviewCount > 0
                              ? '${profile.reviewCount} avis client${profile.reviewCount > 1 ? "s" : ""} certifié${profile.reviewCount > 1 ? "s" : ""}$citySuffix'
                              : 'Aucun avis client enregistré pour le moment',
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
            const SizedBox(height: 20),
            const Text(
              'Chiffre d\'Affaires & Activité',
              style: TextStyle(
                fontSize: 16,
                fontWeight: FontWeight.bold,
                color: GoLivreurTheme.textePrincipal,
              ),
            ),
            const SizedBox(height: 10),
            Row(
              children: [
                _buildMetricCard(
                  title: 'Aujourd\'hui',
                  amount: '${stats.caAujourdhui.toInt()} F',
                  orders:
                      '${stats.commandesAujourdhui} commande${stats.commandesAujourdhui > 1 ? "s" : ""}',
                ),
                const SizedBox(width: 10),
                _buildMetricCard(
                  title: 'Cette semaine',
                  amount: '${stats.caSemaine.toInt()} F',
                  orders:
                      '${stats.commandesSemaine} commande${stats.commandesSemaine > 1 ? "s" : ""}',
                ),
              ],
            ),
            const SizedBox(height: 10),
            Container(
              padding: const EdgeInsets.all(14),
              decoration: BoxDecoration(
                color: Colors.white,
                borderRadius: BorderRadius.circular(14),
                border: Border.all(color: GoLivreurTheme.bordure),
              ),
              child: Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      const Text(
                        'Ce mois-ci',
                        style: TextStyle(
                          color: GoLivreurTheme.texteSecondaire,
                          fontSize: 13,
                        ),
                      ),
                      const SizedBox(height: 4),
                      Text(
                        '${stats.caMois.toInt()} F CFA',
                        style: const TextStyle(
                          fontSize: 18,
                          fontWeight: FontWeight.bold,
                          color: GoLivreurTheme.textePrincipal,
                        ),
                      ),
                    ],
                  ),
                  Text(
                    '${stats.commandesMois} livrée${stats.commandesMois > 1 ? "s" : ""}',
                    style: const TextStyle(
                      color: GoLivreurTheme.secondaire,
                      fontWeight: FontWeight.bold,
                      fontSize: 13,
                    ),
                  ),
                ],
              ),
            ),
            const SizedBox(height: 24),
            const Text(
              'Temps de Préparation & Horaires',
              style: TextStyle(
                fontSize: 16,
                fontWeight: FontWeight.bold,
                color: GoLivreurTheme.textePrincipal,
              ),
            ),
            const SizedBox(height: 10),
            Container(
              padding: const EdgeInsets.all(16),
              decoration: BoxDecoration(
                color: Colors.white,
                borderRadius: BorderRadius.circular(14),
                border: Border.all(color: GoLivreurTheme.bordure),
              ),
              child: Column(
                children: [
                  Row(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      const Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            'Temps moyen de préparation',
                            style: TextStyle(
                              fontWeight: FontWeight.bold,
                              fontSize: 14,
                            ),
                          ),
                          Text(
                            'Affiché aux clients sur GoShop',
                            style: TextStyle(
                              fontSize: 12,
                              color: GoLivreurTheme.texteSecondaire,
                            ),
                          ),
                        ],
                      ),
                      DropdownButton<int>(
                        value: [10, 15, 20, 25, 30, 40]
                                .contains(profile.prepTimeMinutes)
                            ? profile.prepTimeMinutes
                            : 20,
                        items: [10, 15, 20, 25, 30, 40].map((m) {
                          return DropdownMenuItem<int>(
                            value: m,
                            child: Text(
                              '$m min',
                              style:
                                  const TextStyle(fontWeight: FontWeight.bold),
                            ),
                          );
                        }).toList(),
                        onChanged: (val) {
                          if (val != null) {
                            ref
                                .read(businessProfileProvider.notifier)
                                .updatePrepTime(val);
                          }
                        },
                      ),
                    ],
                  ),
                  const Divider(height: 20),
                  Row(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      const Text(
                        'Horaires d\'ouverture',
                        style: TextStyle(
                          fontWeight: FontWeight.w600,
                          fontSize: 13,
                        ),
                      ),
                      Flexible(
                        child: Text(
                          profile.openHoursText.isNotEmpty
                              ? profile.openHoursText
                              : 'Non renseignés',
                          textAlign: TextAlign.end,
                          style: const TextStyle(
                            color: GoLivreurTheme.texteSecondaire,
                            fontSize: 13,
                          ),
                        ),
                      ),
                    ],
                  ),
                ],
              ),
            ),
            const SizedBox(height: 24),
            const Text(
              'Mouvements Portefeuille & Versements',
              style: TextStyle(
                fontSize: 16,
                fontWeight: FontWeight.bold,
                color: GoLivreurTheme.textePrincipal,
              ),
            ),
            const SizedBox(height: 10),
            if (stats.versementsRecents.isEmpty)
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
                      Icons.payments_outlined,
                      size: 38,
                      color: GoLivreurTheme.texteSecondaire,
                    ),
                    SizedBox(height: 10),
                    Text(
                      'Aucun mouvement enregistré pour le moment',
                      style: TextStyle(
                        fontWeight: FontWeight.bold,
                        fontSize: 14,
                        color: GoLivreurTheme.textePrincipal,
                      ),
                    ),
                    SizedBox(height: 4),
                    Text(
                      'Vos dépôts espèces en agence, retraits et versements Mobile Money apparaîtront ici en temps réel.',
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
              ...stats.versementsRecents.map(
                (v) {
                  final isCredit = v.type == 'credit';
                  final accent = isCredit
                      ? GoLivreurTheme.succes
                      : GoLivreurTheme.erreur;
                  return Container(
                    margin: const EdgeInsets.only(bottom: 8),
                    padding: const EdgeInsets.all(12),
                    decoration: BoxDecoration(
                      color: Colors.white,
                      borderRadius: BorderRadius.circular(12),
                      border: Border.all(color: GoLivreurTheme.bordure),
                    ),
                    child: Row(
                      children: [
                        Container(
                          width: 36,
                          height: 36,
                          decoration: BoxDecoration(
                            color: accent.withValues(alpha: 0.12),
                            shape: BoxShape.circle,
                          ),
                          child: Icon(
                            isCredit
                                ? Icons.arrow_downward
                                : Icons.arrow_upward,
                            color: accent,
                            size: 18,
                          ),
                        ),
                        const SizedBox(width: 12),
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(
                                '${isCredit ? "+" : "-"}${v.amount.toInt()} F CFA',
                                style: TextStyle(
                                  fontWeight: FontWeight.bold,
                                  fontSize: 15,
                                  color: accent,
                                ),
                              ),
                              Text(
                                v.provider,
                                style: const TextStyle(
                                  fontSize: 12,
                                  color: GoLivreurTheme.textePrincipal,
                                ),
                              ),
                              if (v.date.isNotEmpty)
                                Text(
                                  v.date,
                                  style: const TextStyle(
                                    fontSize: 11,
                                    color: GoLivreurTheme.texteSecondaire,
                                  ),
                                ),
                            ],
                          ),
                        ),
                        const SizedBox(width: 8),
                        Container(
                          padding: const EdgeInsets.symmetric(
                            horizontal: 8,
                            vertical: 3,
                          ),
                          decoration: BoxDecoration(
                            color: GoLivreurTheme.succes.withValues(alpha: 0.1),
                            borderRadius: BorderRadius.circular(6),
                          ),
                          child: Text(
                            v.status,
                            style: const TextStyle(
                              color: GoLivreurTheme.succes,
                              fontSize: 11,
                              fontWeight: FontWeight.bold,
                            ),
                          ),
                        ),
                      ],
                    ),
                  );
                },
              ),
            const SizedBox(height: 20),
          ],
        ),
      ),
    );
  }

  Widget _buildMetricCard({
    required String title,
    required String amount,
    required String orders,
  }) {
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
              title,
              style: const TextStyle(
                color: GoLivreurTheme.texteSecondaire,
                fontSize: 12,
              ),
            ),
            const SizedBox(height: 6),
            Text(
              amount,
              style: const TextStyle(
                fontSize: 18,
                fontWeight: FontWeight.bold,
                color: GoLivreurTheme.textePrincipal,
              ),
            ),
            const SizedBox(height: 2),
            Text(
              orders,
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

  void _showDisbursementInfo(
    BuildContext context,
    BusinessProfileState profile,
  ) {
    if (profile.walletBalance <= 0) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text(
            'Aucun solde disponible à reverser pour le moment.',
          ),
        ),
      );
      return;
    }

    final citySuffix = (profile.cityName != null && profile.cityName!.isNotEmpty)
        ? ' (${profile.cityName})'
        : '';
    showModalBottomSheet(
      context: context,
      backgroundColor: Colors.white,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
      builder: (sheetCtx) {
        return Padding(
          padding: const EdgeInsets.all(20),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const Text(
                'Versement Mobile Money',
                style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold),
              ),
              const SizedBox(height: 8),
              Text(
                'Solde disponible : ${profile.walletBalance.toInt()} F CFA',
                style: const TextStyle(
                  color: Color(0xFF2E75B6),
                  fontWeight: FontWeight.bold,
                  fontSize: 14,
                ),
              ),
              const SizedBox(height: 10),
              Text(
                'Les reversements commerciaux (Wave, Orange Money, MTN MoMo) sont validés par le service finance de votre agence GoLivreur$citySuffix et crédités sur votre numéro professionnel.',
                style: const TextStyle(
                  color: GoLivreurTheme.texteSecondaire,
                  fontSize: 13,
                  height: 1.4,
                ),
              ),
              const SizedBox(height: 20),
              SizedBox(
                width: double.infinity,
                height: 48,
                child: ElevatedButton(
                  style: ElevatedButton.styleFrom(
                    backgroundColor: const Color(0xFF2E75B6),
                    foregroundColor: Colors.white,
                    shape: RoundedRectangleBorder(
                      borderRadius: BorderRadius.circular(12),
                    ),
                  ),
                  onPressed: () => Navigator.pop(sheetCtx),
                  child: const Text(
                    'Compris',
                    style: TextStyle(fontWeight: FontWeight.bold),
                  ),
                ),
              ),
            ],
          ),
        );
      },
    );
  }
}
