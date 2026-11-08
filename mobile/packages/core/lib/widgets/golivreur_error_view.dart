import 'package:flutter/material.dart';
import '../config/app_theme.dart';
import 'atoms/widgets_atomiques.dart';

/// Type d'erreur réseau / serveur GoLivreur.
enum GoLivreurErrorType {
  networkLoss,
  timeout,
  serverError,
  notFound,
  generic,
}

/// Écran / composant d'erreur réutilisable officiel GoLivreur.
/// 
/// Utilisable en pleine page (isFullScreen: true) ou imbriqué dans une liste / onglet.
/// Conforme à la charte : message explicite, illustration stylisée, bouton « Réessayer » de 52px.
class GoLivreurErrorView extends StatelessWidget {
  final GoLivreurErrorType type;
  final String? customTitle;
  final String? customMessage;
  final VoidCallback onRetry;
  final VoidCallback? onSecondaryAction;
  final String? secondaryActionLabel;
  final bool isFullScreen;

  const GoLivreurErrorView({
    super.key,
    this.type = GoLivreurErrorType.generic,
    this.customTitle,
    this.customMessage,
    required this.onRetry,
    this.onSecondaryAction,
    this.secondaryActionLabel,
    this.isFullScreen = false,
  });

  /// Détecte automatiquement le type d'erreur à partir d'un objet exception.
  factory GoLivreurErrorView.fromException({
    Key? key,
    required Object error,
    required VoidCallback onRetry,
    bool isFullScreen = false,
  }) {
    final str = error.toString().toLowerCase();
    GoLivreurErrorType detectedType = GoLivreurErrorType.generic;

    if (str.contains('socket') || str.contains('network') || str.contains('connection') || str.contains('offline')) {
      detectedType = GoLivreurErrorType.networkLoss;
    } else if (str.contains('timeout') || str.contains('timed out') || str.contains('deadline')) {
      detectedType = GoLivreurErrorType.timeout;
    } else if (str.contains('500') || str.contains('server') || str.contains('502') || str.contains('503')) {
      detectedType = GoLivreurErrorType.serverError;
    } else if (str.contains('404') || str.contains('not found')) {
      detectedType = GoLivreurErrorType.notFound;
    }

    return GoLivreurErrorView(
      key: key,
      type: detectedType,
      onRetry: onRetry,
      isFullScreen: isFullScreen,
    );
  }

  IconData get _icon {
    switch (type) {
      case GoLivreurErrorType.networkLoss:
        return Icons.wifi_off_rounded;
      case GoLivreurErrorType.timeout:
        return Icons.timer_off_rounded;
      case GoLivreurErrorType.serverError:
        return Icons.cloud_off_rounded;
      case GoLivreurErrorType.notFound:
        return Icons.search_off_rounded;
      case GoLivreurErrorType.generic:
        return Icons.error_outline_rounded;
    }
  }

  Color get _iconColor {
    switch (type) {
      case GoLivreurErrorType.networkLoss:
      case GoLivreurErrorType.timeout:
        return GoLivreurTheme.avertissement;
      case GoLivreurErrorType.serverError:
        return GoLivreurTheme.erreur;
      case GoLivreurErrorType.notFound:
      case GoLivreurErrorType.generic:
        return const Color(0xFFE57373);
    }
  }

  String get _title {
    if (customTitle != null) return customTitle!;
    switch (type) {
      case GoLivreurErrorType.networkLoss:
        return 'Perte de connexion Internet';
      case GoLivreurErrorType.timeout:
        return 'Délai d\'attente dépassé';
      case GoLivreurErrorType.serverError:
        return 'Service temporairement indisponible';
      case GoLivreurErrorType.notFound:
        return 'Élément introuvable';
      case GoLivreurErrorType.generic:
        return 'Une difficulté est survenue';
    }
  }

  String get _message {
    if (customMessage != null) return customMessage!;
    switch (type) {
      case GoLivreurErrorType.networkLoss:
        return 'Vérifiez votre connexion Wi-Fi ou vos données mobiles pour reprendre votre session à Daloa.';
      case GoLivreurErrorType.timeout:
        return 'La connexion est un peu lente en ce moment. Touchez Réessayer pour recharger les informations.';
      case GoLivreurErrorType.serverError:
        return 'Nos serveurs rencontrent une perturbation momentanée. Nos équipes techniques s\'en occupent déjà.';
      case GoLivreurErrorType.notFound:
        return 'Les informations demandées ne semblent plus être disponibles actuellement.';
      case GoLivreurErrorType.generic:
        return 'Nous n\'avons pas réussi à charger ces données. Merci de réessayer dans un instant.';
    }
  }

  Widget _buildContent(BuildContext context) {
    return Center(
      child: SingleChildScrollView(
        padding: const EdgeInsets.symmetric(horizontal: 28, vertical: 32),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          mainAxisAlignment: MainAxisAlignment.center,
          crossAxisAlignment: CrossAxisAlignment.center,
          children: [
            // Illustration / Icône en cercle doux
            Container(
              width: 96,
              height: 96,
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                color: _iconColor.withValues(alpha: 0.12),
              ),
              child: Center(
                child: Icon(
                  _icon,
                  size: 48,
                  color: _iconColor,
                ),
              ),
            ),
            const SizedBox(height: 24),

            // Titre d'erreur
            Text(
              _title,
              textAlign: TextAlign.center,
              style: const TextStyle(
                fontFamily: 'Inter',
                fontSize: 20,
                fontWeight: FontWeight.w700,
                color: GoLivreurTheme.textePrincipal,
                letterSpacing: -0.3,
              ),
            ),
            const SizedBox(height: 10),

            // Message explicatif
            Text(
              _message,
              textAlign: TextAlign.center,
              style: const TextStyle(
                fontFamily: 'Inter',
                fontSize: 14,
                height: 1.45,
                color: GoLivreurTheme.texteSecondaire,
              ),
            ),
            const SizedBox(height: 28),

            // Bouton « Réessayer » (52px hauteur, orange #F4A623)
            SizedBox(
              width: double.infinity,
              height: 52,
              child: BoutonPrimaire(
                label: 'Réessayer',
                icone: Icons.refresh_rounded,
                onPressed: onRetry,
              ),
            ),

            if (onSecondaryAction != null && secondaryActionLabel != null) ...[
              const SizedBox(height: 12),
              SizedBox(
                width: double.infinity,
                height: 48,
                child: BoutonSecondaire(
                  label: secondaryActionLabel!,
                  onPressed: onSecondaryAction,
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    if (isFullScreen) {
      return Scaffold(
        backgroundColor: GoLivreurTheme.fond,
        body: SafeArea(
          child: _buildContent(context),
        ),
      );
    }

    return _buildContent(context);
  }
}

