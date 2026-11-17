import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../config/app_theme.dart';
import '../config/env_config.dart';
import '../providers/branding_provider.dart';

/// Type d'application GoLivreur pour la déclinaison du logo.
enum GoLivreurAppType {
  client,
  business,
  driver,
}

bool _hasProviderScope(BuildContext context) {
  return context.findAncestorWidgetOfExactType<ProviderScope>() != null ||
      context.findAncestorWidgetOfExactType<UncontrolledProviderScope>() != null;
}

/// Badge visuel GoLivreur : affiche le logo configuré en base s'il existe,
/// sinon l'emoji par défaut (🛵).
/// Écoute automatiquement [appBrandingProvider] si aucun [logoUrl] explicite n'est fourni.
class GoLivreurBrandMark extends StatelessWidget {
  final String? logoUrl;
  final String defaultEmoji;
  final double size;

  const GoLivreurBrandMark({
    super.key,
    this.logoUrl,
    this.defaultEmoji = '🛵',
    this.size = 48.0,
  });

  Widget _buildMark(String? effectiveLogoUrl, String effectiveEmoji) {
    final resolvedUrl = GoLivreurEnv.resolveMediaUrl(effectiveLogoUrl);
    final emoji =
        effectiveEmoji.trim().isNotEmpty ? effectiveEmoji.trim() : '🛵';

    if (resolvedUrl != null && resolvedUrl.isNotEmpty) {
      return Image.network(
        resolvedUrl,
        width: size,
        height: size,
        fit: BoxFit.cover,
        errorBuilder: (_, __, ___) => Center(
          child: Text(
            emoji,
            style: TextStyle(fontSize: size * 0.54, height: 1.0),
          ),
        ),
      );
    }

    return Center(
      child: Text(
        emoji,
        style: TextStyle(fontSize: size * 0.54, height: 1.0),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    if (_hasProviderScope(context)) {
      return Consumer(
        builder: (context, ref, _) {
          final branding = ref.watch(appBrandingProvider);
          final effectiveLogoUrl =
              (logoUrl != null && logoUrl!.trim().isNotEmpty)
                  ? logoUrl
                  : branding.logoUrl;
          final effectiveEmoji =
              (defaultEmoji.trim().isNotEmpty && defaultEmoji.trim() != '🛵')
                  ? defaultEmoji
                  : branding.defaultEmoji;
          return _buildMark(effectiveLogoUrl, effectiveEmoji);
        },
      );
    }
    return _buildMark(logoUrl, defaultEmoji);
  }
}

/// Logo officiel GoLivreur conforme aux spécifications :
///  - Logo configuré par l'admin si présent, sinon badge dégradé ambre + emoji par défaut (🛵)
///  - Texte "GoLivreur" centré
///  - Déclinaison "Client", "Business" ou "Driver" en dessous centré
/// Écoute automatiquement [appBrandingProvider] sur toutes les plateformes.
class GoLivreurLogo extends StatelessWidget {
  final GoLivreurAppType type;
  final double size;
  final bool showSubtitle;
  final String? logoUrl;
  final String defaultEmoji;

  const GoLivreurLogo({
    super.key,
    this.type = GoLivreurAppType.client,
    this.size = 88.0,
    this.showSubtitle = true,
    this.logoUrl,
    this.defaultEmoji = '🛵',
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

  Widget _buildLogo(String? effectiveLogoUrl, String effectiveEmoji) {
    final resolvedLogo = GoLivreurEnv.resolveMediaUrl(effectiveLogoUrl);
    final hasCustomLogo = resolvedLogo != null && resolvedLogo.isNotEmpty;

    return Column(
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.center,
      children: [
        // 1. Logo configuré ou badge orange avec emoji par défaut
        Container(
          width: size,
          height: size,
          decoration: BoxDecoration(
            color: hasCustomLogo ? Colors.white : GoLivreurTheme.primaire,
            borderRadius: BorderRadius.circular(size * 0.24),
            boxShadow: [
              BoxShadow(
                color: GoLivreurTheme.primaire.withValues(alpha: 0.35),
                blurRadius: 16,
                offset: const Offset(0, 8),
              ),
            ],
            gradient: hasCustomLogo
                ? null
                : const LinearGradient(
                    begin: Alignment.topLeft,
                    end: Alignment.bottomRight,
                    colors: [
                      Color(0xFFFFB732),
                      Color(0xFFF4A623),
                    ],
                  ),
          ),
          clipBehavior: Clip.antiAlias,
          child: GoLivreurBrandMark(
            logoUrl: resolvedLogo,
            defaultEmoji: effectiveEmoji,
            size: size,
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

  @override
  Widget build(BuildContext context) {
    if (_hasProviderScope(context)) {
      return Consumer(
        builder: (context, ref, _) {
          final branding = ref.watch(appBrandingProvider);
          final effectiveLogoUrl =
              (logoUrl != null && logoUrl!.trim().isNotEmpty)
                  ? logoUrl
                  : branding.logoUrl;
          final effectiveEmoji =
              (defaultEmoji.trim().isNotEmpty && defaultEmoji.trim() != '🛵')
                  ? defaultEmoji
                  : branding.defaultEmoji;
          return _buildLogo(effectiveLogoUrl, effectiveEmoji);
        },
      );
    }
    return _buildLogo(logoUrl, defaultEmoji);
  }
}

