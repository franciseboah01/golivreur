import 'package:flutter/material.dart';
import 'package:shimmer/shimmer.dart';
import 'package:golivreur_core/config/app_theme.dart';

// ═══════════════════════════════════════════════════════════════════════════════
// ATOMES — Composants de base réutilisables dans les 3 apps
// ═══════════════════════════════════════════════════════════════════════════════

/// Bouton primaire GoLivreur.
/// Orange #F4A623, pleine largeur, 52px de hauteur (facile à taper avec le pouce).
class BoutonPrimaire extends StatelessWidget {
  final String label;
  final VoidCallback? onPressed;
  final bool estEnChargement;
  final IconData? icone;
  final Color? couleur;

  const BoutonPrimaire({
    super.key,
    required this.label,
    this.onPressed,
    this.estEnChargement = false,
    this.icone,
    this.couleur,
  });

  @override
  Widget build(BuildContext context) {
    return ElevatedButton(
      onPressed: estEnChargement ? null : onPressed,
      style: ElevatedButton.styleFrom(
        backgroundColor: couleur ?? GoLivreurTheme.primaire,
      ),
      child: estEnChargement
          ? const SizedBox(
              height: 20,
              width: 20,
              child: CircularProgressIndicator(
                strokeWidth: 2.5,
                valueColor: AlwaysStoppedAnimation<Color>(Colors.white),
              ),
            )
          : Row(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                if (icone != null) ...[
                  Icon(icone, size: 20),
                  const SizedBox(width: 8),
                ],
                Text(label),
              ],
            ),
    );
  }
}

/// Bouton secondaire (outline) GoLivreur.
class BoutonSecondaire extends StatelessWidget {
  final String label;
  final VoidCallback? onPressed;
  final IconData? icone;

  const BoutonSecondaire({
    super.key,
    required this.label,
    this.onPressed,
    this.icone,
  });

  @override
  Widget build(BuildContext context) {
    return OutlinedButton(
      onPressed: onPressed,
      child: Row(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          if (icone != null) ...[Icon(icone, size: 20), const SizedBox(width: 8)],
          Text(label),
        ],
      ),
    );
  }
}

/// Badge de statut de commande/expédition.
class BadgeStatut extends StatelessWidget {
  final String statut;
  final String label;

  const BadgeStatut({super.key, required this.statut, required this.label});

  @override
  Widget build(BuildContext context) {
    final couleur = GoLivreurTheme.couleurStatutCommande(statut);
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
      decoration: BoxDecoration(
        color: couleur.withOpacity(0.12),
        borderRadius: BorderRadius.circular(GoLivreurTheme.rayonXL),
        border: Border.all(color: couleur.withOpacity(0.4)),
      ),
      child: Text(
        label,
        style: TextStyle(
          fontSize: 12,
          fontWeight: FontWeight.w600,
          color: couleur,
          fontFamily: 'Inter',
        ),
      ),
    );
  }
}

/// Champ de saisie du code à 4 chiffres (OTP ou code de confirmation).
class ChampCodeOTP extends StatelessWidget {
  final int longueur;
  final void Function(String) onCompleted;
  final String? erreur;

  const ChampCodeOTP({
    super.key,
    this.longueur = 4,
    required this.onCompleted,
    this.erreur,
  });

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        Row(
          mainAxisAlignment: MainAxisAlignment.spaceEvenly,
          children: List.generate(longueur, (index) => _CaseCode(index: index)),
        ),
        if (erreur != null) ...[
          const SizedBox(height: 8),
          Text(
            erreur!,
            style: const TextStyle(
              color: GoLivreurTheme.erreur,
              fontSize: 13,
              fontFamily: 'Inter',
            ),
          ),
        ],
      ],
    );
  }
}

class _CaseCode extends StatelessWidget {
  final int index;
  const _CaseCode({required this.index});

  @override
  Widget build(BuildContext context) {
    return Container(
      width: 60,
      height: 64,
      decoration: BoxDecoration(
        border: Border.all(color: GoLivreurTheme.primaire, width: 2),
        borderRadius: BorderRadius.circular(GoLivreurTheme.rayonM),
      ),
      child: const Center(
        child: Text(
          '•',
          style: TextStyle(fontSize: 28, color: GoLivreurTheme.textePrincipal),
        ),
      ),
    );
  }
}

// ═══════════════════════════════════════════════════════════════════════════════
// ÉTATS — 4 états obligatoires sur tous les écrans de liste
// ═══════════════════════════════════════════════════════════════════════════════

/// État 1 : Chargement avec skeleton loader.
/// Utilisé sur TOUS les écrans de liste pendant le fetch initial.
class EtatChargement extends StatelessWidget {
  final int nombreLignes;

  const EtatChargement({super.key, this.nombreLignes = 5});

  @override
  Widget build(BuildContext context) {
    return Shimmer.fromColors(
      baseColor: Colors.grey[300]!,
      highlightColor: Colors.grey[100]!,
      child: ListView.builder(
        physics: const NeverScrollableScrollPhysics(),
        itemCount: nombreLignes,
        padding: const EdgeInsets.all(GoLivreurTheme.espacementM),
        itemBuilder: (_, __) => Padding(
          padding: const EdgeInsets.only(bottom: GoLivreurTheme.espacementM),
          child: Container(
            height: 88,
            decoration: BoxDecoration(
              color: Colors.white,
              borderRadius: BorderRadius.circular(GoLivreurTheme.rayonL),
            ),
            child: Padding(
              padding: const EdgeInsets.all(GoLivreurTheme.espacementM),
              child: Row(
                children: [
                  // Avatar placeholder
                  Container(
                    width: 56,
                    height: 56,
                    decoration: BoxDecoration(
                      color: Colors.white,
                      borderRadius: BorderRadius.circular(GoLivreurTheme.rayonM),
                    ),
                  ),
                  const SizedBox(width: 12),
                  // Lignes de texte placeholder
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        Container(height: 14, color: Colors.white),
                        const SizedBox(height: 8),
                        Container(height: 12, width: 160, color: Colors.white),
                        const SizedBox(height: 6),
                        Container(height: 10, width: 100, color: Colors.white),
                      ],
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
}

/// État 2 : Erreur avec bouton "Réessayer" et message explicite.
/// Affiché quand la requête API échoue (réseau, timeout, erreur serveur).
class EtatErreur extends StatelessWidget {
  final String message;
  final VoidCallback onReessayer;
  final bool estTimeout;

  const EtatErreur({
    super.key,
    required this.message,
    required this.onReessayer,
    this.estTimeout = false,
  });

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(GoLivreurTheme.espacementXL),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Icon(
              estTimeout ? Icons.wifi_off_rounded : Icons.error_outline_rounded,
              size: 64,
              color: GoLivreurTheme.erreur.withOpacity(0.7),
            ),
            const SizedBox(height: GoLivreurTheme.espacementM),
            Text(
              estTimeout ? 'Connexion lente' : 'Oups !',
              style: Theme.of(context).textTheme.titleLarge,
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: GoLivreurTheme.espacementS),
            Text(
              message,
              style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                    color: GoLivreurTheme.texteSecondaire,
                  ),
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: GoLivreurTheme.espacementL),
            SizedBox(
              width: 200,
              child: BoutonPrimaire(
                label: 'Réessayer',
                icone: Icons.refresh_rounded,
                onPressed: onReessayer,
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// État 3 : Liste vide avec action suggérée.
/// Affiché quand la requête réussit mais retourne 0 éléments.
class EtatVide extends StatelessWidget {
  final IconData icone;
  final String message;
  final String? labelAction;
  final VoidCallback? onAction;

  const EtatVide({
    super.key,
    required this.icone,
    required this.message,
    this.labelAction,
    this.onAction,
  });

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(GoLivreurTheme.espacementXL),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Icon(
              icone,
              size: 72,
              color: GoLivreurTheme.texteSecondaire.withOpacity(0.4),
            ),
            const SizedBox(height: GoLivreurTheme.espacementM),
            Text(
              message,
              style: Theme.of(context).textTheme.bodyLarge?.copyWith(
                    color: GoLivreurTheme.texteSecondaire,
                  ),
              textAlign: TextAlign.center,
            ),
            if (labelAction != null && onAction != null) ...[
              const SizedBox(height: GoLivreurTheme.espacementL),
              SizedBox(
                width: 220,
                child: BoutonPrimaire(
                  label: labelAction!,
                  onPressed: onAction,
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }
}

/// État 4 : Bannière "Connexion lente" superposée à l'écran.
/// Affiché après 8 secondes de chargement sans réponse.
/// Décision Francis EBOAH : le client doit toujours savoir ce qui se passe.
class BanniereConnexionLente extends StatelessWidget {
  final VoidCallback onAnnuler;

  const BanniereConnexionLente({super.key, required this.onAnnuler});

  @override
  Widget build(BuildContext context) {
    return Container(
      margin: const EdgeInsets.symmetric(horizontal: 16),
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
      decoration: BoxDecoration(
        color: GoLivreurTheme.avertissement,
        borderRadius: BorderRadius.circular(GoLivreurTheme.rayonL),
        boxShadow: [
          BoxShadow(
            color: Colors.black.withOpacity(0.1),
            blurRadius: 8,
            offset: const Offset(0, 4),
          ),
        ],
      ),
      child: Row(
        children: [
          const Icon(Icons.wifi_off, color: Colors.white, size: 20),
          const SizedBox(width: 10),
          const Expanded(
            child: Text(
              'Connexion lente, veuillez patienter...',
              style: TextStyle(
                color: Colors.white,
                fontFamily: 'Inter',
                fontSize: 13,
                fontWeight: FontWeight.w500,
              ),
            ),
          ),
          GestureDetector(
            onTap: onAnnuler,
            child: const Text(
              'Annuler',
              style: TextStyle(
                color: Colors.white,
                fontFamily: 'Inter',
                fontSize: 13,
                fontWeight: FontWeight.w700,
                decoration: TextDecoration.underline,
                decorationColor: Colors.white,
              ),
            ),
          ),
        ],
      ),
    );
  }
}
