import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:golivreur_core/config/app_theme.dart';
import '../../business/providers/business_provider.dart';
import '../../auth/screens/business_login_screen.dart';

/// Écran "Mon Profil" & Menus d'Accès Rapide — GoLivreur Business.
class BusinessProfileScreen extends ConsumerStatefulWidget {
  final void Function(int)? onSwitchTab;

  const BusinessProfileScreen({super.key, this.onSwitchTab});

  @override
  ConsumerState<BusinessProfileScreen> createState() => _BusinessProfileScreenState();
}

class _BusinessProfileScreenState extends ConsumerState<BusinessProfileScreen> {
  bool _isOpen = true;

  @override
  Widget build(BuildContext context) {
    final orders = ref.watch(businessOrdersProvider);
    final pendingCount = orders.where((o) => o.status == BusinessOrderStatus.pending).length;
    final totalRevenue = orders.fold<double>(0, (sum, o) => sum + o.totalAmount);

    return Scaffold(
      backgroundColor: GoLivreurTheme.fond,
      appBar: AppBar(
        title: const Text('Mon Profil Commerce'),
        backgroundColor: Colors.white,
        elevation: 0.5,
        actions: [
          IconButton(
            icon: const Icon(Icons.help_outline, color: GoLivreurTheme.texteSecondaire),
            tooltip: 'Support Partenaires',
            onPressed: () {
              ScaffoldMessenger.of(context).showSnackBar(
                const SnackBar(content: Text('Support Commerces Daloa : +225 07 00 00 00 02')),
              );
            },
          ),
        ],
      ),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          // ─── En-tête Commerce Partenaire ────────────────────────────────
          Container(
            padding: const EdgeInsets.all(16),
            decoration: BoxDecoration(
              color: Colors.white,
              borderRadius: BorderRadius.circular(16),
              border: Border.all(color: GoLivreurTheme.bordure),
            ),
            child: Row(
              children: [
                Container(
                  width: 64,
                  height: 64,
                  decoration: BoxDecoration(
                    color: const Color(0xFF2E75B6).withValues(alpha: 0.12),
                    borderRadius: BorderRadius.circular(16),
                  ),
                  child: const Center(
                    child: Icon(
                      Icons.storefront_rounded,
                      color: Color(0xFF2E75B6),
                      size: 36,
                    ),
                  ),
                ),
                const SizedBox(width: 14),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      const Text(
                        'Maquis Chez Tantie Adjoua',
                        style: TextStyle(
                          fontSize: 17,
                          fontWeight: FontWeight.bold,
                          color: GoLivreurTheme.textePrincipal,
                        ),
                      ),
                      const SizedBox(height: 4),
                      const Text(
                        'Gérant : Adjoua Kouassi • +225 0702010001',
                        style: TextStyle(
                          fontSize: 13,
                          color: GoLivreurTheme.texteSecondaire,
                        ),
                      ),
                      const SizedBox(height: 4),
                      Row(
                        children: [
                          const Icon(Icons.location_on, size: 14, color: GoLivreurTheme.primaire),
                          const SizedBox(width: 4),
                          const Text(
                            'Quartier Commerce, Daloa',
                            style: TextStyle(
                              fontSize: 12,
                              fontWeight: FontWeight.w600,
                              color: GoLivreurTheme.textePrincipal,
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
          const SizedBox(height: 16),

          // ─── Statut Boutique (Ouvert / Fermé en direct) ─────────────────
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
            decoration: BoxDecoration(
              color: _isOpen ? Colors.green.shade50 : Colors.red.shade50,
              borderRadius: BorderRadius.circular(12),
              border: Border.all(
                color: _isOpen ? Colors.green.shade300 : Colors.red.shade300,
              ),
            ),
            child: Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Row(
                  children: [
                    Icon(
                      _isOpen ? Icons.check_circle : Icons.pause_circle_filled,
                      color: _isOpen ? Colors.green : Colors.red,
                      size: 24,
                    ),
                    const SizedBox(width: 10),
                    Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          _isOpen ? 'Boutique Ouverte' : 'Boutique Fermée',
                          style: TextStyle(
                            fontWeight: FontWeight.bold,
                            fontSize: 15,
                            color: _isOpen ? Colors.green.shade900 : Colors.red.shade900,
                          ),
                        ),
                        Text(
                          _isOpen ? 'Visible par les clients à Daloa' : 'Commandes suspendues temporairement',
                          style: TextStyle(
                            fontSize: 12,
                            color: _isOpen ? Colors.green.shade700 : Colors.red.shade700,
                          ),
                        ),
                      ],
                    ),
                  ],
                ),
                Switch(
                  value: _isOpen,
                  activeThumbColor: Colors.green,
                  activeTrackColor: Colors.green.withValues(alpha: 0.5),
                  onChanged: (val) {
                    setState(() => _isOpen = val);
                    ScaffoldMessenger.of(context).showSnackBar(
                      SnackBar(
                        content: Text(val ? '🟢 Votre boutique accepte maintenant les commandes.' : '🔴 Votre boutique est fermée.'),
                        duration: const Duration(seconds: 2),
                      ),
                    );
                  },
                ),
              ],
            ),
          ),
          const SizedBox(height: 20),

          // ─── MENUS D'ACCÈS RAPIDE ───────────────────────────────────────
          const Text(
            'Accès Rapide',
            style: TextStyle(
              fontSize: 16,
              fontWeight: FontWeight.bold,
              color: GoLivreurTheme.textePrincipal,
            ),
          ),
          const SizedBox(height: 10),

          GridView.count(
            crossAxisCount: 2,
            shrinkWrap: true,
            physics: const NeverScrollableScrollPhysics(),
            mainAxisSpacing: 12,
            crossAxisSpacing: 12,
            childAspectRatio: 1.45,
            children: [
              _buildQuickCard(
                icon: Icons.receipt_long,
                color: Colors.orange,
                title: 'Commandes',
                badge: pendingCount > 0 ? '$pendingCount en attente' : 'À jour',
                badgeColor: pendingCount > 0 ? Colors.red : Colors.green,
                onTap: () {
                  widget.onSwitchTab?.call(0);
                },
              ),
              _buildQuickCard(
                icon: Icons.restaurant_menu,
                color: Colors.purple,
                title: 'Mon Catalogue',
                badge: '5 produits',
                badgeColor: Colors.purple,
                onTap: () {
                  widget.onSwitchTab?.call(1);
                },
              ),
              _buildQuickCard(
                icon: Icons.account_balance_wallet,
                color: Colors.blue,
                title: 'Recettes',
                badge: '${totalRevenue.toInt()} F CFA',
                badgeColor: Colors.blue,
                onTap: () {
                  widget.onSwitchTab?.call(2);
                },
              ),
              _buildQuickCard(
                icon: Icons.notifications_active,
                color: Colors.teal,
                title: 'Alertes Daloa',
                badge: 'Actif',
                badgeColor: Colors.teal,
                onTap: () {
                  ScaffoldMessenger.of(context).showSnackBar(
                    const SnackBar(content: Text('Notifications sonores de nouvelles commandes activées')),
                  );
                },
              ),
            ],
          ),
          const SizedBox(height: 24),

          // ─── Paramètres & Déconnexion ──────────────────────────────────
          Material(
            color: Colors.white,
            borderRadius: BorderRadius.circular(16),
            clipBehavior: Clip.antiAlias,
            child: Column(
              children: [
                ListTile(
                  leading: const Icon(Icons.schedule, color: GoLivreurTheme.texteSecondaire),
                  title: const Text('Horaires de livraison'),
                  subtitle: const Text('Lun - Dim : 08h00 - 22h00'),
                  trailing: const Icon(Icons.chevron_right),
                  onTap: () {},
                ),
                const Divider(height: 1),
                ListTile(
                  leading: const Icon(Icons.phone_in_talk, color: GoLivreurTheme.texteSecondaire),
                  title: const Text('Assistance Partenaires'),
                  subtitle: const Text('Équipe GoLivreur Daloa'),
                  trailing: const Icon(Icons.chevron_right),
                  onTap: () {
                    ScaffoldMessenger.of(context).showSnackBar(
                      const SnackBar(content: Text('Centre d\'assistance GoLivreur Daloa joignable au 0700000002')),
                    );
                  },
                ),
                const Divider(height: 1),
                ListTile(
                  leading: const Icon(Icons.logout, color: GoLivreurTheme.erreur),
                  title: const Text(
                    'Déconnexion boutique',
                    style: TextStyle(color: GoLivreurTheme.erreur, fontWeight: FontWeight.bold),
                  ),
                  onTap: () {
                    Navigator.of(context).pushAndRemoveUntil(
                      MaterialPageRoute(builder: (_) => const BusinessLoginScreen()),
                      (route) => false,
                    );
                  },
                ),
              ],
            ),
          ),
          const SizedBox(height: 24),
        ],
      ),
    );
  }

  Widget _buildQuickCard({
    required IconData icon,
    required Color color,
    required String title,
    required String badge,
    required Color badgeColor,
    required VoidCallback onTap,
  }) {
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(14),
      child: Container(
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(14),
          border: Border.all(color: GoLivreurTheme.bordure),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          mainAxisAlignment: MainAxisAlignment.spaceBetween,
          children: [
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                CircleAvatar(
                  radius: 18,
                  backgroundColor: color.withValues(alpha: 0.15),
                  child: Icon(icon, size: 20, color: color),
                ),
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                  decoration: BoxDecoration(
                    color: badgeColor.withValues(alpha: 0.12),
                    borderRadius: BorderRadius.circular(6),
                  ),
                  child: Text(
                    badge,
                    style: TextStyle(fontSize: 10, fontWeight: FontWeight.bold, color: badgeColor),
                  ),
                ),
              ],
            ),
            Text(
              title,
              style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14, color: GoLivreurTheme.textePrincipal),
            ),
          ],
        ),
      ),
    );
  }
}
