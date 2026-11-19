import 'package:dio/dio.dart';
import 'package:flutter/material.dart';

import '../config/app_theme.dart';
import '../services/sso_switch_service.dart';

/// Données structurées renvoyées par l'API lors d'un 403 `ROLE_MISSING`.
class RoleMissingInfo {
  final String message;
  final String requiredRole;
  final List<String> userRoles;
  final String? suggestedAction;

  const RoleMissingInfo({
    required this.message,
    required this.requiredRole,
    required this.userRoles,
    this.suggestedAction,
  });

  static RoleMissingInfo? tryParse(DioException e) {
    if (e.response?.statusCode != 403) return null;
    final data = e.response?.data;
    if (data is! Map) return null;
    if (data['code'] != 'ROLE_MISSING') return null;

    final rawRoles = data['user_roles'];
    final roles = rawRoles is List
        ? rawRoles.map((r) => r.toString()).toList()
        : <String>[];

    return RoleMissingInfo(
      message: data['message']?.toString() ??
          'Votre compte ne dispose pas du rôle requis pour cette application.',
      requiredRole: data['required_role']?.toString() ?? '',
      userRoles: roles,
      suggestedAction: data['suggested_action']?.toString(),
    );
  }
}

/// Affiche une boîte de dialogue claire lorsqu'un utilisateur se connecte sur une app
/// sans le rôle correspondant, avec bouton d'inscription et ouverture de son app existante.
Future<void> showRoleMissingDialog(
  BuildContext context, {
  required RoleMissingInfo info,
  required String registerButtonLabel,
  required VoidCallback onRegisterRole,
}) async {
  final matchingTargets = info.userRoles
      .map(GoLivreurAppTarget.fromRole)
      .whereType<GoLivreurAppTarget>()
      .toSet()
      .toList();

  await showDialog<void>(
    context: context,
    builder: (ctx) => AlertDialog(
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(18)),
      title: const Row(
        children: [
          Icon(Icons.verified_user_outlined, color: GoLivreurTheme.primaire),
          SizedBox(width: 10),
          Expanded(
            child: Text(
              'Accès réservé',
              style: TextStyle(fontSize: 18, fontWeight: FontWeight.w700),
            ),
          ),
        ],
      ),
      content: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(info.message, style: const TextStyle(fontSize: 14)),
          if (matchingTargets.isNotEmpty) ...[
            const SizedBox(height: 14),
            const Text(
              'Vos applications actives :',
              style: TextStyle(
                fontSize: 12,
                fontWeight: FontWeight.w600,
                color: GoLivreurTheme.texteSecondaire,
              ),
            ),
            const SizedBox(height: 8),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: matchingTargets.map((target) {
                return OutlinedButton.icon(
                  onPressed: () async {
                    final opened = await SsoSwitchService.openAppPlain(target);
                    if (!opened && ctx.mounted) {
                      ScaffoldMessenger.of(ctx).showSnackBar(
                        SnackBar(
                          content: Text(
                            '${target.displayName} n\'est pas installée sur ce téléphone.',
                          ),
                        ),
                      );
                    }
                  },
                  icon: Icon(target.icon, size: 16, color: target.accentColor),
                  label: Text('Ouvrir ${target.displayName}'),
                );
              }).toList(),
            ),
          ],
        ],
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.of(ctx).pop(),
          child: const Text('Fermer'),
        ),
        FilledButton(
          onPressed: () {
            Navigator.of(ctx).pop();
            onRegisterRole();
          },
          style: FilledButton.styleFrom(
            backgroundColor: GoLivreurTheme.primaire,
          ),
          child: Text(registerButtonLabel),
        ),
      ],
    ),
  );
}
