import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:golivreur_core/config/app_theme.dart';
import '../../goshop/providers/goshop_provider.dart';

/// Écran de gestion complète des adresses de livraison du Client :
/// ajout, modification, suppression et choix de l'adresse / quartier par défaut.
class ClientAddressesScreen extends ConsumerStatefulWidget {
  const ClientAddressesScreen({super.key});

  @override
  ConsumerState<ClientAddressesScreen> createState() => _ClientAddressesScreenState();
}

class _ClientAddressesScreenState extends ConsumerState<ClientAddressesScreen> {
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      ref.read(clientAddressesProvider.notifier).chargerAdresses(syncNeighborhood: false);
    });
  }

  Future<void> _openAddressForm({ClientAddressModel? existing}) async {
    final quarters = ref.read(daloaNeighborhoodsProvider);
    final currentQuarter = ref.read(selectedNeighborhoodProvider);
    String? selectedQuarter = existing != null && quarters.contains(existing.neighborhood)
        ? existing.neighborhood
        : (quarters.contains(currentQuarter)
            ? currentQuarter
            : (quarters.isNotEmpty ? quarters.first : null));

    final formKey = GlobalKey<FormState>();
    final labelCtrl = TextEditingController(text: existing?.label ?? '');
    final lineCtrl = TextEditingController(text: existing?.addressLine ?? '');
    bool isDefault = existing?.isDefault ?? ref.read(clientAddressesProvider).addresses.isEmpty;
    bool isSaving = false;
    String? errorText;

    await showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.white,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
      builder: (sheetCtx) => StatefulBuilder(
        builder: (ctx, setModalState) {
          return Padding(
            padding: EdgeInsets.fromLTRB(
              20,
              20,
              20,
              MediaQuery.of(ctx).viewInsets.bottom + 20,
            ),
            child: SingleChildScrollView(
              child: Form(
                key: formKey,
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: [
                        Text(
                          existing == null ? 'Nouvelle adresse' : 'Modifier l\'adresse',
                          style: const TextStyle(fontSize: 18, fontWeight: FontWeight.bold),
                        ),
                        IconButton(
                          icon: const Icon(Icons.close),
                          onPressed: isSaving ? null : () => Navigator.pop(sheetCtx),
                        ),
                      ],
                    ),
                    const SizedBox(height: 12),
                    if (errorText != null) ...[
                      Container(
                        padding: const EdgeInsets.all(10),
                        decoration: BoxDecoration(
                          color: GoLivreurTheme.erreur.withValues(alpha: 0.1),
                          borderRadius: BorderRadius.circular(10),
                        ),
                        child: Text(
                          errorText!,
                          style: const TextStyle(color: GoLivreurTheme.erreur, fontSize: 12),
                        ),
                      ),
                      const SizedBox(height: 12),
                    ],
                    TextFormField(
                      controller: labelCtrl,
                      decoration: const InputDecoration(
                        labelText: 'Libellé * (ex: Maison, Bureau)',
                        prefixIcon: Icon(Icons.bookmark_outline),
                        border: OutlineInputBorder(),
                      ),
                      validator: (v) =>
                          (v == null || v.trim().length < 2) ? 'Libellé requis' : null,
                    ),
                    const SizedBox(height: 12),
                    if (quarters.isEmpty)
                      Container(
                        padding: const EdgeInsets.all(12),
                        decoration: BoxDecoration(
                          color: Colors.grey.shade100,
                          borderRadius: BorderRadius.circular(10),
                          border: Border.all(color: GoLivreurTheme.bordure),
                        ),
                        child: const Text(
                          'Aucun quartier disponible pour votre ville.',
                          style: TextStyle(color: GoLivreurTheme.texteSecondaire, fontSize: 13),
                        ),
                      )
                    else
                      DropdownButtonFormField<String>(
                        initialValue: selectedQuarter,
                        decoration: const InputDecoration(
                          labelText: 'Quartier de livraison *',
                          prefixIcon: Icon(Icons.location_city_outlined),
                          border: OutlineInputBorder(),
                        ),
                        items: quarters
                            .map((q) => DropdownMenuItem(value: q, child: Text(q)))
                            .toList(),
                        onChanged: (val) {
                          setModalState(() => selectedQuarter = val);
                        },
                        validator: (val) =>
                            (val == null || val.isEmpty) ? 'Quartier obligatoire' : null,
                      ),
                    const SizedBox(height: 12),
                    TextFormField(
                      controller: lineCtrl,
                      maxLines: 2,
                      decoration: const InputDecoration(
                        labelText: 'Repères / Précisions de livraison *',
                        prefixIcon: Icon(Icons.place_outlined),
                        border: OutlineInputBorder(),
                      ),
                      validator: (v) =>
                          (v == null || v.trim().length < 3) ? 'Indiquez un repère précis' : null,
                    ),
                    const SizedBox(height: 8),
                    SwitchListTile(
                      contentPadding: EdgeInsets.zero,
                      title: const Text('Définir comme adresse par défaut', style: TextStyle(fontSize: 14)),
                      subtitle: const Text(
                        'Synchronise aussi votre quartier actif sur l\'accueil',
                        style: TextStyle(fontSize: 12),
                      ),
                      value: isDefault,
                      activeThumbColor: GoLivreurTheme.primaire,
                      onChanged: (val) => setModalState(() => isDefault = val),
                    ),
                    const SizedBox(height: 16),
                    SizedBox(
                      height: 48,
                      child: ElevatedButton(
                        style: ElevatedButton.styleFrom(
                          backgroundColor: GoLivreurTheme.primaire,
                          foregroundColor: Colors.white,
                          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                        ),
                        onPressed: (isSaving || selectedQuarter == null)
                            ? null
                            : () async {
                                if (!formKey.currentState!.validate()) return;
                                final chosenQuarter = selectedQuarter!;
                                setModalState(() {
                                  isSaving = true;
                                  errorText = null;
                                });

                                final notifier = ref.read(clientAddressesProvider.notifier);
                                final (ok, err) = existing == null
                                    ? await notifier.ajouterAdresse(
                                        label: labelCtrl.text,
                                        neighborhood: chosenQuarter,
                                        addressLine: lineCtrl.text,
                                        isDefault: isDefault,
                                      )
                                    : await notifier.modifierAdresse(
                                        id: existing.id,
                                        label: labelCtrl.text,
                                        neighborhood: chosenQuarter,
                                        addressLine: lineCtrl.text,
                                        isDefault: isDefault,
                                      );

                                if (!ctx.mounted) return;
                                if (ok) {
                                  Navigator.pop(sheetCtx);
                                  if (mounted) {
                                    ScaffoldMessenger.of(context).showSnackBar(
                                      SnackBar(
                                        content: Text(
                                          existing == null
                                              ? 'Adresse ajoutée !'
                                              : 'Adresse mise à jour !',
                                        ),
                                        backgroundColor: GoLivreurTheme.succes,
                                      ),
                                    );
                                  }
                                } else {
                                  setModalState(() {
                                    isSaving = false;
                                    errorText = err ?? 'Erreur lors de la sauvegarde.';
                                  });
                                }
                              },
                        child: Text(
                          isSaving
                              ? 'Enregistrement...'
                              : (existing == null ? 'Ajouter l\'adresse' : 'Enregistrer'),
                          style: const TextStyle(fontWeight: FontWeight.bold),
                        ),
                      ),
                    ),
                  ],
                ),
              ),
            ),
          );
        },
      ),
    );
  }

  Future<void> _setDefault(ClientAddressModel address) async {
    final (ok, err) =
        await ref.read(clientAddressesProvider.notifier).choisirParDefaut(address);
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(
          ok
              ? 'Adresse par défaut : ${address.label} (${address.neighborhood})'
              : (err ?? 'Impossible de modifier l\'adresse par défaut.'),
        ),
        backgroundColor: ok ? GoLivreurTheme.succes : GoLivreurTheme.erreur,
      ),
    );
  }

  Future<void> _deleteAddress(ClientAddressModel address) async {
    final confirm = await showDialog<bool>(
      context: context,
      builder: (dlgCtx) => AlertDialog(
        title: const Text('Supprimer cette adresse ?'),
        content: Text('Voulez-vous supprimer « ${address.label} — ${address.neighborhood} » ?'),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(dlgCtx, false),
            child: const Text('Annuler'),
          ),
          TextButton(
            onPressed: () => Navigator.pop(dlgCtx, true),
            child: const Text('Supprimer', style: TextStyle(color: GoLivreurTheme.erreur)),
          ),
        ],
      ),
    );
    if (confirm != true) return;

    final (ok, err) =
        await ref.read(clientAddressesProvider.notifier).supprimerAdresse(address.id);
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(ok ? 'Adresse supprimée.' : (err ?? 'Suppression impossible.')),
        backgroundColor: ok ? GoLivreurTheme.succes : GoLivreurTheme.erreur,
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final addressesState = ref.watch(clientAddressesProvider);
    final weather = ref.watch(weatherProvider);
    final activeQuarter = ref.watch(selectedNeighborhoodProvider);

    return Scaffold(
      backgroundColor: GoLivreurTheme.fond,
      appBar: AppBar(
        title: const Text('Mes adresses de livraison'),
        backgroundColor: Colors.white,
        elevation: 0.5,
      ),
      floatingActionButton: FloatingActionButton.extended(
        backgroundColor: GoLivreurTheme.primaire,
        foregroundColor: Colors.white,
        onPressed: () => _openAddressForm(),
        icon: const Icon(Icons.add_location_alt_outlined),
        label: const Text('Ajouter une adresse', style: TextStyle(fontWeight: FontWeight.bold)),
      ),
      body: RefreshIndicator(
        color: GoLivreurTheme.primaire,
        onRefresh: () => ref.read(clientAddressesProvider.notifier).chargerAdresses(),
        child: addressesState.isLoading && addressesState.addresses.isEmpty
            ? const Center(child: CircularProgressIndicator(color: GoLivreurTheme.primaire))
            : ListView(
                padding: const EdgeInsets.fromLTRB(16, 16, 16, 96),
                children: [
                  Container(
                    padding: const EdgeInsets.all(14),
                    decoration: BoxDecoration(
                      color: GoLivreurTheme.primaire.withValues(alpha: 0.1),
                      borderRadius: BorderRadius.circular(14),
                      border: Border.all(color: GoLivreurTheme.primaire.withValues(alpha: 0.3)),
                    ),
                    child: Row(
                      children: [
                        const Icon(Icons.location_on, color: GoLivreurTheme.primaire),
                        const SizedBox(width: 10),
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(
                                activeQuarter.isNotEmpty
                                    ? 'Quartier actif : ${weather.cityName.isNotEmpty ? "${weather.cityName}, " : ""}$activeQuarter'
                                    : (weather.cityName.isNotEmpty
                                        ? 'Ville : ${weather.cityName}'
                                        : 'Aucun quartier sélectionné'),
                                style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13),
                              ),
                              const Text(
                                'Touchez une adresse pour la choisir par défaut et adapter les frais.',
                                style: TextStyle(fontSize: 12, color: GoLivreurTheme.texteSecondaire),
                              ),
                            ],
                          ),
                        ),
                      ],
                    ),
                  ),
                  const SizedBox(height: 16),
                  if (addressesState.errorMessage != null) ...[
                    Container(
                      padding: const EdgeInsets.all(12),
                      decoration: BoxDecoration(
                        color: GoLivreurTheme.erreur.withValues(alpha: 0.1),
                        borderRadius: BorderRadius.circular(12),
                      ),
                      child: Text(
                        addressesState.errorMessage!,
                        style: const TextStyle(color: GoLivreurTheme.erreur),
                      ),
                    ),
                    const SizedBox(height: 12),
                  ],
                  if (addressesState.addresses.isEmpty)
                    Container(
                      padding: const EdgeInsets.all(28),
                      decoration: BoxDecoration(
                        color: Colors.white,
                        borderRadius: BorderRadius.circular(16),
                        border: Border.all(color: GoLivreurTheme.bordure),
                      ),
                      child: Column(
                        children: [
                          const Icon(Icons.home_work_outlined, size: 48, color: GoLivreurTheme.texteSecondaire),
                          const SizedBox(height: 12),
                          const Text(
                            'Aucune adresse enregistrée',
                            style: TextStyle(fontWeight: FontWeight.bold, fontSize: 16),
                          ),
                          const SizedBox(height: 6),
                          const Text(
                            'Ajoutez votre maison, bureau ou lieu favori pour commander plus vite dans votre quartier.',
                            textAlign: TextAlign.center,
                            style: TextStyle(color: GoLivreurTheme.texteSecondaire, fontSize: 13),
                          ),
                          const SizedBox(height: 16),
                          ElevatedButton.icon(
                            style: ElevatedButton.styleFrom(
                              backgroundColor: GoLivreurTheme.primaire,
                              foregroundColor: Colors.white,
                            ),
                            onPressed: () => _openAddressForm(),
                            icon: const Icon(Icons.add),
                            label: const Text('Ajouter ma première adresse'),
                          ),
                        ],
                      ),
                    )
                  else
                    ...addressesState.addresses.map((addr) {
                      return Padding(
                        padding: const EdgeInsets.only(bottom: 12),
                        child: InkWell(
                          onTap: () => _setDefault(addr),
                          borderRadius: BorderRadius.circular(16),
                          child: Container(
                            padding: const EdgeInsets.all(14),
                            decoration: BoxDecoration(
                              color: Colors.white,
                              borderRadius: BorderRadius.circular(16),
                              border: Border.all(
                                color: addr.isDefault
                                    ? GoLivreurTheme.secondaire
                                    : GoLivreurTheme.bordure,
                                width: addr.isDefault ? 1.8 : 1,
                              ),
                            ),
                            child: Row(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Container(
                                  padding: const EdgeInsets.all(10),
                                  decoration: BoxDecoration(
                                    color: (addr.isDefault
                                            ? GoLivreurTheme.secondaire
                                            : GoLivreurTheme.primaire)
                                        .withValues(alpha: 0.12),
                                    borderRadius: BorderRadius.circular(12),
                                  ),
                                  child: Icon(
                                    addr.label.toLowerCase().contains('bureau')
                                        ? Icons.work_outline
                                        : Icons.home_outlined,
                                    color: addr.isDefault
                                        ? GoLivreurTheme.secondaire
                                        : GoLivreurTheme.primaire,
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
                                              '${addr.label} — ${addr.neighborhood}',
                                              style: const TextStyle(
                                                fontWeight: FontWeight.bold,
                                                fontSize: 15,
                                              ),
                                            ),
                                          ),
                                          if (addr.isDefault) ...[
                                            const SizedBox(width: 8),
                                            Container(
                                              padding: const EdgeInsets.symmetric(
                                                horizontal: 8,
                                                vertical: 2,
                                              ),
                                              decoration: BoxDecoration(
                                                color: GoLivreurTheme.secondaire.withValues(alpha: 0.15),
                                                borderRadius: BorderRadius.circular(8),
                                              ),
                                              child: const Text(
                                                'Par défaut',
                                                style: TextStyle(
                                                  fontSize: 11,
                                                  fontWeight: FontWeight.bold,
                                                  color: GoLivreurTheme.secondaire,
                                                ),
                                              ),
                                            ),
                                          ],
                                        ],
                                      ),
                                      const SizedBox(height: 4),
                                      Text(
                                        addr.addressLine,
                                        style: const TextStyle(
                                          color: GoLivreurTheme.texteSecondaire,
                                          fontSize: 13,
                                        ),
                                      ),
                                    ],
                                  ),
                                ),
                                Column(
                                  children: [
                                    IconButton(
                                      icon: const Icon(Icons.edit_outlined, size: 20),
                                      tooltip: 'Modifier',
                                      onPressed: () => _openAddressForm(existing: addr),
                                    ),
                                    IconButton(
                                      icon: const Icon(
                                        Icons.delete_outline,
                                        size: 20,
                                        color: GoLivreurTheme.erreur,
                                      ),
                                      tooltip: 'Supprimer',
                                      onPressed: () => _deleteAddress(addr),
                                    ),
                                  ],
                                ),
                              ],
                            ),
                          ),
                        ),
                      );
                    }),
                ],
              ),
      ),
    );
  }
}

