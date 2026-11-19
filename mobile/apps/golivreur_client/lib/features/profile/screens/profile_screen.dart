import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:golivreur_core/config/app_theme.dart';
import 'package:golivreur_core/config/env_config.dart';
import 'package:golivreur_core/widgets/golivreur_widgets.dart';
import '../../auth/providers/auth_provider.dart';
import '../../auth/utils/client_logout.dart';
import '../../goshop/providers/goshop_provider.dart';
import 'client_addresses_screen.dart';
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
      ref.read(clientAddressesProvider.notifier).chargerAdresses(syncNeighborhood: false);
    });
  }

  Future<void> _openEditProfile() async {
    await Navigator.of(context).push(
      MaterialPageRoute(builder: (_) => const EditClientProfileScreen()),
    );
    if (mounted) {
      await ref.read(authProvider.notifier).chargerProfil();
      await ref.read(clientAddressesProvider.notifier).chargerAdresses(syncNeighborhood: false);
    }
  }

  Future<void> _openAddressesScreen() async {
    await Navigator.of(context).push(
      MaterialPageRoute(builder: (_) => const ClientAddressesScreen()),
    );
    if (mounted) {
      await ref.read(authProvider.notifier).chargerProfil();
      await ref.read(clientAddressesProvider.notifier).chargerAdresses(syncNeighborhood: false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final authState = ref.watch(authProvider);
    final branding = ref.watch(appBrandingProvider);
    final addressesState = ref.watch(clientAddressesProvider);
    final user = authState.user;
    final photoUrl = GoLivreurEnv.resolveMediaUrl(user?.photoUrl);
    final registeredCity = (user?.cityName ?? '').trim();
    final activeDefaultAddress = addressesState.defaultAddress?.formattedAddress ??
        (user?.defaultAddress?.trim() ?? '');

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
                  Container(
                    width: 64,
                    height: 64,
                    decoration: BoxDecoration(
                      shape: BoxShape.circle,
                      color: GoLivreurTheme.primaire.withValues(alpha: 0.15),
                    ),
                    clipBehavior: Clip.antiAlias,
                    child: (photoUrl != null && photoUrl.isNotEmpty)
                        ? Image.network(
                            photoUrl,
                            width: 64,
                            height: 64,
                            fit: BoxFit.cover,
                            errorBuilder: (_, __, ___) => const Icon(
                              Icons.person,
                              color: GoLivreurTheme.primaire,
                              size: 36,
                            ),
                          )
                        : const Icon(
                            Icons.person,
                            color: GoLivreurTheme.primaire,
                            size: 36,
                          ),
                  ),
                  const SizedBox(width: 16),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          user?.name ?? 'Client',
                          style: const TextStyle(
                            fontSize: 18,
                            fontWeight: FontWeight.bold,
                            color: GoLivreurTheme.textePrincipal,
                          ),
                        ),
                        const SizedBox(height: 4),
                        Text(
                          '+225 ${user?.phone ?? ""}',
                          style: const TextStyle(color: GoLivreurTheme.texteSecondaire, fontSize: 14),
                        ),
                        if (user?.email != null && user!.email!.isNotEmpty) ...[
                          const SizedBox(height: 2),
                          Text(
                            user.email!,
                            style: const TextStyle(color: GoLivreurTheme.texteSecondaire, fontSize: 12),
                          ),
                        ],
                        const SizedBox(height: 4),
                        Row(
                          children: [
                            const Icon(
                              Icons.location_city_outlined,
                              size: 14,
                              color: GoLivreurTheme.secondaire,
                            ),
                            const SizedBox(width: 4),
                            Flexible(
                              child: Text(
                                registeredCity.isNotEmpty
                                    ? registeredCity
                                    : '',
                                style: const TextStyle(
                                  color: GoLivreurTheme.secondaire,
                                  fontSize: 12,
                                  fontWeight: FontWeight.w600,
                                ),
                                maxLines: 1,
                                overflow: TextOverflow.ellipsis,
                              ),
                            ),
                          ],
                        ),
                        if (activeDefaultAddress.isNotEmpty) ...[
                          const SizedBox(height: 2),
                          Text(
                            activeDefaultAddress,
                            style: const TextStyle(
                              color: GoLivreurTheme.texteSecondaire,
                              fontSize: 12,
                            ),
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                          ),
                        ],
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
                    subtitle: addressesState.defaultAddress != null
                        ? '${addressesState.defaultAddress!.label} — ${addressesState.defaultAddress!.neighborhood} (${addressesState.addresses.length} enregistrée(s))'
                        : (user?.defaultAddress?.isNotEmpty == true
                            ? user!.defaultAddress!
                            : 'Ajouter, modifier ou choisir une adresse par défaut'),
                    onTap: _openAddressesScreen,
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
                  icon: Icons.pin_outlined,
                  title: 'Verrouillage rapide (Code PIN 4 chiffres)',
                  subtitle: 'Chiffre votre session locale (3 essais max)',
                  onTap: () async {
                    final storage = ref.read(secureStorageProvider);
                    final changed = await PinSetupSheet.show(
                      context,
                      pinService: PinLockService(storage: storage),
                    );
                    if (changed != null && context.mounted) {
                      ScaffoldMessenger.of(context).showSnackBar(
                        SnackBar(
                          content: Text(
                            changed
                                ? 'Code PIN rapide activé. Votre Refresh Token est désormais chiffré.'
                                : 'Code PIN rapide désactivé.',
                          ),
                        ),
                      );
                    }
                  },
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
                  title: registeredCity.isNotEmpty
                      ? 'Support client & Urgence ($registeredCity)'
                      : 'Support client & Urgence',
                  subtitle: registeredCity.isNotEmpty
                      ? 'Assistance agence locale $registeredCity'
                      : 'Assistance agence locale GoLivreur',
                  onTap: () {
                    ScaffoldMessenger.of(context).showSnackBar(
                      SnackBar(
                        content: Text(
                          registeredCity.isNotEmpty
                              ? 'Contactez votre agence Support GoLivreur ($registeredCity).'
                              : 'Contactez votre agence Support GoLivreur.',
                        ),
                      ),
                    );
                  },
                ),
              ],
            ),
          ),
          const SizedBox(height: 16),

          // ─── Bascule Inter-Apps (SSO PKCE) ────────────────────────────────
          InterAppSwitcherSection(
            currentApp: GoLivreurAppTarget.client,
            userRoles: user?.roles ?? const ['client'],
            apiClient: ref.read(apiClientProvider),
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
              onPressed: () => deconnecterEtReinitialiserClient(context, ref),
              icon: const Icon(Icons.logout, size: 20),
              label: const Text('Se déconnecter', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 15)),
            ),
          ),
          const SizedBox(height: 16),
          Center(
            child: Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                Container(
                  width: 20,
                  height: 20,
                  decoration: BoxDecoration(
                    borderRadius: BorderRadius.circular(5),
                  ),
                  clipBehavior: Clip.antiAlias,
                  child: GoLivreurBrandMark(
                    logoUrl: branding.logoUrl,
                    defaultEmoji: branding.defaultEmoji,
                    size: 20,
                  ),
                ),
                const SizedBox(width: 6),
                Text(
                  registeredCity.isNotEmpty
                      ? 'GL Client v5.0 • $registeredCity'
                      : 'GL Client v5.0',
                  style: const TextStyle(color: GoLivreurTheme.texteSecondaire, fontSize: 12),
                ),
              ],
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

  void _showNotificationsDialog(BuildContext context) {
    ref.invalidate(clientNotificationsProvider);
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
            child: Consumer(
              builder: (ctx, modalRef, _) {
                final notifsAsync = modalRef.watch(clientNotificationsProvider);
                return Column(
                  mainAxisSize: MainAxisSize.min,
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Text(
                      'Notifications Récentes',
                      style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold),
                    ),
                    const SizedBox(height: 12),
                    notifsAsync.when(
                      loading: () => const Padding(
                        padding: EdgeInsets.all(16),
                        child: Center(child: CircularProgressIndicator()),
                      ),
                      error: (_, __) => const Text('Impossible de charger les notifications.'),
                      data: (items) {
                        if (items.isEmpty) {
                          return const Padding(
                            padding: EdgeInsets.symmetric(vertical: 16),
                            child: Row(
                              children: [
                                Icon(Icons.notifications_none, color: GoLivreurTheme.texteSecondaire),
                                SizedBox(width: 10),
                                Expanded(
                                  child: Text(
                                    'Aucune alerte en cours. Service standard actif.',
                                    style: TextStyle(color: GoLivreurTheme.texteSecondaire),
                                  ),
                                ),
                              ],
                            ),
                          );
                        }
                        return Column(
                          mainAxisSize: MainAxisSize.min,
                          children: items.take(5).map((item) {
                            return ListTile(
                              contentPadding: EdgeInsets.zero,
                              leading: Icon(
                                item.type == 'rain_mode'
                                    ? Icons.cloudy_snowing
                                    : Icons.notifications_active_outlined,
                                color: item.type == 'rain_mode'
                                    ? const Color(0xFF2E75B6)
                                    : GoLivreurTheme.primaire,
                              ),
                              title: Text(item.title, style: const TextStyle(fontWeight: FontWeight.w600)),
                              subtitle: Text(item.body),
                            );
                          }).toList(),
                        );
                      },
                    ),
                  ],
                );
              },
            ),
          ),
        );
      },
    );
  }
}
