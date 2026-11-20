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

class _HomeProductEntry {
  final ProductModel product;
  final MerchantModel merchant;

  const _HomeProductEntry({
    required this.product,
    required this.merchant,
  });
}

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
  bool _showShopsView = false; // Par défaut : affiche les Produits sous Catégories (bascule vers Boutiques à la demande)

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
                                hintText: 'Rechercher un produit, plat, boutique...',
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

                  // ─── Catégories (cartes pastel arrondies fidèles à la maquette) ──
                  const Padding(
                    padding: EdgeInsets.fromLTRB(16, 16, 16, 10),
                    child: Text(
                      'Catégories',
                      style: TextStyle(
                        fontSize: 18,
                        fontWeight: FontWeight.bold,
                        color: Color(0xFF1F2937),
                      ),
                    ),
                  ),
                  SizedBox(
                    height: 88,
                    child: ListView.separated(
                      padding: const EdgeInsets.symmetric(horizontal: 16),
                      scrollDirection: Axis.horizontal,
                      itemCount: categories.length + 1,
                      separatorBuilder: (_, __) => const SizedBox(width: 10),
                      itemBuilder: (context, index) {
                        // Dernière tuile : accès rapide Colis (comme sur la maquette)
                        if (index == categories.length) {
                          return InkWell(
                            onTap: () {
                              Navigator.of(context).push(
                                MaterialPageRoute(
                                  builder: (_) => const GoColisHomeScreen(),
                                ),
                              );
                            },
                            borderRadius: BorderRadius.circular(16),
                            child: Container(
                              width: 82,
                              padding: const EdgeInsets.symmetric(
                                horizontal: 6,
                                vertical: 8,
                              ),
                              decoration: BoxDecoration(
                                color: const Color(0xFFDCE6EE),
                                borderRadius: BorderRadius.circular(16),
                                border: Border.all(
                                  color: const Color(0xFFC6D5E2),
                                ),
                              ),
                              child: const Column(
                                mainAxisAlignment: MainAxisAlignment.center,
                                children: [
                                  Icon(
                                    Icons.inventory_2_outlined,
                                    color: Color(0xFF47607A),
                                    size: 30,
                                  ),
                                  SizedBox(height: 6),
                                  Text(
                                    'Colis',
                                    textAlign: TextAlign.center,
                                    maxLines: 1,
                                    overflow: TextOverflow.ellipsis,
                                    style: TextStyle(
                                      fontSize: 12,
                                      fontWeight: FontWeight.w600,
                                      color: Color(0xFF1F2937),
                                    ),
                                  ),
                                ],
                              ),
                            ),
                          );
                        }

                        final cat = categories[index];
                        final isSelected = cat.id == selectedCat;
                        final pastelBg = _categoryPastelColor(cat.id, index);

                        return InkWell(
                          onTap: () {
                            ref.read(selectedCategoryProvider.notifier).state =
                                cat.id;
                          },
                          borderRadius: BorderRadius.circular(16),
                          child: AnimatedContainer(
                            duration: const Duration(milliseconds: 160),
                            width: 84,
                            padding: const EdgeInsets.symmetric(
                              horizontal: 6,
                              vertical: 8,
                            ),
                            decoration: BoxDecoration(
                              color: isSelected
                                  ? GoLivreurTheme.primaire
                                      .withValues(alpha: 0.18)
                                  : pastelBg,
                              borderRadius: BorderRadius.circular(16),
                              border: Border.all(
                                color: isSelected
                                    ? GoLivreurTheme.primaire
                                    : pastelBg,
                                width: isSelected ? 2 : 1,
                              ),
                            ),
                            child: Column(
                              mainAxisAlignment: MainAxisAlignment.center,
                              children: [
                                SizedBox(
                                  width: 36,
                                  height: 36,
                                  child: ClipRRect(
                                    borderRadius: BorderRadius.circular(10),
                                    child: _buildCategoryVisual(cat, isSelected),
                                  ),
                                ),
                                const SizedBox(height: 6),
                                Text(
                                  cat.name,
                                  textAlign: TextAlign.center,
                                  style: TextStyle(
                                    fontSize: 11.5,
                                    fontWeight: isSelected
                                        ? FontWeight.bold
                                        : FontWeight.w600,
                                    color: isSelected
                                        ? const Color(0xFF835500)
                                        : const Color(0xFF1F2937),
                                  ),
                                  maxLines: 1,
                                  overflow: TextOverflow.ellipsis,
                                ),
                              ],
                            ),
                          ),
                        );
                      },
                    ),
                  ),

                  // ─── Populaires à {Ville} : Produits par défaut + bascule Boutiques ─
                  Padding(
                    padding: const EdgeInsets.fromLTRB(16, 20, 16, 12),
                    child: Row(
                      children: [
                        Expanded(
                          child: Text(
                            _showShopsView
                                ? (destination.cityName.isNotEmpty
                                    ? 'Boutiques à ${destination.cityName}'
                                    : 'Nos boutiques')
                                : (destination.cityName.isNotEmpty
                                    ? 'Populaires à ${destination.cityName}'
                                    : 'Produits populaires'),
                            style: const TextStyle(
                              fontSize: 18,
                              fontWeight: FontWeight.bold,
                              color: Color(0xFF1F2937),
                            ),
                            overflow: TextOverflow.ellipsis,
                          ),
                        ),
                        const SizedBox(width: 8),
                        Container(
                          padding: const EdgeInsets.all(3),
                          decoration: BoxDecoration(
                            color: Colors.white,
                            borderRadius: BorderRadius.circular(10),
                            border: Border.all(color: GoLivreurTheme.bordure),
                          ),
                          child: Row(
                            mainAxisSize: MainAxisSize.min,
                            children: [
                              _buildViewTogglePill(
                                label: 'Produits',
                                active: !_showShopsView,
                                onTap: () =>
                                    setState(() => _showShopsView = false),
                              ),
                              _buildViewTogglePill(
                                label: 'Boutiques',
                                active: _showShopsView,
                                onTap: () =>
                                    setState(() => _showShopsView = true),
                              ),
                            ],
                          ),
                        ),
                      ],
                    ),
                  ),

                  // ─── Grille Produits (par défaut) OU Liste complète Boutiques ───
                  merchantsAsync.when(
                    data: (merchants) {
                      final normalizedQuery = searchQuery.trim().toLowerCase();
                      final filteredMerchants = merchants.where((m) {
                        final matchesCategory =
                            selectedCat == 'all' || m.category == selectedCat;
                        if (!matchesCategory) return false;
                        if (normalizedQuery.isEmpty) return true;
                        final inMerchant =
                            m.name.toLowerCase().contains(normalizedQuery) ||
                                m.address.toLowerCase().contains(normalizedQuery) ||
                                (m.categoryName ?? m.category)
                                    .toLowerCase()
                                    .contains(normalizedQuery);
                        final inProducts = m.products.any(
                          (p) =>
                              p.name.toLowerCase().contains(normalizedQuery) ||
                              (p.description ?? '')
                                  .toLowerCase()
                                  .contains(normalizedQuery),
                        );
                        return inMerchant || inProducts;
                      }).toList();

                      // Extraire tous les produits disponibles des boutiques filtrées
                      final productEntries = <_HomeProductEntry>[];
                      for (final m in filteredMerchants) {
                        for (final p in m.products) {
                          if (!p.isAvailable) continue;
                          if (normalizedQuery.isNotEmpty) {
                            final matchesProduct =
                                p.name.toLowerCase().contains(normalizedQuery) ||
                                    (p.description ?? '')
                                        .toLowerCase()
                                        .contains(normalizedQuery) ||
                                    (p.category ?? '')
                                        .toLowerCase()
                                        .contains(normalizedQuery) ||
                                    m.name
                                        .toLowerCase()
                                        .contains(normalizedQuery);
                            if (!matchesProduct) continue;
                          }
                          productEntries.add(
                            _HomeProductEntry(product: p, merchant: m),
                          );
                        }
                      }

                      // Mode 1 : Vue complète des Boutiques (sur clic "Boutiques")
                      if (_showShopsView) {
                        if (filteredMerchants.isEmpty) {
                          return _buildEmptyCatalogState(
                            icon: Icons.storefront_outlined,
                            message: normalizedQuery.isNotEmpty
                                ? 'Aucune boutique trouvée pour « ${searchQuery.trim()} ».'
                                : 'Aucune boutique disponible dans cette catégorie pour le moment.',
                          );
                        }

                        return ListView.separated(
                          shrinkWrap: true,
                          physics: const NeverScrollableScrollPhysics(),
                          padding: const EdgeInsets.symmetric(horizontal: 16),
                          itemCount: filteredMerchants.length,
                          separatorBuilder: (_, __) =>
                              const SizedBox(height: 16),
                          itemBuilder: (context, index) {
                            final merchant = filteredMerchants[index];
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
                      }

                      // Mode 2 (Par défaut) : Grille 2 colonnes de Produits + bande « Boutiques à visiter »
                      return Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          if (productEntries.isEmpty)
                            _buildEmptyCatalogState(
                              icon: Icons.shopping_bag_outlined,
                              message: normalizedQuery.isNotEmpty
                                  ? 'Aucun produit trouvé pour « ${searchQuery.trim()} ».'
                                  : 'Aucun produit publié dans cette catégorie pour le moment.',
                              actionLabel: filteredMerchants.isNotEmpty
                                  ? 'Visiter les ${filteredMerchants.length} boutique(s)'
                                  : null,
                              onAction: filteredMerchants.isNotEmpty
                                  ? () => setState(() => _showShopsView = true)
                                  : null,
                            )
                          else
                            GridView.builder(
                              shrinkWrap: true,
                              physics: const NeverScrollableScrollPhysics(),
                              padding:
                                  const EdgeInsets.symmetric(horizontal: 16),
                              gridDelegate:
                                  const SliverGridDelegateWithFixedCrossAxisCount(
                                crossAxisCount: 2,
                                mainAxisSpacing: 12,
                                crossAxisSpacing: 12,
                                mainAxisExtent: 238,
                              ),
                              itemCount: productEntries.length,
                              itemBuilder: (context, index) {
                                final entry = productEntries[index];
                                return _buildProductGridCard(
                                  context,
                                  ref,
                                  entry,
                                  weather.isRainMode,
                                );
                              },
                            ),

                          // Bandeau horizontal « Boutiques à visiter » juste en dessous des produits
                          if (filteredMerchants.isNotEmpty) ...[
                            Padding(
                              padding: const EdgeInsets.fromLTRB(16, 22, 16, 10),
                              child: Row(
                                mainAxisAlignment:
                                    MainAxisAlignment.spaceBetween,
                                children: [
                                  const Row(
                                    children: [
                                      Icon(
                                        Icons.storefront_outlined,
                                        color: GoLivreurTheme.primaire,
                                        size: 20,
                                      ),
                                      SizedBox(width: 6),
                                      Text(
                                        'Visiter les boutiques',
                                        style: TextStyle(
                                          fontSize: 16,
                                          fontWeight: FontWeight.bold,
                                          color: Color(0xFF1F2937),
                                        ),
                                      ),
                                    ],
                                  ),
                                  TextButton(
                                    onPressed: () =>
                                        setState(() => _showShopsView = true),
                                    child: const Text(
                                      'Voir tout ›',
                                      style: TextStyle(
                                        fontWeight: FontWeight.bold,
                                        color: GoLivreurTheme.primaire,
                                      ),
                                    ),
                                  ),
                                ],
                              ),
                            ),
                            SizedBox(
                              height: 118,
                              child: ListView.separated(
                                padding:
                                    const EdgeInsets.symmetric(horizontal: 16),
                                scrollDirection: Axis.horizontal,
                                itemCount: filteredMerchants.length,
                                separatorBuilder: (_, __) =>
                                    const SizedBox(width: 12),
                                itemBuilder: (context, index) {
                                  final m = filteredMerchants[index];
                                  return _buildCompactShopCard(context, m);
                                },
                              ),
                            ),
                          ],
                        ],
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

  static String _formatFcfa(double price) {
    final digits = price.round().toString();
    final buf = StringBuffer();
    for (var i = 0; i < digits.length; i++) {
      final posFromEnd = digits.length - i;
      buf.write(digits[i]);
      if (posFromEnd > 1 && posFromEnd % 3 == 1) {
        buf.write(' ');
      }
    }
    return '${buf.toString()} FCFA';
  }

  Color _categoryPastelColor(String catId, int index) {
    return switch (catId) {
      'all' => const Color(0xFFFFF1DB),
      'restaurant' || 'maquis' || 'fast_food' => const Color(0xFFFCE6D4),
      'cosmetique' || 'pharmacie' || 'beaute' => const Color(0xFFD4EFE3),
      'supermarche' || 'epicerie' || 'boulangerie' => const Color(0xFFFCECC9),
      'boutique' || 'mode' => const Color(0xFFEAE1F9),
      'hightech' || 'high_tech' => const Color(0xFFDCE6EE),
      _ => const [
          Color(0xFFFCE6D4),
          Color(0xFFD4EFE3),
          Color(0xFFFCECC9),
          Color(0xFFDCE6EE),
          Color(0xFFEAE1F9),
        ][index % 5],
    };
  }

  Widget _buildViewTogglePill({
    required String label,
    required bool active,
    required VoidCallback onTap,
  }) {
    return GestureDetector(
      onTap: onTap,
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
        decoration: BoxDecoration(
          color: active ? GoLivreurTheme.primaire : Colors.transparent,
          borderRadius: BorderRadius.circular(8),
        ),
        child: Text(
          label,
          style: TextStyle(
            fontSize: 12,
            fontWeight: FontWeight.bold,
            color: active ? Colors.white : GoLivreurTheme.texteSecondaire,
          ),
        ),
      ),
    );
  }

  Widget _buildEmptyCatalogState({
    required IconData icon,
    required String message,
    String? actionLabel,
    VoidCallback? onAction,
  }) {
    return Container(
      padding: const EdgeInsets.all(28),
      alignment: Alignment.center,
      child: Column(
        children: [
          Icon(icon, size: 46, color: GoLivreurTheme.texteSecondaire),
          const SizedBox(height: 10),
          Text(
            message,
            textAlign: TextAlign.center,
            style: const TextStyle(color: GoLivreurTheme.texteSecondaire),
          ),
          if (actionLabel != null && onAction != null) ...[
            const SizedBox(height: 12),
            OutlinedButton.icon(
              onPressed: onAction,
              icon: const Icon(Icons.storefront_outlined, size: 18),
              label: Text(actionLabel),
            ),
          ],
        ],
      ),
    );
  }

  /// Carte Produit en grille 2 colonnes (fidèle à la maquette : photo, nom, prix FCFA en vert, délai, lien boutique).
  Widget _buildProductGridCard(
    BuildContext context,
    WidgetRef ref,
    _HomeProductEntry entry,
    bool isRainMode,
  ) {
    final product = entry.product;
    final merchant = entry.merchant;
    final prepMin = merchant.prepTimeMinutes + (isRainMode ? 15 : 0);

    return InkWell(
      onTap: () => _showProductDetailSheet(context, ref, entry, prepMin),
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
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            // Photo produit arrondie + bouton rapide "+"
            Padding(
              padding: const EdgeInsets.fromLTRB(8, 8, 8, 6),
              child: Stack(
                children: [
                  ClipRRect(
                    borderRadius: BorderRadius.circular(12),
                    child: SizedBox(
                      height: 112,
                      width: double.infinity,
                      child: product.photoUrl != null &&
                              product.photoUrl!.isNotEmpty
                          ? Image.network(
                              product.photoUrl!,
                              fit: BoxFit.cover,
                              errorBuilder: (_, __, ___) =>
                                  _buildProductPlaceholder(merchant),
                            )
                          : _buildProductPlaceholder(merchant),
                    ),
                  ),
                  Positioned(
                    right: 6,
                    bottom: 6,
                    child: Material(
                      color: GoLivreurTheme.primaire,
                      shape: const CircleBorder(),
                      elevation: 2,
                      child: InkWell(
                        customBorder: const CircleBorder(),
                        onTap: () {
                          ref
                              .read(cartProvider.notifier)
                              .addProduct(merchant, product);
                          ScaffoldMessenger.of(context).showSnackBar(
                            SnackBar(
                              content:
                                  Text('${product.name} ajouté au panier !'),
                              backgroundColor: GoLivreurTheme.secondaire,
                              duration: const Duration(seconds: 1),
                            ),
                          );
                        },
                        child: const Padding(
                          padding: EdgeInsets.all(5),
                          child:
                              Icon(Icons.add, color: Colors.white, size: 18),
                        ),
                      ),
                    ),
                  ),
                ],
              ),
            ),

            // Nom du produit + Prix en vert + Badge délai
            Expanded(
              child: Padding(
                padding: const EdgeInsets.symmetric(horizontal: 10),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      product.name,
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(
                        fontSize: 13.5,
                        fontWeight: FontWeight.w700,
                        color: Color(0xFF1F2937),
                        height: 1.2,
                      ),
                    ),
                    const Spacer(),
                    Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: [
                        Flexible(
                          child: Text(
                            _formatFcfa(product.price),
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: const TextStyle(
                              fontSize: 13.5,
                              fontWeight: FontWeight.w800,
                              color: GoLivreurTheme.secondaire,
                            ),
                          ),
                        ),
                        const SizedBox(width: 4),
                        Container(
                          padding: const EdgeInsets.symmetric(
                            horizontal: 6,
                            vertical: 2,
                          ),
                          decoration: BoxDecoration(
                            color: const Color(0xFFF3F4F6),
                            borderRadius: BorderRadius.circular(6),
                          ),
                          child: Text(
                            '$prepMin min',
                            style: const TextStyle(
                              fontSize: 10.5,
                              fontWeight: FontWeight.w600,
                              color: Color(0xFF4B5563),
                            ),
                          ),
                        ),
                      ],
                    ),
                  ],
                ),
              ),
            ),

            // Lien discret cliquable pour visiter directement la boutique
            InkWell(
              onTap: () {
                Navigator.of(context).push(
                  MaterialPageRoute(
                    builder: (_) => MerchantDetailScreen(merchant: merchant),
                  ),
                );
              },
              borderRadius: const BorderRadius.vertical(
                bottom: Radius.circular(16),
              ),
              child: Container(
                margin: const EdgeInsets.only(top: 6),
                padding:
                    const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                decoration: const BoxDecoration(
                  color: Color(0xFFFAFAFA),
                  borderRadius: BorderRadius.vertical(
                    bottom: Radius.circular(15),
                  ),
                  border: Border(
                    top: BorderSide(color: Color(0xFFEEEEEE)),
                  ),
                ),
                child: Row(
                  children: [
                    const Icon(
                      Icons.storefront_outlined,
                      size: 13,
                      color: GoLivreurTheme.texteSecondaire,
                    ),
                    const SizedBox(width: 4),
                    Expanded(
                      child: Text(
                        merchant.name,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(
                          fontSize: 11,
                          fontWeight: FontWeight.w600,
                          color: GoLivreurTheme.texteSecondaire,
                        ),
                      ),
                    ),
                    const Icon(
                      Icons.chevron_right,
                      size: 14,
                      color: GoLivreurTheme.primaire,
                    ),
                  ],
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildProductPlaceholder(MerchantModel merchant) {
    final emoji = merchant.emoji?.trim();
    return Container(
      color: const Color(0xFFF3F0EA),
      alignment: Alignment.center,
      child: emoji != null && emoji.isNotEmpty
          ? Text(emoji, style: const TextStyle(fontSize: 38))
          : Icon(
              _getCategoryIcon(merchant.category),
              size: 38,
              color: GoLivreurTheme.primaire.withValues(alpha: 0.6),
            ),
    );
  }

  /// Carte boutique compacte horizontale sous les produits pour visiter chaque boutique en 1 clic.
  Widget _buildCompactShopCard(BuildContext context, MerchantModel merchant) {
    return InkWell(
      onTap: () {
        Navigator.of(context).push(
          MaterialPageRoute(
            builder: (_) => MerchantDetailScreen(merchant: merchant),
          ),
        );
      },
      borderRadius: BorderRadius.circular(14),
      child: Container(
        width: 224,
        padding: const EdgeInsets.all(10),
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
              children: [
                _buildMerchantLogoBadge(merchant),
                const SizedBox(width: 8),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        merchant.name,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(
                          fontSize: 13.5,
                          fontWeight: FontWeight.bold,
                          color: GoLivreurTheme.textePrincipal,
                        ),
                      ),
                      Text(
                        merchant.categoryName ?? merchant.category,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(
                          fontSize: 11,
                          color: GoLivreurTheme.texteSecondaire,
                        ),
                      ),
                    ],
                  ),
                ),
              ],
            ),
            Row(
              children: [
                Expanded(
                  child: Row(
                    children: [
                      const Icon(Icons.star, color: Color(0xFFF9A825), size: 14),
                      const SizedBox(width: 3),
                      Text(
                        merchant.averageRating.toStringAsFixed(1),
                        style: const TextStyle(
                          fontSize: 12,
                          fontWeight: FontWeight.bold,
                        ),
                      ),
                      const SizedBox(width: 6),
                      Flexible(
                        child: Text(
                          '${merchant.products.length} art.',
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: const TextStyle(
                            fontSize: 11,
                            color: GoLivreurTheme.texteSecondaire,
                          ),
                        ),
                      ),
                    ],
                  ),
                ),
                const SizedBox(width: 6),
                Container(
                  padding:
                      const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                  decoration: BoxDecoration(
                    color: GoLivreurTheme.primaire.withValues(alpha: 0.14),
                    borderRadius: BorderRadius.circular(8),
                  ),
                  child: const Text(
                    'Visiter ›',
                    style: TextStyle(
                      fontSize: 11,
                      fontWeight: FontWeight.bold,
                      color: Color(0xFF835500),
                    ),
                  ),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }

  void _showProductDetailSheet(
    BuildContext context,
    WidgetRef ref,
    _HomeProductEntry entry,
    int prepMin,
  ) {
    final product = entry.product;
    final merchant = entry.merchant;
    var qty = 1;

    showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.white,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(22)),
      ),
      builder: (sheetCtx) => StatefulBuilder(
        builder: (ctx, setModalState) => SafeArea(
          child: Padding(
            padding: const EdgeInsets.fromLTRB(20, 14, 20, 20),
            child: SingleChildScrollView(
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Center(
                    child: Container(
                      width: 40,
                      height: 4,
                      decoration: BoxDecoration(
                        color: GoLivreurTheme.bordure,
                        borderRadius: BorderRadius.circular(2),
                      ),
                    ),
                  ),
                  const SizedBox(height: 14),
                  ClipRRect(
                    borderRadius: BorderRadius.circular(14),
                    child: SizedBox(
                      height: 180,
                      width: double.infinity,
                      child: product.photoUrl != null &&
                              product.photoUrl!.isNotEmpty
                          ? Image.network(
                              product.photoUrl!,
                              fit: BoxFit.cover,
                              errorBuilder: (_, __, ___) =>
                                  _buildProductPlaceholder(merchant),
                            )
                          : _buildProductPlaceholder(merchant),
                    ),
                  ),
                  const SizedBox(height: 14),
                  Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Expanded(
                        child: Text(
                          product.name,
                          style: const TextStyle(
                            fontSize: 19,
                            fontWeight: FontWeight.bold,
                            color: Color(0xFF1F2937),
                          ),
                        ),
                      ),
                      const SizedBox(width: 8),
                      Container(
                        padding: const EdgeInsets.symmetric(
                          horizontal: 8,
                          vertical: 4,
                        ),
                        decoration: BoxDecoration(
                          color: const Color(0xFFF3F4F6),
                          borderRadius: BorderRadius.circular(8),
                        ),
                        child: Text(
                          '$prepMin min',
                          style: const TextStyle(
                            fontSize: 12,
                            fontWeight: FontWeight.w600,
                            color: Color(0xFF4B5563),
                          ),
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 6),
                  Text(
                    _formatFcfa(product.price),
                    style: const TextStyle(
                      fontSize: 18,
                      fontWeight: FontWeight.w800,
                      color: GoLivreurTheme.secondaire,
                    ),
                  ),
                  if (product.description != null &&
                      product.description!.trim().isNotEmpty) ...[
                    const SizedBox(height: 10),
                    Text(
                      product.description!.trim(),
                      style: const TextStyle(
                        fontSize: 13.5,
                        color: GoLivreurTheme.texteSecondaire,
                      ),
                    ),
                  ],
                  if (product.attributes.isNotEmpty) ...[
                    const SizedBox(height: 10),
                    Wrap(
                      spacing: 6,
                      runSpacing: 6,
                      children: product.attributes.entries
                          .where((e) =>
                              e.value != null && '${e.value}'.trim().isNotEmpty)
                          .map((e) {
                        final valStr = e.value is bool
                            ? (e.value == true ? 'Oui' : 'Non')
                            : '${e.value}';
                        return Chip(
                          visualDensity: VisualDensity.compact,
                          backgroundColor: GoLivreurTheme.fond,
                          label: Text(
                            '${e.key} : $valStr',
                            style: const TextStyle(fontSize: 12),
                          ),
                        );
                      }).toList(),
                    ),
                  ],
                  const SizedBox(height: 16),
                  Row(
                    children: [
                      Container(
                        decoration: BoxDecoration(
                          borderRadius: BorderRadius.circular(12),
                          border: Border.all(color: GoLivreurTheme.bordure),
                        ),
                        child: Row(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            IconButton(
                              onPressed: qty > 1
                                  ? () => setModalState(() => qty--)
                                  : null,
                              icon: const Icon(Icons.remove, size: 18),
                            ),
                            Text(
                              '$qty',
                              style: const TextStyle(
                                fontSize: 16,
                                fontWeight: FontWeight.bold,
                              ),
                            ),
                            IconButton(
                              onPressed: () => setModalState(() => qty++),
                              icon: const Icon(Icons.add, size: 18),
                            ),
                          ],
                        ),
                      ),
                      const SizedBox(width: 12),
                      Expanded(
                        child: SizedBox(
                          height: 48,
                          child: ElevatedButton.icon(
                            style: ElevatedButton.styleFrom(
                              backgroundColor: GoLivreurTheme.secondaire,
                              foregroundColor: Colors.white,
                              shape: RoundedRectangleBorder(
                                borderRadius: BorderRadius.circular(12),
                              ),
                            ),
                            onPressed: () {
                              ref.read(cartProvider.notifier).addProduct(
                                    merchant,
                                    product,
                                    quantity: qty,
                                  );
                              Navigator.of(ctx).pop();
                              ScaffoldMessenger.of(context).showSnackBar(
                                SnackBar(
                                  content: Text(
                                    '$qty × ${product.name} ajouté au panier !',
                                  ),
                                  backgroundColor: GoLivreurTheme.secondaire,
                                ),
                              );
                            },
                            icon: const Icon(Icons.add_shopping_cart, size: 18),
                            label: Text(
                              'Ajouter • ${_formatFcfa(product.price * qty)}',
                              style: const TextStyle(
                                fontWeight: FontWeight.bold,
                                fontSize: 13.5,
                              ),
                            ),
                          ),
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 10),
                  OutlinedButton.icon(
                    style: OutlinedButton.styleFrom(
                      foregroundColor: GoLivreurTheme.textePrincipal,
                      side: const BorderSide(color: GoLivreurTheme.bordure),
                      padding: const EdgeInsets.symmetric(vertical: 12),
                      shape: RoundedRectangleBorder(
                        borderRadius: BorderRadius.circular(12),
                      ),
                    ),
                    onPressed: () {
                      Navigator.of(ctx).pop();
                      Navigator.of(context).push(
                        MaterialPageRoute(
                          builder: (_) =>
                              MerchantDetailScreen(merchant: merchant),
                        ),
                      );
                    },
                    icon: const Icon(Icons.storefront_outlined, size: 18),
                    label: Text(
                      'Visiter la boutique « ${merchant.name} »',
                      style: const TextStyle(fontWeight: FontWeight.w600),
                    ),
                  ),
                ],
              ),
            ),
          ),
        ),
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
          style: const TextStyle(fontSize: 24),
        ),
      );
    }

    return Icon(
      _getCategoryIcon(cat.id),
      color: isSelected ? GoLivreurTheme.primaire : const Color(0xFF374151),
      size: 26,
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

