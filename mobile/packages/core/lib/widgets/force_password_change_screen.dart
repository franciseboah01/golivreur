import 'package:dio/dio.dart';
import 'package:flutter/material.dart';

import '../config/app_theme.dart';
import '../services/api_client.dart';

/// Écran obligatoire de définition d'un nouveau mot de passe après une
/// réinitialisation temporaire déclenchée par le Service Client.
class ForcePasswordChangeScreen extends StatefulWidget {
  final ApiClient apiClient;
  final String? initialTemporaryPassword;
  final VoidCallback onPasswordChanged;

  const ForcePasswordChangeScreen({
    super.key,
    required this.apiClient,
    this.initialTemporaryPassword,
    required this.onPasswordChanged,
  });

  @override
  State<ForcePasswordChangeScreen> createState() =>
      _ForcePasswordChangeScreenState();
}

class _ForcePasswordChangeScreenState extends State<ForcePasswordChangeScreen> {
  final _formKey = GlobalKey<FormState>();
  late final TextEditingController _currentCtrl;
  final _newCtrl = TextEditingController();
  final _confirmCtrl = TextEditingController();
  bool _obscure = true;
  bool _submitting = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _currentCtrl = TextEditingController(
      text: widget.initialTemporaryPassword ?? '',
    );
  }

  @override
  void dispose() {
    _currentCtrl.dispose();
    _newCtrl.dispose();
    _confirmCtrl.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!(_formKey.currentState?.validate() ?? false)) return;

    setState(() {
      _submitting = true;
      _error = null;
    });

    try {
      await widget.apiClient.put(
        '/profile/password',
        data: {
          'current_password': _currentCtrl.text,
          'new_password': _newCtrl.text,
          'new_password_confirmation': _confirmCtrl.text,
        },
      );
      if (mounted) {
        widget.onPasswordChanged();
      }
    } on DioException catch (e) {
      if (mounted) {
        setState(() {
          _submitting = false;
          _error = ApiClient.messageErreur(e);
        });
      }
    } catch (_) {
      if (mounted) {
        setState(() {
          _submitting = false;
          _error = 'Impossible de mettre à jour votre mot de passe.';
        });
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return PopScope(
      canPop: false,
      child: Scaffold(
        appBar: AppBar(
          automaticallyImplyLeading: false,
          title: const Text('Nouveau mot de passe requis'),
        ),
        body: SingleChildScrollView(
          padding: const EdgeInsets.all(24),
          child: Form(
            key: _formKey,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Container(
                  padding: const EdgeInsets.all(14),
                  decoration: BoxDecoration(
                    color: GoLivreurTheme.primaire.withValues(alpha: 0.12),
                    borderRadius: BorderRadius.circular(12),
                    border: Border.all(
                      color: GoLivreurTheme.primaire.withValues(alpha: 0.35),
                    ),
                  ),
                  child: const Row(
                    children: [
                      Icon(Icons.security_rounded,
                          color: GoLivreurTheme.primaire),
                      SizedBox(width: 12),
                      Expanded(
                        child: Text(
                          'Votre mot de passe a été réinitialisé par le service client. Définissez un nouveau mot de passe personnel pour continuer.',
                          style: TextStyle(fontSize: 13),
                        ),
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: 20),
                TextFormField(
                  controller: _currentCtrl,
                  obscureText: _obscure,
                  decoration: InputDecoration(
                    labelText: 'Mot de passe temporaire reçu',
                    border: const OutlineInputBorder(),
                    suffixIcon: IconButton(
                      icon: Icon(
                        _obscure ? Icons.visibility_off : Icons.visibility,
                      ),
                      onPressed: () => setState(() => _obscure = !_obscure),
                    ),
                  ),
                  validator: (v) =>
                      (v == null || v.isEmpty) ? 'Champ requis' : null,
                ),
                const SizedBox(height: 14),
                TextFormField(
                  controller: _newCtrl,
                  obscureText: _obscure,
                  decoration: const InputDecoration(
                    labelText: 'Nouveau mot de passe (min. 8 caractères)',
                    border: OutlineInputBorder(),
                  ),
                  validator: (v) {
                    if (v == null || v.length < 8) {
                      return 'Minimum 8 caractères requis.';
                    }
                    if (v == _currentCtrl.text) {
                      return 'Choisissez un mot de passe différent du temporaire.';
                    }
                    return null;
                  },
                ),
                const SizedBox(height: 14),
                TextFormField(
                  controller: _confirmCtrl,
                  obscureText: _obscure,
                  decoration: const InputDecoration(
                    labelText: 'Confirmer le nouveau mot de passe',
                    border: OutlineInputBorder(),
                  ),
                  validator: (v) => v != _newCtrl.text
                      ? 'Les mots de passe ne correspondent pas.'
                      : null,
                ),
                if (_error != null) ...[
                  const SizedBox(height: 12),
                  Text(
                    _error!,
                    style: const TextStyle(
                      color: GoLivreurTheme.erreur,
                      fontWeight: FontWeight.w500,
                    ),
                  ),
                ],
                const SizedBox(height: 22),
                FilledButton(
                  onPressed: _submitting ? null : _submit,
                  style: FilledButton.styleFrom(
                    backgroundColor: GoLivreurTheme.primaire,
                    padding: const EdgeInsets.symmetric(vertical: 15),
                  ),
                  child: _submitting
                      ? const SizedBox(
                          width: 20,
                          height: 20,
                          child: CircularProgressIndicator(
                            strokeWidth: 2,
                            color: Colors.white,
                          ),
                        )
                      : const Text('Enregistrer mon nouveau mot de passe'),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
