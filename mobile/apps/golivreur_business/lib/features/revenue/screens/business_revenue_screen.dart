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

    return Scaffold(
      backgroundColor: GoLivreurTheme.fond,
      appBar: AppBar(
        title: const Text('Revenus & Performances'),
        backgroundColor: Colors.white,
        elevation: 0.5,
      ),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          // ─── Carte Solde Disponible pour Versement ─────────────────────────
          Container(
            padding: const EdgeInsets.all(18),
            decoration: BoxDecoration(
              gradient: const LinearGradient(
                colors: [Color(0xFF2E75B6), Color(0xFF1B4D7E)], // Bleu accent Business Stitch
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
                        Icon(Icons.account_balance_wallet, color: Colors.white70, size: 20),
                        SizedBox(width: 8),
                        Text(
                          'Solde disponible',
                          style: TextStyle(color: Colors.white70, fontSize: 13, fontWeight: FontWeight.w500),
                        ),
                      ],
                    ),
                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                      decoration: BoxDecoration(
                        color: Colors.white.withValues(alpha: 0.2),
                        borderRadius: BorderRadius.circular(6),
                      ),
                      child: const Text(
                        '⚡ Immédiat',
                        style: TextStyle(color: Colors.white, fontSize: 11, fontWeight: FontWeight.bold),
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
                  padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                  decoration: BoxDecoration(
                    color: Colors.black.withValues(alpha: 0.15),
                    borderRadius: BorderRadius.circular(8),
                  ),
                  child: const Row(
                    children: [
                      Icon(Icons.phone_android, color: Colors.white70, size: 16),
                      SizedBox(width: 6),
                      Expanded(
                        child: Text(
                          'Wave, Orange Money & MTN MoMo • Sans frais',
                          style: TextStyle(color: Colors.white70, fontSize: 12),
                        ),
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: 16),
                SizedBox(
                  width: double.infinity,
                  height: 52, // Zone tactile min 52px
                  child: ElevatedButton.icon(
                    style: ElevatedButton.styleFrom(
                      backgroundColor: GoLivreurTheme.primaire,
                      foregroundColor: Colors.white,
                      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                      elevation: 2,
                    ),
                    onPressed: () => _showDisbursementDialog(context, ref, profile.walletBalance),
                    icon: const Icon(Icons.payments, size: 20),
                    label: const Text(
                      'Demander un versement Mobile Money',
                      style: TextStyle(fontWeight: FontWeight.bold, fontSize: 15),
                    ),
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(height: 20),

          // ─── Note & Avis Clients ──────────────────────────────────────────
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
                  child: const Icon(Icons.star, color: Color(0xFFF9A825), size: 28),
                ),
                const SizedBox(width: 14),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        children: [
                          Text(
                            '${profile.rating}',
                            style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 18),
                          ),
                          const SizedBox(width: 6),
                          const Row(
                            children: [
                              Icon(Icons.star, color: Color(0xFFF9A825), size: 16),
                              Icon(Icons.star, color: Color(0xFFF9A825), size: 16),
                              Icon(Icons.star, color: Color(0xFFF9A825), size: 16),
                              Icon(Icons.star, color: Color(0xFFF9A825), size: 16),
                              Icon(Icons.star_half, color: Color(0xFFF9A825), size: 16),
                            ],
                          ),
                        ],
                      ),
                      Text(
                        '${profile.reviewCount} avis clients certifiés à Daloa',
                        style: const TextStyle(fontSize: 12, color: GoLivreurTheme.texteSecondaire),
                      ),
                    ],
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(height: 20),

          // ─── Chiffre d'Affaires & Commandes ──────────────────────────────
          const Text(
            'Chiffre d\'Affaires & Activité',
            style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold, color: GoLivreurTheme.textePrincipal),
          ),
          const SizedBox(height: 10),
          Row(
            children: [
              _buildMetricCard(
                title: 'Aujourd\'hui',
                amount: '${stats.caAujourdhui.toInt()} F',
                orders: '${stats.commandesAujourdhui} commandes',
                trend: '+15%',
              ),
              const SizedBox(width: 10),
              _buildMetricCard(
                title: 'Cette semaine',
                amount: '${stats.caSemaine.toInt()} F',
                orders: '${stats.commandesSemaine} commandes',
                trend: '+8%',
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
                    const Text('Ce mois-ci', style: TextStyle(color: GoLivreurTheme.texteSecondaire, fontSize: 13)),
                    const SizedBox(height: 4),
                    Text(
                      '${stats.caMois.toInt()} F CFA',
                      style: const TextStyle(fontSize: 18, fontWeight: FontWeight.bold, color: GoLivreurTheme.textePrincipal),
                    ),
                  ],
                ),
                Text(
                  '${stats.commandesMois} commandes livrées',
                  style: const TextStyle(color: GoLivreurTheme.secondaire, fontWeight: FontWeight.bold, fontSize: 13),
                ),
              ],
            ),
          ),
          const SizedBox(height: 24),

          // ─── Paramètres Préparation & Horaires ────────────────────────────
          const Text(
            'Temps de Préparation & Horaires',
            style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold, color: GoLivreurTheme.textePrincipal),
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
                        Text('Temps moyen de préparation', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 14)),
                        Text('Affiché aux clients sur GoShop', style: TextStyle(fontSize: 12, color: GoLivreurTheme.texteSecondaire)),
                      ],
                    ),
                    DropdownButton<int>(
                      value: profile.prepTimeMinutes,
                      items: [10, 15, 20, 25, 30, 40].map((m) {
                        return DropdownMenuItem<int>(
                          value: m,
                          child: Text('$m min', style: const TextStyle(fontWeight: FontWeight.bold)),
                        );
                      }).toList(),
                      onChanged: (val) {
                        if (val != null) {
                          ref.read(businessProfileProvider.notifier).updatePrepTime(val);
                        }
                      },
                    ),
                  ],
                ),
                const Divider(height: 20),
                const Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Text('Horaires d\'ouverture', style: TextStyle(fontWeight: FontWeight.w600, fontSize: 13)),
                    Text('Tous les jours • 08h00 - 22h30', style: TextStyle(color: GoLivreurTheme.texteSecondaire, fontSize: 13)),
                  ],
                ),
              ],
            ),
          ),
          const SizedBox(height: 24),

          // ─── Historique des Versements ───────────────────────────────────
          const Text(
            'Derniers Versements Mobile Money',
            style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold, color: GoLivreurTheme.textePrincipal),
          ),
          const SizedBox(height: 10),
          ...stats.versementsRecents.map((v) => Container(
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
                    Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text('${v.amount.toInt()} F CFA', style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 15)),
                        Text('${v.provider} • ${v.date}', style: const TextStyle(fontSize: 12, color: GoLivreurTheme.texteSecondaire)),
                      ],
                    ),
                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                      decoration: BoxDecoration(
                        color: GoLivreurTheme.succes.withValues(alpha: 0.1),
                        borderRadius: BorderRadius.circular(6),
                      ),
                      child: Text(
                        v.status,
                        style: const TextStyle(color: GoLivreurTheme.succes, fontSize: 11, fontWeight: FontWeight.bold),
                      ),
                    ),
                  ],
                ),
              )),
          const SizedBox(height: 20),
        ],
      ),
    );
  }

  Widget _buildMetricCard({
    required String title,
    required String amount,
    required String orders,
    required String trend,
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
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Text(title, style: const TextStyle(color: GoLivreurTheme.texteSecondaire, fontSize: 12)),
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                  decoration: BoxDecoration(
                    color: GoLivreurTheme.succes.withValues(alpha: 0.1),
                    borderRadius: BorderRadius.circular(4),
                  ),
                  child: Text(trend, style: const TextStyle(color: GoLivreurTheme.succes, fontSize: 10, fontWeight: FontWeight.bold)),
                ),
              ],
            ),
            const SizedBox(height: 6),
            Text(amount, style: const TextStyle(fontSize: 18, fontWeight: FontWeight.bold, color: GoLivreurTheme.textePrincipal)),
            const SizedBox(height: 2),
            Text(orders, style: const TextStyle(fontSize: 11, color: GoLivreurTheme.texteSecondaire)),
          ],
        ),
      ),
    );
  }

  void _showDisbursementDialog(BuildContext context, WidgetRef ref, double balance) {
    final amountCtrl = TextEditingController(text: balance.toInt().toString());
    String selectedProvider = 'wave';

    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.white,
      shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(20))),
      builder: (context) {
        return StatefulBuilder(
          builder: (context, setModalState) {
            return Padding(
              padding: EdgeInsets.only(
                left: 20,
                right: 20,
                top: 20,
                bottom: MediaQuery.of(context).viewInsets.bottom + 20,
              ),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const Text('Versement Mobile Money', style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold)),
                  const SizedBox(height: 8),
                  Text('Solde disponible : ${balance.toInt()} F CFA', style: const TextStyle(color: GoLivreurTheme.texteSecondaire, fontSize: 13)),
                  const SizedBox(height: 16),
                  TextField(
                    controller: amountCtrl,
                    keyboardType: TextInputType.number,
                    decoration: const InputDecoration(labelText: 'Montant à retirer (F CFA)', suffixText: 'F CFA'),
                  ),
                  const SizedBox(height: 14),
                  const Text('Compte de réception :', style: TextStyle(fontWeight: FontWeight.w600, fontSize: 13)),
                  const SizedBox(height: 6),
                  Row(
                    children: [
                      _buildProviderRadio('wave', 'Wave', selectedProvider, (p) => setModalState(() => selectedProvider = p)),
                      const SizedBox(width: 8),
                      _buildProviderRadio('orange', 'Orange', selectedProvider, (p) => setModalState(() => selectedProvider = p)),
                      const SizedBox(width: 8),
                      _buildProviderRadio('mtn', 'MTN MoMo', selectedProvider, (p) => setModalState(() => selectedProvider = p)),
                    ],
                  ),
                  const SizedBox(height: 20),
                  SizedBox(
                    width: double.infinity,
                    height: 52,
                    child: ElevatedButton(
                      style: ElevatedButton.styleFrom(
                        backgroundColor: GoLivreurTheme.primaire,
                        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                      ),
                      onPressed: () {
                        final val = double.tryParse(amountCtrl.text.trim()) ?? 0;
                        if (val > 0 && val <= balance) {
                          ref.read(businessProfileProvider.notifier).withdrawFunds(val);
                          Navigator.pop(context);
                          ScaffoldMessenger.of(context).showSnackBar(
                            SnackBar(content: Text('Demande de versement de ${val.toInt()} F CFA envoyée via $selectedProvider !')),
                          );
                        }
                      },
                      child: const Text('Confirmer le virement', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 16, color: Colors.white)),
                    ),
                  ),
                ],
              ),
            );
          },
        );
      },
    );
  }

  Widget _buildProviderRadio(String id, String label, String current, ValueChanged<String> onChanged) {
    final isSelected = id == current;
    return Expanded(
      child: InkWell(
        onTap: () => onChanged(id),
        borderRadius: BorderRadius.circular(8),
        child: Container(
          padding: const EdgeInsets.symmetric(vertical: 8),
          decoration: BoxDecoration(
            color: isSelected ? GoLivreurTheme.primaire.withValues(alpha: 0.15) : Colors.grey.shade100,
            borderRadius: BorderRadius.circular(8),
            border: Border.all(color: isSelected ? GoLivreurTheme.primaire : Colors.grey.shade300),
          ),
          alignment: Alignment.center,
          child: Text(
            label,
            style: TextStyle(fontWeight: FontWeight.bold, fontSize: 13, color: isSelected ? GoLivreurTheme.primaire : GoLivreurTheme.textePrincipal),
          ),
        ),
      ),
    );
  }
}

