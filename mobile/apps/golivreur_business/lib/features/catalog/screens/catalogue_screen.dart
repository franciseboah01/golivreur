import 'package:flutter/material.dart';
import 'package:golivreur_core/config/app_theme.dart';

class CatalogueScreen extends StatefulWidget {
  final int merchantId;
  const CatalogueScreen({super.key, required this.merchantId});

  @override
  State<CatalogueScreen> createState() => _CatalogueScreenState();
}

class _CatalogueScreenState extends State<CatalogueScreen> {
  final List<Map<String, dynamic>> _produits = [
    {'id': 1, 'name': 'Garba Thon VIP', 'price': 1500, 'is_available': true, 'category': 'Plats'},
    {'id': 2, 'name': 'Alloco Poulet Braisé', 'price': 3500, 'is_available': true, 'category': 'Plats'},
    {'id': 3, 'name': 'Bissap Glacé 50cl', 'price': 500, 'is_available': false, 'category': 'Boissons'},
  ];

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: GoLivreurTheme.fond,
      appBar: AppBar(
        title: const Text('Mon Catalogue'),
        actions: [
          IconButton(
            icon: const Icon(Icons.add),
            onPressed: () {
              ScaffoldMessenger.of(context).showSnackBar(
                const SnackBar(content: Text('Formulaire d\'ajout de produit ouvert')),
              );
            },
          ),
        ],
      ),
      body: ListView.separated(
        padding: const EdgeInsets.all(16),
        itemCount: _produits.length,
        separatorBuilder: (_, __) => const SizedBox(height: 8),
        itemBuilder: (context, index) {
          final p = _produits[index];
          final dispo = p['is_available'] as bool;

          return Card(
            child: ListTile(
              leading: CircleAvatar(
                backgroundColor: GoLivreurTheme.primaire.withValues(alpha: 0.1),
                child: const Icon(Icons.fastfood, color: GoLivreurTheme.primaire),
              ),
              title: Text(p['name'] as String, style: const TextStyle(fontWeight: FontWeight.bold)),
              subtitle: Text('${p['price']} F CFA • ${p['category']}'),
              trailing: Switch(
                value: dispo,
                activeTrackColor: GoLivreurTheme.secondaire,
                onChanged: (val) {
                  setState(() => p['is_available'] = val);
                },
              ),
            ),
          );
        },
      ),
    );
  }
}
