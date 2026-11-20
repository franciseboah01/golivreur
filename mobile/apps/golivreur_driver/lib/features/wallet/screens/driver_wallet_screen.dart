import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:golivreur_core/config/app_theme.dart';
import '../../driver/providers/driver_provider.dart';

class DriverWalletScreen extends ConsumerWidget {
  const DriverWalletScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final profile = ref.watch(driverProfileProvider);
    final transactions = ref.watch(driverTransactionsProvider);

    return Scaffold(
      backgroundColor: GoLivreurTheme.fond,
      appBar: AppBar(
        title: const Text('Mon Portefeuille Virtuel'),
        backgroundColor: Colors.white,
        elevation: 0.5,
        actions: [
          IconButton(
            tooltip: 'Actualiser le solde',
            icon: const Icon(Icons.refresh, color: GoLivreurTheme.secondaire),
            onPressed: () =>
                ref.read(driverProfileProvider.notifier).chargerPortefeuille(),
          ),
        ],
      ),
      body: RefreshIndicator(
        color: GoLivreurTheme.secondaire,
        onRefresh: () =>
            ref.read(driverProfileProvider.notifier).chargerPortefeuille(),
        child: ListView(
          physics: const AlwaysScrollableScrollPhysics(),
          padding: const EdgeInsets.all(16),
          children: [
            // ─── Carte Solde Portefeuille ─────────────────────────────────────
            Container(
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
                  Row(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      const Text(
                        'Solde disponible',
                        style: TextStyle(color: Colors.white70, fontSize: 13),
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
                          '🏍️ Livreur Actif',
                          style: TextStyle(
                            color: Colors.white,
                            fontSize: 11,
                            fontWeight: FontWeight.bold,
                          ),
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 8),
                  Text(
                    '${profile.walletBalance.toInt()} F CFA',
                    style: const TextStyle(
                      color: Colors.white,
                      fontSize: 32,
                      fontWeight: FontWeight.bold,
                    ),
                  ),
                  const SizedBox(height: 4),
                  const Text(
                    'Seuil minimum de retrait : 2 000 F CFA',
                    style: TextStyle(color: Colors.white70, fontSize: 11),
                  ),
                  const SizedBox(height: 16),
                  Row(
                    children: [
                      Expanded(
                        child: SizedBox(
                          height: 48,
                          child: ElevatedButton.icon(
                            style: ElevatedButton.styleFrom(
                              backgroundColor: GoLivreurTheme.primaire,
                              foregroundColor: Colors.white,
                              shape: RoundedRectangleBorder(
                                borderRadius: BorderRadius.circular(10),
                              ),
                            ),
                            onPressed: () => _showAgencyInfoSheet(
                              context,
                              title: 'Recharger mon portefeuille',
                              description:
                                  'Pour créditer votre portefeuille livreur (Wave, Orange Money, MTN ou espèces), rapprochez-vous de votre agence GoLivreur. Votre solde est mis à jour automatiquement après validation.',
                              cityName: profile.cityName,
                            ),
                            icon: const Icon(Icons.add, size: 18),
                            label: const Text(
                              'Recharger',
                              style: TextStyle(fontWeight: FontWeight.bold),
                            ),
                          ),
                        ),
                      ),
                      const SizedBox(width: 10),
                      Expanded(
                        child: SizedBox(
                          height: 48,
                          child: OutlinedButton.icon(
                            style: OutlinedButton.styleFrom(
                              foregroundColor: Colors.white,
                              side: const BorderSide(
                                color: Colors.white,
                                width: 1.5,
                              ),
                              shape: RoundedRectangleBorder(
                                borderRadius: BorderRadius.circular(10),
                              ),
                            ),
                            onPressed: () {
                              if (profile.walletBalance < 2000) {
                                ScaffoldMessenger.of(context).showSnackBar(
                                  const SnackBar(
                                    content: Text(
                                      'Votre solde doit atteindre au moins 2 000 F CFA pour effectuer un retrait.',
                                    ),
                                  ),
                                );
                                return;
                              }
                              _showAgencyInfoSheet(
                                context,
                                title: 'Retirer mes gains',
                                description:
                                    'Les retraits Mobile Money (Wave, Orange Money, MTN) sont traités par la caisse de votre agence GoLivreur. Contactez votre responsable d\'agence pour déclencher le versement.',
                                cityName: profile.cityName,
                              );
                            },
                            icon: const Icon(Icons.arrow_upward, size: 18),
                            label: const Text(
                              'Retirer',
                              style: TextStyle(fontWeight: FontWeight.bold),
                            ),
                          ),
                        ),
                      ),
                    ],
                  ),
                ],
              ),
            ),
            const SizedBox(height: 24),

            // ─── Historique des Transactions ──────────────────────────────────
            const Text(
              'Historique des Transactions',
              style: TextStyle(
                fontSize: 16,
                fontWeight: FontWeight.bold,
                color: GoLivreurTheme.textePrincipal,
              ),
            ),
            const SizedBox(height: 10),
            if (transactions.isEmpty)
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
                      Icons.receipt_long_outlined,
                      size: 40,
                      color: GoLivreurTheme.texteSecondaire,
                    ),
                    SizedBox(height: 10),
                    Text(
                      'Aucune transaction pour le moment',
                      style: TextStyle(
                        fontWeight: FontWeight.bold,
                        fontSize: 14,
                        color: GoLivreurTheme.textePrincipal,
                      ),
                    ),
                    SizedBox(height: 4),
                    Text(
                      'Vos gains de livraison, recharges et retraits apparaîtront ici en temps réel.',
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
              ...transactions.map((tx) {
                final isCredit = tx.type == 'credit';
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
                          color: isCredit
                              ? GoLivreurTheme.succes.withValues(alpha: 0.12)
                              : GoLivreurTheme.erreur.withValues(alpha: 0.12),
                          shape: BoxShape.circle,
                        ),
                        child: Icon(
                          isCredit ? Icons.arrow_downward : Icons.arrow_upward,
                          color: isCredit
                              ? GoLivreurTheme.succes
                              : GoLivreurTheme.erreur,
                          size: 18,
                        ),
                      ),
                      const SizedBox(width: 12),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              tx.title,
                              style: const TextStyle(
                                fontWeight: FontWeight.w600,
                                fontSize: 13,
                              ),
                            ),
                            if (tx.date.isNotEmpty)
                              Text(
                                tx.date,
                                style: const TextStyle(
                                  fontSize: 11,
                                  color: GoLivreurTheme.texteSecondaire,
                                ),
                              ),
                          ],
                        ),
                      ),
                      Text(
                        '${isCredit ? "+" : "-"}${tx.amount.toInt()} F',
                        style: TextStyle(
                          fontWeight: FontWeight.bold,
                          fontSize: 14,
                          color: isCredit
                              ? GoLivreurTheme.succes
                              : GoLivreurTheme.erreur,
                        ),
                      ),
                    ],
                  ),
                );
              }),
          ],
        ),
      ),
    );
  }

  void _showAgencyInfoSheet(
    BuildContext context, {
    required String title,
    required String description,
    String? cityName,
  }) {
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
              Text(
                title,
                style: const TextStyle(
                  fontSize: 18,
                  fontWeight: FontWeight.bold,
                ),
              ),
              if (cityName != null && cityName.isNotEmpty) ...[
                const SizedBox(height: 4),
                Text(
                  'Agence GoLivreur — $cityName',
                  style: const TextStyle(
                    color: GoLivreurTheme.secondaire,
                    fontWeight: FontWeight.w600,
                    fontSize: 13,
                  ),
                ),
              ],
              const SizedBox(height: 12),
              Text(
                description,
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
                    backgroundColor: GoLivreurTheme.secondaire,
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
