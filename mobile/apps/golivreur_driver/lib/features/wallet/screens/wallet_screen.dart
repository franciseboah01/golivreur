import 'package:flutter/material.dart';
import 'package:golivreur_core/config/app_theme.dart';

class WalletScreen extends StatelessWidget {
  final double solde;
  const WalletScreen({super.key, this.solde = 15400.0});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: GoLivreurTheme.fond,
      appBar: AppBar(title: const Text('Mon Portefeuille')),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Container(
              padding: const EdgeInsets.all(24),
              decoration: BoxDecoration(
                gradient: const LinearGradient(
                  colors: [GoLivreurTheme.secondaire, Color(0xFF2E7D32)],
                  begin: Alignment.topLeft,
                  end: Alignment.bottomRight,
                ),
                borderRadius: BorderRadius.circular(16),
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const Text('Solde disponible', style: TextStyle(color: Colors.white70, fontSize: 14)),
                  const SizedBox(height: 8),
                  Text(
                    '${solde.toInt()} F CFA',
                    style: const TextStyle(color: Colors.white, fontSize: 32, fontWeight: FontWeight.bold),
                  ),
                  const SizedBox(height: 16),
                  Row(
                    children: [
                      ElevatedButton.icon(
                        style: ElevatedButton.styleFrom(backgroundColor: Colors.white, foregroundColor: GoLivreurTheme.secondaire),
                        onPressed: () {
                          ScaffoldMessenger.of(context).showSnackBar(
                            const SnackBar(content: Text('Demande de retrait Orange Money initiée')),
                          );
                        },
                        icon: const Icon(Icons.account_balance_wallet),
                        label: const Text('Retirer mes gains'),
                      ),
                    ],
                  ),
                ],
              ),
            ),
            const SizedBox(height: 24),
            Text('Historique des courses', style: Theme.of(context).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.bold)),
            const SizedBox(height: 8),
            const Card(
              child: ListTile(
                leading: Icon(Icons.arrow_downward, color: GoLivreurTheme.secondaire),
                title: Text('Livraison GoShop #1042'),
                subtitle: Text('Aujourd\'hui à 14:32'),
                trailing: Text('+640 F CFA', style: TextStyle(color: GoLivreurTheme.secondaire, fontWeight: FontWeight.bold)),
              ),
            ),
            const Card(
              child: ListTile(
                leading: Icon(Icons.arrow_downward, color: GoLivreurTheme.secondaire),
                title: Text('Expédition GoColis #890'),
                subtitle: Text('Aujourd\'hui à 11:15'),
                trailing: Text('+1 125 F CFA', style: TextStyle(color: GoLivreurTheme.secondaire, fontWeight: FontWeight.bold)),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
