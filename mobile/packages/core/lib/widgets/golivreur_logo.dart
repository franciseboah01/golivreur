import 'dart:typed_data';

import 'package:cached_network_image/cached_network_image.dart';
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

bool _isWidgetTestEnv() {
  final bindingType = WidgetsBinding.instance.runtimeType.toString();
  return bindingType.contains('TestWidgetsFlutterBinding') ||
      bindingType.contains('AutomatedTestWidgetsFlutterBinding');
}

/// Monogramme vectoriel propre "GL" utilisé uniquement si aucun logo image
/// n'a encore été téléchargé — aucun emoji n'est jamais affiché en fallback.
class _GlMonogramFallback extends StatelessWidget {
  final double size;

  const _GlMonogramFallback({required this.size});

  @override
  Widget build(BuildContext context) {
    return Container(
      width: size,
      height: size,
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(size * 0.24),
        gradient: const LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: [Color(0xFFFFB732), Color(0xFFF4A623)],
        ),
      ),
      alignment: Alignment.center,
      child: Text(
        'GL',
        style: TextStyle(
          color: const Color(0xFF1A1A2E),
          fontSize: size * 0.42,
          fontWeight: FontWeight.w900,
          letterSpacing: -1.0,
          height: 1.0,
        ),
      ),
    );
  }
}

/// Badge visuel GoLivreur : affiche en priorité le logo en cache local (bytes),
/// puis le logo réseau via [CachedNetworkImage] (cache disque hors-ligne),
/// et le monogramme vectoriel "GL" si aucun logo n'existe (aucun emoji fallback).
class GoLivreurBrandMark extends StatelessWidget {
  final String? logoUrl;
  final Uint8List? logoBytes;
  final String defaultEmoji;
  final double size;
  final double? borderRadius;

  const GoLivreurBrandMark({
    super.key,
    this.logoUrl,
    this.logoBytes,
    this.defaultEmoji = '',
    this.size = 48.0,
    this.borderRadius,
  });

  Widget _buildMark(
    String? effectiveLogoUrl,
    Uint8List? effectiveBytes,
  ) {
    final resolvedUrl = GoLivreurEnv.resolveMediaUrl(effectiveLogoUrl);
    final radius = BorderRadius.circular(borderRadius ?? (size * 0.24));

    if (effectiveBytes != null && effectiveBytes.isNotEmpty) {
      return ClipRRect(
        borderRadius: radius,
        child: Image.memory(
          effectiveBytes,
          width: size,
          height: size,
          fit: BoxFit.cover,
          gaplessPlayback: true,
          errorBuilder: (_, __, ___) => _GlMonogramFallback(size: size),
        ),
      );
    }

    if (resolvedUrl != null && resolvedUrl.isNotEmpty) {
      return ClipRRect(
        borderRadius: radius,
        child: _isWidgetTestEnv()
            ? Image.network(
                resolvedUrl,
                width: size,
                height: size,
                fit: BoxFit.cover,
                gaplessPlayback: true,
                errorBuilder: (_, __, ___) => _GlMonogramFallback(size: size),
              )
            : CachedNetworkImage(
                imageUrl: resolvedUrl,
                width: size,
                height: size,
                fit: BoxFit.cover,
                placeholder: (_, __) => _GlMonogramFallback(size: size),
                errorWidget: (_, __, ___) => _GlMonogramFallback(size: size),
              ),
      );
    }

    return _GlMonogramFallback(size: size);
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
          final effectiveBytes = logoBytes ?? branding.logoBytes;
          return _buildMark(effectiveLogoUrl, effectiveBytes);
        },
      );
    }
    return _buildMark(logoUrl, logoBytes);
  }
}

/// Logo officiel GoLivreur avec coins arrondis, cache hors-ligne et sans emoji fallback.
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
    this.defaultEmoji = '',
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
        return GoLivreurTheme.secondaire;
      case GoLivreurAppType.business:
        return const Color(0xFF2E75B6);
      case GoLivreurAppType.driver:
        return const Color(0xFF1B5E20);
    }
  }

  Widget _buildLogo(
    String? effectiveLogoUrl,
    Uint8List? effectiveBytes,
  ) {
    final resolvedLogo = GoLivreurEnv.resolveMediaUrl(effectiveLogoUrl);
    final radius = BorderRadius.circular(size * 0.24);

    return Column(
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.center,
      children: [
        Container(
          width: size,
          height: size,
          decoration: BoxDecoration(
            color: Colors.transparent,
            borderRadius: radius,
            boxShadow: [
              BoxShadow(
                color: Colors.black.withValues(alpha: 0.12),
                blurRadius: 14,
                offset: const Offset(0, 6),
              ),
            ],
          ),
          clipBehavior: Clip.antiAlias,
          child: GoLivreurBrandMark(
            logoUrl: resolvedLogo,
            logoBytes: effectiveBytes,
            size: size,
            borderRadius: size * 0.24,
          ),
        ),
        const SizedBox(height: 14),
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
          return _buildLogo(effectiveLogoUrl, branding.logoBytes);
        },
      );
    }
    return _buildLogo(logoUrl, null);
  }
}
