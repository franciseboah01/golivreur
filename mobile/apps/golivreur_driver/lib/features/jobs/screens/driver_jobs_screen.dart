import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:golivreur_core/config/app_theme.dart';
import '../../auth/screens/driver_login_screen.dart';
import '../../driver/providers/driver_provider.dart';

class DriverJobsScreen extends ConsumerStatefulWidget {
  final void Function(int)? onSwitchTab;

  const DriverJobsScreen({super.key, this.onSwitchTab});

  @override
  ConsumerState<DriverJobsScreen> createState() => _DriverJobsScreenState();
}

class _DriverJobsScreenState extends ConsumerState<DriverJobsScreen> {
  final _codeController = TextEditingController();
  Timer? _countdownTimer;
  final int _secondsLeft = 30;
  bool _verifying = false;

  @override
  void initState() {
    super.initState();
  }

  @override
  void dispose() {
    _countdownTimer?.cancel();
    _codeController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final profile = ref.watch(driverProfileProvider);
    final activeJob = ref.watch(driverActiveJobProvider);

    return Scaffold(
      backgroundColor: GoLivreurTheme.fond,
      drawer: _buildDriverDrawer(context, ref, profile),
      appBar: AppBar(
        backgroundColor: Colors.white,
        elevation: 0.5,
        leading: Builder(
          builder: (drawerCtx) => IconButton(
            icon: const Icon(Icons.menu, color: GoLivreurTheme.textePrincipal),
            tooltip: 'Menu rapide & Profil',
            onPressed: () => Scaffold.of(drawerCtx).openDrawer(),
          ),
        ),
        title: Row(
          children: [
            Container(
              width: 32,
              height: 32,
              decoration: const BoxDecoration(
                color: GoLivreurTheme.secondaire,
                shape: BoxShape.circle,
              ),
              child: const Icon(Icons.two_wheeler, color: Colors.white, size: 18),
            ),
            const SizedBox(width: 10),
            Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  profile.name.isNotEmpty ? profile.name : 'Livreur GoLivreur',
                  style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 15, color: GoLivreurTheme.textePrincipal),
                ),
                Text(
                  'Solde : ${profile.walletBalance.toInt()} F CFA',
                  style: const TextStyle(fontSize: 12, color: GoLivreurTheme.secondaire, fontWeight: FontWeight.bold),
                ),
              ],
            ),
          ],
        ),
        actions: [
          // Commutateur En Ligne / Hors Ligne
          Padding(
            padding: const EdgeInsets.only(right: 12),
            child: InkWell(
              onTap: () async {
                final (ok, msg) = await ref.read(driverProfileProvider.notifier).toggleOnline();
                if (!ok && msg != null && context.mounted) {
                  ScaffoldMessenger.of(context).showSnackBar(
                    SnackBar(content: Text(msg)),
                  );
                }
              },
              borderRadius: BorderRadius.circular(20),
              child: Container(
                padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
                decoration: BoxDecoration(
                  color: profile.isOnline
                      ? GoLivreurTheme.secondaire.withValues(alpha: 0.15)
                      : Colors.grey.shade200,
                  borderRadius: BorderRadius.circular(20),
                  border: Border.all(
                    color: profile.isOnline ? GoLivreurTheme.secondaire : Colors.grey,
                  ),
                ),
                child: Row(
                  children: [
                    Container(
                      width: 8,
                      height: 8,
                      decoration: BoxDecoration(
                        color: profile.isOnline ? GoLivreurTheme.secondaire : Colors.grey,
                        shape: BoxShape.circle,
                      ),
                    ),
                    const SizedBox(width: 6),
                    Text(
                      profile.isOnline ? 'En ligne' : 'Hors ligne',
                      style: TextStyle(
                        fontSize: 12,
                        fontWeight: FontWeight.bold,
                        color: profile.isOnline ? GoLivreurTheme.secondaire : Colors.grey.shade700,
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ),
        ],
      ),
      body: !profile.isOnline
          ? _buildOfflineView(context, profile)
          : activeJob == null
              ? _buildIdleView(context, ref, profile)
              : _buildJobFlow(context, ref, activeJob, profile),
    );
  }

  Widget _buildOfflineView(BuildContext context, DriverProfileState profile) {
    final citySuffix = (profile.cityName != null && profile.cityName!.isNotEmpty)
        ? ' à ${profile.cityName}'
        : '';
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Icon(Icons.cloud_off, size: 64, color: Colors.grey),
            const SizedBox(height: 16),
            const Text(
              'Vous êtes actuellement hors-ligne',
              style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold, color: GoLivreurTheme.textePrincipal),
            ),
            const SizedBox(height: 8),
            Text(
              'Passez en ligne pour recevoir des courses GoShop et GoColis$citySuffix.',
              textAlign: TextAlign.center,
              style: const TextStyle(color: GoLivreurTheme.texteSecondaire),
            ),
            const SizedBox(height: 24),
            ElevatedButton(
              style: ElevatedButton.styleFrom(
                backgroundColor: GoLivreurTheme.secondaire,
                padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 12),
              ),
              onPressed: () async {
                final (ok, msg) = await ref.read(driverProfileProvider.notifier).toggleOnline();
                if (!ok && msg != null && context.mounted) {
                  ScaffoldMessenger.of(context).showSnackBar(
                    SnackBar(content: Text(msg)),
                  );
                } else if (ok) {
                  await ref.read(driverActiveJobProvider.notifier).chargerCourses();
                }
              },
              child: const Text('Passer en ligne', style: TextStyle(fontWeight: FontWeight.bold, color: Colors.white)),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildIdleView(BuildContext context, WidgetRef ref, DriverProfileState profile) {
    final cityTitle = (profile.cityName != null && profile.cityName!.isNotEmpty)
        ? 'En attente de courses sur ${profile.cityName}...'
        : 'En attente de courses...';
    return RefreshIndicator(
      color: GoLivreurTheme.secondaire,
      onRefresh: () async {
        await Future.wait([
          ref.read(driverActiveJobProvider.notifier).chargerCourses(),
          ref.read(driverProfileProvider.notifier).chargerPortefeuille(),
        ]);
      },
      child: ListView(
        physics: const AlwaysScrollableScrollPhysics(),
        padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 72),
        children: [
          Center(
            child: Container(
              padding: const EdgeInsets.all(20),
              decoration: BoxDecoration(
                color: GoLivreurTheme.secondaire.withValues(alpha: 0.1),
                shape: BoxShape.circle,
              ),
              child: const Icon(Icons.radar, size: 48, color: GoLivreurTheme.secondaire),
            ),
          ),
          const SizedBox(height: 20),
          Text(
            cityTitle,
            textAlign: TextAlign.center,
            style: const TextStyle(fontSize: 18, fontWeight: FontWeight.bold, color: GoLivreurTheme.textePrincipal),
          ),
          const SizedBox(height: 8),
          const Text(
            'Restez disponible, les nouvelles commandes GoShop et expéditions GoColis de votre zone apparaîtront automatiquement ici.',
            textAlign: TextAlign.center,
            style: TextStyle(color: GoLivreurTheme.texteSecondaire, fontSize: 13),
          ),
          const SizedBox(height: 24),
          Center(
            child: OutlinedButton.icon(
              onPressed: () {
                ref.read(driverActiveJobProvider.notifier).chargerCourses();
              },
              icon: const Icon(Icons.refresh),
              label: const Text('Actualiser les courses'),
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildJobFlow(
    BuildContext context,
    WidgetRef ref,
    DriverJob job,
    DriverProfileState profile,
  ) {
    switch (job.step) {
      case DriverJobStep.incoming:
        return _buildIncomingAlert(context, ref, job);
      case DriverJobStep.accepted:
        return _buildNavigationStep(
          context,
          ref,
          job,
          title: 'Aller au commerce / expéditeur',
          destination: job.pickupAddress,
          buttonLabel: 'Je suis arrivé sur place',
          onAction: () => ref.read(driverActiveJobProvider.notifier).arrivedAtMerchant(),
        );
      case DriverJobStep.atMerchant:
        return _buildAtMerchantStep(context, ref, job);
      case DriverJobStep.inTransit:
        return _buildDeliveryStep(context, ref, job);
      case DriverJobStep.securityLocked:
        return _buildSecurityLockedView(context, profile);
      case DriverJobStep.completed:
        return _buildSuccessView(context, ref, job);
    }
  }

  Widget _buildIncomingAlert(BuildContext context, WidgetRef ref, DriverJob job) {
    final isGoColis = job.type == DriverJobType.gocolis;

    return SingleChildScrollView(
      padding: const EdgeInsets.all(16),
      child: Column(
        children: [
          Container(
            padding: const EdgeInsets.all(20),
            decoration: BoxDecoration(
              color: Colors.white,
              borderRadius: BorderRadius.circular(16),
              border: Border.all(color: const Color(0xFFF9A825), width: 2),
              boxShadow: [
                BoxShadow(
                  color: const Color(0xFFF9A825).withValues(alpha: 0.2),
                  blurRadius: 12,
                  offset: const Offset(0, 4),
                ),
              ],
            ),
            child: Column(
              children: [
                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                      decoration: BoxDecoration(
                        color: isGoColis ? const Color(0xFF1B5E20) : const Color(0xFFF4A623),
                        borderRadius: BorderRadius.circular(8),
                      ),
                      child: Text(
                        isGoColis ? '📦 COURSE GOCOLIS' : '🍔 COMMANDE GOSHOP',
                        style: const TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 12),
                      ),
                    ),
                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                      decoration: BoxDecoration(
                        color: _secondsLeft <= 10 ? GoLivreurTheme.erreur : const Color(0xFFF9A825),
                        borderRadius: BorderRadius.circular(20),
                      ),
                      child: Text(
                        '${_secondsLeft}s',
                        style: const TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 14),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 16),
                Text(
                  '+${job.payout.toInt()} F CFA',
                  style: const TextStyle(
                    fontSize: 32,
                    fontWeight: FontWeight.bold,
                    color: GoLivreurTheme.secondaire,
                  ),
                ),
                const Text('Gain estimé net pour vous', style: TextStyle(color: GoLivreurTheme.texteSecondaire, fontSize: 13)),
                const Divider(height: 24),
                if (job.parcelSize != null) ...[
                  Container(
                    padding: const EdgeInsets.all(8),
                    decoration: BoxDecoration(
                      color: GoLivreurTheme.fond,
                      borderRadius: BorderRadius.circular(8),
                    ),
                    child: Row(
                      children: [
                        const Icon(Icons.inventory_2, color: GoLivreurTheme.secondaire, size: 20),
                        const SizedBox(width: 8),
                        Text('Taille : ${job.parcelSize!}', style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13)),
                      ],
                    ),
                  ),
                  const SizedBox(height: 12),
                ],
                _buildRouteItem(Icons.store, 'Ramassage', job.pickupAddress),
                const SizedBox(height: 10),
                _buildRouteItem(Icons.location_on, 'Livraison', job.deliveryAddress),
                const SizedBox(height: 12),
                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Text('Distance totale : ${job.distanceKm} km', style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13)),
                    const Text('Temps estimé : 15 min', style: TextStyle(color: GoLivreurTheme.texteSecondaire, fontSize: 13)),
                  ],
                ),
                const SizedBox(height: 24),
                Row(
                  children: [
                    Expanded(
                      child: SizedBox(
                        height: 52,
                        child: OutlinedButton(
                          style: OutlinedButton.styleFrom(
                            foregroundColor: GoLivreurTheme.erreur,
                            side: const BorderSide(color: GoLivreurTheme.erreur, width: 1.5),
                            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                          ),
                          onPressed: () {
                            _countdownTimer?.cancel();
                            ref.read(driverActiveJobProvider.notifier).refuseJob();
                          },
                          child: const Text('Refuser', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 15)),
                        ),
                      ),
                    ),
                    const SizedBox(width: 12),
                    Expanded(
                      child: SizedBox(
                        height: 52,
                        child: ElevatedButton(
                          style: ElevatedButton.styleFrom(
                            backgroundColor: GoLivreurTheme.secondaire,
                            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                          ),
                          onPressed: () {
                            _countdownTimer?.cancel();
                            ref.read(driverActiveJobProvider.notifier).acceptJob();
                          },
                          child: const Text('Accepter la course', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 15, color: Colors.white)),
                        ),
                      ),
                    ),
                  ],
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildNavigationStep(
    BuildContext context,
    WidgetRef ref,
    DriverJob job, {
    required String title,
    required String destination,
    required String buttonLabel,
    required VoidCallback onAction,
  }) {
    return Column(
      children: [
        // Carte de simulation GPS
        Expanded(
          child: Container(
            color: Colors.grey.shade200,
            alignment: Alignment.center,
            child: Column(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                const Icon(Icons.navigation, size: 54, color: GoLivreurTheme.secondaire),
                const SizedBox(height: 12),
                Text(
                  title,
                  style: const TextStyle(fontSize: 18, fontWeight: FontWeight.bold),
                ),
                const SizedBox(height: 4),
                Text(destination, style: const TextStyle(color: GoLivreurTheme.texteSecondaire)),
              ],
            ),
          ),
        ),
        // Panneau d'action bas
        Container(
          padding: const EdgeInsets.all(16),
          color: Colors.white,
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              SizedBox(
                width: double.infinity,
                height: 52,
                child: ElevatedButton(
                  style: ElevatedButton.styleFrom(
                    backgroundColor: GoLivreurTheme.secondaire,
                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                  ),
                  onPressed: onAction,
                  child: Text(
                    buttonLabel,
                    style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16, color: Colors.white),
                  ),
                ),
              ),
            ],
          ),
        ),
      ],
    );
  }

  Widget _buildAtMerchantStep(BuildContext context, WidgetRef ref, DriverJob job) {
    final isGoColis = job.type == DriverJobType.gocolis;

    return SingleChildScrollView(
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            isGoColis ? 'Ramassage Colis — Photo & Code Expéditeur' : 'Récupération Commande au Maquis/Restaurant',
            style: const TextStyle(fontSize: 18, fontWeight: FontWeight.bold),
          ),
          const SizedBox(height: 12),
          if (isGoColis) ...[
            Container(
              padding: const EdgeInsets.all(16),
              decoration: BoxDecoration(
                color: Colors.white,
                borderRadius: BorderRadius.circular(14),
                border: Border.all(color: GoLivreurTheme.bordure),
              ),
              child: Column(
                children: [
                  const Icon(Icons.camera_alt_outlined, size: 40, color: GoLivreurTheme.secondaire),
                  const SizedBox(height: 8),
                  const Text('Photo obligatoire du colis scellé', style: TextStyle(fontWeight: FontWeight.bold)),
                  const SizedBox(height: 4),
                  const Text('Photographiez le colis avec l\'étiquette bien visible', style: TextStyle(fontSize: 12, color: GoLivreurTheme.texteSecondaire)),
                  const SizedBox(height: 12),
                  OutlinedButton.icon(
                    onPressed: () {
                      ScaffoldMessenger.of(context).showSnackBar(
                        const SnackBar(content: Text('📸 Photo du colis capturée avec succès !')),
                      );
                    },
                    icon: const Icon(Icons.camera_alt),
                    label: const Text('Prendre la photo'),
                  ),
                ],
              ),
            ),
            const SizedBox(height: 16),
          ],
          Container(
            padding: const EdgeInsets.all(16),
            decoration: BoxDecoration(
              color: Colors.white,
              borderRadius: BorderRadius.circular(14),
              border: Border.all(color: GoLivreurTheme.bordure),
            ),
            child: Column(
              children: [
                Text(
                  isGoColis ? 'Entrez le code à 4 chiffres de l\'expéditeur :' : 'Vérifiez la commande avec le gérant :',
                  style: const TextStyle(fontWeight: FontWeight.bold),
                ),
                if (isGoColis) ...[
                  const SizedBox(height: 10),
                  TextField(
                    controller: _codeController,
                    keyboardType: TextInputType.number,
                    maxLength: 4,
                    textAlign: TextAlign.center,
                    style: const TextStyle(fontSize: 24, letterSpacing: 8, fontWeight: FontWeight.bold),
                    decoration: const InputDecoration(hintText: '••••', counterText: ''),
                  ),
                ],
                const SizedBox(height: 16),
                SizedBox(
                  width: double.infinity,
                  height: 52,
                  child: ElevatedButton(
                    style: ElevatedButton.styleFrom(
                      backgroundColor: GoLivreurTheme.secondaire,
                      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                    ),
                    onPressed: _verifying
                        ? null
                        : () async {
                            final code = _codeController.text.trim();
                            setState(() => _verifying = true);
                            final (ok, err) = await ref
                                .read(driverActiveJobProvider.notifier)
                                .pickedUp(senderCode: isGoColis ? code : null);
                            if (mounted) {
                              setState(() => _verifying = false);
                              if (ok) {
                                _codeController.clear();
                              } else if (err != null) {
                                ScaffoldMessenger.of(context).showSnackBar(
                                  SnackBar(content: Text(err)),
                                );
                              }
                            }
                          },
                    child: Text(
                      isGoColis ? 'Valider le ramassage' : 'Commande récupérée (En route client)',
                      style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16, color: Colors.white),
                    ),
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Future<void> _soumettreCodeLivraison(String inputCode) async {
    if (_verifying || inputCode.trim().length != 4) return;
    setState(() => _verifying = true);
    final (ok, err) =
        await ref.read(driverActiveJobProvider.notifier).verifyCode(inputCode.trim());
    if (mounted) {
      setState(() => _verifying = false);
      if (ok) {
        _codeController.clear();
        await ref.read(driverProfileProvider.notifier).chargerPortefeuille();
      } else if (err != null) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(err)),
        );
      }
    }
  }

  Widget _buildDeliveryStep(BuildContext context, WidgetRef ref, DriverJob job) {
    return SingleChildScrollView(
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Text(
            'Remise au Client / Destinataire',
            style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold),
          ),
          const SizedBox(height: 6),
          Text('Destination : ${job.deliveryAddress}', style: const TextStyle(color: GoLivreurTheme.texteSecondaire)),
          const SizedBox(height: 20),
          Container(
            padding: const EdgeInsets.all(20),
            decoration: BoxDecoration(
              color: Colors.white,
              borderRadius: BorderRadius.circular(16),
              border: Border.all(color: GoLivreurTheme.bordure),
            ),
            child: Column(
              children: [
                const Icon(Icons.pin, color: GoLivreurTheme.primaire, size: 36),
                const SizedBox(height: 10),
                const Text(
                  'Code de confirmation du client',
                  style: TextStyle(fontWeight: FontWeight.bold, fontSize: 16),
                ),
                const SizedBox(height: 6),
                const Text(
                  'Demandez au client le code à 4 chiffres affiché sur son application (ou reçu par SMS) :',
                  textAlign: TextAlign.center,
                  style: TextStyle(fontSize: 12, color: GoLivreurTheme.texteSecondaire),
                ),
                const SizedBox(height: 16),
                TextField(
                  controller: _codeController,
                  keyboardType: TextInputType.number,
                  maxLength: 4,
                  textAlign: TextAlign.center,
                  style: const TextStyle(fontSize: 32, letterSpacing: 12, fontWeight: FontWeight.bold),
                  decoration: const InputDecoration(
                    counterText: '',
                    hintText: '••••',
                  ),
                  onChanged: (val) {
                    if (val.length == 4) {
                      _soumettreCodeLivraison(val);
                    }
                  },
                ),
                if (job.failedCodeAttempts > 0) ...[
                  const SizedBox(height: 8),
                  Text(
                    'Code incorrect ! Tentative ${job.failedCodeAttempts}/3',
                    style: const TextStyle(color: GoLivreurTheme.erreur, fontWeight: FontWeight.bold),
                  ),
                ],
                const SizedBox(height: 20),
                SizedBox(
                  width: double.infinity,
                  height: 52,
                  child: ElevatedButton(
                    style: ElevatedButton.styleFrom(
                      backgroundColor: GoLivreurTheme.secondaire,
                      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                    ),
                    onPressed: _verifying
                        ? null
                        : () => _soumettreCodeLivraison(_codeController.text),
                    child: _verifying
                        ? const SizedBox(
                            height: 20,
                            width: 20,
                            child: CircularProgressIndicator(
                              strokeWidth: 2,
                              color: Colors.white,
                            ),
                          )
                        : const Text('Valider la livraison', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 16, color: Colors.white)),
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildSecurityLockedView(BuildContext context, DriverProfileState profile) {
    final citySuffix = (profile.cityName != null && profile.cityName!.isNotEmpty)
        ? ' ${profile.cityName}'
        : '';
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Container(
              padding: const EdgeInsets.all(20),
              decoration: BoxDecoration(
                color: GoLivreurTheme.erreur.withValues(alpha: 0.1),
                shape: BoxShape.circle,
              ),
              child: const Icon(Icons.lock, size: 54, color: GoLivreurTheme.erreur),
            ),
            const SizedBox(height: 16),
            const Text(
              'Blocage de Sécurité',
              style: TextStyle(fontSize: 22, fontWeight: FontWeight.bold, color: GoLivreurTheme.erreur),
            ),
            const SizedBox(height: 8),
            const Text(
              '3 tentatives de code erronées consécutives. Par mesure de sécurité anti-fraude, la livraison est suspendue. Contactez le support de votre agence pour débloquer la course.',
              textAlign: TextAlign.center,
              style: TextStyle(color: GoLivreurTheme.texteSecondaire),
            ),
            const SizedBox(height: 24),
            SizedBox(
              width: double.infinity,
              height: 52,
              child: ElevatedButton.icon(
                style: ElevatedButton.styleFrom(
                  backgroundColor: GoLivreurTheme.erreur,
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                ),
                onPressed: () {
                  ScaffoldMessenger.of(context).showSnackBar(
                    SnackBar(content: Text('Contactez votre agence Support GoLivreur$citySuffix.')),
                  );
                },
                icon: const Icon(Icons.support_agent, color: Colors.white),
                label: Text('Contacter le Support$citySuffix', style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16, color: Colors.white)),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildSuccessView(BuildContext context, WidgetRef ref, DriverJob job) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Container(
              padding: const EdgeInsets.all(20),
              decoration: BoxDecoration(
                color: GoLivreurTheme.succes.withValues(alpha: 0.15),
                shape: BoxShape.circle,
              ),
              child: const Icon(Icons.check_circle, size: 64, color: GoLivreurTheme.succes),
            ),
            const SizedBox(height: 16),
            const Text(
              'Course Livrée avec Succès !',
              style: TextStyle(fontSize: 22, fontWeight: FontWeight.bold, color: GoLivreurTheme.textePrincipal),
            ),
            const SizedBox(height: 8),
            Text(
              '+${job.payout.toInt()} F CFA crédités immédiatement',
              style: const TextStyle(fontSize: 18, fontWeight: FontWeight.bold, color: GoLivreurTheme.secondaire),
            ),
            const SizedBox(height: 24),
            SizedBox(
              width: double.infinity,
              height: 52,
              child: ElevatedButton(
                style: ElevatedButton.styleFrom(
                  backgroundColor: GoLivreurTheme.secondaire,
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                ),
                onPressed: () async {
                  ref.read(driverActiveJobProvider.notifier).clearCompletedJob();
                  await Future.wait([
                    ref.read(driverActiveJobProvider.notifier).chargerCourses(),
                    ref.read(driverProfileProvider.notifier).chargerPortefeuille(),
                  ]);
                },
                child: const Text('Prêt pour la course suivante', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 16, color: Colors.white)),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildRouteItem(IconData icon, String label, String address) {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Icon(icon, color: GoLivreurTheme.secondaire, size: 20),
        const SizedBox(width: 8),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(label, style: const TextStyle(color: GoLivreurTheme.texteSecondaire, fontSize: 12)),
              Text(address, style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13)),
            ],
          ),
        ),
      ],
    );
  }

  Widget _buildDriverDrawer(BuildContext context, WidgetRef ref, DriverProfileState profile) {
    return Drawer(
      child: SafeArea(
        child: Column(
          children: [
            Container(
              width: double.infinity,
              padding: const EdgeInsets.all(20),
              decoration: const BoxDecoration(
                color: GoLivreurTheme.primaire,
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      Container(
                        padding: const EdgeInsets.all(8),
                        decoration: BoxDecoration(
                          color: Colors.white,
                          borderRadius: BorderRadius.circular(12),
                        ),
                        child: const Icon(Icons.two_wheeler, color: GoLivreurTheme.primaire, size: 28),
                      ),
                      const SizedBox(width: 12),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              profile.name.isNotEmpty ? profile.name : 'Livreur GoLivreur',
                              style: const TextStyle(color: Colors.white, fontSize: 16, fontWeight: FontWeight.bold),
                            ),
                            Text(
                              profile.motoPlate.isNotEmpty
                                  ? '${profile.vehicleTypeName.isNotEmpty ? profile.vehicleTypeName : "Véhicule"} : ${profile.motoPlate}'
                                  : (profile.cityName ?? ''),
                              style: const TextStyle(color: Colors.white70, fontSize: 12),
                            ),
                          ],
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 12),
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                    decoration: BoxDecoration(
                      color: Colors.black.withValues(alpha: 0.15),
                      borderRadius: BorderRadius.circular(20),
                    ),
                    child: Text(
                      'Solde : ${profile.walletBalance.toInt()} F CFA',
                      style: const TextStyle(color: Colors.white, fontSize: 13, fontWeight: FontWeight.bold),
                    ),
                  ),
                ],
              ),
            ),
            ListTile(
              leading: const Icon(Icons.person, color: GoLivreurTheme.secondaire),
              title: const Text('Mon Profil Livreur', style: TextStyle(fontWeight: FontWeight.bold)),
              subtitle: const Text('Coordonnées, véhicule, documents'),
              onTap: () {
                Navigator.pop(context);
                widget.onSwitchTab?.call(3);
              },
            ),
            const Divider(height: 1),
            ListTile(
              leading: const Icon(Icons.account_balance_wallet, color: GoLivreurTheme.secondaire),
              title: const Text('Portefeuille & Retraits'),
              subtitle: const Text('Wave, Orange Money, historique'),
              onTap: () {
                Navigator.pop(context);
                widget.onSwitchTab?.call(1);
              },
            ),
            ListTile(
              leading: const Icon(Icons.trending_up, color: GoLivreurTheme.secondaire),
              title: const Text('Historique des Gains'),
              onTap: () {
                Navigator.pop(context);
                widget.onSwitchTab?.call(2);
              },
            ),
            const Divider(height: 1),
            ListTile(
              leading: const Icon(Icons.phone_in_talk, color: GoLivreurTheme.primaire),
              title: Text(
                (profile.cityName != null && profile.cityName!.isNotEmpty)
                    ? 'Support Agence ${profile.cityName}'
                    : 'Support Agence GoLivreur',
              ),
              subtitle: const Text('Assistance & dispatch en direct'),
              onTap: () {
                Navigator.pop(context);
                ScaffoldMessenger.of(context).showSnackBar(
                  SnackBar(
                    content: Text(
                      'Contactez votre agence GoLivreur${(profile.cityName != null && profile.cityName!.isNotEmpty) ? " (${profile.cityName})" : ""}.',
                    ),
                  ),
                );
              },
            ),
            const Spacer(),
            const Divider(height: 1),
            ListTile(
              leading: const Icon(Icons.exit_to_app, color: GoLivreurTheme.erreur),
              title: const Text('Terminer mon service', style: TextStyle(color: GoLivreurTheme.erreur, fontWeight: FontWeight.bold)),
              onTap: () async {
                Navigator.pop(context);
                const storage = FlutterSecureStorage();
                await storage.deleteAll();
                ref.read(driverProfileProvider.notifier).reinitialiser();
                ref.read(driverActiveJobProvider.notifier).reinitialiser();
                if (context.mounted) {
                  Navigator.of(context).pushAndRemoveUntil(
                    MaterialPageRoute(builder: (_) => const DriverLoginScreen()),
                    (route) => false,
                  );
                }
              },
            ),
          ],
        ),
      ),
    );
  }
}
