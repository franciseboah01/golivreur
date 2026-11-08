import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:golivreur_core/config/app_theme.dart';
import '../../driver/providers/driver_provider.dart';

class DriverJobsScreen extends ConsumerStatefulWidget {
  const DriverJobsScreen({super.key});

  @override
  ConsumerState<DriverJobsScreen> createState() => _DriverJobsScreenState();
}

class _DriverJobsScreenState extends ConsumerState<DriverJobsScreen> {
  final _codeController = TextEditingController();
  Timer? _countdownTimer;
  int _secondsLeft = 30;

  @override
  void initState() {
    super.initState();
    _startCountdown();
  }

  void _startCountdown() {
    _countdownTimer?.cancel();
    _secondsLeft = 30;
    _countdownTimer = Timer.periodic(const Duration(seconds: 1), (timer) {
      if (_secondsLeft > 0) {
        setState(() => _secondsLeft--);
      } else {
        timer.cancel();
      }
    });
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
                  profile.name,
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
              onTap: () {
                ref.read(driverProfileProvider.notifier).toggleOnline();
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
          ? _buildOfflineView()
          : activeJob == null
              ? _buildIdleView(context, ref)
              : _buildJobFlow(context, ref, activeJob),
    );
  }

  Widget _buildOfflineView() {
    return Center(
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
          const Text(
            'Passez en ligne pour recevoir des courses GoShop et GoColis à Daloa.',
            textAlign: TextAlign.center,
            style: TextStyle(color: GoLivreurTheme.texteSecondaire),
          ),
          const SizedBox(height: 24),
          ElevatedButton(
            style: ElevatedButton.styleFrom(
              backgroundColor: GoLivreurTheme.secondaire,
              padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 12),
            ),
            onPressed: () {
              ref.read(driverProfileProvider.notifier).toggleOnline();
            },
            child: const Text('Passer en ligne', style: TextStyle(fontWeight: FontWeight.bold, color: Colors.white)),
          ),
        ],
      ),
    );
  }

  Widget _buildIdleView(BuildContext context, WidgetRef ref) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Container(
              padding: const EdgeInsets.all(20),
              decoration: BoxDecoration(
                color: GoLivreurTheme.secondaire.withValues(alpha: 0.1),
                shape: BoxShape.circle,
              ),
              child: const Icon(Icons.radar, size: 48, color: GoLivreurTheme.secondaire),
            ),
            const SizedBox(height: 20),
            const Text(
              'En attente de courses sur Daloa...',
              style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold, color: GoLivreurTheme.textePrincipal),
            ),
            const SizedBox(height: 8),
            const Text(
              'Restez disponible, les commandes Chez Tantie Adjoua, Speed Burger et expéditions GoColis arrivent.',
              textAlign: TextAlign.center,
              style: TextStyle(color: GoLivreurTheme.texteSecondaire, fontSize: 13),
            ),
            const SizedBox(height: 24),
            Row(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                OutlinedButton(
                  onPressed: () {
                    ref.read(driverActiveJobProvider.notifier).resetJobDemo(DriverJobType.goshop);
                    _startCountdown();
                  },
                  child: const Text('Simuler GoShop'),
                ),
                const SizedBox(width: 12),
                OutlinedButton(
                  onPressed: () {
                    ref.read(driverActiveJobProvider.notifier).resetJobDemo(DriverJobType.gocolis);
                    _startCountdown();
                  },
                  child: const Text('Simuler GoColis'),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildJobFlow(BuildContext context, WidgetRef ref, DriverJob job) {
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
        return _buildSecurityLockedView(context);
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
                    decoration: const InputDecoration(hintText: '7391', counterText: ''),
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
                    onPressed: () {
                      _codeController.clear();
                      ref.read(driverActiveJobProvider.notifier).pickedUp();
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
                const SizedBox(height: 10),
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                  decoration: BoxDecoration(
                    color: GoLivreurTheme.primaire.withValues(alpha: 0.12),
                    borderRadius: BorderRadius.circular(8),
                    border: Border.all(color: GoLivreurTheme.primaire.withValues(alpha: 0.4)),
                  ),
                  child: Row(
                    children: [
                      Expanded(
                        child: Text(
                          '💡 Code client : ${job.clientCode ?? '1234'} (ou code dev : 1234)',
                          style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w600, color: GoLivreurTheme.textePrincipal),
                        ),
                      ),
                      TextButton(
                        onPressed: () {
                          _codeController.text = job.clientCode ?? '1234';
                          final ok = ref.read(driverActiveJobProvider.notifier).verifyCode(_codeController.text.trim());
                          if (ok) {
                            ref.read(driverProfileProvider.notifier).addEarnings(job.payout);
                          }
                        },
                        child: const Text('Remplir 1234', style: TextStyle(fontWeight: FontWeight.bold)),
                      ),
                    ],
                  ),
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
                      final ok = ref.read(driverActiveJobProvider.notifier).verifyCode(val.trim());
                      if (ok) {
                        ref.read(driverProfileProvider.notifier).addEarnings(job.payout);
                      }
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
                    onPressed: () {
                      final input = _codeController.text.trim();
                      final ok = ref.read(driverActiveJobProvider.notifier).verifyCode(input);
                      if (ok) {
                        ref.read(driverProfileProvider.notifier).addEarnings(job.payout);
                      }
                    },
                    child: const Text('Valider la livraison', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 16, color: Colors.white)),
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildSecurityLockedView(BuildContext context) {
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
              '3 tentatives de code erronées consécutives. Par mesure de sécurité anti-fraude, la livraison est suspendue.',
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
                    const SnackBar(content: Text('Appel d\'urgence Support Daloa : +225 07 00 00 00 02')),
                  );
                },
                icon: const Icon(Icons.support_agent, color: Colors.white),
                label: const Text('Appeler le Support Daloa', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 16, color: Colors.white)),
              ),
            ),
            const SizedBox(height: 12),
            SizedBox(
              width: double.infinity,
              height: 48,
              child: OutlinedButton.icon(
                style: OutlinedButton.styleFrom(
                  foregroundColor: GoLivreurTheme.primaire,
                  side: const BorderSide(color: GoLivreurTheme.primaire, width: 1.5),
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                ),
                onPressed: () {
                  ref.read(driverActiveJobProvider.notifier).unblockSecurity();
                  ScaffoldMessenger.of(context).showSnackBar(
                    const SnackBar(content: Text('Course débloquée ! Code de validation : 1234')),
                  );
                },
                icon: const Icon(Icons.lock_open),
                label: const Text('Débloquer la course (Mode Test)', style: TextStyle(fontWeight: FontWeight.bold)),
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
                onPressed: () {
                  ref.read(driverActiveJobProvider.notifier).refuseJob();
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
                              profile.name,
                              style: const TextStyle(color: Colors.white, fontSize: 16, fontWeight: FontWeight.bold),
                            ),
                            Text(
                              'Moto: ${profile.motoPlate}',
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
                // Navigue vers l'onglet Profil
              },
            ),
            const Divider(height: 1),
            ListTile(
              leading: const Icon(Icons.account_balance_wallet, color: GoLivreurTheme.secondaire),
              title: const Text('Portefeuille & Retraits'),
              subtitle: const Text('Wave, Orange Money, historique'),
              onTap: () => Navigator.pop(context),
            ),
            ListTile(
              leading: const Icon(Icons.trending_up, color: GoLivreurTheme.secondaire),
              title: const Text('Historique des Gains'),
              onTap: () => Navigator.pop(context),
            ),
            const Divider(height: 1),
            ListTile(
              leading: const Icon(Icons.phone_in_talk, color: GoLivreurTheme.primaire),
              title: const Text('Support d\'urgence Daloa'),
              subtitle: const Text('+225 07 00 00 00 02'),
              onTap: () {
                Navigator.pop(context);
                ScaffoldMessenger.of(context).showSnackBar(
                  const SnackBar(content: Text('Appel d\'urgence Support Daloa : +225 07 00 00 00 02')),
                );
              },
            ),
            const Spacer(),
            const Divider(height: 1),
            ListTile(
              leading: const Icon(Icons.exit_to_app, color: GoLivreurTheme.erreur),
              title: const Text('Terminer mon service', style: TextStyle(color: GoLivreurTheme.erreur, fontWeight: FontWeight.bold)),
              onTap: () {
                Navigator.pop(context);
                Navigator.of(context).pushReplacementNamed('/');
              },
            ),
          ],
        ),
      ),
    );
  }
}
