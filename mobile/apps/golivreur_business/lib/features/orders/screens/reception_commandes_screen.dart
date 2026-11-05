import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:golivreur_core/config/app_theme.dart';
import 'package:golivreur_core/models/modeles.dart';
import 'package:golivreur_core/widgets/atoms/widgets_atomiques.dart';

/// Écran de réception des commandes — GoLivreur Business.
///
/// Fonctionnement :
///   - Nouvelle commande → alerte sonore forte + vibration + bandeau orange
///   - Le commerce a 45 secondes pour accepter ou refuser
///   - Compte à rebours visible en temps réel
///   - Boutons "Accepter" (vert) et "Refuser" (rouge) bien distincts
///   - Sans réponse dans les 45s → annulation automatique côté serveur
class ReceptionCommandesScreen extends ConsumerStatefulWidget {
  const ReceptionCommandesScreen({super.key});

  @override
  ConsumerState<ReceptionCommandesScreen> createState() =>
      _ReceptionCommandesScreenState();
}

class _ReceptionCommandesScreenState
    extends ConsumerState<ReceptionCommandesScreen> {
  @override
  Widget build(BuildContext context) {
    final commandesAsync = ref.watch(commandesEnAttenteProvider);

    return Scaffold(
      appBar: AppBar(
        title: const Text('Commandes'),
        actions: [
          // Indicateur statut ouvert/fermé
          _BoutonStatutCommerce(),
        ],
      ),
      body: commandesAsync.when(
        loading: () => const EtatChargement(nombreLignes: 4),
        error: (err, _) => EtatErreur(
          message: 'Impossible de charger les commandes.',
          onReessayer: () => ref.invalidate(commandesEnAttenteProvider),
        ),
        data: (commandes) => commandes.isEmpty
            ? const EtatVide(
                icone: Icons.receipt_long_outlined,
                message: 'Aucune commande en attente.\nVotre commerce est prêt !',
              )
            : RefreshIndicator(
                onRefresh: () async => ref.invalidate(commandesEnAttenteProvider),
                child: ListView.builder(
                  padding: const EdgeInsets.all(GoLivreurTheme.espacementM),
                  itemCount: commandes.length,
                  itemBuilder: (_, i) => _CarteCommande(order: commandes[i]),
                ),
              ),
      ),
    );
  }
}

/// Carte d'une commande avec compte à rebours 45 secondes.
class _CarteCommande extends ConsumerStatefulWidget {
  final OrderModel order;
  const _CarteCommande({required this.order});

  @override
  ConsumerState<_CarteCommande> createState() => _CarteCommandeState();
}

class _CarteCommandeState extends ConsumerState<_CarteCommande> {
  late Timer _timer;
  int _secondesRestantes = 45;
  bool _estEnChargement = false;

  @override
  void initState() {
    super.initState();
    // Compte à rebours 45 secondes
    _timer = Timer.periodic(const Duration(seconds: 1), (_) {
      if (_secondesRestantes > 0) {
        setState(() => _secondesRestantes--);
      } else {
        _timer.cancel();
        // Timeout — la commande sera annulée automatiquement côté serveur
      }
    });
  }

  @override
  void dispose() {
    _timer.cancel();
    super.dispose();
  }

  Future<void> _accepter() async {
    _timer.cancel();
    setState(() => _estEnChargement = true);
    try {
      await ref.read(merchantOrderActionsProvider.notifier)
          .accepter(widget.order.id);
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text('✅ Commande acceptée — Préparez la commande.'),
            backgroundColor: GoLivreurTheme.succes,
          ),
        );
      }
    } catch (e) {
      if (mounted) {
        setState(() => _estEnChargement = false);
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text('Erreur : $e'),
            backgroundColor: GoLivreurTheme.erreur,
          ),
        );
      }
    }
  }

  Future<void> _refuser() async {
    // Confirmer le refus
    final confirmer = await showDialog<bool>(
      context: context,
      builder: (_) => AlertDialog(
        title: const Text('Refuser la commande ?'),
        content: const Text(
          'Le client sera notifié immédiatement.\n'
          'Cette action ne peut pas être annulée.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text('Annuler'),
          ),
          TextButton(
            onPressed: () => Navigator.pop(context, true),
            style: TextButton.styleFrom(foregroundColor: GoLivreurTheme.erreur),
            child: const Text('Refuser'),
          ),
        ],
      ),
    );

    if (confirmer != true) return;

    _timer.cancel();
    setState(() => _estEnChargement = true);
    try {
      await ref.read(merchantOrderActionsProvider.notifier)
          .refuser(widget.order.id);
    } catch (e) {
      if (mounted) setState(() => _estEnChargement = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final estUrgent = _secondesRestantes <= 15;

    return Card(
      margin: const EdgeInsets.only(bottom: GoLivreurTheme.espacementM),
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(GoLivreurTheme.rayonL),
        side: BorderSide(
          color: estUrgent ? GoLivreurTheme.erreur : GoLivreurTheme.primaire,
          width: 2,
        ),
      ),
      child: Padding(
        padding: const EdgeInsets.all(GoLivreurTheme.espacementM),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            // En-tête avec compte à rebours
            Row(
              children: [
                const Icon(Icons.shopping_bag_outlined,
                    color: GoLivreurTheme.primaire),
                const SizedBox(width: 8),
                Text(
                  'Commande #${widget.order.id}',
                  style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16),
                ),
                const Spacer(),
                // Compte à rebours
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
                  decoration: BoxDecoration(
                    color: estUrgent
                        ? GoLivreurTheme.erreur
                        : GoLivreurTheme.primaire,
                    borderRadius: BorderRadius.circular(GoLivreurTheme.rayonXL),
                  ),
                  child: Text(
                    '${_secondesRestantes}s',
                    style: const TextStyle(
                      color: Colors.white,
                      fontWeight: FontWeight.w700,
                      fontSize: 16,
                      fontFamily: 'Inter',
                    ),
                  ),
                ),
              ],
            ),

            const Divider(height: GoLivreurTheme.espacementL),

            // Articles commandés
            ...widget.order.items.map((item) => Padding(
                  padding: const EdgeInsets.only(bottom: 4),
                  child: Row(
                    children: [
                      Text('${item.quantity}×',
                          style: const TextStyle(
                              color: GoLivreurTheme.primaire,
                              fontWeight: FontWeight.w700)),
                      const SizedBox(width: 8),
                      Expanded(child: Text(item.productNameSnapshot)),
                      Text(
                        '${(item.unitPrice * item.quantity).toStringAsFixed(0)} F',
                        style: const TextStyle(fontWeight: FontWeight.w600),
                      ),
                    ],
                  ),
                )),

            const Divider(height: GoLivreurTheme.espacementL),

            // Total et instructions spéciales
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                const Text('Total produits',
                    style: TextStyle(color: GoLivreurTheme.texteSecondaire)),
                Text(
                  '${widget.order.productsTotal.toStringAsFixed(0)} F CFA',
                  style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16),
                ),
              ],
            ),

            if (widget.order.specialInstructions != null &&
                widget.order.specialInstructions!.isNotEmpty) ...[
              const SizedBox(height: GoLivreurTheme.espacementS),
              Container(
                padding: const EdgeInsets.all(GoLivreurTheme.espacementS),
                decoration: BoxDecoration(
                  color: GoLivreurTheme.avertissement.withValues(alpha: 0.1),
                  borderRadius: BorderRadius.circular(GoLivreurTheme.rayonS),
                ),
                child: Row(
                  children: [
                    const Icon(Icons.info_outline,
                        color: GoLivreurTheme.avertissement, size: 16),
                    const SizedBox(width: 6),
                    Expanded(
                      child: Text(
                        widget.order.specialInstructions!,
                        style: const TextStyle(
                          fontSize: 13,
                          color: GoLivreurTheme.textePrincipal,
                        ),
                      ),
                    ),
                  ],
                ),
              ),
            ],

            const SizedBox(height: GoLivreurTheme.espacementM),

            // Boutons Accepter / Refuser
            if (!_estEnChargement && _secondesRestantes > 0)
              Row(
                children: [
                  Expanded(
                    child: OutlinedButton.icon(
                      onPressed: _refuser,
                      icon: const Icon(Icons.close, color: GoLivreurTheme.erreur),
                      label: const Text('Refuser',
                          style: TextStyle(color: GoLivreurTheme.erreur)),
                      style: OutlinedButton.styleFrom(
                        side: const BorderSide(color: GoLivreurTheme.erreur),
                        minimumSize: const Size(0, 48),
                      ),
                    ),
                  ),
                  const SizedBox(width: GoLivreurTheme.espacementM),
                  Expanded(
                    flex: 2,
                    child: ElevatedButton.icon(
                      onPressed: _accepter,
                      icon: const Icon(Icons.check),
                      label: const Text('Accepter'),
                      style: ElevatedButton.styleFrom(
                        backgroundColor: GoLivreurTheme.succes,
                        minimumSize: const Size(0, 48),
                      ),
                    ),
                  ),
                ],
              ),

            if (_estEnChargement)
              const Center(
                child: Padding(
                  padding: EdgeInsets.all(GoLivreurTheme.espacementS),
                  child: CircularProgressIndicator(
                    valueColor: AlwaysStoppedAnimation(GoLivreurTheme.primaire),
                  ),
                ),
              ),

            if (_secondesRestantes == 0 && !_estEnChargement)
              const Center(
                child: Text(
                  'Délai expiré — commande annulée automatiquement.',
                  style: TextStyle(
                    color: GoLivreurTheme.texteSecondaire,
                    fontSize: 13,
                  ),
                  textAlign: TextAlign.center,
                ),
              ),
          ],
        ),
      ),
    );
  }
}

/// Bouton statut ouvert/fermé du commerce dans l'AppBar.
class _BoutonStatutCommerce extends ConsumerWidget {
  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final statut = ref.watch(merchantStatutProvider);

    return Padding(
      padding: const EdgeInsets.only(right: GoLivreurTheme.espacementM),
      child: GestureDetector(
        onTap: () => ref.read(merchantStatutProvider.notifier).toggle(),
        child: Container(
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
          decoration: BoxDecoration(
            color: statut
                ? GoLivreurTheme.succes.withValues(alpha: 0.1)
                : GoLivreurTheme.erreur.withValues(alpha: 0.1),
            borderRadius: BorderRadius.circular(GoLivreurTheme.rayonXL),
            border: Border.all(
              color: statut ? GoLivreurTheme.succes : GoLivreurTheme.erreur,
            ),
          ),
          child: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(
                statut ? Icons.store : Icons.store_mall_directory_outlined,
                size: 16,
                color: statut ? GoLivreurTheme.succes : GoLivreurTheme.erreur,
              ),
              const SizedBox(width: 6),
              Text(
                statut ? 'Ouvert' : 'Fermé',
                style: TextStyle(
                  fontSize: 13,
                  fontWeight: FontWeight.w700,
                  color: statut ? GoLivreurTheme.succes : GoLivreurTheme.erreur,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

// Providers placeholder — à implémenter dans features/orders/providers/
final commandesEnAttenteProvider =
    FutureProvider<List<OrderModel>>((ref) async => []);

class _MerchantOrderActionsNotifier extends StateNotifier<AsyncValue<void>> {
  _MerchantOrderActionsNotifier() : super(const AsyncValue.data(null));
  Future<void> accepter(int orderId) async {}
  Future<void> refuser(int orderId, [String? raison]) async {}
}

final merchantOrderActionsProvider =
    StateNotifierProvider<_MerchantOrderActionsNotifier, AsyncValue<void>>(
        (ref) => _MerchantOrderActionsNotifier());
final merchantStatutProvider =
    StateNotifierProvider<_StatutNotifier, bool>((_) => _StatutNotifier());

class _StatutNotifier extends StateNotifier<bool> {
  _StatutNotifier() : super(true);
  void toggle() => state = !state;
}
