import 'dart:async';

import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:golivreur_core/config/app_theme.dart';
import 'package:golivreur_core/config/env_config.dart';
import 'package:golivreur_core/services/sso_switch_service.dart';

import '../providers/goshop_provider.dart';
import '../screens/merchant_detail_screen.dart';
import '../../gocolis/screens/gocolis_home_screen.dart';

/// Carrousel dynamique des bannières promotionnelles actives (App Client).
///
/// L'API ne retourne que les bannières dont la date courante est comprise entre
/// `starts_at` et `ends_at` : toute promotion expirée disparaît automatiquement.
class PromoBannersCarousel extends ConsumerStatefulWidget {
  const PromoBannersCarousel({super.key});

  @override
  ConsumerState<PromoBannersCarousel> createState() =>
      _PromoBannersCarouselState();
}

class _PromoBannersCarouselState extends ConsumerState<PromoBannersCarousel> {
  final PageController _pageController = PageController(viewportFraction: 0.94);
  int _currentPage = 0;
  Timer? _autoPlayTimer;

  @override
  void dispose() {
    _autoPlayTimer?.cancel();
    _pageController.dispose();
    super.dispose();
  }

  void _syncAutoPlay(int count) {
    _autoPlayTimer?.cancel();
    if (count <= 1) return;
    _autoPlayTimer = Timer.periodic(const Duration(seconds: 5), (_) {
      if (!mounted || !_pageController.hasClients) return;
      final next = (_currentPage + 1) % count;
      _pageController.animateToPage(
        next,
        duration: const Duration(milliseconds: 420),
        curve: Curves.easeOutCubic,
      );
    });
  }

  Future<void> _handleDeepLink(BuildContext context, PromoBannerModel banner) async {
    final link = banner.deepLink?.trim() ?? '';
    if (link.isEmpty) return;

    if (link.startsWith('category:')) {
      final slug = link.substring('category:'.length).trim();
      if (slug.isNotEmpty) {
        ref.read(selectedCategoryProvider.notifier).state = slug;
      }
      return;
    }

    if (link.startsWith('merchant:')) {
      final id = int.tryParse(link.substring('merchant:'.length).trim());
      if (id != null) {
        final merchants = ref.read(merchantsListProvider).valueOrNull ?? const [];
        final found = merchants.where((m) => m.id == id).firstOrNull;
        if (found != null && context.mounted) {
          Navigator.of(context).push(
            MaterialPageRoute(
              builder: (_) => MerchantDetailScreen(merchant: found),
            ),
          );
          return;
        }
      }
      return;
    }

    if (link == 'gocolis' || link == 'golivreur://gocolis') {
      if (context.mounted) {
        Navigator.of(context).push(
          MaterialPageRoute(builder: (_) => const GoColisHomeScreen()),
        );
      }
      return;
    }

    if (link.startsWith('http://') || link.startsWith('https://')) {
      await SsoSwitchService.openExternalUrl(link);
    }
  }

  @override
  Widget build(BuildContext context) {
    final bannersAsync = ref.watch(promoBannersProvider);

    return bannersAsync.when(
      loading: () => const SizedBox.shrink(),
      error: (_, __) => const SizedBox.shrink(),
      data: (banners) {
        if (banners.isEmpty) {
          _autoPlayTimer?.cancel();
          return const SizedBox.shrink();
        }

        WidgetsBinding.instance.addPostFrameCallback((_) {
          if (mounted) _syncAutoPlay(banners.length);
        });

        return Padding(
          padding: const EdgeInsets.only(top: 8, bottom: 4),
          child: Column(
            children: [
              SizedBox(
                height: 146,
                child: PageView.builder(
                  controller: _pageController,
                  itemCount: banners.length,
                  onPageChanged: (index) {
                    if (mounted) setState(() => _currentPage = index);
                  },
                  itemBuilder: (context, index) {
                    final banner = banners[index];
                    final resolvedImg =
                        GoLivreurEnv.resolveMediaUrl(banner.imageUrl);
                    return Padding(
                      padding: const EdgeInsets.symmetric(horizontal: 5),
                      child: GestureDetector(
                        onTap: () => _handleDeepLink(context, banner),
                        child: ClipRRect(
                          borderRadius: BorderRadius.circular(18),
                          child: Stack(
                            fit: StackFit.expand,
                            children: [
                              if (resolvedImg != null && resolvedImg.isNotEmpty)
                                CachedNetworkImage(
                                  imageUrl: resolvedImg,
                                  fit: BoxFit.cover,
                                  errorWidget: (_, __, ___) =>
                                      _gradientFallback(),
                                )
                              else
                                _gradientFallback(),
                              Container(
                                decoration: BoxDecoration(
                                  gradient: LinearGradient(
                                    begin: Alignment.topCenter,
                                    end: Alignment.bottomCenter,
                                    colors: [
                                      Colors.black.withValues(alpha: 0.15),
                                      Colors.black.withValues(alpha: 0.72),
                                    ],
                                  ),
                                ),
                              ),
                              Padding(
                                padding: const EdgeInsets.all(16),
                                child: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  mainAxisAlignment: MainAxisAlignment.end,
                                  children: [
                                    Text(
                                      banner.title,
                                      maxLines: 2,
                                      overflow: TextOverflow.ellipsis,
                                      style: const TextStyle(
                                        color: Colors.white,
                                        fontSize: 17,
                                        fontWeight: FontWeight.w800,
                                        height: 1.15,
                                      ),
                                    ),
                                    if (banner.subtitle.isNotEmpty) ...[
                                      const SizedBox(height: 4),
                                      Text(
                                        banner.subtitle,
                                        maxLines: 2,
                                        overflow: TextOverflow.ellipsis,
                                        style: TextStyle(
                                          color: Colors.white.withValues(alpha: 0.92),
                                          fontSize: 12.5,
                                        ),
                                      ),
                                    ],
                                  ],
                                ),
                              ),
                              if (banner.deepLink != null &&
                                  banner.deepLink!.isNotEmpty)
                                Positioned(
                                  top: 12,
                                  right: 12,
                                  child: Container(
                                    padding: const EdgeInsets.symmetric(
                                      horizontal: 10,
                                      vertical: 4,
                                    ),
                                    decoration: BoxDecoration(
                                      color: GoLivreurTheme.primaire,
                                      borderRadius: BorderRadius.circular(20),
                                    ),
                                    child: const Row(
                                      mainAxisSize: MainAxisSize.min,
                                      children: [
                                        Text(
                                          'Découvrir',
                                          style: TextStyle(
                                            color: Colors.white,
                                            fontSize: 11,
                                            fontWeight: FontWeight.w700,
                                          ),
                                        ),
                                        SizedBox(width: 2),
                                        Icon(
                                          Icons.arrow_forward_ios_rounded,
                                          size: 10,
                                          color: Colors.white,
                                        ),
                                      ],
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
              ),
              if (banners.length > 1) ...[
                const SizedBox(height: 8),
                Row(
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: List.generate(banners.length, (i) {
                    final active = i == _currentPage;
                    return AnimatedContainer(
                      duration: const Duration(milliseconds: 220),
                      margin: const EdgeInsets.symmetric(horizontal: 3),
                      width: active ? 18 : 6,
                      height: 6,
                      decoration: BoxDecoration(
                        color: active
                            ? GoLivreurTheme.primaire
                            : GoLivreurTheme.bordure,
                        borderRadius: BorderRadius.circular(4),
                      ),
                    );
                  }),
                ),
              ],
            ],
          ),
        );
      },
    );
  }

  Widget _gradientFallback() {
    return Container(
      decoration: const BoxDecoration(
        gradient: LinearGradient(
          colors: [Color(0xFF1A1A2E), Color(0xFFF4A623)],
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
        ),
      ),
    );
  }
}
