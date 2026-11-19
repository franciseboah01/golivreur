import 'dart:convert';
import 'dart:math';

import 'package:crypto/crypto.dart';
import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:url_launcher/url_launcher.dart';

import '../config/app_theme.dart';
import 'pin_lock_service.dart';
import 'api_client.dart';
import 'secure_token_storage.dart';

/// Applications de l'écosystème GoLivreur supportant la bascule SSO PKCE.
enum GoLivreurAppTarget {
  client(
    appType: 'client',
    requiredRole: 'client',
    displayName: 'GL Client',
    subtitle: 'Commander & envoyer des colis',
    scheme: 'golivreur-client',
    icon: Icons.shopping_bag_outlined,
    accentColor: Color(0xFFF4A623),
  ),
  business(
    appType: 'business',
    requiredRole: 'merchant',
    displayName: 'GL Business',
    subtitle: 'Gérer votre commerce & commandes',
    scheme: 'golivreur-business',
    icon: Icons.storefront_outlined,
    accentColor: Color(0xFF1B5E20),
  ),
  driver(
    appType: 'driver',
    requiredRole: 'driver',
    displayName: 'GL Driver',
    subtitle: 'Accepter & livrer des courses',
    scheme: 'golivreur-driver',
    icon: Icons.delivery_dining_outlined,
    accentColor: Color(0xFF1565C0),
  );

  final String appType;
  final String requiredRole;
  final String displayName;
  final String subtitle;
  final String scheme;
  final IconData icon;
  final Color accentColor;

  const GoLivreurAppTarget({
    required this.appType,
    required this.requiredRole,
    required this.displayName,
    required this.subtitle,
    required this.scheme,
    required this.icon,
    required this.accentColor,
  });

  static GoLivreurAppTarget? fromRole(String role) {
    final norm = role.toLowerCase();
    if (norm == 'client') return GoLivreurAppTarget.client;
    if (norm == 'merchant' || norm == 'business') return GoLivreurAppTarget.business;
    if (norm == 'driver' || norm == 'courier') return GoLivreurAppTarget.driver;
    return null;
  }
}

enum SsoLaunchOutcome {
  launched,
  appNotInstalled,
  roleMissing,
  failed,
}

class SsoLaunchResult {
  final SsoLaunchOutcome outcome;
  final String message;

  const SsoLaunchResult({required this.outcome, required this.message});
}

class SsoExchangeResult {
  final Map<String, dynamic> userJson;
  final bool mustChangePassword;

  const SsoExchangeResult({
    required this.userJson,
    required this.mustChangePassword,
  });
}

/// Service SSO inter-apps sécurisé par OAuth2 Authorization Code + PKCE (S256).
class SsoSwitchService {
  /// Génère un `code_verifier` PKCE haute entropie (RFC 7636, 43-128 chars URL-safe).
  static String generateCodeVerifier() {
    final rng = Random.secure();
    final bytes = List<int>.generate(32, (_) => rng.nextInt(256));
    return base64UrlEncode(bytes).replaceAll('=', '');
  }

  /// Calcule le `code_challenge` PKCE S256 = BASE64URL(SHA256(verifier)) sans padding.
  static String computeCodeChallenge(String codeVerifier) {
    final digest = sha256.convert(utf8.encode(codeVerifier));
    return base64UrlEncode(digest.bytes).replaceAll('=', '');
  }

  /// Initie la bascule SSO vers [target] sans ressaisie du mot de passe.
  static Future<SsoLaunchResult> switchToApp({
    required ApiClient apiClient,
    required GoLivreurAppTarget target,
  }) async {
    try {
      final verifier = generateCodeVerifier();
      final challenge = computeCodeChallenge(verifier);

      final response = await apiClient.post<Map<String, dynamic>>(
        '/auth/sso/authorize',
        data: {
          'target_app': target.appType,
          'code_challenge': challenge,
          'code_challenge_method': 'S256',
        },
      );

      final donnees = response.data?['donnees'] as Map<String, dynamic>?;
      final code = donnees?['code'] as String?;
      if (code == null || code.isEmpty) {
        return const SsoLaunchResult(
          outcome: SsoLaunchOutcome.failed,
          message: 'Impossible de générer le code de bascule sécurisé.',
        );
      }

      final uri = Uri(
        scheme: target.scheme,
        host: 'sso',
        queryParameters: {
          'code': code,
          'verifier': verifier,
        },
      );

      final launched = await launchUrl(
        uri,
        mode: LaunchMode.externalApplication,
      );

      if (!launched) {
        return SsoLaunchResult(
          outcome: SsoLaunchOutcome.appNotInstalled,
          message:
              'L\'application ${target.displayName} n\'est pas installée sur ce téléphone.',
        );
      }

      return SsoLaunchResult(
        outcome: SsoLaunchOutcome.launched,
        message: 'Ouverture de ${target.displayName}...',
      );
    } on DioException catch (e) {
      final status = e.response?.statusCode;
      final data = e.response?.data;
      final message = data is Map ? data['message']?.toString() : null;
      if (status == 403) {
        return SsoLaunchResult(
          outcome: SsoLaunchOutcome.roleMissing,
          message: message ??
              'Votre compte ne possède pas encore le rôle requis pour ${target.displayName}.',
        );
      }
      return SsoLaunchResult(
        outcome: SsoLaunchOutcome.failed,
        message: ApiClient.messageErreur(e),
      );
    } catch (_) {
      return SsoLaunchResult(
        outcome: SsoLaunchOutcome.appNotInstalled,
        message:
            'Impossible d\'ouvrir ${target.displayName}. Vérifiez qu\'elle est installée.',
      );
    }
  }

  /// Ouvre simplement une autre application GoLivreur (sans transfert de session).
  static Future<bool> openAppPlain(GoLivreurAppTarget target) async {
    final uri = Uri(scheme: target.scheme, host: 'open');
    try {
      return await launchUrl(uri, mode: LaunchMode.externalApplication);
    } catch (_) {
      return false;
    }
  }

  /// Ouvre un lien externe sécurisé (https:// ou http://) depuis une bannière promotionnelle.
  static Future<bool> openExternalUrl(String rawUrl) async {
    final uri = Uri.tryParse(rawUrl.trim());
    if (uri == null || (uri.scheme != 'https' && uri.scheme != 'http')) {
      return false;
    }
    try {
      return await launchUrl(uri, mode: LaunchMode.externalApplication);
    } catch (_) {
      return false;
    }
  }

  /// Échange un Deep Link SSO entrant (`golivreur-*://sso?code=...&verifier=...`) contre des tokens JWT.
  static Future<SsoExchangeResult?> exchangeIncomingDeepLink({
    required ApiClient apiClient,
    required FlutterSecureStorage storage,
    required Uri uri,
    required GoLivreurAppTarget currentApp,
  }) async {
    if (uri.scheme != currentApp.scheme || uri.host != 'sso') {
      return null;
    }
    final code = uri.queryParameters['code'];
    final verifier = uri.queryParameters['verifier'];
    if (code == null || code.isEmpty || verifier == null || verifier.isEmpty) {
      return null;
    }

    final response = await apiClient.post<Map<String, dynamic>>(
      '/auth/sso/exchange',
      data: {
        'code': code,
        'code_verifier': verifier,
        'target_app': currentApp.appType,
      },
    );

    final donnees = response.data?['donnees'] as Map<String, dynamic>?;
    if (donnees == null) return null;

    final accessToken = donnees['access_token'] as String?;
    final refreshToken = donnees['refresh_token'] as String?;
    final userJson = donnees['user'] as Map<String, dynamic>?;

    if (accessToken == null || userJson == null) return null;

    await storage.write(
      key: SecureTokenStorage.accessTokenKey,
      value: accessToken,
    );
    if (refreshToken != null && refreshToken.isNotEmpty) {
      await PinLockService.syncRotatedRefreshToken(storage, refreshToken);
    }

    return SsoExchangeResult(
      userJson: userJson,
      mustChangePassword: userJson['must_change_password'] == true,
    );
  }
}

/// Passerelle légère Android/iOS pour recevoir les Deep Links SSO sans plugin externe.
class DeepLinkBridge {
  static const MethodChannel _channel = MethodChannel('ci.golivreur/deep_links');

  static Future<Uri?> getInitialUri() async {
    try {
      final raw = await _channel.invokeMethod<String>('getInitialLink');
      if (raw == null || raw.isEmpty) return null;
      return Uri.tryParse(raw);
    } catch (_) {
      return null;
    }
  }

  static void listen(void Function(Uri uri) onUri) {
    _channel.setMethodCallHandler((call) async {
      if (call.method == 'onDeepLink') {
        final raw = call.arguments as String?;
        if (raw != null && raw.isNotEmpty) {
          final parsed = Uri.tryParse(raw);
          if (parsed != null) {
            onUri(parsed);
          }
        }
      }
    });
  }
}

/// Carte réutilisable dans l'écran Profil pour basculer en 1 clic vers une autre app GoLivreur (SSO PKCE).
class InterAppSwitcherSection extends StatefulWidget {
  final GoLivreurAppTarget currentApp;
  final List<String> userRoles;
  final ApiClient apiClient;

  const InterAppSwitcherSection({
    super.key,
    required this.currentApp,
    required this.userRoles,
    required this.apiClient,
  });

  @override
  State<InterAppSwitcherSection> createState() => _InterAppSwitcherSectionState();
}

class _InterAppSwitcherSectionState extends State<InterAppSwitcherSection> {
  GoLivreurAppTarget? _launching;

  bool _hasRoleFor(GoLivreurAppTarget target) {
    final normalized = widget.userRoles.map((r) => r.toLowerCase()).toSet();
    if (target == GoLivreurAppTarget.driver) {
      return normalized.contains('driver') || normalized.contains('courier');
    }
    return normalized.contains(target.requiredRole);
  }

  Future<void> _onTapTarget(GoLivreurAppTarget target) async {
    if (_launching != null) return;
    setState(() => _launching = target);

    final result = await SsoSwitchService.switchToApp(
      apiClient: widget.apiClient,
      target: target,
    );

    if (!mounted) return;
    setState(() => _launching = null);

    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(result.message),
        backgroundColor: result.outcome == SsoLaunchOutcome.launched
            ? GoLivreurTheme.secondaire
            : (result.outcome == SsoLaunchOutcome.roleMissing
                ? Colors.orange.shade800
                : GoLivreurTheme.erreur),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final otherApps = GoLivreurAppTarget.values
        .where((t) => t != widget.currentApp)
        .toList();

    return Material(
      color: Colors.white,
      clipBehavior: Clip.antiAlias,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(16),
        side: BorderSide(color: Colors.grey.shade200),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Padding(
            padding: EdgeInsets.fromLTRB(16, 14, 16, 6),
            child: Row(
              children: [
                Icon(Icons.swap_horiz_rounded,
                    size: 18, color: GoLivreurTheme.primaire),
                SizedBox(width: 8),
                Text(
                  'Basculer vers une autre app (SSO)',
                  style: TextStyle(
                    fontWeight: FontWeight.w700,
                    fontSize: 14,
                    color: GoLivreurTheme.textePrincipal,
                  ),
                ),
              ],
            ),
          ),
          ...otherApps.map((target) {
            final hasRole = _hasRoleFor(target);
            final isBusy = _launching == target;
            return ListTile(
              leading: CircleAvatar(
                backgroundColor: target.accentColor.withValues(alpha: 0.14),
                child: Icon(target.icon, color: target.accentColor, size: 20),
              ),
              title: Text(
                target.displayName,
                style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 14),
              ),
              subtitle: Text(
                hasRole
                    ? 'Connexion directe sans mot de passe'
                    : '${target.subtitle} (rôle non actif)',
                style: TextStyle(
                  fontSize: 12,
                  color: hasRole
                      ? GoLivreurTheme.secondaire
                      : GoLivreurTheme.texteSecondaire,
                ),
              ),
              trailing: isBusy
                  ? const SizedBox(
                      width: 20,
                      height: 20,
                      child: CircularProgressIndicator(strokeWidth: 2),
                    )
                  : Icon(
                      Icons.open_in_new_rounded,
                      size: 18,
                      color: hasRole ? target.accentColor : Colors.grey.shade400,
                    ),
              onTap: () => _onTapTarget(target),
            );
          }),
        ],
      ),
    );
  }
}
