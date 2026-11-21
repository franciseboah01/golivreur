import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:golivreur_core/config/app_theme.dart';
import 'package:golivreur_core/services/secure_token_storage.dart';

import '../../auth/providers/auth_provider.dart';

class ClientSecurityScreen extends ConsumerStatefulWidget {
  const ClientSecurityScreen({super.key});

  @override
  ConsumerState<ClientSecurityScreen> createState() =>
      _ClientSecurityScreenState();
}

class _ClientSecurityScreenState extends ConsumerState<ClientSecurityScreen> {
  bool _loading = true;
  String? _error;
  bool _passwordSet = false;
  String _maskedPhone = '';
  List<Map<String, dynamic>> _activeCodes = const [];
  final Set<String> _revealedKeys = <String>{};

  @override
  void initState() {
    super.initState();
    _loadSecurity();
  }

  Future<void> _loadSecurity() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final api = ref.read(apiClientProvider);
      final res = await api.get('/me/security');
      final data = res.data is Map && res.data['donnees'] is Map
          ? Map<String, dynamic>.from(res.data['donnees'] as Map)
          : <String, dynamic>{};

      final rawCodes = data['active_delivery_codes'];
      final codes = rawCodes is List
          ? rawCodes
              .whereType<Map>()
              .map((e) => Map<String, dynamic>.from(e))
              .toList()
          : <Map<String, dynamic>>[];

      if (!mounted) return;
      setState(() {
        _passwordSet = data['password_set'] == true;
        _maskedPhone = (data['masked_phone'] ?? '').toString();
        _activeCodes = codes;
        _loading = false;
      });
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _error = 'Impossible de charger vos informations de sécurité.';
        _loading = false;
      });
    }
  }

  Future<void> _openPasswordSheet() async {
    final currentCtrl = TextEditingController();
    final newCtrl = TextEditingController();
    final confirmCtrl = TextEditingController();
    bool submitting = false;
    String? err;

    await showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setModalState) => Padding(
          padding: EdgeInsets.fromLTRB(
            20,
            20,
            20,
            MediaQuery.of(ctx).viewInsets.bottom + 24,
          ),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text(
                _passwordSet
                    ? 'Modifier mon mot de passe'
                    : 'Définir mon mot de passe',
                style:
                    const TextStyle(fontSize: 18, fontWeight: FontWeight.bold),
              ),
              const SizedBox(height: 14),
              if (_passwordSet) ...[
                TextField(
                  controller: currentCtrl,
                  obscureText: true,
                  decoration: const InputDecoration(
                    labelText: 'Mot de passe actuel',
                    border: OutlineInputBorder(),
                  ),
                ),
                const SizedBox(height: 12),
              ],
              TextField(
                controller: newCtrl,
                obscureText: true,
                decoration: const InputDecoration(
                  labelText: 'Nouveau mot de passe (min. 8 caractères)',
                  border: OutlineInputBorder(),
                ),
              ),
              const SizedBox(height: 12),
              TextField(
                controller: confirmCtrl,
                obscureText: true,
                decoration: const InputDecoration(
                  labelText: 'Confirmer le mot de passe',
                  border: OutlineInputBorder(),
                ),
              ),
              if (err != null) ...[
                const SizedBox(height: 10),
                Text(err!,
                    style: const TextStyle(color: Colors.red, fontSize: 13)),
              ],
              const SizedBox(height: 16),
              ElevatedButton(
                style: ElevatedButton.styleFrom(
                  backgroundColor: GoLivreurTheme.secondaire,
                  foregroundColor: Colors.white,
                  padding: const EdgeInsets.symmetric(vertical: 14),
                ),
                onPressed: submitting
                    ? null
                    : () async {
                        if (newCtrl.text.length < 8) {
                          setModalState(() => err =
                              'Le mot de passe doit contenir au moins 8 caractères.');
                          return;
                        }
                        if (newCtrl.text != confirmCtrl.text) {
                          setModalState(() =>
                              err = 'Les mots de passe ne correspondent pas.');
                          return;
                        }
                        setModalState(() {
                          submitting = true;
                          err = null;
                        });
                        try {
                          final api = ref.read(apiClientProvider);
                          await api.post('/me/password', data: {
                            if (_passwordSet)
                              'current_password': currentCtrl.text,
                            'password': newCtrl.text,
                            'password_confirmation': confirmCtrl.text,
                          });
                          if (ctx.mounted) Navigator.of(ctx).pop();
                          if (mounted) {
                            ScaffoldMessenger.of(context).showSnackBar(
                              const SnackBar(
                                content: Text('Mot de passe enregistré.'),
                                backgroundColor: GoLivreurTheme.secondaire,
                              ),
                            );
                            await _loadSecurity();
                          }
                        } on DioException catch (e) {
                          final msg = e.response?.data is Map
                              ? (e.response!.data['message']?.toString() ??
                                  'Erreur de validation')
                              : 'Erreur réseau';
                          setModalState(() {
                            submitting = false;
                            err = msg;
                          });
                        }
                      },
                child: submitting
                    ? const SizedBox(
                        width: 20,
                        height: 20,
                        child: CircularProgressIndicator(
                            strokeWidth: 2, color: Colors.white),
                      )
                    : const Text('Enregistrer'),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Future<void> _openPhoneChangeSheet() async {
    final phoneCtrl = TextEditingController();
    final codeCtrl = TextEditingController();
    bool stepConfirm = false;
    bool submitting = false;
    String? err;

    await showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setModalState) => Padding(
          padding: EdgeInsets.fromLTRB(
            20,
            20,
            20,
            MediaQuery.of(ctx).viewInsets.bottom + 24,
          ),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text(
                stepConfirm
                    ? 'Étape 2/2 — Confirmer le code OTP'
                    : 'Étape 1/2 — Changer de numéro',
                style:
                    const TextStyle(fontSize: 18, fontWeight: FontWeight.bold),
              ),
              const SizedBox(height: 12),
              if (!stepConfirm)
                TextField(
                  controller: phoneCtrl,
                  keyboardType: TextInputType.phone,
                  decoration: const InputDecoration(
                    labelText: 'Nouveau numéro (ex: 0701020304)',
                    border: OutlineInputBorder(),
                  ),
                )
              else
                TextField(
                  controller: codeCtrl,
                  keyboardType: TextInputType.number,
                  maxLength: 6,
                  decoration: const InputDecoration(
                    labelText: 'Code OTP à 6 chiffres reçu par SMS',
                    border: OutlineInputBorder(),
                  ),
                ),
              if (err != null) ...[
                const SizedBox(height: 8),
                Text(err!,
                    style: const TextStyle(color: Colors.red, fontSize: 13)),
              ],
              const SizedBox(height: 14),
              ElevatedButton(
                style: ElevatedButton.styleFrom(
                  backgroundColor: GoLivreurTheme.primaire,
                  foregroundColor: Colors.white,
                  padding: const EdgeInsets.symmetric(vertical: 14),
                ),
                onPressed: submitting
                    ? null
                    : () async {
                        setModalState(() {
                          submitting = true;
                          err = null;
                        });
                        try {
                          final api = ref.read(apiClientProvider);
                          if (!stepConfirm) {
                            await api.post('/me/phone/request', data: {
                              'new_phone': phoneCtrl.text.trim(),
                            });
                            setModalState(() {
                              stepConfirm = true;
                              submitting = false;
                            });
                          } else {
                            final res =
                                await api.post('/me/phone/confirm', data: {
                              'code': codeCtrl.text.trim(),
                            });
                            final d = res.data is Map &&
                                    res.data['donnees'] is Map
                                ? Map<String, dynamic>.from(
                                    res.data['donnees'] as Map)
                                : const <String, dynamic>{};
                            final newAccess =
                                d['access_token']?.toString() ?? '';
                            final newRefresh =
                                d['refresh_token']?.toString() ?? '';
                            if (newAccess.isNotEmpty) {
                              final storage =
                                  SecureTokenStorage.createStorage();
                              await storage.write(
                                  key: SecureTokenStorage.accessTokenKey,
                                  value: newAccess);
                              if (newRefresh.isNotEmpty) {
                                await storage.write(
                                    key: SecureTokenStorage.refreshTokenKey,
                                    value: newRefresh);
                              }
                            }
                            if (ctx.mounted) Navigator.of(ctx).pop();
                            if (mounted) {
                              ScaffoldMessenger.of(context).showSnackBar(
                                const SnackBar(
                                  content: Text(
                                      'Numéro modifié avec succès. Vos autres sessions ont été déconnectées.'),
                                  backgroundColor: GoLivreurTheme.secondaire,
                                ),
                              );
                              await _loadSecurity();
                            }
                          }
                        } on DioException catch (e) {
                          final msg = e.response?.data is Map
                              ? (e.response!.data['message']?.toString() ??
                                  'Erreur de validation')
                              : 'Erreur réseau';
                          setModalState(() {
                            submitting = false;
                            err = msg;
                          });
                        }
                      },
                child: submitting
                    ? const SizedBox(
                        width: 20,
                        height: 20,
                        child: CircularProgressIndicator(
                            strokeWidth: 2, color: Colors.white),
                      )
                    : Text(stepConfirm ? 'Valider le code' : 'Envoyer le code OTP'),
              ),
            ],
          ),
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: GoLivreurTheme.fond,
      appBar: AppBar(
        title: const Text('Sécurité & Codes de livraison'),
        actions: [
          IconButton(
            icon: const Icon(Icons.refresh),
            onPressed: _loadSecurity,
          ),
        ],
      ),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _error != null
              ? Center(
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Text(_error!),
                      const SizedBox(height: 12),
                      ElevatedButton(
                        onPressed: _loadSecurity,
                        child: const Text('Réessayer'),
                      ),
                    ],
                  ),
                )
              : RefreshIndicator(
                  onRefresh: _loadSecurity,
                  child: ListView(
                    padding: const EdgeInsets.all(16),
                    children: [
                      Card(
                        shape: RoundedRectangleBorder(
                            borderRadius: BorderRadius.circular(14)),
                        child: Column(
                          children: [
                            ListTile(
                              leading: const Icon(Icons.phone_android,
                                  color: GoLivreurTheme.primaire),
                              title: const Text('Numéro de téléphone'),
                              subtitle: Text(_maskedPhone.isEmpty
                                  ? 'Non renseigné'
                                  : _maskedPhone),
                              trailing: TextButton(
                                onPressed: _openPhoneChangeSheet,
                                child: const Text('Changer (OTP)'),
                              ),
                            ),
                            const Divider(height: 1),
                            ListTile(
                              leading: const Icon(Icons.lock_outline,
                                  color: GoLivreurTheme.secondaire),
                              title: const Text('Mot de passe'),
                              subtitle: Text(_passwordSet
                                  ? 'Mot de passe configuré'
                                  : 'Aucun mot de passe défini'),
                              trailing: TextButton(
                                onPressed: _openPasswordSheet,
                                child: Text(
                                    _passwordSet ? 'Modifier' : 'Définir'),
                              ),
                            ),
                          ],
                        ),
                      ),
                      const SizedBox(height: 20),
                      const Text(
                        'Codes de livraison actifs',
                        style: TextStyle(
                            fontSize: 16, fontWeight: FontWeight.bold),
                      ),
                      const SizedBox(height: 6),
                      const Text(
                        'Appuyez sur un code pour l\'afficher. Ne le communiquez qu\'au livreur au moment de la remise.',
                        style: TextStyle(
                            fontSize: 12.5,
                            color: GoLivreurTheme.texteSecondaire),
                      ),
                      const SizedBox(height: 12),
                      if (_activeCodes.isEmpty)
                        Container(
                          padding: const EdgeInsets.all(20),
                          decoration: BoxDecoration(
                            color: Colors.white,
                            borderRadius: BorderRadius.circular(14),
                            border: Border.all(color: GoLivreurTheme.bordure),
                          ),
                          child: const Text(
                            'Aucun code de livraison actif pour le moment.',
                            textAlign: TextAlign.center,
                            style: TextStyle(
                                color: GoLivreurTheme.texteSecondaire),
                          ),
                        )
                      else
                        ..._activeCodes.map((entry) {
                          final key = '${entry['type']}_${entry['id']}';
                          final revealed = _revealedKeys.contains(key);
                          final code = (entry['code'] ?? '').toString();
                          return Card(
                            margin: const EdgeInsets.only(bottom: 10),
                            shape: RoundedRectangleBorder(
                                borderRadius: BorderRadius.circular(14)),
                            child: InkWell(
                              borderRadius: BorderRadius.circular(14),
                              onTap: () {
                                setState(() {
                                  if (revealed) {
                                    _revealedKeys.remove(key);
                                  } else {
                                    _revealedKeys.add(key);
                                  }
                                });
                              },
                              child: Padding(
                                padding: const EdgeInsets.all(14),
                                child: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    Row(
                                      children: [
                                        Expanded(
                                          child: Column(
                                            crossAxisAlignment:
                                                CrossAxisAlignment.start,
                                            children: [
                                              Text(
                                                (entry['reference'] ?? '')
                                                    .toString(),
                                                style: const TextStyle(
                                                    fontWeight: FontWeight.bold,
                                                    fontSize: 14.5),
                                              ),
                                              Text(
                                                (entry['label'] ?? '')
                                                    .toString(),
                                                style: const TextStyle(
                                                    fontSize: 12.5,
                                                    color: GoLivreurTheme
                                                        .texteSecondaire),
                                              ),
                                            ],
                                          ),
                                        ),
                                        Container(
                                          padding: const EdgeInsets.symmetric(
                                              horizontal: 14, vertical: 8),
                                          decoration: BoxDecoration(
                                            color: GoLivreurTheme.primaire
                                                .withValues(alpha: 0.14),
                                            borderRadius:
                                                BorderRadius.circular(10),
                                          ),
                                          child: Text(
                                            revealed ? code : '••••',
                                            style: const TextStyle(
                                              fontSize: 18,
                                              fontWeight: FontWeight.bold,
                                              letterSpacing: 4,
                                            ),
                                          ),
                                        ),
                                      ],
                                    ),
                                    const SizedBox(height: 8),
                                    Text(
                                      (entry['instruction'] ??
                                              'Appuyez pour afficher / masquer.')
                                          .toString(),
                                      style: const TextStyle(
                                          fontSize: 12,
                                          color:
                                              GoLivreurTheme.texteSecondaire),
                                    ),
                                  ],
                                ),
                              ),
                            ),
                          );
                        }),
                    ],
                  ),
                ),
    );
  }
}
