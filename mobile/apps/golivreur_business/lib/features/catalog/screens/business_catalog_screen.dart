import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:golivreur_core/config/app_theme.dart';
import '../../business/providers/business_provider.dart';

class BusinessCatalogScreen extends ConsumerWidget {
  const BusinessCatalogScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final products = ref.watch(businessCatalogProvider);

    return Scaffold(
      backgroundColor: GoLivreurTheme.fond,
      appBar: AppBar(
        title: const Text('Mon Catalogue & Stock'),
        backgroundColor: Colors.white,
        elevation: 0.5,
        actions: [
          IconButton(
            icon: const Icon(Icons.add, color: GoLivreurTheme.primaire, size: 28),
            onPressed: () => _showAddProductDialog(context, ref),
          ),
        ],
      ),
      body: products.isEmpty
          ? const Center(
              child: Text('Aucun produit dans votre catalogue.', style: TextStyle(color: GoLivreurTheme.texteSecondaire)),
            )
          : ListView.separated(
              padding: const EdgeInsets.all(16),
              itemCount: products.length,
              separatorBuilder: (_, __) => const SizedBox(height: 10),
              itemBuilder: (context, index) {
                final item = products[index];
                return _buildProductRow(context, ref, item);
              },
            ),
      floatingActionButton: FloatingActionButton.extended(
        backgroundColor: GoLivreurTheme.primaire,
        foregroundColor: Colors.white,
        onPressed: () => _showAddProductDialog(context, ref),
        icon: const Icon(Icons.add),
        label: const Text('Ajouter un plat / article', style: TextStyle(fontWeight: FontWeight.bold)),
      ),
    );
  }

  Widget _buildProductRow(BuildContext context, WidgetRef ref, BusinessProductItem item) {
    return Container(
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: GoLivreurTheme.bordure),
      ),
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
      child: Row(
        children: [
          Container(
            width: 44,
            height: 44,
            decoration: BoxDecoration(
              color: item.isAvailable
                  ? GoLivreurTheme.primaire.withValues(alpha: 0.12)
                  : Colors.grey.shade200,
              borderRadius: BorderRadius.circular(10),
            ),
            child: Icon(
              Icons.restaurant,
              color: item.isAvailable ? GoLivreurTheme.primaire : Colors.grey,
              size: 24,
            ),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Flexible(
                      child: Text(
                        item.name,
                        style: TextStyle(
                          fontWeight: FontWeight.bold,
                          fontSize: 14,
                          color: item.isAvailable ? GoLivreurTheme.textePrincipal : GoLivreurTheme.texteSecondaire,
                          decoration: item.isAvailable ? null : TextDecoration.lineThrough,
                        ),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 2),
                Text(
                  '${item.price.toInt()} F CFA • ${item.category}',
                  style: const TextStyle(fontSize: 13, color: GoLivreurTheme.texteSecondaire),
                ),
                if (!item.isAvailable)
                  const Text(
                    'Épuisé en cuisine (Non commandable)',
                    style: TextStyle(fontSize: 11, color: GoLivreurTheme.erreur, fontWeight: FontWeight.bold),
                  ),
              ],
            ),
          ),
          // Commutateur de stock en direct
          Column(
            children: [
              Switch(
                value: item.isAvailable,
                activeTrackColor: GoLivreurTheme.secondaire,
                onChanged: (val) {
                  ref.read(businessCatalogProvider.notifier).toggleStock(item.id);
                  ScaffoldMessenger.of(context).showSnackBar(
                    SnackBar(
                      content: Text(val ? '${item.name} remis en stock.' : '${item.name} marqué comme épuisé.'),
                      duration: const Duration(seconds: 1),
                    ),
                  );
                },
              ),
              Text(
                item.isAvailable ? 'En stock' : 'Épuisé',
                style: TextStyle(
                  fontSize: 10,
                  fontWeight: FontWeight.bold,
                  color: item.isAvailable ? GoLivreurTheme.secondaire : GoLivreurTheme.texteSecondaire,
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }

  void _showAddProductDialog(BuildContext context, WidgetRef ref) {
    final nameCtrl = TextEditingController();
    final priceCtrl = TextEditingController();
    final categoryCtrl = TextEditingController(text: 'Plats');
    final descCtrl = TextEditingController();

    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.white,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
      builder: (context) {
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
              const Text(
                'Ajouter un plat / article',
                style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold),
              ),
              const SizedBox(height: 16),
              TextField(
                controller: nameCtrl,
                decoration: const InputDecoration(
                  labelText: 'Nom de l\'article *',
                  hintText: 'Ex: Foutou banane, Jus d\'ananas...',
                ),
              ),
              const SizedBox(height: 12),
              TextField(
                controller: priceCtrl,
                keyboardType: TextInputType.number,
                decoration: const InputDecoration(
                  labelText: 'Prix (F CFA) *',
                  suffixText: 'F CFA',
                ),
              ),
              const SizedBox(height: 12),
              TextField(
                controller: categoryCtrl,
                decoration: const InputDecoration(
                  labelText: 'Catégorie *',
                  hintText: 'Plats, Boissons, Accompagnements...',
                ),
              ),
              const SizedBox(height: 12),
              TextField(
                controller: descCtrl,
                decoration: const InputDecoration(
                  labelText: 'Description (ingrédients, portions)',
                ),
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
                    final name = nameCtrl.text.trim();
                    final price = double.tryParse(priceCtrl.text.trim()) ?? 0;
                    if (name.isNotEmpty && price > 0) {
                      ref.read(businessCatalogProvider.notifier).addProduct(
                            name: name,
                            price: price,
                            category: categoryCtrl.text.trim(),
                            description: descCtrl.text.trim(),
                          );
                      Navigator.pop(context);
                      ScaffoldMessenger.of(context).showSnackBar(
                        SnackBar(content: Text('✅ $name ajouté au catalogue')),
                      );
                    }
                  },
                  child: const Text('Enregistrer l\'article', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 16, color: Colors.white)),
                ),
              ),
            ],
          ),
        );
      },
    );
  }
}

