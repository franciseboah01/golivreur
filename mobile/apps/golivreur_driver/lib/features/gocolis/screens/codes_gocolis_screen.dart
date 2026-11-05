import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:golivreur_core/config/app_theme.dart';
import 'package:golivreur_core/models/modeles.dart';
import 'package:golivreur_core/widgets/atoms/widgets_atomiques.dart';
import '../providers/gocolis_course_provider.dart';

/// Écran de validation des codes GoColis — côté Driver.
///
/// Flux en deux étapes OBLIGATOIRES et dans l'ordre :
///
/// ÉTAPE 1 — Chez l'expéditeur :
///   a) Prendre une photo du colis (obligatoire)
///   b) Saisir le code expéditeur (4 chiffres, donné verbalement)
///   c) Blocage après 3 erreurs → alerte Admin
///
/// ÉTAPE 2 — Chez le destinataire :
///   a) Prendre une photo de remise (obligatoire)
///   b) Saisir le code destinataire (4 chiffres, reçu par SMS)
///   c) Blocage après 3 erreurs → alerte Admin
///
/// Le livreur NE PEUT PAS passer à l'étape 2 sans avoir validé l'étape 1.
class CodesGoColisScreen extends ConsumerStatefulWidget {
  final int parcelId;

  const CodesGoColisScreen({super.key, required this.parcelId});

  @override
  ConsumerState<CodesGoColisScreen> createState() => _CodesGoColisScreenState();
}

class _CodesGoColisScreenState extends ConsumerState<CodesGoColisScreen> {
  // Contrôleurs des champs de saisie
  final List<TextEditingController> _controlleursCodes =
      List.generate(4, (_) => TextEditingController());
  final List<FocusNode> _focusNodes = List.generate(4, (_) => FocusNode());

  // État de validation
  bool _estEnChargement = false;
  String? _messageErreur;
  int _tentativesRestantes = 3;

  // Photo prise (obligatoire avant de valider le code)
  bool _photoOk = false;

  @override
  void dispose() {
    for (final c in _controlleursCodes) c.dispose();
    for (final f in _focusNodes) f.dispose();
    super.dispose();
  }

  /// Assemble les 4 chiffres en un code à 4 chiffres.
  String get _codeAssemble =>
      _controlleursCodes.map((c) => c.text).join();

  bool get _codeComplet => _codeAssemble.length == 4;

  /// Gère la saisie dans les cases du code.
  /// Avance automatiquement au champ suivant après chaque chiffre.
  void _onChiffreSaisi(int index, String valeur) {
    if (valeur.isNotEmpty) {
      // Avancer au champ suivant
      if (index < 3) {
        _focusNodes[index + 1].requestFocus();
      } else {
        // Dernier champ — fermer le clavier
        _focusNodes[index].unfocus();
      }
    } else {
      // Effacement — reculer au champ précédent
      if (index > 0) {
        _focusNodes[index - 1].requestFocus();
      }
    }
    setState(() {}); // Rafraîchir l'état du bouton
  }

  /// Prendre une photo (obligatoire avant validation du code).
  Future<void> _prendrePhoto(String type, int parcelId) async {
    setState(() => _estEnChargement = true);
    try {
      await ref.read(goColisCoursePovider.notifier).prendrePhoto(
            parcelId: parcelId,
            type: type,
          );
      setState(() {
        _photoOk = true;
        _messageErreur = null;
      });
    } catch (e) {
      setState(() => _messageErreur = 'Erreur photo : réessayez.');
    } finally {
      setState(() => _estEnChargement = false);
    }
  }

  /// Valide le code expéditeur (Étape 1).
  Future<void> _validerCodeExpediteur(ParcelModel parcel) async {
    if (!_photoOk) {
      setState(() => _messageErreur = 'Prenez d\'abord une photo du colis.');
      return;
    }

    if (!_codeComplet) return;

    setState(() {
      _estEnChargement = true;
      _messageErreur = null;
    });

    try {
      await ref.read(goColisCoursePovider.notifier).validerCodeExpediteur(
            parcelId: parcel.id,
            code: _codeAssemble,
          );
      // Succès → naviguer vers l'écran suivant (navigation vers destinataire)
      if (mounted) {
        _reinitialiserCodes();
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text('✅ Code expéditeur validé — Colis récupéré !'),
            backgroundColor: GoLivreurTheme.succes,
          ),
        );
      }
    } on CodeIncorrectException catch (e) {
      setState(() {
        _tentativesRestantes = e.tentativesRestantes;
        _messageErreur = 'Code incorrect. '
            '$_tentativesRestantes tentative(s) restante(s).';
        _reinitialiserCodes();
      });
    } on CodeBloqueException {
      setState(() {
        _messageErreur = null;
      });
      if (mounted) _afficherDialogueCodeBloque('expéditeur');
    } catch (e) {
      setState(() => _messageErreur = 'Erreur réseau. Réessayez.');
    } finally {
      if (mounted) setState(() => _estEnChargement = false);
    }
  }

  /// Valide le code destinataire (Étape 2).
  Future<void> _validerCodeDestinataire(ParcelModel parcel) async {
    if (!_photoOk) {
      setState(() => _messageErreur = 'Prenez d\'abord une photo de remise.');
      return;
    }

    if (!_codeComplet) return;

    setState(() {
      _estEnChargement = true;
      _messageErreur = null;
    });

    try {
      final gain = await ref.read(goColisCoursePovider.notifier).validerCodeDestinataire(
            parcelId: parcel.id,
            code: _codeAssemble,
          );

      if (mounted) {
        // Succès → afficher le gain et revenir à la liste des courses
        _afficherDialogueSuccesLivraison(gain);
      }
    } on CodeIncorrectException catch (e) {
      setState(() {
        _tentativesRestantes = e.tentativesRestantes;
        _messageErreur = 'Code incorrect. '
            '$_tentativesRestantes tentative(s) restante(s).';
        _reinitialiserCodes();
      });
    } on CodeBloqueException {
      if (mounted) _afficherDialogueCodeBloque('destinataire');
    } catch (e) {
      setState(() => _messageErreur = 'Erreur réseau. Réessayez.');
    } finally {
      if (mounted) setState(() => _estEnChargement = false);
    }
  }

  void _reinitialiserCodes() {
    for (final c in _controlleursCodes) c.clear();
    if (_focusNodes.isNotEmpty) _focusNodes[0].requestFocus();
    setState(() {});
  }

  void _afficherDialogueCodeBloque(String typeCode) {
    showDialog(
      context: context,
      barrierDismissible: false,
      builder: (_) => AlertDialog(
        title: const Text('Livraison bloquée'),
        content: Text(
          'Le code $typeCode a été saisi incorrectement 3 fois.\n\n'
          'Le City Support a été alerté automatiquement. '
          'Restez sur place et attendez l\'appel du support GoLivreur.',
        ),
        icon: const Icon(Icons.lock_outline, color: GoLivreurTheme.erreur, size: 48),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(context).pop(),
            child: const Text('J\'ai compris'),
          ),
        ],
      ),
    );
  }

  void _afficherDialogueSuccesLivraison(double gain) {
    showDialog(
      context: context,
      barrierDismissible: false,
      builder: (_) => AlertDialog(
        title: const Text('Livraison confirmée ! 🎉'),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Icon(Icons.check_circle, color: GoLivreurTheme.succes, size: 64),
            const SizedBox(height: 16),
            Text(
              'Votre gain de ${gain.toStringAsFixed(0)} F CFA\na été crédité sur votre wallet.',
              textAlign: TextAlign.center,
              style: const TextStyle(fontSize: 16),
            ),
          ],
        ),
        actions: [
          BoutonPrimaire(
            label: 'Voir mes courses',
            onPressed: () {
              Navigator.of(context).popUntil((route) => route.isFirst);
            },
          ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final parcelAsync = ref.watch(parcelDetailProvider(widget.parcelId));

    return Scaffold(
      appBar: AppBar(title: const Text('Validation GoColis')),
      body: parcelAsync.when(
        loading: () => const EtatChargement(nombreLignes: 3),
        error: (err, _) => EtatErreur(
          message: 'Impossible de charger l\'expédition.',
          onReessayer: () => ref.invalidate(parcelDetailProvider(widget.parcelId)),
        ),
        data: (parcel) {
          // Déterminer l'étape actuelle
          final estEtape1 = ['accepted', 'at_sender'].contains(parcel.status);
          final estEtape2 = ['picked_up', 'in_transit', 'at_recipient'].contains(parcel.status);

          return SingleChildScrollView(
            padding: const EdgeInsets.all(GoLivreurTheme.espacementM),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [

                // ── Infos colis ──────────────────────────────────────────────
                Card(
                  child: Padding(
                    padding: const EdgeInsets.all(GoLivreurTheme.espacementM),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Row(
                          children: [
                            const Icon(Icons.inventory_2_outlined,
                                color: GoLivreurTheme.primaire),
                            const SizedBox(width: 8),
                            Text(parcel.sizeLabel,
                                style: const TextStyle(fontWeight: FontWeight.w600)),
                          ],
                        ),
                        const SizedBox(height: 8),
                        Text('Destinataire : ${parcel.recipientName}'),
                        Text('Téléphone : ${parcel.recipientPhone}'),
                        Text('Adresse : ${parcel.deliveryAddress}'),
                        if (parcel.hasInsurance)
                          Container(
                            margin: const EdgeInsets.only(top: 8),
                            padding: const EdgeInsets.symmetric(
                                horizontal: 10, vertical: 4),
                            decoration: BoxDecoration(
                              color: GoLivreurTheme.secondaire.withValues(alpha: 0.1),
                              borderRadius:
                                  BorderRadius.circular(GoLivreurTheme.rayonXL),
                            ),
                            child: const Text(
                              '🔒 Colis assuré — manipulez avec soin',
                              style: TextStyle(
                                color: GoLivreurTheme.secondaire,
                                fontSize: 12,
                                fontWeight: FontWeight.w500,
                              ),
                            ),
                          ),
                      ],
                    ),
                  ),
                ),

                const SizedBox(height: GoLivreurTheme.espacementM),

                // ── Indicateur d'étape ────────────────────────────────────────
                _IndicateurEtape(
                  etapeActuelle: estEtape1 ? 1 : 2,
                  etape1Complete: parcel.status != 'pending' &&
                      parcel.status != 'accepted' &&
                      parcel.status != 'at_sender',
                ),

                const SizedBox(height: GoLivreurTheme.espacementL),

                // ── ÉTAPE 1 : Code expéditeur ─────────────────────────────────
                if (estEtape1) ...[
                  _SectionCode(
                    titre: 'Étape 1 — Chez l\'expéditeur',
                    description: 'Demandez le code à 4 chiffres à l\'expéditeur.',
                    estBloqueCote: parcel.senderCodeLocked,
                    photoRequise: true,
                    photoOk: _photoOk,
                    onPrendrePhoto: () => _prendrePhoto('pickup', parcel.id),
                    controlleurs: _controlleursCodes,
                    focusNodes: _focusNodes,
                    onChiffreSaisi: _onChiffreSaisi,
                    messageErreur: _messageErreur,
                    tentativesRestantes: _tentativesRestantes,
                    estEnChargement: _estEnChargement,
                    codeComplet: _codeComplet,
                    onValider: () => _validerCodeExpediteur(parcel),
                    labelBouton: 'Valider — Colis récupéré',
                  ),
                ],

                // ── ÉTAPE 2 : Code destinataire ───────────────────────────────
                if (estEtape2) ...[
                  _SectionCode(
                    titre: 'Étape 2 — Chez le destinataire',
                    description:
                        'Demandez le code SMS reçu par le destinataire.',
                    estBloqueCote: parcel.recipientCodeLocked,
                    photoRequise: true,
                    photoOk: _photoOk,
                    onPrendrePhoto: () => _prendrePhoto('delivery', parcel.id),
                    controlleurs: _controlleursCodes,
                    focusNodes: _focusNodes,
                    onChiffreSaisi: _onChiffreSaisi,
                    messageErreur: _messageErreur,
                    tentativesRestantes: _tentativesRestantes,
                    estEnChargement: _estEnChargement,
                    codeComplet: _codeComplet,
                    onValider: () => _validerCodeDestinataire(parcel),
                    labelBouton: 'Confirmer la livraison',
                  ),
                ],
              ],
            ),
          );
        },
      ),
    );
  }
}

/// Section de saisie de code (réutilisée pour expéditeur et destinataire).
class _SectionCode extends StatelessWidget {
  final String titre;
  final String description;
  final bool estBloqueCote;
  final bool photoRequise;
  final bool photoOk;
  final VoidCallback onPrendrePhoto;
  final List<TextEditingController> controlleurs;
  final List<FocusNode> focusNodes;
  final void Function(int, String) onChiffreSaisi;
  final String? messageErreur;
  final int tentativesRestantes;
  final bool estEnChargement;
  final bool codeComplet;
  final VoidCallback onValider;
  final String labelBouton;

  const _SectionCode({
    required this.titre,
    required this.description,
    required this.estBloqueCote,
    required this.photoRequise,
    required this.photoOk,
    required this.onPrendrePhoto,
    required this.controlleurs,
    required this.focusNodes,
    required this.onChiffreSaisi,
    required this.messageErreur,
    required this.tentativesRestantes,
    required this.estEnChargement,
    required this.codeComplet,
    required this.onValider,
    required this.labelBouton,
  });

  @override
  Widget build(BuildContext context) {
    if (estBloqueCote) {
      return Container(
        padding: const EdgeInsets.all(GoLivreurTheme.espacementM),
        decoration: BoxDecoration(
          color: GoLivreurTheme.erreur.withValues(alpha: 0.08),
          borderRadius: BorderRadius.circular(GoLivreurTheme.rayonL),
        ),
        child: const Column(
          children: [
            Icon(Icons.lock, color: GoLivreurTheme.erreur, size: 48),
            SizedBox(height: 8),
            Text(
              'Code bloqué — Attendez l\'appel du support GoLivreur.',
              textAlign: TextAlign.center,
              style: TextStyle(color: GoLivreurTheme.erreur, fontWeight: FontWeight.w500),
            ),
          ],
        ),
      );
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(titre,
            style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w700)),
        const SizedBox(height: 8),
        Text(description,
            style: const TextStyle(color: GoLivreurTheme.texteSecondaire)),
        const SizedBox(height: GoLivreurTheme.espacementM),

        // Bouton photo (obligatoire)
        if (photoRequise) ...[
          OutlinedButton.icon(
            onPressed: estEnChargement ? null : onPrendrePhoto,
            icon: Icon(
              photoOk ? Icons.check_circle : Icons.camera_alt_outlined,
              color: photoOk ? GoLivreurTheme.succes : GoLivreurTheme.primaire,
            ),
            label: Text(
              photoOk ? 'Photo prise ✓' : 'Prendre une photo (obligatoire)',
              style: TextStyle(
                color: photoOk ? GoLivreurTheme.succes : GoLivreurTheme.primaire,
              ),
            ),
            style: OutlinedButton.styleFrom(
              side: BorderSide(
                color: photoOk ? GoLivreurTheme.succes : GoLivreurTheme.primaire,
              ),
            ),
          ),
          const SizedBox(height: GoLivreurTheme.espacementM),
        ],

        // Cases de saisie du code
        Row(
          mainAxisAlignment: MainAxisAlignment.spaceEvenly,
          children: List.generate(4, (index) {
            return SizedBox(
              width: 64,
              height: 72,
              child: TextField(
                controller: controlleurs[index],
                focusNode: focusNodes[index],
                keyboardType: TextInputType.number,
                textAlign: TextAlign.center,
                maxLength: 1,
                enabled: photoOk && !estBloqueCote,
                decoration: InputDecoration(
                  counterText: '',
                  filled: true,
                  fillColor: photoOk
                      ? Colors.white
                      : GoLivreurTheme.fond,
                  border: OutlineInputBorder(
                    borderRadius: BorderRadius.circular(GoLivreurTheme.rayonM),
                    borderSide: BorderSide(
                      color: messageErreur != null
                          ? GoLivreurTheme.erreur
                          : GoLivreurTheme.primaire,
                      width: 2,
                    ),
                  ),
                ),
                style: const TextStyle(
                  fontSize: 28,
                  fontWeight: FontWeight.w700,
                  fontFamily: 'Inter',
                ),
                onChanged: (v) => onChiffreSaisi(index, v),
              ),
            );
          }),
        ),

        // Message d'erreur
        if (messageErreur != null) ...[
          const SizedBox(height: 8),
          Row(
            children: [
              const Icon(Icons.warning_amber, color: GoLivreurTheme.erreur, size: 16),
              const SizedBox(width: 6),
              Expanded(
                child: Text(
                  messageErreur!,
                  style: const TextStyle(
                    color: GoLivreurTheme.erreur,
                    fontSize: 13,
                    fontFamily: 'Inter',
                  ),
                ),
              ),
            ],
          ),
        ],

        const SizedBox(height: GoLivreurTheme.espacementL),

        BoutonPrimaire(
          label: labelBouton,
          onPressed: (codeComplet && photoOk && !estEnChargement) ? onValider : null,
          estEnChargement: estEnChargement,
          icone: Icons.check_circle_outline,
          couleur: codeComplet && photoOk ? null : GoLivreurTheme.texteSecondaire,
        ),
      ],
    );
  }
}

/// Indicateur visuel de progression en 2 étapes.
class _IndicateurEtape extends StatelessWidget {
  final int etapeActuelle;
  final bool etape1Complete;

  const _IndicateurEtape({
    required this.etapeActuelle,
    required this.etape1Complete,
  });

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        _Etape(numero: 1, label: 'Expéditeur', active: etapeActuelle == 1, complete: etape1Complete),
        Expanded(
          child: Container(
            height: 2,
            color: etape1Complete ? GoLivreurTheme.succes : GoLivreurTheme.bordure,
          ),
        ),
        _Etape(numero: 2, label: 'Destinataire', active: etapeActuelle == 2, complete: false),
      ],
    );
  }
}

class _Etape extends StatelessWidget {
  final int numero;
  final String label;
  final bool active;
  final bool complete;

  const _Etape({
    required this.numero,
    required this.label,
    required this.active,
    required this.complete,
  });

  @override
  Widget build(BuildContext context) {
    final couleur = complete
        ? GoLivreurTheme.succes
        : active
            ? GoLivreurTheme.primaire
            : GoLivreurTheme.bordure;

    return Column(
      children: [
        CircleAvatar(
          radius: 18,
          backgroundColor: couleur,
          child: complete
              ? const Icon(Icons.check, color: Colors.white, size: 18)
              : Text(
                  '$numero',
                  style: TextStyle(
                    color: active ? Colors.white : GoLivreurTheme.texteSecondaire,
                    fontWeight: FontWeight.w700,
                  ),
                ),
        ),
        const SizedBox(height: 4),
        Text(label,
            style: TextStyle(
              fontSize: 11,
              color: couleur,
              fontWeight: active ? FontWeight.w600 : FontWeight.w400,
            )),
      ],
    );
  }
}

// ─── Exceptions métier ────────────────────────────────────────────────────────

class CodeIncorrectException implements Exception {
  final int tentativesRestantes;
  const CodeIncorrectException(this.tentativesRestantes);
}

class CodeBloqueException implements Exception {
  const CodeBloqueException();
}
