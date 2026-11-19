import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:golivreur_core/config/app_theme.dart';
import 'package:golivreur_core/config/env_config.dart';
import '../../business/providers/business_provider.dart';
import '../../auth/screens/business_login_screen.dart';
import 'edit_business_profile_screen.dart';

/// Écran "Mon Profil" & Menus d'Accès Rapide — GoLivreur Business.
class BusinessProfileScreen extends ConsumerStatefulWidget {
  final void Function(int)? onSwitchTab;

  const BusinessProfileScreen({super.key, this.onSwitchTab});

  @override
  ConsumerState<BusinessProfileScreen> createState() => _BusinessProfileScreenState();
}

class _BusinessProfileScreenState extends ConsumerState<BusinessProfileScreen> {
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      ref.read(businessProfileProvider.notifier).chargerProfil();
    });
  }

  Future<void> _openEditProfile() async {
    await Navigator.of(context).push(
      MaterialPageRoute(builder: (_) => const EditBusinessProfileScreen()),
    );
    if (mounted) {
      await ref.read(businessProfileProvider.notifier).chargerProfil();
    }
  }

  @override
  Widget build(BuildContext context) {
    final profile = ref.watch(businessProfileProvider);
    final orders = ref.watch(businessOrdersProvider);
    final catalog = ref.watch(businessCatalogProvider);
    final pendingCount = orders.where((o) => o.status == BusinessOrderStatus.pending).length;
    final logoUrl = GoLivreurEnv.resolveMediaUrl(profile.logoUrl);
    final citySuffix = (profile.cityName != null && profile.cityName!.isNotEmpty)
        ? ' à ${profile.cityName}'
        : '';
    final cityLabel = (profile.cityName != null && profile.cityName!.isNotEmpty)
        ? ' (${profile.cityName})'
        : '';

    return Scaffold(
      backgroundColor: GoLivreurTheme.fond,
      appBar: AppBar(
        title: const Text('Mon Profil Commerce'),
        backgroundColor: Colors.white,
        elevation: 0.5,
        actions: [
          IconButton(
            icon: const Icon(Icons.edit_outlined, color: Color(0xFF2E75B6)),
            tooltip: 'Modifier mon profil',
            onPressed: _openEditProfile,
          ),
          IconButton(
            icon: const Icon(Icons.help_outline, color: GoLivreurTheme.texteSecondaire),
            tooltip: 'Support Partenaires',
            onPressed: () {
              ScaffoldMessenger.of(context).showSnackBar(
                SnackBar(content: Text('Contactez votre agence Support Partenaires GoLivreur$cityLabel.')),
              );
            },
          ),
        ],
      ),
      body: RefreshIndicator(
        color: const Color(0xFF2E75B6),
        onRefresh: () async {
          await Future.wait([
            ref.read(businessProfileProvider.notifier).chargerProfil(),
            ref.read(businessCatalogProvider.notifier).chargerCatalogue(),
            ref.read(revenueStatsProvider.notifier).chargerRevenus(),
          ]);
        },
        child: ListView(
          padding: const EdgeInsets.all(16),
          children: [
            // ─── En-tête Commerce Partenaire ──────────────────────────────
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
                    clipBehavior: Clip.antiAlias,
                    child: (logoUrl != null && logoUrl.isNotEmpty)
                        ? Image.network(
                            logoUrl,
                            width: 64,
                            height: 64,
                            fit: BoxFit.cover,
                            errorBuilder: (_, __, ___) => _buildStoreFallback(profile.emoji),
                          )
                        : _buildStoreFallback(profile.emoji),
                  ),
                  const SizedBox(width: 14),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          profile.name,
                          style: const TextStyle(
                            fontSize: 17,
                            fontWeight: FontWeight.bold,
                            color: GoLivreurTheme.textePrincipal,
                          ),
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                        ),
                        const SizedBox(height: 4),
                        Text(
                          'Gérant : ${profile.ownerName} • +225 ${profile.ownerPhone}',
                          style: const TextStyle(
                            fontSize: 13,
                            color: GoLivreurTheme.texteSecondaire,
                          ),
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                        ),
                        const SizedBox(height: 4),
                        Row(
                          children: [
                            const Icon(Icons.location_on, size: 14, color: GoLivreurTheme.primaire),
                            const SizedBox(width: 4),
                            Expanded(
                              child: Text(
                                profile.quarter,
                                style: const TextStyle(
                                  fontSize: 12,
                                  fontWeight: FontWeight.w600,
                                  color: GoLivreurTheme.textePrincipal,
                                ),
                                maxLines: 1,
                                overflow: TextOverflow.ellipsis,
                              ),
                            ),
                          ],
                        ),
                      ],
                    ),
                  ),
                  IconButton(
                    icon: const Icon(Icons.edit_outlined, color: Color(0xFF2E75B6)),
                    tooltip: 'Modifier mon profil',
                    onPressed: _openEditProfile,
                  ),
                ],
              ),
            ),
            const SizedBox(height: 16),

            // ─── Statut Boutique (Ouvert / Fermé en direct) ───────────────
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
              decoration: BoxDecoration(
                color: profile.isOpen ? Colors.green.shade50 : Colors.red.shade50,
                borderRadius: BorderRadius.circular(12),
                border: Border.all(
                  color: profile.isOpen ? Colors.green.shade300 : Colors.red.shade300,
                ),
              ),
              child: Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  Row(
                    children: [
                      Icon(
                        profile.isOpen ? Icons.check_circle : Icons.pause_circle_filled,
                        color: profile.isOpen ? Colors.green : Colors.red,
                        size: 24,
                      ),
                      const SizedBox(width: 10),
                      Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            profile.isOpen ? 'Boutique Ouverte' : 'Boutique Fermée',
                            style: TextStyle(
                              fontWeight: FontWeight.bold,
                              fontSize: 15,
                              color: profile.isOpen ? Colors.green.shade900 : Colors.red.shade900,
                            ),
                          ),
                          Text(
                            profile.isOpen ? 'Visible par les clients$citySuffix' : 'Commandes suspendues temporairement',
                            style: TextStyle(
                              fontSize: 12,
                              color: profile.isOpen ? Colors.green.shade700 : Colors.red.shade700,
                            ),
                          ),
                        ],
                      ),
                    ],
                  ),
                  Switch(
                    value: profile.isOpen,
                    activeThumbColor: Colors.green,
                    activeTrackColor: Colors.green.withValues(alpha: 0.5),
                    onChanged: (val) {
                      ref.read(businessProfileProvider.notifier).toggleOpenStatus();
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
                badge: '${catalog.length} produit${catalog.length > 1 ? "s" : ""}',
                badgeColor: Colors.purple,
                onTap: () {
                  widget.onSwitchTab?.call(1);
                },
              ),
              _buildQuickCard(
                icon: Icons.account_balance_wallet,
                color: Colors.blue,
                title: 'Recettes',
                badge: '${profile.walletBalance.toInt()} F CFA',
                badgeColor: Colors.blue,
                onTap: () {
                  widget.onSwitchTab?.call(2);
                },
              ),
              _buildQuickCard(
                icon: Icons.notifications_active,
                color: Colors.teal,
                title: 'Notifications',
                badge: profile.notificationsEnabled ? 'Actif' : 'Pause',
                badgeColor: Colors.teal,
                onTap: () {
                  ScaffoldMessenger.of(context).showSnackBar(
                    const SnackBar(content: Text('Alertes de nouvelles commandes actives.')),
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
                  leading: const Icon(Icons.manage_accounts_outlined, color: Color(0xFF2E75B6)),
                  title: const Text('Modifier mon profil'),
                  subtitle: const Text('Commerce, catégorie, emoji, logo, horaires et gérant'),
                  trailing: const Icon(Icons.chevron_right),
                  onTap: _openEditProfile,
                ),
                const Divider(height: 1),
                ListTile(
                  leading: const Icon(Icons.schedule, color: GoLivreurTheme.texteSecondaire),
                  title: const Text('Horaires de livraison'),
                  subtitle: Text(
                    profile.openHoursText.isNotEmpty
                        ? profile.openHoursText
                        : 'Non renseignés',
                  ),
                  trailing: const Icon(Icons.chevron_right),
                  onTap: _openEditProfile,
                ),
                const Divider(height: 1),
                ListTile(
                  leading: const Icon(Icons.phone_in_talk, color: GoLivreurTheme.texteSecondaire),
                  title: const Text('Assistance Partenaires'),
                  subtitle: Text('Agence GoLivreur$cityLabel'),
                  trailing: const Icon(Icons.chevron_right),
                  onTap: () {
                    ScaffoldMessenger.of(context).showSnackBar(
                      SnackBar(content: Text('Contactez votre agence GoLivreur$cityLabel.')),
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
                  onTap: () async {
                    const storage = FlutterSecureStorage();
                    await storage.deleteAll();
                    ref.read(businessProfileProvider.notifier).reinitialiser();
                    ref.read(businessCatalogProvider.notifier).reinitialiser();
                    ref.read(businessOrdersProvider.notifier).reinitialiser();
                    ref.read(revenueStatsProvider.notifier).reinitialiser();
                    if (context.mounted) {
                      Navigator.of(context).pushAndRemoveUntil(
                        MaterialPageRoute(builder: (_) => const BusinessLoginScreen()),
                        (route) => false,
                      );
                    }
                  },
                ),
              ],
            ),
          ),
          const SizedBox(height: 24),
        ],
      ),
      ),
    );
  }

  Widget _buildStoreFallback(String? emoji) {
    if (emoji != null && emoji.trim().isNotEmpty) {
      return Center(
        child: Text(emoji, style: const TextStyle(fontSize: 32)),
      );
    }
    return const Center(
      child: Icon(
        Icons.storefront_rounded,
        color: Color(0xFF2E75B6),
        size: 36,
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
