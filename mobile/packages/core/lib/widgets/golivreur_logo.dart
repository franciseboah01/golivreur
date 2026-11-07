import 'package:flutter/material.dart';
import '../config/app_theme.dart';

/// Type d'application GoLivreur pour la déclinaison du logo.
enum GoLivreurAppType {
  client,
  business,
  driver,
}

/// Logo officiel GoLivreur conforme aux spécifications :
///  - Carré orange aux couleurs de GoLivreur (#F4A623)
///  - Dessin d'une moto simple blanche à l'intérieur
///  - Texte "GoLivreur" centré
///  - Déclinaison "Client", "Business" ou "Driver" en dessous centré
class GoLivreurLogo extends StatelessWidget {
  final GoLivreurAppType type;
  final double size;
  final bool showSubtitle;

  const GoLivreurLogo({
    super.key,
    this.type = GoLivreurAppType.client,
    this.size = 88.0,
    this.showSubtitle = true,
  });

  String get _badgeText {
    switch (type) {
      case GoLivreurAppType.client:
        return 'CLIENT';
      case GoLivreurAppType.business:
        return 'BUSINESS';
      case GoLivreurAppType.driver:
        return 'DRIVER';
    }
  }

  Color get _badgeColor {
    switch (type) {
      case GoLivreurAppType.client:
        return GoLivreurTheme.secondaire; // #1B5E20 Vert Confiance
      case GoLivreurAppType.business:
        return const Color(0xFF2E75B6); // #2E75B6 Bleu Info
      case GoLivreurAppType.driver:
        return const Color(0xFF1B5E20); // #1B5E20 Vert Confiance
    }
  }

  @override
  Widget build(BuildContext context) {
    return Column(
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.center,
      children: [
        // 1. Carré orange avec moto simple au centre
        Container(
          width: size,
          height: size,
          decoration: BoxDecoration(
            color: GoLivreurTheme.primaire,
            borderRadius: BorderRadius.circular(size * 0.24),
            boxShadow: [
              BoxShadow(
                color: GoLivreurTheme.primaire.withValues(alpha: 0.35),
                blurRadius: 16,
                offset: const Offset(0, 8),
              ),
            ],
            gradient: const LinearGradient(
              begin: Alignment.topLeft,
              end: Alignment.bottomRight,
              colors: [
                Color(0xFFFFB732),
                Color(0xFFF4A623),
              ],
            ),
          ),
          child: Center(
            child: Icon(
              Icons.two_wheeler_rounded,
              size: size * 0.58,
              color: Colors.white,
            ),
          ),
        ),
        const SizedBox(height: 14),

        // 2. Nom de marque "GoLivreur"
        RichText(
          textAlign: TextAlign.center,
          text: const TextSpan(
            style: TextStyle(
              fontSize: 26,
              fontWeight: FontWeight.w800,
              letterSpacing: -0.5,
            ),
            children: [
              TextSpan(
                text: 'Go',
                style: TextStyle(color: GoLivreurTheme.primaire),
              ),
              TextSpan(
                text: 'Livreur',
                style: TextStyle(color: GoLivreurTheme.textePrincipal),
              ),
            ],
          ),
        ),

        // 3. Déclinaison Client / Business / Driver en dessous centré
        if (showSubtitle) ...[
          const SizedBox(height: 6),
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 4),
            decoration: BoxDecoration(
              color: _badgeColor.withValues(alpha: 0.10),
              borderRadius: BorderRadius.circular(16),
              border: Border.all(
                color: _badgeColor.withValues(alpha: 0.25),
                width: 1,
              ),
            ),
            child: Text(
              _badgeText,
              style: TextStyle(
                fontSize: 11,
                fontWeight: FontWeight.bold,
                letterSpacing: 2.0,
                color: _badgeColor,
              ),
            ),
          ),
        ],
      ],
    );
  }
}

