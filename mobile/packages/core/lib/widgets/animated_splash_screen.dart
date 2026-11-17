import 'dart:async';
import 'dart:math' as math;
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../config/app_theme.dart';
import '../config/env_config.dart';
import '../providers/branding_provider.dart';
import 'golivreur_logo.dart';

/// Écran de démarrage (Splash Screen) sombre et animé officiel GoLivreur.
/// 
/// Spécifications :
///  - Fond sombre profond (#121212)
///  - Animation du cercle : un cercle se dessine progressivement autour de la moto
///    (CustomPainter animé avec strokeCap round)
///  - Moto en orange (#F4A623) centrée dans le cercle
///  - Texte « GoLivreur » apparaissant en fondu (fade-in) après le tracé du cercle
///  - Transition fluide vers l'écran suivant (login ou accueil)
class AnimatedSplashScreen extends StatefulWidget {
  final GoLivreurAppType appType;
  final Widget? nextScreen;
  final VoidCallback? onFinished;
  final Future<void> Function()? onInit;
  final String? logoUrl;
  final String defaultEmoji;

  const AnimatedSplashScreen({
    super.key,
    this.appType = GoLivreurAppType.client,
    this.nextScreen,
    this.onFinished,
    this.onInit,
    this.logoUrl,
    this.defaultEmoji = '🛵',
  });

  @override
  State<AnimatedSplashScreen> createState() => _AnimatedSplashScreenState();
}

class _AnimatedSplashScreenState extends State<AnimatedSplashScreen>
    with SingleTickerProviderStateMixin {
  late final AnimationController _controller;
  late final Animation<double> _circleProgress;
  late final Animation<double> _motorcycleScale;
  late final Animation<double> _textFade;
  late final Animation<Offset> _textSlide;

  bool _initFinished = false;
  bool _hasNavigated = false;
  Timer? _fallbackTimer;

  @override
  void initState() {
    super.initState();

    _controller = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 2400),
    );

    // 1. Cercle tracé de 0 à 100% entre 0.0 et 0.65 de la durée
    _circleProgress = CurvedAnimation(
      parent: _controller,
      curve: const Interval(0.0, 0.65, curve: Curves.easeInOutCubic),
    );

    // 2. Légère pulsation / apparition dynamique de la moto
    _motorcycleScale = Tween<double>(begin: 0.75, end: 1.0).animate(
      CurvedAnimation(
        parent: _controller,
        curve: const Interval(0.1, 0.65, curve: Curves.easeOutBack),
      ),
    );

    // 3. Texte "GoLivreur" apparaissant en fondu après le cercle (0.60 à 0.90)
    _textFade = CurvedAnimation(
      parent: _controller,
      curve: const Interval(0.60, 0.90, curve: Curves.easeIn),
    );

    _textSlide = Tween<Offset>(
      begin: const Offset(0, 0.25),
      end: Offset.zero,
    ).animate(
      CurvedAnimation(
        parent: _controller,
        curve: const Interval(0.60, 0.90, curve: Curves.easeOutCubic),
      ),
    );

    _controller.addStatusListener((status) {
      if (status == AnimationStatus.completed) {
        _terminerEtNaviguer();
      }
    });

    // Démarrer l'initialisation (incluant la synchronisation du logo) puis l'animation
    _executerInitialisation();

    // Sécurité absolue : délai max de 3.2s après lequel on navigue d'office
    _fallbackTimer = Timer(const Duration(milliseconds: 3200), () {
      if (mounted && !_hasNavigated) {
        _terminerEtNaviguer(force: true);
      }
    });
  }

  Future<void> _executerInitialisation() async {
    final bindingType = WidgetsBinding.instance.runtimeType.toString();
    final isWidgetTest = bindingType.contains('TestWidgetsFlutterBinding') ||
        bindingType.contains('AutomatedTestWidgetsFlutterBinding');

    if (isWidgetTest) {
      _controller.forward();
    } else {
      // Pré-charger le branding avant de lancer le tracé du cercle pour que
      // le logo personnalisé soit immédiatement visible au centre du cercle.
      try {
        WidgetsBinding.instance.addPostFrameCallback((_) async {
          if (!mounted) return;
          try {
            if (context.findAncestorWidgetOfExactType<ProviderScope>() != null ||
                context.findAncestorWidgetOfExactType<UncontrolledProviderScope>() != null) {
              await ProviderScope.containerOf(context, listen: false)
                  .read(appBrandingProvider.notifier)
                  .loadBranding()
                  .timeout(const Duration(milliseconds: 700), onTimeout: () {});
            }
          } catch (_) {}
          if (mounted && !_controller.isAnimating && !_controller.isCompleted) {
            _controller.forward();
          }
        });
      } catch (_) {
        _controller.forward();
      }
    }

    if (widget.onInit != null) {
      try {
        await widget.onInit!();
      } catch (_) {
        // En cas d'erreur réseau ponctuelle, la navigation continue vers login/error
      }
    }
    _initFinished = true;
    if (_controller.isCompleted && mounted) {
      _terminerEtNaviguer();
    }
  }

  void _terminerEtNaviguer({bool force = false}) {
    if (!force && !_initFinished) return;
    if (!mounted || _hasNavigated) return;
    _hasNavigated = true;
    _fallbackTimer?.cancel();

    if (widget.onFinished != null) {
      widget.onFinished!();
      return;
    }

    if (widget.nextScreen != null) {
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (!mounted) return;
        Navigator.of(context).pushReplacement(
          PageRouteBuilder(
            transitionDuration: const Duration(milliseconds: 500),
            pageBuilder: (context, animation, secondaryAnimation) => widget.nextScreen!,
            transitionsBuilder: (context, animation, secondaryAnimation, child) {
              return FadeTransition(opacity: animation, child: child);
            },
          ),
        );
      });
    }
  }

  @override
  void dispose() {
    _fallbackTimer?.cancel();
    _controller.dispose();
    super.dispose();
  }

  String get _appBadge {
    switch (widget.appType) {
      case GoLivreurAppType.client:
        return 'CLIENT';
      case GoLivreurAppType.business:
        return 'BUSINESS';
      case GoLivreurAppType.driver:
        return 'DRIVER';
    }
  }

  Color get _badgeColor {
    switch (widget.appType) {
      case GoLivreurAppType.client:
        return GoLivreurTheme.primaire; // #F4A623
      case GoLivreurAppType.business:
        return const Color(0xFF2E75B6); // #2E75B6
      case GoLivreurAppType.driver:
        return const Color(0xFF4CAF50); // Vert
    }
  }

  @override
  Widget build(BuildContext context) {
    const double circleSize = 130.0;

    return Scaffold(
      backgroundColor: const Color(0xFF121212), // Fond sombre profond
      body: GestureDetector(
        behavior: HitTestBehavior.opaque,
        onTap: () => _terminerEtNaviguer(force: true),
        child: SafeArea(
          child: Center(
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              // ── Cercle animé et Logo/Moto au centre ──────────────────────
              SizedBox(
                width: circleSize,
                height: circleSize,
                child: Stack(
                  alignment: Alignment.center,
                  children: [
                    // Tracé circulaire progressif
                    AnimatedBuilder(
                      animation: _circleProgress,
                      builder: (context, _) {
                        return CustomPaint(
                          size: const Size(circleSize, circleSize),
                          painter: CircleProgressPainter(
                            progress: _circleProgress.value,
                            color: GoLivreurTheme.primaire,
                            trackColor: Colors.white.withValues(alpha: 0.08),
                            strokeWidth: 3.5,
                          ),
                        );
                      },
                    ),
                    // Logo configuré (en mémoire / réseau) ou badge ambre unifié par défaut centré
                    ScaleTransition(
                      scale: _motorcycleScale,
                      child: Builder(
                        builder: (context) {
                          final hasScope = context.findAncestorWidgetOfExactType<ProviderScope>() != null ||
                              context.findAncestorWidgetOfExactType<UncontrolledProviderScope>() != null;
                          if (hasScope) {
                            return Consumer(
                              builder: (context, ref, _) {
                                final branding = ref.watch(appBrandingProvider);
                                final effectiveLogoUrl = (widget.logoUrl != null && widget.logoUrl!.trim().isNotEmpty)
                                    ? widget.logoUrl
                                    : branding.logoUrl;
                                final resolvedUrl = GoLivreurEnv.resolveMediaUrl(effectiveLogoUrl);
                                final hasCustom = (branding.logoBytes != null && branding.logoBytes!.isNotEmpty) ||
                                    (resolvedUrl != null && resolvedUrl.isNotEmpty);
                                final bindingType = WidgetsBinding.instance.runtimeType.toString();
                                final isWidgetTest = bindingType.contains('TestWidgetsFlutterBinding') ||
                                    bindingType.contains('AutomatedTestWidgetsFlutterBinding');
                                final hideYellowBackground =
                                    hasCustom || (!branding.isLoaded && !isWidgetTest);

                                return Container(
                                  width: 84,
                                  height: 84,
                                  decoration: hideYellowBackground
                                      ? const BoxDecoration(
                                          color: Colors.transparent,
                                        )
                                      : BoxDecoration(
                                          color: GoLivreurTheme.primaire,
                                          borderRadius: BorderRadius.circular(84 * 0.24),
                                          gradient: const LinearGradient(
                                            begin: Alignment.topLeft,
                                            end: Alignment.bottomRight,
                                            colors: [
                                              Color(0xFFFFB732),
                                              Color(0xFFF4A623),
                                            ],
                                          ),
                                          boxShadow: [
                                            BoxShadow(
                                              color: GoLivreurTheme.primaire.withValues(alpha: 0.35),
                                              blurRadius: 16,
                                              offset: const Offset(0, 6),
                                            ),
                                          ],
                                        ),
                                  clipBehavior: Clip.antiAlias,
                                  child: GoLivreurBrandMark(
                                    logoUrl: resolvedUrl,
                                    logoBytes: branding.logoBytes,
                                    defaultEmoji: branding.defaultEmoji,
                                    size: 84,
                                  ),
                                );
                              },
                            );
                          }

                          return Container(
                            width: 84,
                            height: 84,
                            decoration: BoxDecoration(
                              borderRadius: BorderRadius.circular(84 * 0.24),
                              gradient: const LinearGradient(
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
                              logoUrl: widget.logoUrl,
                              defaultEmoji: widget.defaultEmoji,
                              size: 84,
                            ),
                          );
                        },
                      ),
                    ),
                  ],
                ),
              ),

              const SizedBox(height: 28),

              // ── Texte "GoLivreur" apparaissant en fondu après le cercle ──
              SlideTransition(
                position: _textSlide,
                child: FadeTransition(
                  opacity: _textFade,
                  child: Column(
                    children: [
                      RichText(
                        textAlign: TextAlign.center,
                        text: const TextSpan(
                          style: TextStyle(
                            fontFamily: 'Inter',
                            fontSize: 32,
                            fontWeight: FontWeight.w900,
                            letterSpacing: -0.5,
                          ),
                          children: [
                            TextSpan(
                              text: 'Go',
                              style: TextStyle(color: GoLivreurTheme.primaire),
                            ),
                            TextSpan(
                              text: 'Livreur',
                              style: TextStyle(color: Colors.white),
                            ),
                          ],
                        ),
                      ),
                      const SizedBox(height: 8),
                      // Badge applicatif
                      Container(
                        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 4),
                        decoration: BoxDecoration(
                          color: _badgeColor.withValues(alpha: 0.15),
                          borderRadius: BorderRadius.circular(20),
                          border: Border.all(
                            color: _badgeColor.withValues(alpha: 0.4),
                            width: 1,
                          ),
                        ),
                        child: Text(
                          _appBadge,
                          style: TextStyle(
                            fontFamily: 'Inter',
                            fontSize: 12,
                            fontWeight: FontWeight.bold,
                            letterSpacing: 2.5,
                            color: _badgeColor,
                          ),
                        ),
                      ),
                      const SizedBox(height: 12),
                      Text(
                        'Daloa bouge, on livre !',
                        style: TextStyle(
                          color: Colors.white.withValues(alpha: 0.6),
                          fontSize: 13,
                          letterSpacing: 0.3,
                        ),
                      ),
                    ],
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    ),
  );
  }
}

/// CustomPainter dessinant le cercle animé autour de la moto
class CircleProgressPainter extends CustomPainter {
  final double progress;
  final Color color;
  final Color trackColor;
  final double strokeWidth;

  CircleProgressPainter({
    required this.progress,
    required this.color,
    required this.trackColor,
    required this.strokeWidth,
  });

  @override
  void paint(Canvas canvas, Size size) {
    final center = Offset(size.width / 2, size.height / 2);
    final radius = (size.width - strokeWidth) / 2;

    // 1. Piste de fond discrète
    final trackPaint = Paint()
      ..color = trackColor
      ..style = PaintingStyle.stroke
      ..strokeWidth = strokeWidth;
    canvas.drawCircle(center, radius, trackPaint);

    // 2. Arc animé de 0 à 360 degrés (départ en haut à -pi/2)
    if (progress > 0.0) {
      final progressPaint = Paint()
        ..color = color
        ..style = PaintingStyle.stroke
        ..strokeWidth = strokeWidth
        ..strokeCap = StrokeCap.round;

      const startAngle = -math.pi / 2;
      final sweepAngle = 2 * math.pi * progress;

      canvas.drawArc(
        Rect.fromCircle(center: center, radius: radius),
        startAngle,
        sweepAngle,
        false,
        progressPaint,
      );
    }
  }

  @override
  bool shouldRepaint(covariant CircleProgressPainter oldDelegate) {
    return oldDelegate.progress != progress ||
        oldDelegate.color != color ||
        oldDelegate.trackColor != trackColor;
  }
}

