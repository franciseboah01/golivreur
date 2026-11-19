import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:golivreur_core/config/app_theme.dart';
import 'package:golivreur_core/config/env_config.dart';
import 'package:golivreur_core/models/modeles.dart';
import 'package:golivreur_core/widgets/golivreur_widgets.dart';
import '../providers/goshop_provider.dart';
import '../widgets/promo_banners_carousel.dart';
import 'merchant_detail_screen.dart';
import 'cart_screen.dart';
import '../../auth/providers/auth_provider.dart';
import '../../auth/utils/client_logout.dart';
import '../../gocolis/screens/gocolis_home_screen.dart';
import '../../orders/screens/orders_tracking_history_screen.dart';
import '../../profile/screens/client_addresses_screen.dart';
import '../../profile/screens/profile_screen.dart';

class GoShopHomeScreen extends ConsumerStatefulWidget {
  const GoShopHomeScreen({super.key});

  @override
  ConsumerState<GoShopHomeScreen> createState() => _GoShopHomeScreenState();
}

class _GoShopHomeScreenState extends ConsumerState<GoShopHomeScreen> {
  final TextEditingController _searchCtrl = TextEditingController();
  final FocusNode _searchFocusNode = FocusNode();
  final ScrollController _scrollController = ScrollController();
  bool _detectingCity = false;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      final authState = ref.read(authProvider);
      ref.read(weatherProvider.notifier).chargerStatutVille(cityId: authState.user?.cityId);
      ref.read(clientAddressesProvider.notifier).chargerAdresses();
      ref.read(favoritesProvider.notifier).chargerFavoris();
      _synchroniserVilleGps(silent: true);
    });
  }

  Future<void> _synchroniserVilleGps({bool silent = false}) async {
    if (_detectingCity) return;
    if (!silent && mounted) {
      setState(() => _detectingCity = true);
    }

    final result = await ref
        .read(authProvider.notifier)
        .detecterVilleParGps(silent: silent);

    if (!mounted) return;
    if (!silent) {
      setState(() => _detectingCity = false);
    }

    if (result == null) return;

    if (result.changed && result.cityId != null) {
      if (ref.read(cartProvider).items.isNotEmpty) {
        ref.read(cartProvider.notifier).clear();
      }
      ref.read(selectedNeighborhoodProvider.notifier).state = '';
      await ref
          .read(weatherProvider.notifier)
          .chargerStatutVille(cityId: result.cityId);
      await ref
          .read(clientAddressesProvider.notifier)
          .chargerAdresses(syncNeighborhood: true);
      await ref.read(favoritesProvider.notifier).chargerFavoris();
      ref.invalidate(merchantsListProvider);
      ref.invalidate(promoBannersProvider);
      ref.invalidate(categoriesProvider);
      ref.invalidate(clientNotificationsProvider);

      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(
              '📍 Ville détectée : ${result.cityName ?? ''} — commerces, livraisons et expéditions mis à jour.',
            ),
            backgroundColor: GoLivreurTheme.succes,
            duration: const Duration(seconds: 4),
          ),
        );
      }
    } else if (!silent && mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(result.message),
          backgroundColor:
              result.detected ? GoLivreurTheme.secondaire : GoLivreurTheme.erreur,
        ),
      );
    }
  }

  @override
  void dispose() {
    _searchCtrl.dispose();
    _searchFocusNode.dispose();
    _scrollController.dispose();
    super.dispose();
  }

  void _focusSearch() {
    if (_scrollController.hasClients) {
      _scrollController.animateTo(
        0,
        duration: const Duration(milliseconds: 250),
        curve: Curves.easeOut,
      );
    }
    _searchFocusNode.requestFocus();
  }

  @override
  Widget build(BuildContext context) {
    final weather = ref.watch(weatherProvider);
    final selectedQuarter = ref.watch(selectedNeighborhoodProvider);
    final destination = ref.watch(activeDeliveryDestinationProvider);
    final categories = ref.watch(categoriesProvider).valueOrNull ?? fallbackCategoriesList;
    final selectedCat = ref.watch(selectedCategoryProvider);
    final searchQuery = ref.watch(searchQueryProvider);
    final merchantsAsync = ref.watch(merchantsListProvider);
    final cart = ref.watch(cartProvider);
    final deliveryEstimate = ref.watch(cartDeliveryEstimateProvider);
    final favoriteIds = ref.watch(favoritesProvider);
    final notifs = ref.watch(clientNotificationsProvider).valueOrNull ?? const <ClientNotificationItem>[];
    final unreadCount = notifs.where((n) => !n.isRead).length;

    return Scaffold(
      backgroundColor: GoLivreurTheme.fond,
      drawer: _buildQuickAccessDrawer(context, ref),
      appBar: AppBar(
        automaticallyImplyLeading: false,
        backgroundColor: Colors.white,
        elevation: 0.5,
        titleSpacing: 16,
        title: Row(
          children: [
            // Logo GoLivreur interactif ouvrant le menu rapide
            Builder(
              builder: (drawerCtx) => InkWell(
                onTap: () => Scaffold.of(drawerCtx).openDrawer(),
                borderRadius: BorderRadius.circular(10),
                child: Tooltip(
                  message: 'Menu & Profil',
                  child: Container(
                    width: 36,
                    height: 36,
                    decoration: BoxDecoration(
                      color: GoLivreurTheme.primaire,
                      borderRadius: BorderRadius.circular(10),
                      boxShadow: [
                        BoxShadow(
                          color: GoLivreurTheme.primaire.withValues(alpha: 0.3),
                          blurRadius: 4,
                          offset: const Offset(0, 2),
                        ),
                      ],
                    ),
                    child: const Icon(
                      Icons.menu,
                      color: Colors.white,
                      size: 20,
                    ),
                  ),
                ),
              ),
            ),
            const SizedBox(width: 10),
            // Sélecteur de quartier
            Expanded(
              child: InkWell(
                onTap: () => _showNeighborhoodPicker(context, ref),
                borderRadius: BorderRadius.circular(8),
                child: Padding(
                  padding: const EdgeInsets.symmetric(vertical: 4, horizontal: 4),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      const Text(
                        'Livraison à',
                        style: TextStyle(
                          fontSize: 12,
                          color: GoLivreurTheme.texteSecondaire,
                          fontWeight: FontWeight.w400,
                        ),
                      ),
                      Row(
                        children: [
                          Flexible(
                            child: Text(
                              selectedQuarter.isNotEmpty
                                  ? (destination.cityName.isNotEmpty
                                      ? '${destination.cityName}, $selectedQuarter'
                                      : selectedQuarter)
                                  : (destination.cityName.isNotEmpty
                                      ? destination.cityName
                                      : 'Choisir un quartier'),
                              style: const TextStyle(
                                fontSize: 15,
                                fontWeight: FontWeight.bold,
                                color: GoLivreurTheme.textePrincipal,
                              ),
                              overflow: TextOverflow.ellipsis,
                            ),
                          ),
                          const Icon(
                            Icons.arrow_drop_down,
                            color: GoLivreurTheme.textePrincipal,
                            size: 20,
                          ),
                        ],
                      ),
                    ],
                  ),
                ),
              ),
            ),
          ],
        ),
        actions: [
          // 1. Bouton Recherche
          IconButton(
            constraints: const BoxConstraints(minWidth: 36, minHeight: 36),
            padding: EdgeInsets.zero,
            tooltip: 'Rechercher',
            icon: const Icon(Icons.search, color: GoLivreurTheme.textePrincipal, size: 22),
            onPressed: _focusSearch,
          ),
          // 2. Bouton Panier avec badge compteur
          Stack(
            alignment: Alignment.center,
            children: [
              IconButton(
                constraints: const BoxConstraints(minWidth: 36, minHeight: 36),
                padding: EdgeInsets.zero,
                tooltip: 'Mon panier',
                icon: const Icon(
                  Icons.shopping_cart_outlined,
                  color: GoLivreurTheme.textePrincipal,
                  size: 22,
                ),
                onPressed: () {
                  Navigator.of(context).push(
                    MaterialPageRoute(builder: (_) => const CartScreen()),
                  );
                },
              ),
              if (cart.totalItemCount > 0)
                Positioned(
                  top: 4,
                  right: 2,
                  child: Container(
                    padding: const EdgeInsets.symmetric(horizontal: 5, vertical: 1),
                    decoration: BoxDecoration(
                      color: GoLivreurTheme.secondaire,
                      borderRadius: BorderRadius.circular(10),
                    ),
                    child: Text(
                      '${cart.totalItemCount}',
                      style: const TextStyle(
                        color: Colors.white,
                        fontSize: 10,
                        fontWeight: FontWeight.bold,
                      ),
                    ),
                  ),
                ),
            ],
          ),
          // 3. Bouton Favoris avec badge compteur
          Stack(
            alignment: Alignment.center,
            children: [
              IconButton(
                constraints: const BoxConstraints(minWidth: 36, minHeight: 36),
                padding: EdgeInsets.zero,
                tooltip: 'Mes favoris',
                icon: Icon(
                  favoriteIds.isNotEmpty ? Icons.favorite : Icons.favorite_border,
                  color: favoriteIds.isNotEmpty
                      ? GoLivreurTheme.erreur
                      : GoLivreurTheme.textePrincipal,
                  size: 22,
                ),
                onPressed: () => _showFavoritesSheet(context),
              ),
              if (favoriteIds.isNotEmpty)
                Positioned(
                  top: 4,
                  right: 2,
                  child: Container(
                    padding: const EdgeInsets.symmetric(horizontal: 5, vertical: 1),
                    decoration: BoxDecoration(
                      color: GoLivreurTheme.primaire,
                      borderRadius: BorderRadius.circular(10),
                    ),
                    child: Text(
                      '${favoriteIds.length}',
                      style: const TextStyle(
                        color: Colors.white,
                        fontSize: 10,
                        fontWeight: FontWeight.bold,
                      ),
                    ),
                  ),
                ),
            ],
          ),
          // 4. Bouton Notification (après Recherche, Panier et Favoris)
          Stack(
            alignment: Alignment.center,
            children: [
              IconButton(
                constraints: const BoxConstraints(minWidth: 36, minHeight: 36),
                padding: EdgeInsets.zero,
                tooltip: 'Notifications',
                icon: const Icon(
                  Icons.notifications_outlined,
                  color: GoLivreurTheme.textePrincipal,
                  size: 22,
                ),
                onPressed: () => _showNotificationsSheet(context),
              ),
              if (unreadCount > 0)
                Positioned(
                  top: 6,
                  right: 6,
                  child: Container(
                    width: 8,
                    height: 8,
                    decoration: const BoxDecoration(
                      color: GoLivreurTheme.erreur,
                      shape: BoxShape.circle,
                    ),
                  ),
                ),
            ],
          ),
          const SizedBox(width: 6),
        ],
      ),
      body: Stack(
        children: [
          RefreshIndicator(
            color: GoLivreurTheme.primaire,
            onRefresh: () async {
              await ref
                  .read(weatherProvider.notifier)
                  .chargerStatutVille(cityId: ref.read(authProvider).user?.cityId);
              await ref.read(clientAddressesProvider.notifier).chargerAdresses(syncNeighborhood: false);
              await ref.read(favoritesProvider.notifier).chargerFavoris();
              ref.invalidate(categoriesProvider);
              ref.invalidate(merchantsListProvider);
              ref.invalidate(clientNotificationsProvider);
            },
            child: SingleChildScrollView(
              controller: _scrollController,
              physics: const AlwaysScrollableScrollPhysics(),
              padding: EdgeInsets.only(
                bottom: cart.items.isNotEmpty ? 100 : 32,
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  // ─── Bandeau Mode Urgence (si actif en base) ───────────
                  if (weather.emergencyModeActive)
                    Container(
                      width: double.infinity,
                      margin: const EdgeInsets.fromLTRB(16, 12, 16, 4),
                      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
                      decoration: BoxDecoration(
                        color: GoLivreurTheme.erreur.withValues(alpha: 0.1),
                        borderRadius: BorderRadius.circular(12),
                        border: Border.all(color: GoLivreurTheme.erreur.withValues(alpha: 0.4)),
                      ),
                      child: Row(
                        children: [
                          const Icon(Icons.warning_amber_rounded, color: GoLivreurTheme.erreur, size: 22),
                          const SizedBox(width: 10),
                          Expanded(
                            child: Text(
                              weather.emergencyMessage ??
                                  'Mode Urgence activé : les livraisons peuvent être perturbées.',
                              style: const TextStyle(
                                fontSize: 13,
                                fontWeight: FontWeight.w600,
                                color: GoLivreurTheme.erreur,
                              ),
                            ),
                          ),
                        ],
                      ),
                    ),

                  // ─── Bandeau Mode Pluie (uniquement si actif en base) ────
                  if (weather.isRainMode && !weather.bannerDismissed)
                    Container(
                      width: double.infinity,
                      margin: const EdgeInsets.fromLTRB(16, 12, 16, 4),
                      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
                      decoration: BoxDecoration(
                        color: const Color(0xFFE8F1F8), // Bleu ciel doux
                        borderRadius: BorderRadius.circular(12),
                        border: Border.all(color: const Color(0xFF2E75B6).withValues(alpha: 0.3)),
                      ),
                      child: Row(
                        children: [
                          const Icon(Icons.cloudy_snowing, color: Color(0xFF2E75B6), size: 24),
                          const SizedBox(width: 10),
                          Expanded(
                            child: Text(
                              weather.weatherAlertMessage,
                              style: const TextStyle(
                                fontSize: 13,
                                fontWeight: FontWeight.w600,
                                color: Color(0xFF1B4D7E),
                              ),
                            ),
                          ),
                          GestureDetector(
                            onTap: () {
                              ref.read(weatherProvider.notifier).dismissBanner();
                            },
                            child: const Padding(
                              padding: EdgeInsets.only(left: 6),
                              child: Icon(Icons.close, size: 18, color: Color(0xFF1B4D7E)),
                            ),
                          ),
                        ],
                      ),
                    ),

                  // ─── Bandeau Adresse de livraison cohérente avec le quartier ─
                  Padding(
                    padding: const EdgeInsets.fromLTRB(16, 10, 16, 0),
                    child: InkWell(
                      onTap: () async {
                        await Navigator.of(context).push(
                          MaterialPageRoute(builder: (_) => const ClientAddressesScreen()),
                        );
                      },
                      borderRadius: BorderRadius.circular(10),
                      child: Container(
                        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                        decoration: BoxDecoration(
                          color: Colors.white,
                          borderRadius: BorderRadius.circular(10),
                          border: Border.all(color: GoLivreurTheme.bordure),
                        ),
                        child: Row(
                          children: [
                            const Icon(Icons.location_on_outlined, size: 18, color: GoLivreurTheme.secondaire),
                            const SizedBox(width: 8),
                            Expanded(
                              child: Text(
                                destination.fullAddress.isNotEmpty
                                    ? 'Adresse : ${destination.fullAddress}'
                                    : 'Aucune adresse par défaut définie',
                                style: const TextStyle(
                                  fontSize: 12,
                                  fontWeight: FontWeight.w600,
                                  color: GoLivreurTheme.textePrincipal,
                                ),
                                maxLines: 1,
                                overflow: TextOverflow.ellipsis,
                              ),
                            ),
                            const Text(
                              'Gérer',
                              style: TextStyle(
                                fontSize: 12,
                                fontWeight: FontWeight.bold,
                                color: GoLivreurTheme.primaire,
                              ),
                            ),
                          ],
                        ),
                      ),
                    ),
                  ),

                  // ─── Zone Recherche & Filtres (Interactive) ───────────────
                  Padding(
                    padding: const EdgeInsets.fromLTRB(16, 10, 16, 8),
                    child: Container(
                      height: 50,
                      decoration: BoxDecoration(
                        color: Colors.white,
                        borderRadius: BorderRadius.circular(12),
                        border: Border.all(color: GoLivreurTheme.bordure),
                        boxShadow: [
                          BoxShadow(
                            color: Colors.black.withValues(alpha: 0.03),
                            blurRadius: 6,
                            offset: const Offset(0, 2),
                          ),
                        ],
                      ),
                      child: Row(
                        children: [
                          const SizedBox(width: 12),
                          const Icon(Icons.search, color: GoLivreurTheme.primaire, size: 22),
                          const SizedBox(width: 8),
                          Expanded(
                            child: TextField(
                              controller: _searchCtrl,
                              focusNode: _searchFocusNode,
                              onChanged: (value) {
                                ref.read(searchQueryProvider.notifier).state = value;
                              },
                              decoration: const InputDecoration(
                                border: InputBorder.none,
                                enabledBorder: InputBorder.none,
                                focusedBorder: InputBorder.none,
                                filled: false,
                                isDense: true,
                                contentPadding: EdgeInsets.zero,
                                hintText: 'Rechercher un maquis, garba, pharmacie...',
                                hintStyle: TextStyle(
                                  color: GoLivreurTheme.texteSecondaire,
                                  fontSize: 14,
                                ),
                              ),
                            ),
                          ),
                          if (searchQuery.trim().isNotEmpty)
                            IconButton(
                              icon: const Icon(Icons.close, size: 18, color: GoLivreurTheme.texteSecondaire),
                              onPressed: () {
                                _searchCtrl.clear();
                                ref.read(searchQueryProvider.notifier).state = '';
                              },
                            ),
                          InkWell(
                            onTap: () => _showNeighborhoodPicker(context, ref),
                            borderRadius: BorderRadius.circular(8),
                            child: Container(
                              margin: const EdgeInsets.only(right: 8),
                              padding: const EdgeInsets.all(6),
                              decoration: BoxDecoration(
                                color: GoLivreurTheme.fond,
                                borderRadius: BorderRadius.circular(8),
                              ),
                              child: const Icon(
                                Icons.tune,
                                color: GoLivreurTheme.textePrincipal,
                                size: 18,
                              ),
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),

                  // ─── Carrousel Bannières Dynamiques (auto-expirables) ────
                  const PromoBannersCarousel(),

                  // ─── Rayons & Services (Catégories) ──────────────────────
                  const Padding(
                    padding: EdgeInsets.fromLTRB(16, 16, 16, 10),
                    child: Text(
                      'Rayons & Services',
                      style: TextStyle(
                        fontSize: 18,
                        fontWeight: FontWeight.bold,
                        color: GoLivreurTheme.textePrincipal,
                      ),
                    ),
                  ),
                  SizedBox(
                    height: 92,
                    child: ListView.separated(
                      padding: const EdgeInsets.symmetric(horizontal: 16),
                      scrollDirection: Axis.horizontal,
                      itemCount: categories.length,
                      separatorBuilder: (_, __) => const SizedBox(width: 12),
                      itemBuilder: (context, index) {
                        final cat = categories[index];
                        final isSelected = cat.id == selectedCat;

                        return InkWell(
                          onTap: () {
                            ref.read(selectedCategoryProvider.notifier).state = cat.id;
                          },
                          borderRadius: BorderRadius.circular(14),
                          child: Column(
                            mainAxisSize: MainAxisSize.min,
                            children: [
                              Container(
                                width: 58,
                                height: 58,
                                decoration: BoxDecoration(
                                  color: isSelected
                                      ? GoLivreurTheme.primaire
                                      : Colors.white,
                                  borderRadius: BorderRadius.circular(16),
                                  border: Border.all(
                                    color: isSelected
                                        ? GoLivreurTheme.primaire
                                        : GoLivreurTheme.bordure,
                                  ),
                                  boxShadow: [
                                    BoxShadow(
                                      color: Colors.black.withValues(alpha: 0.04),
                                      blurRadius: 4,
                                      offset: const Offset(0, 2),
                                    ),
                                  ],
                                ),
                                clipBehavior: Clip.antiAlias,
                                child: _buildCategoryVisual(cat, isSelected),
                              ),
                              const SizedBox(height: 6),
                              SizedBox(
                                width: 70,
                                child: Text(
                                  cat.name,
                                  textAlign: TextAlign.center,
                                  style: TextStyle(
                                    fontSize: 12,
                                    fontWeight: isSelected ? FontWeight.bold : FontWeight.w500,
                                    color: isSelected
                                        ? GoLivreurTheme.primaire
                                        : GoLivreurTheme.textePrincipal,
                                  ),
                                  maxLines: 1,
                                  overflow: TextOverflow.ellipsis,
                                ),
                              ),
                            ],
                          ),
                        );
                      },
                    ),
                  ),

                  // ─── Populaire dans la ville active ──────────────────────
                  Padding(
                    padding: const EdgeInsets.fromLTRB(16, 20, 16, 12),
                    child: Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: [
                        Row(
                          children: [
                            const Icon(Icons.local_fire_department, color: GoLivreurTheme.primaire, size: 22),
                            const SizedBox(width: 6),
                            Text(
                              destination.cityName.isNotEmpty
                                  ? 'Populaire à ${destination.cityName}'
                                  : 'Commerces populaires',
                              style: const TextStyle(
                                fontSize: 18,
                                fontWeight: FontWeight.bold,
                                color: GoLivreurTheme.textePrincipal,
                              ),
                            ),
                          ],
                        ),
                        Text(
                          'Sélection locale',
                          style: TextStyle(
                            fontSize: 13,
                            color: GoLivreurTheme.texteSecondaire.withValues(alpha: 0.8),
                          ),
                        ),
                      ],
                    ),
                  ),

                  // ─── Liste des Commerces ─────────────────────────────────
                  merchantsAsync.when(
                    data: (merchants) {
                      final normalizedQuery = searchQuery.trim().toLowerCase();
                      final filtered = merchants.where((m) {
                        final matchesCategory =
                            selectedCat == 'all' || m.category == selectedCat;
                        if (!matchesCategory) return false;
                        if (normalizedQuery.isEmpty) return true;
                        final inMerchant = m.name.toLowerCase().contains(normalizedQuery) ||
                            m.address.toLowerCase().contains(normalizedQuery) ||
                            (m.categoryName ?? m.category).toLowerCase().contains(normalizedQuery);
                        final inProducts = m.products.any(
                          (p) => p.name.toLowerCase().contains(normalizedQuery),
                        );
                        return inMerchant || inProducts;
                      }).toList();

                      if (filtered.isEmpty) {
                        return Container(
                          padding: const EdgeInsets.all(32),
                          alignment: Alignment.center,
                          child: Column(
                            children: [
                              const Icon(Icons.storefront_outlined, size: 48, color: GoLivreurTheme.texteSecondaire),
                              const SizedBox(height: 12),
                              Text(
                                normalizedQuery.isNotEmpty
                                    ? 'Aucun résultat pour « ${searchQuery.trim()} ».'
                                    : 'Aucun commerce disponible dans cette catégorie pour le moment.',
                                textAlign: TextAlign.center,
                                style: const TextStyle(color: GoLivreurTheme.texteSecondaire),
                              ),
                            ],
                          ),
                        );
                      }

                      return ListView.separated(
                        shrinkWrap: true,
                        physics: const NeverScrollableScrollPhysics(),
                        padding: const EdgeInsets.symmetric(horizontal: 16),
                        itemCount: filtered.length,
                        separatorBuilder: (_, __) => const SizedBox(height: 16),
                        itemBuilder: (context, index) {
                          final merchant = filtered[index];
                          return _buildMerchantCard(
                            context,
                            ref,
                            merchant,
                            weather,
                            selectedQuarter,
                            favoriteIds.contains(merchant.id),
                          );
                        },
                      );
                    },
                    loading: () => const GoLivreurListSkeleton(
                      count: 4,
                      itemHeight: 96,
                    ),
                    error: (err, stack) => GoLivreurErrorView.fromException(
                      error: err,
                      onRetry: () => ref.invalidate(merchantsListProvider),
                    ),
                  ),

                  // ─── Bouton Achat Spécifique ─────────────────────────────
                  Padding(
                    padding: const EdgeInsets.all(16),
                    child: InkWell(
                      onTap: () {
                        ScaffoldMessenger.of(context).showSnackBar(
                          const SnackBar(
                            content: Text('Service coursier personnalisé : contactez le support ou créez un colis GoColis !'),
                          ),
                        );
                      },
                      borderRadius: BorderRadius.circular(14),
                      child: Container(
                        height: 52, // Zone tactile min 52px selon Stitch
                        decoration: BoxDecoration(
                          color: GoLivreurTheme.primaire.withValues(alpha: 0.15),
                          borderRadius: BorderRadius.circular(14),
                          border: Border.all(color: GoLivreurTheme.primaire.withValues(alpha: 0.4)),
                        ),
                        child: Row(
                          mainAxisAlignment: MainAxisAlignment.center,
                          children: [
                            const Icon(Icons.add_shopping_cart, color: Color(0xFFC47B00), size: 22),
                            const SizedBox(width: 8),
                            Text(
                              destination.cityName.isNotEmpty
                                  ? 'Besoin d\'un achat spécifique à ${destination.cityName} ?'
                                  : 'Besoin d\'un achat spécifique ?',
                              style: const TextStyle(
                                fontSize: 15,
                                fontWeight: FontWeight.w600,
                                color: Color(0xFF835500),
                              ),
                            ),
                          ],
                        ),
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ),

          // ─── Barre Flottante Panier Actif ──────────────────────────────
          if (cart.items.isNotEmpty)
            Positioned(
              bottom: 16,
              left: 16,
              right: 16,
              child: InkWell(
                onTap: () {
                  Navigator.of(context).push(
                    MaterialPageRoute(builder: (_) => const CartScreen()),
                  );
                },
                borderRadius: BorderRadius.circular(14),
                child: Container(
                  height: 56, // Touch target confortable
                  padding: const EdgeInsets.symmetric(horizontal: 16),
                  decoration: BoxDecoration(
                    color: GoLivreurTheme.secondaire, // Vert Confiance #1B5E20
                    borderRadius: BorderRadius.circular(14),
                    boxShadow: [
                      BoxShadow(
                        color: GoLivreurTheme.secondaire.withValues(alpha: 0.4),
                        blurRadius: 10,
                        offset: const Offset(0, 4),
                      ),
                    ],
                  ),
                  child: Row(
                    children: [
                      Container(
                        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                        decoration: BoxDecoration(
                          color: Colors.white.withValues(alpha: 0.2),
                          borderRadius: BorderRadius.circular(8),
                        ),
                        child: Text(
                          '${cart.totalItemCount}',
                          style: const TextStyle(
                            color: Colors.white,
                            fontWeight: FontWeight.bold,
                            fontSize: 14,
                          ),
                        ),
                      ),
                      const SizedBox(width: 12),
                      const Text(
                        'Voir mon panier',
                        style: TextStyle(
                          color: Colors.white,
                          fontWeight: FontWeight.w600,
                          fontSize: 15,
                        ),
                      ),
                      const Spacer(),
                      Text(
                        '${(cart.subtotal + deliveryEstimate.deliveryFee).toInt()} F CFA',
                        style: const TextStyle(
                          color: Colors.white,
                          fontWeight: FontWeight.bold,
                          fontSize: 16,
                        ),
                      ),
                      const SizedBox(width: 6),
                      const Icon(Icons.arrow_forward_ios, color: Colors.white, size: 14),
                    ],
                  ),
                ),
              ),
            ),
        ],
      ),
    );
  }

  Widget _buildMerchantCard(
    BuildContext context,
    WidgetRef ref,
    MerchantModel merchant,
    WeatherState weather,
    String selectedQuarter,
    bool isFavorite,
  ) {
    final isRainMode = weather.isRainMode;
    final distKm = weather.distancePourQuartier(selectedQuarter, merchant: merchant);
    final fee = weather.calculerFraisLivraison(
      distKm,
      isPharmacy: merchant.category == 'pharmacie',
    ).toInt();

    return InkWell(
      onTap: () {
        Navigator.of(context).push(
          MaterialPageRoute(
            builder: (_) => MerchantDetailScreen(merchant: merchant),
          ),
        );
      },
      borderRadius: BorderRadius.circular(16),
      child: Container(
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(16),
          border: Border.all(color: GoLivreurTheme.bordure),
          boxShadow: [
            BoxShadow(
              color: Colors.black.withValues(alpha: 0.04),
              blurRadius: 8,
              offset: const Offset(0, 2),
            ),
          ],
        ),
        clipBehavior: Clip.antiAlias,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            // Image de couverture
            Stack(
              children: [
                Container(
                  height: 140,
                  width: double.infinity,
                  color: Colors.grey.shade200,
                  child: merchant.photoUrl != null
                      ? Image.network(
                          merchant.photoUrl!,
                          fit: BoxFit.cover,
                          errorBuilder: (_, __, ___) => _buildPlaceholderImage(merchant),
                        )
                      : _buildPlaceholderImage(merchant),
                ),
                // Badge Catégorie officiel depuis la base de données
                Positioned(
                  top: 10,
                  left: 10,
                  child: Container(
                    padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                    decoration: BoxDecoration(
                      color: GoLivreurTheme.primaire,
                      borderRadius: BorderRadius.circular(8),
                      boxShadow: [
                        BoxShadow(
                          color: Colors.black.withValues(alpha: 0.2),
                          blurRadius: 4,
                        ),
                      ],
                    ),
                    child: Text(
                      (merchant.categoryName ?? merchant.category).toUpperCase(),
                      style: const TextStyle(
                        color: Colors.white,
                        fontWeight: FontWeight.bold,
                        fontSize: 11,
                      ),
                    ),
                  ),
                ),
                // Bouton Favori (Cœur) sur la carte
                Positioned(
                  top: 8,
                  right: 8,
                  child: Material(
                    color: Colors.white.withValues(alpha: 0.92),
                    shape: const CircleBorder(),
                    child: InkWell(
                      customBorder: const CircleBorder(),
                      onTap: () async {
                        final added = await ref.read(favoritesProvider.notifier).toggleFavori(merchant);
                        if (context.mounted) {
                          ScaffoldMessenger.of(context).showSnackBar(
                            SnackBar(
                              content: Text(
                                added
                                    ? '${merchant.name} ajouté aux favoris'
                                    : '${merchant.name} retiré des favoris',
                              ),
                              duration: const Duration(seconds: 1),
                            ),
                          );
                        }
                      },
                      child: Padding(
                        padding: const EdgeInsets.all(6),
                        child: Icon(
                          isFavorite ? Icons.favorite : Icons.favorite_border,
                          color: isFavorite ? GoLivreurTheme.erreur : GoLivreurTheme.textePrincipal,
                          size: 20,
                        ),
                      ),
                    ),
                  ),
                ),
                // Badge Délai estimé avec mention Pluie si active
                Positioned(
                  bottom: 10,
                  right: 10,
                  child: Container(
                    padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                    decoration: BoxDecoration(
                      color: const Color(0xFF212121).withValues(alpha: 0.85),
                      borderRadius: BorderRadius.circular(8),
                    ),
                    child: Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        const Icon(Icons.schedule, color: GoLivreurTheme.primaire, size: 14),
                        const SizedBox(width: 4),
                        Text(
                          isRainMode
                              ? '${merchant.prepTimeMinutes + 15} min (🌧️ +15m)'
                              : '${merchant.prepTimeMinutes}-${merchant.prepTimeMinutes + 10} min',
                          style: const TextStyle(
                            color: Colors.white,
                            fontSize: 12,
                            fontWeight: FontWeight.w600,
                          ),
                        ),
                      ],
                    ),
                  ),
                ),
              ],
            ),
            // Contenu carte
            Padding(
              padding: const EdgeInsets.all(14),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      Expanded(
                        child: Row(
                          children: [
                            _buildMerchantLogoBadge(merchant),
                            const SizedBox(width: 8),
                            Expanded(
                              child: Text(
                                merchant.name,
                                style: const TextStyle(
                                  fontSize: 16,
                                  fontWeight: FontWeight.bold,
                                  color: GoLivreurTheme.textePrincipal,
                                ),
                                maxLines: 1,
                                overflow: TextOverflow.ellipsis,
                              ),
                            ),
                          ],
                        ),
                      ),
                      const SizedBox(width: 8),
                      Container(
                        padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                        decoration: BoxDecoration(
                          color: GoLivreurTheme.fond,
                          borderRadius: BorderRadius.circular(6),
                        ),
                        child: Row(
                          children: [
                            const Icon(Icons.star, color: Color(0xFFF9A825), size: 15),
                            const SizedBox(width: 3),
                            Text(
                              merchant.averageRating.toStringAsFixed(1),
                              style: const TextStyle(
                                fontSize: 13,
                                fontWeight: FontWeight.bold,
                                color: GoLivreurTheme.textePrincipal,
                              ),
                            ),
                          ],
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 4),
                  Text(
                    merchant.address,
                    style: const TextStyle(
                      fontSize: 13,
                      color: GoLivreurTheme.texteSecondaire,
                    ),
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                  ),
                  const SizedBox(height: 10),
                  Row(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      Row(
                        children: [
                          const Icon(Icons.delivery_dining, color: GoLivreurTheme.secondaire, size: 18),
                          const SizedBox(width: 4),
                          const Text(
                            'Frais : ',
                            style: TextStyle(fontSize: 13, color: GoLivreurTheme.texteSecondaire),
                          ),
                          Text(
                            '$fee F CFA (${distKm.toStringAsFixed(1)} km)',
                            style: const TextStyle(
                              fontSize: 13,
                              fontWeight: FontWeight.bold,
                              color: GoLivreurTheme.textePrincipal,
                            ),
                          ),
                        ],
                      ),
                      Container(
                        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
                        decoration: BoxDecoration(
                          color: GoLivreurTheme.primaire.withValues(alpha: 0.12),
                          borderRadius: BorderRadius.circular(8),
                        ),
                        child: const Row(
                          children: [
                            Text(
                              'Commander',
                              style: TextStyle(
                                fontSize: 13,
                                fontWeight: FontWeight.bold,
                                color: Color(0xFF835500),
                              ),
                            ),
                            SizedBox(width: 4),
                            Icon(Icons.chevron_right, size: 16, color: Color(0xFF835500)),
                          ],
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
    );
  }

  /// Affiche le logo de la catégorie si uploadé en BDD, sinon son emoji, sinon l'icône fallback.
  Widget _buildCategoryVisual(CategoryItem cat, bool isSelected) {
    if (cat.logoUrl != null && cat.logoUrl!.isNotEmpty) {
      return Image.network(
        cat.logoUrl!,
        width: 58,
        height: 58,
        fit: BoxFit.cover,
        errorBuilder: (_, __, ___) => _buildCategoryEmojiOrIcon(cat, isSelected),
      );
    }

    return _buildCategoryEmojiOrIcon(cat, isSelected);
  }

  Widget _buildCategoryEmojiOrIcon(CategoryItem cat, bool isSelected) {
    if (cat.emoji != null && cat.emoji!.trim().isNotEmpty) {
      return Center(
        child: Text(
          cat.emoji!,
          style: const TextStyle(fontSize: 26),
        ),
      );
    }

    return Icon(
      _getCategoryIcon(cat.id),
      color: isSelected ? Colors.white : GoLivreurTheme.primaire,
      size: 28,
    );
  }

  /// Affiche le logo du commerce si uploadé en BDD, sinon son emoji, sinon icône fallback.
  Widget _buildMerchantLogoBadge(MerchantModel merchant) {
    if (merchant.logoUrl != null && merchant.logoUrl!.isNotEmpty) {
      return ClipRRect(
        borderRadius: BorderRadius.circular(8),
        child: Image.network(
          merchant.logoUrl!,
          width: 28,
          height: 28,
          fit: BoxFit.cover,
          errorBuilder: (_, __, ___) => _buildMerchantEmojiBox(merchant),
        ),
      );
    }

    return _buildMerchantEmojiBox(merchant);
  }

  Widget _buildMerchantEmojiBox(MerchantModel merchant) {
    final emoji = merchant.emoji?.trim();
    return Container(
      width: 28,
      height: 28,
      alignment: Alignment.center,
      decoration: BoxDecoration(
        color: GoLivreurTheme.primaire.withValues(alpha: 0.12),
        borderRadius: BorderRadius.circular(8),
      ),
      child: emoji != null && emoji.isNotEmpty
          ? Text(emoji, style: const TextStyle(fontSize: 16))
          : Icon(_getCategoryIcon(merchant.category), size: 16, color: GoLivreurTheme.primaire),
    );
  }

  Widget _buildPlaceholderImage(MerchantModel merchant) {
    final emoji = merchant.emoji?.trim();
    return Container(
      color: const Color(0xFFF0EDED),
      child: Center(
        child: emoji != null && emoji.isNotEmpty
            ? Text(emoji, style: const TextStyle(fontSize: 48))
            : Icon(
                _getCategoryIcon(merchant.category),
                size: 54,
                color: GoLivreurTheme.primaire.withValues(alpha: 0.5),
              ),
      ),
    );
  }

  IconData _getCategoryIcon(String catId) {
    switch (catId) {
      case 'restaurant':
        return Icons.restaurant;
      case 'maquis':
        return Icons.outdoor_grill;
      case 'pharmacie':
        return Icons.local_pharmacy;
      case 'boulangerie':
      case 'patisserie':
        return Icons.bakery_dining;
      case 'fast_food':
      case 'fastfood':
        return Icons.lunch_dining;
      case 'supermarche':
      case 'epicerie':
        return Icons.shopping_cart;
      case 'boissons':
        return Icons.liquor;
      case 'boutique':
      case 'mode':
        return Icons.checkroom;
      case 'high_tech':
      case 'hightech':
      case 'electronique':
        return Icons.devices;
      default:
        return Icons.storefront;
    }
  }

  void _showFavoritesSheet(BuildContext context) {
    showModalBottomSheet(
      context: context,
      backgroundColor: Colors.white,
      isScrollControlled: true,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
      builder: (sheetCtx) {
        return SafeArea(
          child: Consumer(
            builder: (ctx, modalRef, _) {
              final favoriteIds = modalRef.watch(favoritesProvider);
              final merchants = modalRef.watch(merchantsListProvider).valueOrNull ?? [];
              final favMerchants =
                  merchants.where((m) => favoriteIds.contains(m.id)).toList();

              return Padding(
                padding: const EdgeInsets.fromLTRB(20, 20, 20, 20),
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: [
                        const Text(
                          'Mes Commerces Favoris',
                          style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold),
                        ),
                        IconButton(
                          icon: const Icon(Icons.close),
                          onPressed: () => Navigator.pop(sheetCtx),
                        ),
                      ],
                    ),
                    const Divider(),
                    if (favMerchants.isEmpty)
                      const Padding(
                        padding: EdgeInsets.symmetric(vertical: 28),
                        child: Center(
                          child: Column(
                            children: [
                              Icon(Icons.favorite_border, size: 44, color: GoLivreurTheme.texteSecondaire),
                              SizedBox(height: 10),
                              Text(
                                'Aucun commerce en favori pour le moment.\nTouchez le cœur sur un commerce pour l\'ajouter.',
                                textAlign: TextAlign.center,
                                style: TextStyle(color: GoLivreurTheme.texteSecondaire, fontSize: 13),
                              ),
                            ],
                          ),
                        ),
                      )
                    else
                      ConstrainedBox(
                        constraints: const BoxConstraints(maxHeight: 360),
                        child: ListView.builder(
                          shrinkWrap: true,
                          itemCount: favMerchants.length,
                          itemBuilder: (_, index) {
                            final m = favMerchants[index];
                            return ListTile(
                              contentPadding: EdgeInsets.zero,
                              leading: _buildMerchantLogoBadge(m),
                              title: Text(
                                m.name,
                                style: const TextStyle(fontWeight: FontWeight.bold),
                              ),
                              subtitle: Text(m.address, maxLines: 1, overflow: TextOverflow.ellipsis),
                              trailing: IconButton(
                                icon: const Icon(Icons.favorite, color: GoLivreurTheme.erreur),
                                onPressed: () {
                                  modalRef.read(favoritesProvider.notifier).toggleFavori(m);
                                },
                              ),
                              onTap: () {
                                Navigator.pop(sheetCtx);
                                Navigator.of(context).push(
                                  MaterialPageRoute(
                                    builder: (_) => MerchantDetailScreen(merchant: m),
                                  ),
                                );
                              },
                            );
                          },
                        ),
                      ),
                  ],
                ),
              );
            },
          ),
        );
      },
    );
  }

  void _showNotificationsSheet(BuildContext context) {
    ref.invalidate(clientNotificationsProvider);
    showModalBottomSheet(
      context: context,
      backgroundColor: Colors.white,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
      builder: (sheetCtx) {
        return SafeArea(
          child: Padding(
            padding: const EdgeInsets.all(20),
            child: Consumer(
              builder: (ctx, modalRef, _) {
                final notifsAsync = modalRef.watch(clientNotificationsProvider);
                final weather = modalRef.watch(weatherProvider);
                return Column(
                  mainAxisSize: MainAxisSize.min,
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: [
                        const Text(
                          'Notifications & Alertes',
                          style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold),
                        ),
                        Container(
                          padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                          decoration: BoxDecoration(
                            color: (weather.isRainMode
                                    ? const Color(0xFF2E75B6)
                                    : GoLivreurTheme.secondaire)
                                .withValues(alpha: 0.12),
                            borderRadius: BorderRadius.circular(10),
                          ),
                          child: Text(
                            weather.isRainMode ? '🌧️ Mode Pluie actif' : '☀️ Météo normale',
                            style: TextStyle(
                              fontSize: 11,
                              fontWeight: FontWeight.bold,
                              color: weather.isRainMode
                                  ? const Color(0xFF2E75B6)
                                  : GoLivreurTheme.secondaire,
                            ),
                          ),
                        ),
                      ],
                    ),
                    const Divider(),
                    notifsAsync.when(
                      loading: () => const Padding(
                        padding: EdgeInsets.all(16),
                        child: Center(child: CircularProgressIndicator()),
                      ),
                      error: (_, __) => const Text('Impossible de charger les alertes.'),
                      data: (items) {
                        if (items.isEmpty) {
                          return const Padding(
                            padding: EdgeInsets.symmetric(vertical: 20),
                            child: Text(
                              'Aucune nouvelle notification pour le moment.',
                              style: TextStyle(color: GoLivreurTheme.texteSecondaire),
                            ),
                          );
                        }
                        return Column(
                          mainAxisSize: MainAxisSize.min,
                          children: items.take(6).map((n) {
                            return ListTile(
                              contentPadding: EdgeInsets.zero,
                              leading: Icon(
                                n.type == 'rain_mode'
                                    ? Icons.cloudy_snowing
                                    : Icons.notifications_active_outlined,
                                color: n.type == 'rain_mode'
                                    ? const Color(0xFF2E75B6)
                                    : GoLivreurTheme.primaire,
                              ),
                              title: Text(n.title, style: const TextStyle(fontWeight: FontWeight.w600)),
                              subtitle: Text(n.body),
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

  void _showNeighborhoodPicker(BuildContext context, WidgetRef ref) {
    final weather = ref.read(weatherProvider);
    final quarters = ref.read(daloaNeighborhoodsProvider);
    final current = ref.read(selectedNeighborhoodProvider);

    showModalBottomSheet(
      context: context,
      backgroundColor: Colors.white,
      isScrollControlled: true,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
      builder: (sheetCtx) {
        return SafeArea(
          child: SizedBox(
            height: MediaQuery.of(context).size.height * 0.68,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Padding(
                  padding: const EdgeInsets.fromLTRB(20, 20, 12, 8),
                  child: Row(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      Expanded(
                        child: Text(
                          weather.cityName.isNotEmpty
                              ? 'Choisissez votre quartier (${weather.cityName})'
                              : 'Choisissez votre quartier',
                          style: const TextStyle(
                            fontSize: 18,
                            fontWeight: FontWeight.bold,
                            color: GoLivreurTheme.textePrincipal,
                          ),
                        ),
                      ),
                      TextButton.icon(
                        onPressed: () {
                          Navigator.pop(sheetCtx);
                          Navigator.of(context).push(
                            MaterialPageRoute(builder: (_) => const ClientAddressesScreen()),
                          );
                        },
                        icon: const Icon(Icons.edit_location_alt_outlined, size: 18),
                        label: const Text('Mes adresses'),
                      ),
                    ],
                  ),
                ),
                Padding(
                  padding: const EdgeInsets.fromLTRB(16, 0, 16, 10),
                  child: InkWell(
                    borderRadius: BorderRadius.circular(12),
                    onTap: () async {
                      Navigator.pop(sheetCtx);
                      await _synchroniserVilleGps(silent: false);
                    },
                    child: Container(
                      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
                      decoration: BoxDecoration(
                        color: GoLivreurTheme.secondaire.withValues(alpha: 0.08),
                        borderRadius: BorderRadius.circular(12),
                        border: Border.all(
                          color: GoLivreurTheme.secondaire.withValues(alpha: 0.35),
                        ),
                      ),
                      child: const Row(
                        children: [
                          Icon(
                            Icons.my_location,
                            color: GoLivreurTheme.secondaire,
                            size: 20,
                          ),
                          SizedBox(width: 10),
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text(
                                  'Détecter ma ville automatiquement (GPS)',
                                  style: TextStyle(
                                    fontSize: 13,
                                    fontWeight: FontWeight.bold,
                                    color: GoLivreurTheme.secondaire,
                                  ),
                                ),
                                Text(
                                  'Vous changez de localité ? Met à jour commerces, livraisons et expéditions.',
                                  style: TextStyle(
                                    fontSize: 11,
                                    color: GoLivreurTheme.texteSecondaire,
                                  ),
                                ),
                              ],
                            ),
                          ),
                          Icon(
                            Icons.chevron_right,
                            color: GoLivreurTheme.secondaire,
                            size: 20,
                          ),
                        ],
                      ),
                    ),
                  ),
                ),
                const Divider(height: 1),
                Expanded(
                  child: quarters.isEmpty
                      ? const Center(
                          child: Padding(
                            padding: EdgeInsets.all(24),
                            child: Text(
                              'Aucun quartier disponible pour cette ville.',
                              textAlign: TextAlign.center,
                              style: TextStyle(color: GoLivreurTheme.texteSecondaire),
                            ),
                          ),
                        )
                      : ListView.builder(
                          itemCount: quarters.length,
                          itemBuilder: (_, index) {
                            final q = quarters[index];
                            final isSel = q == current;
                            return ListTile(
                              leading: Icon(
                                Icons.location_on,
                                color: isSel ? GoLivreurTheme.primaire : GoLivreurTheme.texteSecondaire,
                              ),
                              title: Text(
                                q,
                                style: TextStyle(
                                  fontWeight: isSel ? FontWeight.bold : FontWeight.normal,
                                  color: isSel ? GoLivreurTheme.primaire : GoLivreurTheme.textePrincipal,
                                ),
                              ),
                              trailing: isSel
                                  ? const Icon(Icons.check, color: GoLivreurTheme.primaire)
                                  : null,
                              onTap: () {
                                ref.read(selectedNeighborhoodProvider.notifier).state = q;
                                Navigator.pop(sheetCtx);
                              },
                            );
                          },
                        ),
                ),
              ],
            ),
          ),
        );
      },
    );
  }

  Widget _buildQuickAccessDrawer(BuildContext context, WidgetRef ref) {
    final user = ref.watch(authProvider).user;
    final branding = ref.watch(appBrandingProvider);
    final photoUrl = GoLivreurEnv.resolveMediaUrl(user?.photoUrl);
    final cityName = (user?.cityName ?? '').trim();

    return Drawer(
      child: SafeArea(
        child: Column(
          children: [
            Container(
              width: double.infinity,
              padding: const EdgeInsets.all(20),
              decoration: const BoxDecoration(
                color: GoLivreurTheme.primaire,
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  // ── Identité GoLivreur (logo configuré ou emoji par défaut) ──
                  Row(
                    children: [
                      Container(
                        width: 44,
                        height: 44,
                        decoration: BoxDecoration(
                          color: Colors.white,
                          borderRadius: BorderRadius.circular(12),
                        ),
                        clipBehavior: Clip.antiAlias,
                        child: GoLivreurBrandMark(
                          logoUrl: branding.logoUrl,
                          defaultEmoji: branding.defaultEmoji,
                          size: 44,
                        ),
                      ),
                      const SizedBox(width: 12),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            const Text(
                              'GoLivreur',
                              style: TextStyle(
                                color: Colors.white,
                                fontSize: 20,
                                fontWeight: FontWeight.bold,
                              ),
                            ),
                            if (cityName.isNotEmpty)
                              Text(
                                cityName,
                                style: const TextStyle(
                                  color: Colors.white70,
                                  fontSize: 13,
                                ),
                              ),
                          ],
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 16),
                  // ── Profil du client (photo à côté de son nom) ─────────────
                  Row(
                    children: [
                      Container(
                        width: 44,
                        height: 44,
                        decoration: BoxDecoration(
                          color: Colors.white,
                          shape: BoxShape.circle,
                          border: Border.all(
                            color: Colors.white.withValues(alpha: 0.8),
                            width: 1.5,
                          ),
                        ),
                        clipBehavior: Clip.antiAlias,
                        child: (photoUrl != null && photoUrl.isNotEmpty)
                            ? Image.network(
                                photoUrl,
                                width: 44,
                                height: 44,
                                fit: BoxFit.cover,
                                errorBuilder: (_, __, ___) => const Icon(
                                  Icons.person,
                                  color: GoLivreurTheme.primaire,
                                  size: 24,
                                ),
                              )
                            : const Icon(
                                Icons.person,
                                color: GoLivreurTheme.primaire,
                                size: 24,
                              ),
                      ),
                      const SizedBox(width: 12),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              user?.name ?? 'Client',
                              style: const TextStyle(
                                color: Colors.white,
                                fontSize: 16,
                                fontWeight: FontWeight.bold,
                              ),
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                            ),
                            Text(
                              '+225 ${user?.phone ?? ""}',
                              style: const TextStyle(
                                color: Colors.white70,
                                fontSize: 13,
                              ),
                            ),
                          ],
                        ),
                      ),
                    ],
                  ),
                ],
              ),
            ),
            Expanded(
              child: ListView(
                padding: const EdgeInsets.symmetric(vertical: 8),
                children: [
                  ListTile(
                    leading: const Icon(Icons.storefront, color: GoLivreurTheme.primaire),
                    title: Text(
                      cityName.isNotEmpty
                          ? 'Shop - Commerces $cityName'
                          : 'Shop - Commerces locaux',
                    ),
                    onTap: () => Navigator.pop(context),
                  ),
                  ListTile(
                    leading: const Icon(Icons.all_inbox, color: Colors.blue),
                    title: const Text('Colis - Envoi de colis'),
                    subtitle: const Text('Expédition Express'),
                    onTap: () {
                      Navigator.pop(context);
                      Navigator.of(context).push(
                        MaterialPageRoute(builder: (_) => const GoColisHomeScreen()),
                      );
                    },
                  ),
                  ListTile(
                    leading: const Icon(Icons.receipt_long, color: Colors.orange),
                    title: const Text('Mes Commandes en cours'),
                    subtitle: const Text('Suivi en temps réel & historique'),
                    onTap: () {
                      Navigator.pop(context);
                      Navigator.of(context).push(
                        MaterialPageRoute(builder: (_) => const OrdersTrackingHistoryScreen()),
                      );
                    },
                  ),
                  ListTile(
                    leading: const Icon(Icons.person, color: Colors.purple),
                    title: const Text('Mon Profil & Adresses'),
                    subtitle: const Text('Informations personnelles & favoris'),
                    onTap: () {
                      Navigator.pop(context);
                      Navigator.of(context).push(
                        MaterialPageRoute(builder: (_) => const ProfileScreen()),
                      );
                    },
                  ),
                  const Divider(),
                  ListTile(
                    leading: const Icon(Icons.support_agent, color: Colors.teal),
                    title: Text(
                      cityName.isNotEmpty
                          ? 'Support Client $cityName'
                          : 'Support Client GoLivreur',
                    ),
                    subtitle: const Text('Assistance & suivi'),
                    onTap: () {
                      Navigator.pop(context);
                      ScaffoldMessenger.of(context).showSnackBar(
                        SnackBar(
                          content: Text(
                            cityName.isNotEmpty
                                ? 'Contactez votre agence Support GoLivreur ($cityName).'
                                : 'Contactez votre agence Support GoLivreur.',
                          ),
                        ),
                      );
                    },
                  ),
                  ListTile(
                    leading: const Icon(Icons.logout, color: GoLivreurTheme.erreur),
                    title: const Text('Déconnexion', style: TextStyle(color: GoLivreurTheme.erreur, fontWeight: FontWeight.bold)),
                    onTap: () async {
                      Navigator.pop(context);
                      await deconnecterEtReinitialiserClient(context, ref);
                    },
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

