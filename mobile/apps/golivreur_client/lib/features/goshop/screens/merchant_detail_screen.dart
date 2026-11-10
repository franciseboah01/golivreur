import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:golivreur_core/config/app_theme.dart';
import 'package:golivreur_core/models/modeles.dart';
import '../providers/goshop_provider.dart';
import 'cart_screen.dart';

class MerchantDetailScreen extends ConsumerStatefulWidget {
  final MerchantModel merchant;

  const MerchantDetailScreen({super.key, required this.merchant});

  @override
  ConsumerState<MerchantDetailScreen> createState() => _MerchantDetailScreenState();
}

class _MerchantDetailScreenState extends ConsumerState<MerchantDetailScreen> {
  bool _isFavorite = false;

  @override
  Widget build(BuildContext context) {
    final weather = ref.watch(weatherProvider);
    final cart = ref.watch(cartProvider);
    final merchant = widget.merchant;

    return Scaffold(
      backgroundColor: GoLivreurTheme.fond,
      body: CustomScrollView(
        slivers: [
          // ─── SliverAppBar avec photo ─────────────────────────────────────
          SliverAppBar(
            expandedHeight: 220,
            pinned: true,
            backgroundColor: Colors.white,
            leading: Padding(
              padding: const EdgeInsets.all(8.0),
              child: CircleAvatar(
                backgroundColor: Colors.white.withValues(alpha: 0.9),
                child: IconButton(
                  icon: const Icon(Icons.arrow_back, color: GoLivreurTheme.textePrincipal),
                  onPressed: () => Navigator.pop(context),
                ),
              ),
            ),
            actions: [
              Padding(
                padding: const EdgeInsets.all(8.0),
                child: CircleAvatar(
                  backgroundColor: Colors.white.withValues(alpha: 0.9),
                  child: IconButton(
                    icon: Icon(
                      _isFavorite ? Icons.favorite : Icons.favorite_border,
                      color: _isFavorite ? GoLivreurTheme.erreur : GoLivreurTheme.textePrincipal,
                    ),
                    onPressed: () {
                      setState(() => _isFavorite = !_isFavorite);
                      ScaffoldMessenger.of(context).showSnackBar(
                        SnackBar(
                          content: Text(
                            _isFavorite
                                ? '${merchant.name} ajouté aux favoris'
                                : '${merchant.name} retiré des favoris',
                          ),
                          duration: const Duration(seconds: 1),
                        ),
                      );
                    },
                  ),
                ),
              ),
            ],
            flexibleSpace: FlexibleSpaceBar(
              background: merchant.photoUrl != null
                  ? Image.network(
                      merchant.photoUrl!,
                      fit: BoxFit.cover,
                      errorBuilder: (_, __, ___) => _buildPlaceholder(),
                    )
                  : _buildPlaceholder(),
            ),
          ),

          // ─── Fiche Commerce & Alerte Météo ──────────────────────────────
          SliverToBoxAdapter(
            child: Container(
              color: Colors.white,
              padding: const EdgeInsets.all(16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      Expanded(
                        child: Row(
                          children: [
                            if (merchant.logoUrl != null && merchant.logoUrl!.isNotEmpty) ...[
                              ClipRRect(
                                borderRadius: BorderRadius.circular(10),
                                child: Image.network(
                                  merchant.logoUrl!,
                                  width: 36,
                                  height: 36,
                                  fit: BoxFit.cover,
                                  errorBuilder: (_, __, ___) => Text(
                                    merchant.emoji ?? '🏪',
                                    style: const TextStyle(fontSize: 24),
                                  ),
                                ),
                              ),
                              const SizedBox(width: 10),
                            ] else if (merchant.emoji != null && merchant.emoji!.trim().isNotEmpty) ...[
                              Text(
                                merchant.emoji!,
                                style: const TextStyle(fontSize: 24),
                              ),
                              const SizedBox(width: 8),
                            ],
                            Expanded(
                              child: Text(
                                merchant.name,
                                style: const TextStyle(
                                  fontSize: 22,
                                  fontWeight: FontWeight.bold,
                                  color: GoLivreurTheme.textePrincipal,
                                ),
                              ),
                            ),
                          ],
                        ),
                      ),
                      const SizedBox(width: 8),
                      Container(
                        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                        decoration: BoxDecoration(
                          color: GoLivreurTheme.fond,
                          borderRadius: BorderRadius.circular(8),
                        ),
                        child: Row(
                          children: [
                            const Icon(Icons.star, color: Color(0xFFF9A825), size: 18),
                            const SizedBox(width: 4),
                            Text(
                              merchant.averageRating.toStringAsFixed(1),
                              style: const TextStyle(
                                fontSize: 14,
                                fontWeight: FontWeight.bold,
                                color: GoLivreurTheme.textePrincipal,
                              ),
                            ),
                          ],
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 6),
                  Text(
                    '${(merchant.categoryName ?? merchant.category).toUpperCase()} • ${merchant.address}',
                    style: const TextStyle(
                      fontSize: 14,
                      color: GoLivreurTheme.texteSecondaire,
                    ),
                  ),
                  const SizedBox(height: 12),
                  Row(
                    children: [
                      Container(
                        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                        decoration: BoxDecoration(
                          color: GoLivreurTheme.secondaire.withValues(alpha: 0.1),
                          borderRadius: BorderRadius.circular(8),
                        ),
                        child: Row(
                          children: [
                            const Icon(Icons.access_time, color: GoLivreurTheme.secondaire, size: 16),
                            const SizedBox(width: 4),
                            Text(
                              weather.isRainMode
                                  ? '${merchant.prepTimeMinutes + 15} min (🌧️ Pluie)'
                                  : '${merchant.prepTimeMinutes} min',
                              style: const TextStyle(
                                color: GoLivreurTheme.secondaire,
                                fontWeight: FontWeight.bold,
                                fontSize: 13,
                              ),
                            ),
                          ],
                        ),
                      ),
                      const SizedBox(width: 12),
                      Container(
                        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                        decoration: BoxDecoration(
                          color: GoLivreurTheme.primaire.withValues(alpha: 0.1),
                          borderRadius: BorderRadius.circular(8),
                        ),
                        child: Row(
                          children: [
                            const Icon(Icons.delivery_dining, color: Color(0xFFC47B00), size: 16),
                            const SizedBox(width: 4),
                            Text(
                              merchant.category == 'pharmacie' ? 'Frais : 500 F CFA' : 'Frais : 800 F CFA',
                              style: const TextStyle(
                                color: Color(0xFF835500),
                                fontWeight: FontWeight.bold,
                                fontSize: 13,
                              ),
                            ),
                          ],
                        ),
                      ),
                    ],
                  ),

                  // Alerte Mode Pluie contextuelle
                  if (weather.isRainMode) ...[
                    const SizedBox(height: 14),
                    Container(
                      padding: const EdgeInsets.all(12),
                      decoration: BoxDecoration(
                        color: const Color(0xFFE8F1F8),
                        borderRadius: BorderRadius.circular(10),
                        border: Border.all(color: const Color(0xFF2E75B6).withValues(alpha: 0.3)),
                      ),
                      child: const Row(
                        children: [
                          Icon(Icons.water_drop, color: Color(0xFF2E75B6), size: 20),
                          SizedBox(width: 8),
                          Expanded(
                            child: Text(
                              'Délais de préparation et livraison adaptés (+15 min) en raison de la pluie à Daloa.',
                              style: TextStyle(
                                fontSize: 12,
                                color: Color(0xFF1B4D7E),
                                fontWeight: FontWeight.w500,
                              ),
                            ),
                          ),
                        ],
                      ),
                    ),
                  ],
                ],
              ),
            ),
          ),

          // ─── En-tête Catalogue ──────────────────────────────────────────
          SliverToBoxAdapter(
            child: Padding(
              padding: const EdgeInsets.fromLTRB(16, 20, 16, 8),
              child: Text(
                'Menu & Produits disponibles (${merchant.products.length})',
                style: const TextStyle(
                  fontSize: 18,
                  fontWeight: FontWeight.bold,
                  color: GoLivreurTheme.textePrincipal,
                ),
              ),
            ),
          ),

          // ─── Liste des Produits ─────────────────────────────────────────
          SliverPadding(
            padding: EdgeInsets.fromLTRB(16, 0, 16, cart.items.isNotEmpty ? 100 : 32),
            sliver: SliverList(
              delegate: SliverChildBuilderDelegate(
                (context, index) {
                  final product = merchant.products[index];
                  return _buildProductCard(context, ref, merchant, product);
                },
                childCount: merchant.products.length,
              ),
            ),
          ),
        ],
      ),

      // ─── Barre Flottante Panier Actif ──────────────────────────────────
      bottomNavigationBar: cart.items.isNotEmpty
          ? SafeArea(
              child: Container(
                margin: const EdgeInsets.fromLTRB(16, 0, 16, 12),
                height: 56, // Touch target confortable min 52px
                child: ElevatedButton(
                  style: ElevatedButton.styleFrom(
                    backgroundColor: GoLivreurTheme.secondaire,
                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
                    elevation: 4,
                  ),
                  onPressed: () {
                    Navigator.of(context).push(
                      MaterialPageRoute(builder: (_) => const CartScreen()),
                    );
                  },
                  child: Row(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      Row(
                        children: [
                          Container(
                            padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                            decoration: BoxDecoration(
                              color: Colors.white.withValues(alpha: 0.2),
                              borderRadius: BorderRadius.circular(8),
                            ),
                            child: Text(
                              '${cart.totalItemCount}',
                              style: const TextStyle(fontWeight: FontWeight.bold),
                            ),
                          ),
                          const SizedBox(width: 12),
                          const Text(
                            'Voir mon panier',
                            style: TextStyle(fontSize: 15, fontWeight: FontWeight.w600),
                          ),
                        ],
                      ),
                      Text(
                        '${cart.total.toInt()} F CFA',
                        style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold),
                      ),
                    ],
                  ),
                ),
              ),
            )
          : null,
    );
  }

  Widget _buildProductCard(
    BuildContext context,
    WidgetRef ref,
    MerchantModel merchant,
    ProductModel product,
  ) {
    return Container(
      margin: const EdgeInsets.only(bottom: 12),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: GoLivreurTheme.bordure),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                if (product.category != null)
                  Container(
                    margin: const EdgeInsets.only(bottom: 4),
                    padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                    decoration: BoxDecoration(
                      color: GoLivreurTheme.fond,
                      borderRadius: BorderRadius.circular(4),
                    ),
                    child: Text(
                      product.category!,
                      style: const TextStyle(
                        fontSize: 11,
                        color: GoLivreurTheme.texteSecondaire,
                        fontWeight: FontWeight.w600,
                      ),
                    ),
                  ),
                Text(
                  product.name,
                  style: const TextStyle(
                    fontSize: 15,
                    fontWeight: FontWeight.bold,
                    color: GoLivreurTheme.textePrincipal,
                  ),
                ),
                if (product.description != null) ...[
                  const SizedBox(height: 4),
                  Text(
                    product.description!,
                    style: const TextStyle(
                      fontSize: 12,
                      color: GoLivreurTheme.texteSecondaire,
                    ),
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                  ),
                ],
                const SizedBox(height: 8),
                Text(
                  '${product.price.toInt()} F CFA',
                  style: const TextStyle(
                    fontSize: 15,
                    fontWeight: FontWeight.bold,
                    color: GoLivreurTheme.primaire,
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(width: 12),
          Column(
            children: [
              InkWell(
                onTap: () => _showProductOptions(context, ref, merchant, product),
                borderRadius: BorderRadius.circular(10),
                child: Container(
                  height: 44,
                  padding: const EdgeInsets.symmetric(horizontal: 14),
                  decoration: BoxDecoration(
                    color: GoLivreurTheme.primaire,
                    borderRadius: BorderRadius.circular(10),
                  ),
                  child: const Row(
                    children: [
                      Icon(Icons.add, color: Colors.white, size: 18),
                      SizedBox(width: 4),
                      Text(
                        'Ajouter',
                        style: TextStyle(
                          color: Colors.white,
                          fontWeight: FontWeight.bold,
                          fontSize: 13,
                        ),
                      ),
                    ],
                  ),
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }

  void _showProductOptions(
    BuildContext context,
    WidgetRef ref,
    MerchantModel merchant,
    ProductModel product,
  ) {
    int quantity = 1;

    showModalBottomSheet(
      context: context,
      backgroundColor: Colors.white,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
      builder: (context) {
        return StatefulBuilder(
          builder: (context, setModalState) {
            return SafeArea(
              child: Padding(
                padding: const EdgeInsets.all(20),
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: [
                        Expanded(
                          child: Text(
                            product.name,
                            style: const TextStyle(
                              fontSize: 18,
                              fontWeight: FontWeight.bold,
                              color: GoLivreurTheme.textePrincipal,
                            ),
                          ),
                        ),
                        IconButton(
                          icon: const Icon(Icons.close),
                          onPressed: () => Navigator.pop(context),
                        ),
                      ],
                    ),
                    if (product.description != null) ...[
                      const SizedBox(height: 6),
                      Text(
                        product.description!,
                        style: const TextStyle(color: GoLivreurTheme.texteSecondaire, fontSize: 13),
                      ),
                    ],
                    const SizedBox(height: 20),
                    Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: [
                        const Text(
                          'Quantité :',
                          style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold),
                        ),
                        Row(
                          children: [
                            IconButton(
                              icon: const Icon(Icons.remove_circle_outline, color: GoLivreurTheme.primaire, size: 28),
                              onPressed: () {
                                if (quantity > 1) {
                                  setModalState(() => quantity--);
                                }
                              },
                            ),
                            Text(
                              '$quantity',
                              style: const TextStyle(fontSize: 18, fontWeight: FontWeight.bold),
                            ),
                            IconButton(
                              icon: const Icon(Icons.add_circle, color: GoLivreurTheme.primaire, size: 28),
                              onPressed: () {
                                setModalState(() => quantity++);
                              },
                            ),
                          ],
                        ),
                      ],
                    ),
                    const SizedBox(height: 24),
                    SizedBox(
                      width: double.infinity,
                      height: 52, // Zone tactile min 52px Stitch
                      child: ElevatedButton(
                        style: ElevatedButton.styleFrom(
                          backgroundColor: GoLivreurTheme.primaire,
                          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                        ),
                        onPressed: () {
                          ref.read(cartProvider.notifier).addProduct(
                                merchant,
                                product,
                                quantity: quantity,
                              );
                          Navigator.pop(context);
                          ScaffoldMessenger.of(context).showSnackBar(
                            SnackBar(
                              content: Text('$quantity x ${product.name} ajouté au panier'),
                              duration: const Duration(seconds: 1),
                            ),
                          );
                        },
                        child: Text(
                          'Ajouter au panier • ${(product.price * quantity).toInt()} F CFA',
                          style: const TextStyle(
                            fontSize: 16,
                            fontWeight: FontWeight.bold,
                            color: Colors.white,
                          ),
                        ),
                      ),
                    ),
                  ],
                ),
              ),
            );
          },
        );
      },
    );
  }

  Widget _buildPlaceholder() {
    return Container(
      color: Colors.grey.shade300,
      child: const Center(
        child: Icon(Icons.storefront, size: 64, color: Colors.grey),
      ),
    );
  }
}

