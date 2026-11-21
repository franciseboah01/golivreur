import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:golivreur_core/config/app_theme.dart';
import 'package:golivreur_core/services/api_client.dart';
import 'package:golivreur_core/services/gps_location_service.dart';
import 'package:golivreur_core/widgets/osm_live_tracking_map.dart';
import 'package:image_picker/image_picker.dart';
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
  final _imagePicker = ImagePicker();
  Timer? _countdownTimer;
  Timer? _pollTimer;
  int _secondsLeft = 30;
  int? _trackedIncomingJobId;
  DriverJobType? _trackedIncomingType;
  bool _verifying = false;
  bool _uploadingPhoto = false;
  bool _processingAction = false;

  @override
  void initState() {
    super.initState();
    _startPolling();
  }

  void _startPolling() {
    _pollTimer?.cancel();
    _pollTimer = Timer.periodic(const Duration(seconds: 6), (_) {
      if (!mounted) return;
      final profile = ref.read(driverProfileProvider);
      if (!profile.isOnline) return;
      final job = ref.read(driverActiveJobProvider);
      if (job == null ||
          job.step == DriverJobStep.incoming ||
          job.step == DriverJobStep.accepted) {
        ref.read(driverActiveJobProvider.notifier).chargerCourses();
      }
    });
  }

  void _syncIncomingCountdown(DriverJob? activeJob) {
    if (activeJob == null || activeJob.step != DriverJobStep.incoming) {
      _countdownTimer?.cancel();
      _countdownTimer = null;
      _trackedIncomingJobId = null;
      _trackedIncomingType = null;
      return;
    }

    if (_trackedIncomingJobId != activeJob.id ||
        _trackedIncomingType != activeJob.type ||
        _countdownTimer == null) {
      _trackedIncomingJobId = activeJob.id;
      _trackedIncomingType = activeJob.type;
      _secondsLeft = activeJob.offerRemainingSeconds > 0
          ? activeJob.offerRemainingSeconds
          : 30;
      _countdownTimer?.cancel();
      _countdownTimer = Timer.periodic(const Duration(seconds: 1), (timer) {
        if (!mounted) {
          timer.cancel();
          return;
        }
        if (_secondsLeft > 1) {
          setState(() => _secondsLeft--);
        } else {
          timer.cancel();
          _countdownTimer = null;
          ref.read(driverActiveJobProvider.notifier).chargerCourses();
        }
      });
    }
  }

  Future<void> _captureAndUploadParcelPhoto(String type) async {
    if (_uploadingPhoto) return;
    XFile? picked;
    try {
      picked = await _imagePicker.pickImage(
        source: ImageSource.camera,
        imageQuality: 82,
        maxWidth: 1600,
      );
    } catch (_) {
      picked = await _imagePicker.pickImage(
        source: ImageSource.gallery,
        imageQuality: 82,
        maxWidth: 1600,
      );
    }
    if (picked == null || !mounted) return;

    setState(() => _uploadingPhoto = true);
    final profile = ref.read(driverProfileProvider);
    final (ok, msg) = await ref
        .read(driverActiveJobProvider.notifier)
        .uploadParcelPhoto(
          type: type,
          photo: picked,
          lat: profile.currentLat,
          lng: profile.currentLng,
        );
    if (!mounted) return;
    setState(() => _uploadingPhoto = false);
    if (msg != null) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(msg),
          backgroundColor: ok ? GoLivreurTheme.succes : GoLivreurTheme.erreur,
        ),
      );
    }
  }

  @override
  void dispose() {
    _countdownTimer?.cancel();
    _pollTimer?.cancel();
    _codeController.dispose();
    super.dispose();
  }

  Future<void> _handleToggleOnline({bool loadJobsOnSuccess = false}) async {
    final (ok, msg) =
        await ref.read(driverProfileProvider.notifier).toggleOnline();
    if (!mounted) return;
    final updatedProfile = ref.read(driverProfileProvider);

    if (!ok) {
      if (updatedProfile.cityReidentificationRequired) {
        await showDialog<void>(
          context: context,
          builder: (dlgCtx) => AlertDialog(
            title: const Row(
              children: [
                Icon(Icons.verified_user_outlined, color: GoLivreurTheme.erreur),
                SizedBox(width: 8),
                Expanded(child: Text('Réidentification requise')),
              ],
            ),
            content: Text(
              msg ??
                  updatedProfile.cityReidentificationMessage ??
                  'Changement de ville détecté. Vous devez vous faire réidentifier par un administrateur GoLivreur avant de prendre service.',
            ),
            actions: [
              TextButton(
                onPressed: () => Navigator.pop(dlgCtx),
                child: const Text('Compris'),
              ),
            ],
          ),
        );
      } else if (msg != null) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(msg)),
        );
      }
      return;
    }

    if (loadJobsOnSuccess) {
      await ref.read(driverActiveJobProvider.notifier).chargerCourses();
    }
  }

  @override
  Widget build(BuildContext context) {
    final profile = ref.watch(driverProfileProvider);
    final activeJob = ref.watch(driverActiveJobProvider);
    _syncIncomingCountdown(activeJob);

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
              onTap: () => _handleToggleOnline(loadJobsOnSuccess: true),
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

    if (profile.cityReidentificationRequired) {
      return Center(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(24),
          child: Container(
            padding: const EdgeInsets.all(20),
            decoration: BoxDecoration(
              color: Colors.white,
              borderRadius: BorderRadius.circular(18),
              border: Border.all(
                color: GoLivreurTheme.erreur.withValues(alpha: 0.45),
                width: 1.5,
              ),
            ),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                Container(
                  padding: const EdgeInsets.all(16),
                  decoration: BoxDecoration(
                    color: GoLivreurTheme.erreur.withValues(alpha: 0.1),
                    shape: BoxShape.circle,
                  ),
                  child: const Icon(
                    Icons.badge_outlined,
                    size: 48,
                    color: GoLivreurTheme.erreur,
                  ),
                ),
                const SizedBox(height: 16),
                const Text(
                  'Réidentification Admin obligatoire',
                  textAlign: TextAlign.center,
                  style: TextStyle(
                    fontSize: 18,
                    fontWeight: FontWeight.bold,
                    color: GoLivreurTheme.erreur,
                  ),
                ),
                const SizedBox(height: 10),
                Text(
                  profile.cityReidentificationMessage ??
                      'Un changement de ville a été détecté par GPS. Présentez-vous à l\'agence GoLivreur pour vous faire réidentifier par un administrateur avant de prendre service.',
                  textAlign: TextAlign.center,
                  style: const TextStyle(
                    fontSize: 13.5,
                    height: 1.4,
                    color: GoLivreurTheme.textePrincipal,
                  ),
                ),
                const SizedBox(height: 20),
                SizedBox(
                  width: double.infinity,
                  child: ElevatedButton.icon(
                    style: ElevatedButton.styleFrom(
                      backgroundColor: GoLivreurTheme.secondaire,
                      foregroundColor: Colors.white,
                      padding: const EdgeInsets.symmetric(vertical: 12),
                    ),
                    onPressed: () async {
                      await ref.read(driverProfileProvider.notifier).chargerProfil();
                    },
                    icon: const Icon(Icons.refresh),
                    label: const Text(
                      'Vérifier ma réidentification',
                      style: TextStyle(fontWeight: FontWeight.bold),
                    ),
                  ),
                ),
              ],
            ),
          ),
        ),
      );
    }

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
              onPressed: () => _handleToggleOnline(loadJobsOnSuccess: true),
              child: const Text('Passer en ligne', style: TextStyle(fontWeight: FontWeight.bold, color: Colors.white)),
            ),
          ],
        ),
      ),
    );
  }

  Future<void> _openDriverPlanBSheet() async {
    final profile = ref.read(driverProfileProvider);
    final picked = await NeighborhoodPlanBSheet.show(
      context,
      title: 'Votre quartier actuel (Plan B Livreur)',
      subtitle: LocationService.standardUnavailableMessage,
      apiClient: ref.read(apiClientProvider),
      defaultCityName: profile.cityName,
      fallbackLat: profile.cityCenterLat,
      fallbackLng: profile.cityCenterLng,
      onRetryGps: _retryDriverGps,
    );

    if (picked == null || !mounted) return;
    final (ok, msg) = await ref
        .read(driverProfileProvider.notifier)
        .definirPositionQuartierPlanB(picked);
    if (!mounted) return;
    if (ok) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            '📍 Position Plan B enregistrée : quartier ${picked.name}. Vous êtes éligible aux courses.',
          ),
          backgroundColor: GoLivreurTheme.succes,
        ),
      );
      await ref.read(driverActiveJobProvider.notifier).chargerCourses();
    } else if (msg != null) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(msg),
          backgroundColor: GoLivreurTheme.erreur,
        ),
      );
    }
  }

  Future<void> _retryDriverGps() async {
    final (ok, _) = await ref
        .read(driverProfileProvider.notifier)
        .envoyerPositionGps(silent: false);
    if (!mounted) return;
    if (ok) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('📍 Position GPS synchronisée avec le serveur.'),
          backgroundColor: GoLivreurTheme.succes,
        ),
      );
    } else {
      final failure = ref.read(driverProfileProvider).lastGpsFailure;
      LocationService.afficherAlerteNonBloquante(
        context,
        result: failure,
        onRetry: _retryDriverGps,
        onManualFallback: _openDriverPlanBSheet,
      );
      await _openDriverPlanBSheet();
    }
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
        padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 48),
        children: [
          if (profile.lastGpsFailure != null ||
              profile.manualNeighborhoodName != null) ...[
            GpsFallbackBanner(
              lastResult: profile.lastGpsFailure,
              onRetry: _retryDriverGps,
              onSelectNeighborhood: _openDriverPlanBSheet,
              manualActionLabel: 'Choisir mon quartier (Plan B)',
              selectedManualLabel: profile.manualNeighborhoodName,
            ),
            const SizedBox(height: 20),
          ],
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
          Wrap(
            alignment: WrapAlignment.center,
            spacing: 10,
            runSpacing: 10,
            children: [
              OutlinedButton.icon(
                onPressed: () {
                  ref.read(driverActiveJobProvider.notifier).chargerCourses();
                },
                icon: const Icon(Icons.refresh),
                label: const Text('Actualiser les courses'),
              ),
              OutlinedButton.icon(
                onPressed: _retryDriverGps,
                icon: const Icon(Icons.my_location, color: GoLivreurTheme.secondaire),
                label: Text(
                  profile.manualNeighborhoodName != null
                      ? 'Plan B : ${profile.manualNeighborhoodName}'
                      : (profile.currentLat != null
                          ? 'GPS actif ✓'
                          : 'Actualiser GPS'),
                ),
              ),
              OutlinedButton.icon(
                onPressed: _openDriverPlanBSheet,
                icon: const Icon(Icons.location_city_outlined),
                label: const Text('Quartier (Plan B)'),
              ),
            ],
          ),
          const SizedBox(height: 18),
          Container(
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(
              color: Colors.amber.shade50,
              borderRadius: BorderRadius.circular(12),
              border: Border.all(color: Colors.amber.shade200),
            ),
            child: const Row(
              children: [
                Icon(Icons.battery_saver_outlined, size: 18, color: Colors.orange),
                SizedBox(width: 8),
                Expanded(
                  child: Text(
                    'Astuce : désactivez l\'optimisation batterie pour GoLivreur Driver dans les paramètres Android afin de recevoir les courses même écran verrouillé.',
                    style: TextStyle(fontSize: 11.5, color: GoLivreurTheme.textePrincipal),
                  ),
                ),
              ],
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
          profile,
          title: 'Aller au commerce / expéditeur',
          destination: job.pickupAddress,
          buttonLabel: 'Je suis arrivé sur place',
          onAction: () async {
            final (ok, msg) = await ref
                .read(driverActiveJobProvider.notifier)
                .arrivedAtMerchant();
            if (!context.mounted) return;
            if (!ok && msg != null) {
              ScaffoldMessenger.of(context).showSnackBar(
                SnackBar(
                  content: Text(msg),
                  backgroundColor: GoLivreurTheme.erreur,
                ),
              );
            }
          },
        );
      case DriverJobStep.atMerchant:
        return _buildAtMerchantStep(context, ref, job);
      case DriverJobStep.inTransit:
        return _buildDeliveryStep(context, ref, job, profile);
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
                          onPressed: _processingAction
                              ? null
                              : () async {
                                  _countdownTimer?.cancel();
                                  setState(() => _processingAction = true);
                                  final (_, msg) = await ref
                                      .read(driverActiveJobProvider.notifier)
                                      .refuseJob();
                                  await ref
                                      .read(driverProfileProvider.notifier)
                                      .chargerProfil();
                                  if (!context.mounted) return;
                                  setState(() => _processingAction = false);
                                  if (msg != null && msg.isNotEmpty) {
                                    ScaffoldMessenger.of(context).showSnackBar(
                                      SnackBar(content: Text(msg)),
                                    );
                                  }
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
                          onPressed: _processingAction
                              ? null
                              : () async {
                                  _countdownTimer?.cancel();
                                  setState(() => _processingAction = true);
                                  final (ok, msg) = await ref
                                      .read(driverActiveJobProvider.notifier)
                                      .acceptJob();
                                  if (!context.mounted) return;
                                  setState(() => _processingAction = false);
                                  if (!ok && msg != null) {
                                    ScaffoldMessenger.of(context).showSnackBar(
                                      SnackBar(
                                        content: Text(msg),
                                        backgroundColor: GoLivreurTheme.erreur,
                                      ),
                                    );
                                  }
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
    DriverJob job,
    DriverProfileState profile, {
    required String title,
    required String destination,
    required String buttonLabel,
    required VoidCallback onAction,
  }) {
    final targetLat = job.pickupLat;
    final targetLng = job.pickupLng;

    return Column(
      children: [
        // Carte OpenStreetMap temps réel (100 % gratuite)
        Expanded(
          child: OsmLiveTrackingMap(
            courierLat: profile.currentLat,
            courierLng: profile.currentLng,
            pickupLat: job.pickupLat,
            pickupLng: job.pickupLng,
            destinationLat: job.pickupLat,
            destinationLng: job.pickupLng,
            fallbackLat: profile.cityCenterLat ?? 6.8770,
            fallbackLng: profile.cityCenterLng ?? -6.4502,
            destinationLabel: 'Ramassage',
          ),
        ),
        // Panneau d'action bas
        Container(
          padding: const EdgeInsets.all(16),
          color: Colors.white,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisSize: MainAxisSize.min,
            children: [
              Row(
                children: [
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          title,
                          style: const TextStyle(
                            fontSize: 15,
                            fontWeight: FontWeight.bold,
                            color: GoLivreurTheme.textePrincipal,
                          ),
                        ),
                        const SizedBox(height: 2),
                        Text(
                          destination,
                          maxLines: 2,
                          overflow: TextOverflow.ellipsis,
                          style: const TextStyle(
                            fontSize: 13,
                            color: GoLivreurTheme.texteSecondaire,
                          ),
                        ),
                      ],
                    ),
                  ),
                  if (targetLat != null && targetLng != null) ...[
                    const SizedBox(width: 8),
                    OutlinedButton.icon(
                      style: OutlinedButton.styleFrom(
                        foregroundColor: GoLivreurTheme.secondaire,
                        side: const BorderSide(color: GoLivreurTheme.secondaire),
                        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 10),
                      ),
                      onPressed: () {
                        GpsLocationService.ouvrirNavigationExterne(
                          targetLat,
                          targetLng,
                          label: destination,
                        );
                      },
                      icon: const Icon(Icons.directions, size: 18),
                      label: const Text(
                        'Itinéraire',
                        style: TextStyle(fontWeight: FontWeight.bold, fontSize: 12),
                      ),
                    ),
                  ],
                ],
              ),
              const SizedBox(height: 10),
              _buildCourseInfoStrip(job),
              const SizedBox(height: 12),
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
          const SizedBox(height: 10),
          _buildCourseInfoStrip(job),
          const SizedBox(height: 12),
          if (isGoColis) ...[
            Container(
              padding: const EdgeInsets.all(16),
              decoration: BoxDecoration(
                color: Colors.white,
                borderRadius: BorderRadius.circular(14),
                border: Border.all(
                  color: job.pickupPhotoUploaded
                      ? GoLivreurTheme.succes
                      : GoLivreurTheme.bordure,
                ),
              ),
              child: Column(
                children: [
                  Icon(
                    job.pickupPhotoUploaded
                        ? Icons.check_circle
                        : Icons.camera_alt_outlined,
                    size: 40,
                    color: job.pickupPhotoUploaded
                        ? GoLivreurTheme.succes
                        : GoLivreurTheme.secondaire,
                  ),
                  const SizedBox(height: 8),
                  Text(
                    job.pickupPhotoUploaded
                        ? 'Photo de ramassage enregistrée ✓'
                        : 'Photo obligatoire du colis scellé',
                    style: const TextStyle(fontWeight: FontWeight.bold),
                  ),
                  const SizedBox(height: 4),
                  const Text(
                    'Photographiez le colis avec l\'étiquette bien visible avant de saisir le code expéditeur',
                    textAlign: TextAlign.center,
                    style: TextStyle(
                      fontSize: 12,
                      color: GoLivreurTheme.texteSecondaire,
                    ),
                  ),
                  const SizedBox(height: 12),
                  OutlinedButton.icon(
                    onPressed: _uploadingPhoto
                        ? null
                        : () => _captureAndUploadParcelPhoto('pickup'),
                    icon: _uploadingPhoto
                        ? const SizedBox(
                            width: 16,
                            height: 16,
                            child: CircularProgressIndicator(strokeWidth: 2),
                          )
                        : const Icon(Icons.camera_alt),
                    label: Text(
                      job.pickupPhotoUploaded
                          ? 'Reprendre la photo'
                          : 'Prendre la photo',
                    ),
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

  Widget _buildDeliveryStep(
    BuildContext context,
    WidgetRef ref,
    DriverJob job,
    DriverProfileState profile,
  ) {
    final destLat = job.deliveryLat;
    final destLng = job.deliveryLng;

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
          const SizedBox(height: 10),
          _buildCourseInfoStrip(job),
          const SizedBox(height: 12),
          ClipRRect(
            borderRadius: BorderRadius.circular(16),
            child: SizedBox(
              height: 200,
              width: double.infinity,
              child: OsmLiveTrackingMap(
                courierLat: profile.currentLat,
                courierLng: profile.currentLng,
                pickupLat: job.pickupLat,
                pickupLng: job.pickupLng,
                destinationLat: destLat,
                destinationLng: destLng,
                fallbackLat: profile.cityCenterLat ?? 6.8770,
                fallbackLng: profile.cityCenterLng ?? -6.4502,
                destinationLabel: 'Client',
              ),
            ),
          ),
          if (destLat != null && destLng != null) ...[
            const SizedBox(height: 10),
            SizedBox(
              width: double.infinity,
              child: OutlinedButton.icon(
                style: OutlinedButton.styleFrom(
                  foregroundColor: GoLivreurTheme.secondaire,
                  side: const BorderSide(color: GoLivreurTheme.secondaire),
                  padding: const EdgeInsets.symmetric(vertical: 12),
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                ),
                onPressed: () {
                  GpsLocationService.ouvrirNavigationExterne(
                    destLat,
                    destLng,
                    label: job.deliveryAddress,
                  );
                },
                icon: const Icon(Icons.directions),
                label: const Text(
                  'Ouvrir l\'itinéraire GPS vers le client',
                  style: TextStyle(fontWeight: FontWeight.bold),
                ),
              ),
            ),
          ],
          const SizedBox(height: 16),
          if (job.type == DriverJobType.gocolis) ...[
            Container(
              padding: const EdgeInsets.all(16),
              decoration: BoxDecoration(
                color: Colors.white,
                borderRadius: BorderRadius.circular(16),
                border: Border.all(
                  color: (job.atRecipientReached && job.deliveryPhotoUploaded)
                      ? GoLivreurTheme.succes
                      : GoLivreurTheme.bordure,
                ),
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  const Text(
                    'Étapes obligatoires avant remise du colis :',
                    style: TextStyle(fontWeight: FontWeight.bold, fontSize: 14),
                  ),
                  const SizedBox(height: 10),
                  OutlinedButton.icon(
                    style: OutlinedButton.styleFrom(
                      foregroundColor: job.atRecipientReached
                          ? GoLivreurTheme.succes
                          : GoLivreurTheme.secondaire,
                      side: BorderSide(
                        color: job.atRecipientReached
                            ? GoLivreurTheme.succes
                            : GoLivreurTheme.secondaire,
                      ),
                    ),
                    onPressed: (_processingAction || job.atRecipientReached)
                        ? null
                        : () async {
                            setState(() => _processingAction = true);
                            final (ok, msg) = await ref
                                .read(driverActiveJobProvider.notifier)
                                .arrivedAtRecipient();
                            if (!context.mounted) return;
                            setState(() => _processingAction = false);
                            if (msg != null) {
                              ScaffoldMessenger.of(context).showSnackBar(
                                SnackBar(
                                  content: Text(msg),
                                  backgroundColor: ok
                                      ? GoLivreurTheme.succes
                                      : GoLivreurTheme.erreur,
                                ),
                              );
                            }
                          },
                    icon: Icon(
                      job.atRecipientReached
                          ? Icons.check_circle
                          : Icons.place_outlined,
                    ),
                    label: Text(
                      job.atRecipientReached
                          ? '1. Arrivée chez le destinataire enregistrée ✓'
                          : '1. Signaler mon arrivée chez le destinataire',
                    ),
                  ),
                  const SizedBox(height: 8),
                  OutlinedButton.icon(
                    style: OutlinedButton.styleFrom(
                      foregroundColor: job.deliveryPhotoUploaded
                          ? GoLivreurTheme.succes
                          : GoLivreurTheme.secondaire,
                      side: BorderSide(
                        color: job.deliveryPhotoUploaded
                            ? GoLivreurTheme.succes
                            : GoLivreurTheme.secondaire,
                      ),
                    ),
                    onPressed: (!job.atRecipientReached || _uploadingPhoto)
                        ? null
                        : () => _captureAndUploadParcelPhoto('delivery'),
                    icon: _uploadingPhoto
                        ? const SizedBox(
                            width: 16,
                            height: 16,
                            child: CircularProgressIndicator(strokeWidth: 2),
                          )
                        : Icon(
                            job.deliveryPhotoUploaded
                                ? Icons.check_circle
                                : Icons.camera_alt_outlined,
                          ),
                    label: Text(
                      job.deliveryPhotoUploaded
                          ? '2. Photo de remise enregistrée ✓'
                          : '2. Prendre la photo de remise du colis',
                    ),
                  ),
                ],
              ),
            ),
            const SizedBox(height: 16),
          ],
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

  Widget _buildCourseInfoStrip(DriverJob job) {
    final rows = <Widget>[];
    if (job.parcelSize != null && job.parcelSize!.isNotEmpty) {
      rows.add(_infoChip(Icons.inventory_2_outlined, 'Taille : ${job.parcelSize}'));
    }
    if (job.pickupContactName != null || job.pickupContactPhone != null) {
      final label = [
        if (job.pickupContactName != null) job.pickupContactName!,
        if (job.pickupContactPhone != null) job.pickupContactPhone!,
      ].join(' • ');
      rows.add(_infoChip(Icons.storefront_outlined, 'Ramassage : $label'));
    }
    if (job.deliveryContactName != null || job.deliveryContactPhone != null) {
      final label = [
        if (job.deliveryContactName != null) job.deliveryContactName!,
        if (job.deliveryContactPhone != null) job.deliveryContactPhone!,
      ].join(' • ');
      rows.add(_infoChip(Icons.person_pin_circle_outlined, 'Client : $label'));
    }
    if (job.amountToCollect > 0) {
      rows.add(
        _infoChip(
          Icons.payments_outlined,
          'À encaisser en espèces : ${job.amountToCollect.toInt()} F CFA',
          highlight: true,
        ),
      );
    } else if (job.paymentMethod != null && job.paymentMethod != 'cash') {
      rows.add(_infoChip(Icons.verified_outlined, 'Déjà payé en ligne'));
    }
    if (job.instructions != null && job.instructions!.isNotEmpty) {
      rows.add(_infoChip(Icons.notes_outlined, 'Instructions : ${job.instructions}'));
    }
    if (rows.isEmpty) return const SizedBox.shrink();

    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(10),
      decoration: BoxDecoration(
        color: GoLivreurTheme.fond,
        borderRadius: BorderRadius.circular(10),
        border: Border.all(color: GoLivreurTheme.bordure),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: rows,
      ),
    );
  }

  Widget _infoChip(IconData icon, String text, {bool highlight = false}) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 2),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(
            icon,
            size: 15,
            color: highlight ? GoLivreurTheme.primaire : GoLivreurTheme.texteSecondaire,
          ),
          const SizedBox(width: 6),
          Expanded(
            child: Text(
              text,
              style: TextStyle(
                fontSize: 12,
                fontWeight: highlight ? FontWeight.bold : FontWeight.w500,
                color: highlight
                    ? GoLivreurTheme.primaire
                    : GoLivreurTheme.textePrincipal,
              ),
            ),
          ),
        ],
      ),
    );
  }
}
