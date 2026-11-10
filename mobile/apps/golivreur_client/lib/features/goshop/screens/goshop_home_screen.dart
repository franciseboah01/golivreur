import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:golivreur_core/config/app_theme.dart';
import 'package:golivreur_core/models/modeles.dart';
import 'package:golivreur_core/widgets/golivreur_widgets.dart';
import '../providers/goshop_provider.dart';
import 'merchant_detail_screen.dart';
import 'cart_screen.dart';
import '../../auth/screens/login_screen.dart';
import '../../gocolis/screens/gocolis_home_screen.dart';
import '../../orders/screens/orders_tracking_history_screen.dart';
import '../../profile/screens/profile_screen.dart';

class GoShopHomeScreen extends ConsumerWidget {
  const GoShopHomeScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final weather = ref.watch(weatherProvider);
    final selectedQuarter = ref.watch(selectedNeighborhoodProvider);
    final categories = ref.watch(categoriesProvider).valueOrNull ?? fallbackCategoriesList;
    final selectedCat = ref.watch(selectedCategoryProvider);
    final merchantsAsync = ref.watch(merchantsListProvider);
    final cart = ref.watch(cartProvider);

    return Scaffold(
      backgroundColor: GoLivreurTheme.fond,
      drawer: _buildQuickAccessDrawer(context, ref),
      appBar: AppBar(
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
                              'Daloa, $selectedQuarter',
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
          // Bouton Notification avec pastille rouge
          Stack(
            alignment: Alignment.center,
            children: [
              IconButton(
                icon: const Icon(Icons.notifications_outlined, color: GoLivreurTheme.textePrincipal),
                onPressed: () {
                  ScaffoldMessenger.of(context).showSnackBar(
                    const SnackBar(content: Text('Aucune nouvelle alerte non lue.')),
                  );
                },
              ),
              Positioned(
                top: 12,
                right: 12,
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
          const SizedBox(width: 8),
        ],
      ),
      body: Stack(
        children: [
          RefreshIndicator(
            color: GoLivreurTheme.primaire,
            onRefresh: () async {
              ref.invalidate(categoriesProvider);
              ref.invalidate(merchantsListProvider);
            },
            child: SingleChildScrollView(
              physics: const AlwaysScrollableScrollPhysics(),
              padding: EdgeInsets.only(
                bottom: cart.items.isNotEmpty ? 100 : 32,
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  // ─── Bandeau Mode Pluie Daloa ────────────────────────────
                  if (weather.isRainMode)
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
                              ref.read(weatherProvider.notifier).toggleRainMode();
                            },
                            child: const Padding(
                              padding: EdgeInsets.only(left: 6),
                              child: Icon(Icons.close, size: 18, color: Color(0xFF1B4D7E)),
                            ),
                          ),
                        ],
                      ),
                    ),

                  // ─── Zone Recherche & Filtres ────────────────────────────
                  Padding(
                    padding: const EdgeInsets.fromLTRB(16, 12, 16, 8),
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
                          const Icon(Icons.search, color: GoLivreurTheme.primaire, size: 24),
                          const SizedBox(width: 10),
                          const Expanded(
                            child: Text(
                              'Rechercher un maquis, garba, pharmacie...',
                              style: TextStyle(
                                color: GoLivreurTheme.texteSecondaire,
                                fontSize: 14,
                              ),
                              overflow: TextOverflow.ellipsis,
                            ),
                          ),
                          Container(
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
                        ],
                      ),
                    ),
                  ),

                  // ─── Bannière Promotionnelle Daloa ───────────────────────
                  Padding(
                    padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
                    child: Container(
                      width: double.infinity,
                      decoration: BoxDecoration(
                        gradient: const LinearGradient(
                          colors: [Color(0xFFF4A623), Color(0xFFE28E10)],
                          begin: Alignment.topLeft,
                          end: Alignment.bottomRight,
                        ),
                        borderRadius: BorderRadius.circular(16),
                        boxShadow: [
                          BoxShadow(
                            color: GoLivreurTheme.primaire.withValues(alpha: 0.25),
                            blurRadius: 10,
                            offset: const Offset(0, 4),
                          ),
                        ],
                      ),
                      padding: const EdgeInsets.all(16),
                      child: Row(
                        children: [
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Container(
                                  padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                                  decoration: BoxDecoration(
                                    color: Colors.white.withValues(alpha: 0.25),
                                    borderRadius: BorderRadius.circular(20),
                                  ),
                                  child: const Text(
                                    '🎉 OFFRE DE BIENVENUE',
                                    style: TextStyle(
                                      color: Colors.white,
                                      fontSize: 11,
                                      fontWeight: FontWeight.bold,
                                    ),
                                  ),
                                ),
                                const SizedBox(height: 6),
                                const Text(
                                  'Livraison offerte sur votre 1ère commande',
                                  style: TextStyle(
                                    color: Colors.white,
                                    fontSize: 16,
                                    fontWeight: FontWeight.bold,
                                    height: 1.2,
                                  ),
                                ),
                                const SizedBox(height: 4),
                                const Text(
                                  'Ce week-end pour fêter nos commerces de Daloa !',
                                  style: TextStyle(
                                    color: Colors.white,
                                    fontSize: 12,
                                  ),
                                ),
                              ],
                            ),
                          ),
                          const SizedBox(width: 12),
                          Container(
                            width: 52,
                            height: 52,
                            decoration: BoxDecoration(
                              color: Colors.white.withValues(alpha: 0.2),
                              shape: BoxShape.circle,
                            ),
                            child: const Icon(
                              Icons.delivery_dining,
                              color: Colors.white,
                              size: 34,
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),

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

                  // ─── Populaire à Daloa ────────────────────────────────────
                  Padding(
                    padding: const EdgeInsets.fromLTRB(16, 20, 16, 12),
                    child: Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: [
                        const Row(
                          children: [
                            Icon(Icons.local_fire_department, color: GoLivreurTheme.primaire, size: 22),
                            SizedBox(width: 6),
                            Text(
                              'Populaire à Daloa',
                              style: TextStyle(
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
                      final filtered = selectedCat == 'all'
                          ? merchants
                          : merchants.where((m) => m.category == selectedCat).toList();

                      if (filtered.isEmpty) {
                        return Container(
                          padding: const EdgeInsets.all(32),
                          alignment: Alignment.center,
                          child: const Column(
                            children: [
                              Icon(Icons.storefront_outlined, size: 48, color: GoLivreurTheme.texteSecondaire),
                              SizedBox(height: 12),
                              Text(
                                'Aucun commerce disponible dans cette catégorie pour le moment.',
                                textAlign: TextAlign.center,
                                style: TextStyle(color: GoLivreurTheme.texteSecondaire),
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
                          return _buildMerchantCard(context, ref, merchant, weather.isRainMode);
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

                  // ─── Bouton Achat Spécifique à Daloa ─────────────────────
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
                        child: const Row(
                          mainAxisAlignment: MainAxisAlignment.center,
                          children: [
                            Icon(Icons.add_shopping_cart, color: Color(0xFFC47B00), size: 22),
                            SizedBox(width: 8),
                            Text(
                              'Besoin d\'un achat spécifique à Daloa ?',
                              style: TextStyle(
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
                        '${cart.total.toInt()} F CFA',
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
    bool isRainMode,
  ) {
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
                            merchant.category == 'pharmacie' ? '500 F CFA' : '800 F CFA',
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

  void _showNeighborhoodPicker(BuildContext context, WidgetRef ref) {
    final quarters = ref.read(daloaNeighborhoodsProvider);
    final current = ref.read(selectedNeighborhoodProvider);

    showModalBottomSheet(
      context: context,
      backgroundColor: Colors.white,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
      builder: (context) {
        return SafeArea(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const Padding(
                padding: EdgeInsets.fromLTRB(20, 20, 20, 10),
                child: Text(
                  'Choisissez votre quartier à Daloa',
                  style: TextStyle(
                    fontSize: 18,
                    fontWeight: FontWeight.bold,
                    color: GoLivreurTheme.textePrincipal,
                  ),
                ),
              ),
              const Divider(),
              Expanded(
                child: ListView.builder(
                  itemCount: quarters.length,
                  itemBuilder: (context, index) {
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
                        Navigator.pop(context);
                      },
                    );
                  },
                ),
              ),
            ],
          ),
        );
      },
    );
  }

  Widget _buildQuickAccessDrawer(BuildContext context, WidgetRef ref) {
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
                  Row(
                    children: [
                      Container(
                        padding: const EdgeInsets.all(8),
                        decoration: BoxDecoration(
                          color: Colors.white,
                          borderRadius: BorderRadius.circular(12),
                        ),
                        child: const Icon(
                          Icons.moped,
                          color: GoLivreurTheme.primaire,
                          size: 28,
                        ),
                      ),
                      const SizedBox(width: 12),
                      const Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            'GoLivreur',
                            style: TextStyle(
                              color: Colors.white,
                              fontSize: 20,
                              fontWeight: FontWeight.bold,
                            ),
                          ),
                          Text(
                            'Daloa Express',
                            style: TextStyle(
                              color: Colors.white70,
                              fontSize: 13,
                            ),
                          ),
                        ],
                      ),
                    ],
                  ),
                  const SizedBox(height: 16),
                  const Text(
                    'Ama Kouassi',
                    style: TextStyle(
                      color: Colors.white,
                      fontSize: 16,
                      fontWeight: FontWeight.bold,
                    ),
                  ),
                  const Text(
                    '+225 07 01 00 00 01',
                    style: TextStyle(
                      color: Colors.white70,
                      fontSize: 13,
                    ),
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
                    title: const Text('GoShop - Commerces Daloa'),
                    subtitle: const Text('Restaurants, Maquis & Boutiques'),
                    onTap: () => Navigator.pop(context),
                  ),
                  ListTile(
                    leading: const Icon(Icons.all_inbox, color: Colors.blue),
                    title: const Text('GoColis - Envoi de colis'),
                    subtitle: const Text('Expéditions express moto à Daloa'),
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
                    title: const Text('Support Client Daloa'),
                    subtitle: const Text('+225 07 00 00 00 02'),
                    onTap: () {
                      Navigator.pop(context);
                      ScaffoldMessenger.of(context).showSnackBar(
                        const SnackBar(content: Text('Support GoLivreur Daloa joignable au 0700000002')),
                      );
                    },
                  ),
                  ListTile(
                    leading: const Icon(Icons.logout, color: GoLivreurTheme.erreur),
                    title: const Text('Déconnexion', style: TextStyle(color: GoLivreurTheme.erreur, fontWeight: FontWeight.bold)),
                    onTap: () {
                      Navigator.pop(context);
                      Navigator.of(context).pushAndRemoveUntil(
                        MaterialPageRoute(builder: (_) => const LoginScreen()),
                        (route) => false,
                      );
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

