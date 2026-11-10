import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:golivreur_core/config/app_theme.dart';
import 'package:golivreur_core/config/env_config.dart';
import '../../auth/providers/auth_provider.dart';
import 'edit_client_profile_screen.dart';

class ProfileScreen extends ConsumerStatefulWidget {
  const ProfileScreen({super.key});

  @override
  ConsumerState<ProfileScreen> createState() => _ProfileScreenState();
}

class _ProfileScreenState extends ConsumerState<ProfileScreen> {
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      ref.read(authProvider.notifier).chargerProfil();
    });
  }

  Future<void> _openEditProfile() async {
    await Navigator.of(context).push(
      MaterialPageRoute(builder: (_) => const EditClientProfileScreen()),
    );
    if (mounted) {
      await ref.read(authProvider.notifier).chargerProfil();
    }
  }

  @override
  Widget build(BuildContext context) {
    final authState = ref.watch(authProvider);
    final user = authState.user;
    final photoUrl = GoLivreurEnv.resolveMediaUrl(user?.photoUrl);

    return Scaffold(
      backgroundColor: GoLivreurTheme.fond,
      appBar: AppBar(
        title: const Text('Mon Profil'),
        backgroundColor: Colors.white,
        elevation: 0.5,
        actions: [
          IconButton(
            icon: const Icon(Icons.refresh, color: GoLivreurTheme.texteSecondaire),
            tooltip: 'Actualiser le profil',
            onPressed: () => ref.read(authProvider.notifier).chargerProfil(),
          ),
        ],
      ),
      body: RefreshIndicator(
        color: GoLivreurTheme.primaire,
        onRefresh: () => ref.read(authProvider.notifier).chargerProfil(),
        child: ListView(
          padding: const EdgeInsets.all(16),
          children: [
            // ─── En-tête Utilisateur ───────────────────────────────────────
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
                    radius: 32,
                    backgroundColor: GoLivreurTheme.primaire.withValues(alpha: 0.15),
                    backgroundImage: (photoUrl != null && photoUrl.isNotEmpty) ? NetworkImage(photoUrl) : null,
                    child: (photoUrl == null || photoUrl.isEmpty)
                        ? const Icon(Icons.person, color: GoLivreurTheme.primaire, size: 36)
                        : null,
                  ),
                  const SizedBox(width: 16),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          user?.name ?? 'Client GoLivreur',
                          style: const TextStyle(
                            fontSize: 18,
                            fontWeight: FontWeight.bold,
                            color: GoLivreurTheme.textePrincipal,
                          ),
                        ),
                        const SizedBox(height: 4),
                        Text(
                          '+225 ${user?.phone ?? "0701000001"}',
                          style: const TextStyle(color: GoLivreurTheme.texteSecondaire, fontSize: 14),
                        ),
                        if (user?.email != null && user!.email!.isNotEmpty) ...[
                          const SizedBox(height: 2),
                          Text(
                            user.email!,
                            style: const TextStyle(color: GoLivreurTheme.texteSecondaire, fontSize: 12),
                          ),
                        ],
                        const SizedBox(height: 2),
                        Text(
                          user?.defaultAddress?.isNotEmpty == true
                              ? user!.defaultAddress!
                              : '${user?.cityName ?? "Daloa"}, Côte d\'Ivoire',
                          style: const TextStyle(color: GoLivreurTheme.secondaire, fontSize: 12, fontWeight: FontWeight.w600),
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                        ),
                      ],
                    ),
                  ),
                  IconButton(
                    icon: const Icon(Icons.edit_outlined, color: GoLivreurTheme.primaire),
                    tooltip: 'Modifier mon profil',
                    onPressed: _openEditProfile,
                  ),
                ],
              ),
            ),
            const SizedBox(height: 20),

            // ─── Menu Options ──────────────────────────────────────────────
            Material(
              color: Colors.white,
              clipBehavior: Clip.antiAlias,
              shape: RoundedRectangleBorder(
                borderRadius: BorderRadius.circular(16),
                side: const BorderSide(color: GoLivreurTheme.bordure),
              ),
              child: Column(
                children: [
                  _buildTile(
                    icon: Icons.manage_accounts_outlined,
                    title: 'Modifier mon profil',
                    subtitle: 'Nom, téléphone, email, photo et adresse par défaut',
                    onTap: _openEditProfile,
                  ),
                  const Divider(height: 1),
                  _buildTile(
                    icon: Icons.location_on_outlined,
                    title: 'Mes adresses de livraison favorites',
                    subtitle: user?.defaultAddress?.isNotEmpty == true
                        ? user!.defaultAddress!
                        : 'Quartier Résidentiel, Lobia, Hublot...',
                    onTap: () => _showAddressesDialog(context),
                  ),
                const Divider(height: 1),
                _buildTile(
                  icon: Icons.notifications_none,
                  title: 'Centre de notifications',
                  subtitle: 'Historique des alertes commandes et météo',
                  onTap: () => _showNotificationsDialog(context),
                ),
                const Divider(height: 1),
                _buildTile(
                  icon: Icons.shield_outlined,
                  title: 'Sécurité & Codes de livraison',
                  subtitle: 'Codes 4 chiffres pour GoShop & GoColis',
                  onTap: () {
                    ScaffoldMessenger.of(context).showSnackBar(
                      const SnackBar(
                        content: Text('Les codes 4 chiffres sont uniques à chaque commande et protègent vos remises.'),
                      ),
                    );
                  },
                ),
                const Divider(height: 1),
                _buildTile(
                  icon: Icons.support_agent,
                  title: 'Support client & Urgence Daloa',
                  subtitle: 'Appel direct vers notre bureau local Daloa',
                  onTap: () {
                    ScaffoldMessenger.of(context).showSnackBar(
                      const SnackBar(content: Text('Ligne directe Support Daloa : +225 07 00 00 00 02')),
                    );
                  },
                ),
              ],
            ),
          ),
          const SizedBox(height: 24),

          // ─── Bouton Déconnexion ──────────────────────────────────────────
          SizedBox(
            width: double.infinity,
            height: 52,
            child: OutlinedButton.icon(
              style: OutlinedButton.styleFrom(
                side: const BorderSide(color: GoLivreurTheme.erreur, width: 1.5),
                foregroundColor: GoLivreurTheme.erreur,
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
              ),
              onPressed: () {
                ref.read(authProvider.notifier).deconnecterLocalement();
              },
              icon: const Icon(Icons.logout, size: 20),
              label: const Text('Se déconnecter', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 15)),
            ),
          ),
          const SizedBox(height: 16),
          const Center(
            child: Text(
              'GoLivreur v5.0 • Fait avec passion pour Daloa',
              style: TextStyle(color: GoLivreurTheme.texteSecondaire, fontSize: 12),
            ),
          ),
        ],
      ),
      ),
    );
  }

  Widget _buildTile({
    required IconData icon,
    required String title,
    required String subtitle,
    required VoidCallback onTap,
  }) {
    return ListTile(
      leading: Container(
        padding: const EdgeInsets.all(8),
        decoration: BoxDecoration(
          color: GoLivreurTheme.fond,
          borderRadius: BorderRadius.circular(8),
        ),
        child: Icon(icon, color: GoLivreurTheme.primaire, size: 22),
      ),
      title: Text(title, style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 14)),
      subtitle: Text(subtitle, style: const TextStyle(fontSize: 12, color: GoLivreurTheme.texteSecondaire)),
      trailing: const Icon(Icons.chevron_right, size: 18, color: GoLivreurTheme.texteSecondaire),
      onTap: onTap,
    );
  }

  void _showAddressesDialog(BuildContext context) {
    showModalBottomSheet(
      context: context,
      backgroundColor: Colors.white,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
      builder: (context) {
        return SafeArea(
          child: Padding(
            padding: const EdgeInsets.all(20),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Text(
                  'Mes adresses favorites à Daloa',
                  style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold),
                ),
                const SizedBox(height: 12),
                ListTile(
                  leading: const Icon(Icons.home, color: GoLivreurTheme.secondaire),
                  title: const Text('Maison — Quartier Résidentiel'),
                  subtitle: const Text('Face Maquis Le Baoulé, portail bleu'),
                  trailing: const Icon(Icons.check, color: GoLivreurTheme.secondaire),
                  onTap: () => Navigator.pop(context),
                ),
                ListTile(
                  leading: const Icon(Icons.work, color: GoLivreurTheme.primaire),
                  title: const Text('Bureau — Centre-ville Daloa'),
                  subtitle: const Text('Immeuble Palmier, 2ème étage'),
                  onTap: () => Navigator.pop(context),
                ),
              ],
            ),
          ),
        );
      },
    );
  }

  void _showNotificationsDialog(BuildContext context) {
    showModalBottomSheet(
      context: context,
      backgroundColor: Colors.white,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
      builder: (context) {
        return SafeArea(
          child: Padding(
            padding: const EdgeInsets.all(20),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Text(
                  'Notifications Récentes',
                  style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold),
                ),
                const SizedBox(height: 12),
                ListTile(
                  leading: const Icon(Icons.cloudy_snowing, color: Color(0xFF2E75B6)),
                  title: const Text('🌧️ Mode Pluie activé à Daloa'),
                  subtitle: const Text('Les livreurs réduisent leur vitesse pour assurer la sécurité.'),
                ),
                ListTile(
                  leading: const Icon(Icons.check_circle, color: GoLivreurTheme.secondaire),
                  title: const Text('Commande #98 livrée avec succès'),
                  subtitle: const Text('Chez Pharmacie Centrale — Merci de votre confiance !'),
                ),
              ],
            ),
          ),
        );
      },
    );
  }
}
