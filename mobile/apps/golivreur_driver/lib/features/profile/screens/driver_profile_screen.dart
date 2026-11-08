import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:golivreur_core/config/app_theme.dart';
import '../../driver/providers/driver_provider.dart';
import '../../auth/screens/driver_login_screen.dart';

/// Écran "Mon Profil" & Menus d'Accès Rapide — GoLivreur Driver.
class DriverProfileScreen extends ConsumerStatefulWidget {
  final void Function(int)? onSwitchTab;

  const DriverProfileScreen({super.key, this.onSwitchTab});

  @override
  ConsumerState<DriverProfileScreen> createState() => _DriverProfileScreenState();
}

class _DriverProfileScreenState extends ConsumerState<DriverProfileScreen> {
  @override
  Widget build(BuildContext context) {
    final profile = ref.watch(driverProfileProvider);
    final activeJob = ref.watch(driverActiveJobProvider);

    return Scaffold(
      backgroundColor: GoLivreurTheme.fond,
      appBar: AppBar(
        title: const Text('Mon Profil Livreur'),
        backgroundColor: Colors.white,
        elevation: 0.5,
        actions: [
          IconButton(
            icon: const Icon(Icons.help_outline, color: GoLivreurTheme.texteSecondaire),
            tooltip: 'Support Dispatch Daloa',
            onPressed: () {
              ScaffoldMessenger.of(context).showSnackBar(
                const SnackBar(content: Text('Dispatch Daloa d\'urgence : +225 07 00 00 00 01')),
              );
            },
          ),
        ],
      ),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          // ─── En-tête Livreur ─────────────────────────────────────────────
          Container(
            padding: const EdgeInsets.all(16),
            decoration: BoxDecoration(
              color: Colors.white,
              borderRadius: BorderRadius.circular(16),
              border: Border.all(color: GoLivreurTheme.bordure),
            ),
            child: Row(
              children: [
                Stack(
                  children: [
                    CircleAvatar(
                      radius: 32,
                      backgroundColor: GoLivreurTheme.secondaire.withValues(alpha: 0.15),
                      child: const Icon(
                        Icons.two_wheeler,
                        color: GoLivreurTheme.secondaire,
                        size: 34,
                      ),
                    ),
                    Positioned(
                      bottom: 0,
                      right: 0,
                      child: Container(
                        width: 16,
                        height: 16,
                        decoration: BoxDecoration(
                          color: profile.isOnline ? Colors.green : Colors.grey,
                          shape: BoxShape.circle,
                          border: Border.all(color: Colors.white, width: 2),
                        ),
                      ),
                    ),
                  ],
                ),
                const SizedBox(width: 14),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        children: [
                          Text(
                            profile.name,
                            style: const TextStyle(
                              fontSize: 17,
                              fontWeight: FontWeight.bold,
                              color: GoLivreurTheme.textePrincipal,
                            ),
                          ),
                          const SizedBox(width: 6),
                          const Icon(Icons.verified, size: 16, color: Colors.blue),
                        ],
                      ),
                      const SizedBox(height: 4),
                      Text(
                        'Moto : ${profile.motoPlate} • +225 ${profile.phone}',
                        style: const TextStyle(
                          fontSize: 13,
                          color: GoLivreurTheme.texteSecondaire,
                        ),
                      ),
                      const SizedBox(height: 6),
                      Row(
                        children: [
                          Container(
                            padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                            decoration: BoxDecoration(
                              color: Colors.amber.shade100,
                              borderRadius: BorderRadius.circular(6),
                            ),
                            child: Row(
                              children: [
                                const Icon(Icons.star, size: 13, color: Colors.amber),
                                const SizedBox(width: 3),
                                Text(
                                  '${profile.averageRating} (${profile.coursesToday} courses)',
                                  style: const TextStyle(fontSize: 11, fontWeight: FontWeight.bold, color: Colors.black87),
                                ),
                              ],
                            ),
                          ),
                          const SizedBox(width: 8),
                          const Text(
                            'Daloa Centre',
                            style: TextStyle(fontSize: 12, color: GoLivreurTheme.texteSecondaire),
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

          // ─── Statut Livreur En Ligne / Hors Ligne ────────────────────────
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
            decoration: BoxDecoration(
              color: profile.isOnline ? Colors.green.shade50 : Colors.grey.shade100,
              borderRadius: BorderRadius.circular(12),
              border: Border.all(
                color: profile.isOnline ? Colors.green.shade300 : Colors.grey.shade400,
              ),
            ),
            child: Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Row(
                  children: [
                    Icon(
                      profile.isOnline ? Icons.power_settings_new : Icons.offline_bolt_outlined,
                      color: profile.isOnline ? Colors.green : Colors.grey.shade600,
                      size: 24,
                    ),
                    const SizedBox(width: 10),
                    Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          profile.isOnline ? 'En service (Disponible)' : 'Hors service (En pause)',
                          style: TextStyle(
                            fontWeight: FontWeight.bold,
                            fontSize: 15,
                            color: profile.isOnline ? Colors.green.shade900 : Colors.grey.shade800,
                          ),
                        ),
                        Text(
                          profile.isOnline ? 'Vous recevez les courses à proximité' : 'Aucune course ne vous sera envoyée',
                          style: TextStyle(
                            fontSize: 12,
                            color: profile.isOnline ? Colors.green.shade700 : Colors.grey.shade600,
                          ),
                        ),
                      ],
                    ),
                  ],
                ),
                Switch(
                  value: profile.isOnline,
                  activeThumbColor: GoLivreurTheme.secondaire,
                  activeTrackColor: GoLivreurTheme.secondaire.withValues(alpha: 0.5),
                  onChanged: (val) {
                    ref.read(driverProfileProvider.notifier).toggleOnline();
                    ScaffoldMessenger.of(context).showSnackBar(
                      SnackBar(
                        content: Text(val ? '🟢 Vous êtes en service. Prêt à livrer !' : '⏸️ Vous êtes passé hors service.'),
                        duration: const Duration(seconds: 2),
                      ),
                    );
                  },
                ),
              ],
            ),
          ),
          const SizedBox(height: 20),

          // ─── MENUS D'ACCÈS RAPIDE ─────────────────────────────────────────
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
                icon: Icons.two_wheeler,
                color: GoLivreurTheme.secondaire,
                title: 'Courses',
                badge: activeJob != null ? 'Course active' : 'Prêt',
                badgeColor: activeJob != null ? Colors.orange : Colors.green,
                onTap: () {
                  widget.onSwitchTab?.call(0);
                },
              ),
              _buildQuickCard(
                icon: Icons.account_balance_wallet,
                color: Colors.blue,
                title: 'Portefeuille',
                badge: '${profile.walletBalance.toInt()} F',
                badgeColor: Colors.blue,
                onTap: () {
                  widget.onSwitchTab?.call(1);
                },
              ),
              _buildQuickCard(
                icon: Icons.bar_chart,
                color: Colors.teal,
                title: 'Mes Gains',
                badge: '${profile.earningsToday.toInt()} F auj.',
                badgeColor: Colors.teal,
                onTap: () {
                  widget.onSwitchTab?.call(2);
                },
              ),
              _buildQuickCard(
                icon: Icons.lock_open,
                color: Colors.deepOrange,
                title: 'Déblocage Test',
                badge: 'Code 1234',
                badgeColor: Colors.deepOrange,
                onTap: () {
                  ref.read(driverActiveJobProvider.notifier).unblockSecurity();
                  ScaffoldMessenger.of(context).showSnackBar(
                    const SnackBar(
                      content: Text('🔓 Sécurité débloquée & code test 1234 réactivé !'),
                      backgroundColor: Colors.green,
                    ),
                  );
                  widget.onSwitchTab?.call(0);
                },
              ),
            ],
          ),
          const SizedBox(height: 24),

          // ─── Paramètres & Déconnexion ────────────────────────────────────
          Material(
            color: Colors.white,
            borderRadius: BorderRadius.circular(16),
            clipBehavior: Clip.antiAlias,
            child: Column(
              children: [
                ListTile(
                  leading: const Icon(Icons.shield_outlined, color: GoLivreurTheme.texteSecondaire),
                  title: const Text('Code de sécurité universel'),
                  subtitle: const Text('En mode démo : saisissez 1234'),
                  trailing: const Icon(Icons.info_outline, color: GoLivreurTheme.primaire),
                  onTap: () {
                    ScaffoldMessenger.of(context).showSnackBar(
                      const SnackBar(content: Text('Astuce : Le code de livraison client universel en test est toujours 1234')),
                    );
                  },
                ),
                const Divider(height: 1),
                ListTile(
                  leading: const Icon(Icons.phone_in_talk, color: GoLivreurTheme.texteSecondaire),
                  title: const Text('Appeler Dispatcher Daloa'),
                  subtitle: const Text('Kouadio Serge (+225 0700000001)'),
                  trailing: const Icon(Icons.chevron_right),
                  onTap: () {
                    ScaffoldMessenger.of(context).showSnackBar(
                      const SnackBar(content: Text('Appel du responsable opérationnel Daloa : 0700000001')),
                    );
                  },
                ),
                const Divider(height: 1),
                ListTile(
                  leading: const Icon(Icons.logout, color: GoLivreurTheme.erreur),
                  title: const Text(
                    'Fin de service / Déconnexion',
                    style: TextStyle(color: GoLivreurTheme.erreur, fontWeight: FontWeight.bold),
                  ),
                  onTap: () {
                    Navigator.of(context).pushAndRemoveUntil(
                      MaterialPageRoute(builder: (_) => const DriverLoginScreen()),
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
