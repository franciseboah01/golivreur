import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import '../config/app_theme.dart';
import '../services/api_client.dart';

/// Validateurs communs et dialogue de changement de mot de passe partagés entre les 3 apps.
class ProfileFormHelpers {
  ProfileFormHelpers._();

  static String? validateRequiredName(String? value) {
    final trimmed = value?.trim() ?? '';
    if (trimmed.isEmpty) return 'Ce champ est obligatoire.';
    if (trimmed.length < 2) return 'Minimum 2 caractères requis.';
    return null;
  }

  static String? validateIvorianPhone(String? value) {
    var cleaned = (value ?? '').replaceAll(RegExp(r'[\s\.\-\(\)]+'), '');
    if (cleaned.startsWith('+225')) {
      cleaned = cleaned.substring(4);
    } else if (cleaned.startsWith('00225')) {
      cleaned = cleaned.substring(5);
    } else if (cleaned.length == 13 && cleaned.startsWith('225')) {
      cleaned = cleaned.substring(3);
    }
    if (!RegExp(r'^(01|05|07|21|25|27)[0-9]{8}$').hasMatch(cleaned)) {
      return 'Numéro ivoirien invalide (ex: 0701000001).';
    }
    return null;
  }

  static String? validateOptionalEmail(String? value) {
    final trimmed = value?.trim() ?? '';
    if (trimmed.isEmpty) return null;
    if (!RegExp(r'^[^@\s]+@[^@\s]+\.[^@\s]+$').hasMatch(trimmed)) {
      return 'Adresse email invalide.';
    }
    return null;
  }

  /// Dialogue modal permettant de modifier le mot de passe via PUT /profile/password.
  static Future<void> showChangePasswordDialog(
    BuildContext context, {
    required ApiClient apiClient,
    Color accentColor = GoLivreurTheme.primaire,
  }) async {
    final formKey = GlobalKey<FormState>();
    final currentCtrl = TextEditingController();
    final newCtrl = TextEditingController();
    final confirmCtrl = TextEditingController();
    bool isSaving = false;
    String? errorMsg;

    await showDialog(
      context: context,
      builder: (dialogCtx) => StatefulBuilder(
        builder: (ctx, setModalState) => AlertDialog(
          title: const Text('Changer le mot de passe'),
          content: Form(
            key: formKey,
            child: SingleChildScrollView(
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  if (errorMsg != null) ...[
                    Container(
                      padding: const EdgeInsets.all(10),
                      decoration: BoxDecoration(
                        color: GoLivreurTheme.erreur.withValues(alpha: 0.1),
                        borderRadius: BorderRadius.circular(8),
                      ),
                      child: Text(
                        errorMsg!,
                        style: const TextStyle(color: GoLivreurTheme.erreur, fontSize: 12),
                      ),
                    ),
                    const SizedBox(height: 12),
                  ],
                  TextFormField(
                    controller: currentCtrl,
                    obscureText: true,
                    decoration: const InputDecoration(
                      labelText: 'Mot de passe actuel',
                      border: OutlineInputBorder(),
                    ),
                    validator: (v) => (v == null || v.isEmpty) ? 'Obligatoire' : null,
                  ),
                  const SizedBox(height: 12),
                  TextFormField(
                    controller: newCtrl,
                    obscureText: true,
                    decoration: const InputDecoration(
                      labelText: 'Nouveau mot de passe',
                      border: OutlineInputBorder(),
                    ),
                    validator: (v) => (v == null || v.length < 6) ? 'Minimum 6 caractères' : null,
                  ),
                  const SizedBox(height: 12),
                  TextFormField(
                    controller: confirmCtrl,
                    obscureText: true,
                    decoration: const InputDecoration(
                      labelText: 'Confirmer le nouveau mot de passe',
                      border: OutlineInputBorder(),
                    ),
                    validator: (v) => v != newCtrl.text ? 'Les mots de passe ne correspondent pas' : null,
                  ),
                ],
              ),
            ),
          ),
          actions: [
            TextButton(
              onPressed: isSaving ? null : () => Navigator.pop(dialogCtx),
              child: const Text('Annuler'),
            ),
            ElevatedButton(
              style: ElevatedButton.styleFrom(
                backgroundColor: accentColor,
                foregroundColor: Colors.white,
              ),
              onPressed: isSaving
                  ? null
                  : () async {
                      if (!formKey.currentState!.validate()) return;
                      setModalState(() {
                        isSaving = true;
                        errorMsg = null;
                      });
                      try {
                        final res = await apiClient.put('/profile/password', data: {
                          'current_password': currentCtrl.text,
                          'password': newCtrl.text,
                          'password_confirmation': confirmCtrl.text,
                        });
                        if (res.statusCode == 200 && res.data['succes'] == true) {
                          if (dialogCtx.mounted) Navigator.pop(dialogCtx);
                          if (context.mounted) {
                            ScaffoldMessenger.of(context).showSnackBar(
                              const SnackBar(
                                content: Text('Mot de passe modifié avec succès !'),
                                backgroundColor: GoLivreurTheme.succes,
                              ),
                            );
                          }
                        }
                      } on DioException catch (e) {
                        setModalState(() {
                          isSaving = false;
                          errorMsg = ApiClient.messageErreur(e);
                        });
                      } catch (_) {
                        setModalState(() {
                          isSaving = false;
                          errorMsg = 'Erreur lors de la modification.';
                        });
                      }
                    },
              child: isSaving
                  ? const SizedBox(
                      width: 16,
                      height: 16,
                      child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
                    )
                  : const Text('Enregistrer'),
            ),
          ],
        ),
      ),
    );
  }
}
