import 'dart:typed_data';

import 'package:camera/camera.dart';
import 'package:flutter/material.dart';
import 'package:golivreur_core/config/app_theme.dart';

/// Caméra intégrée (in-app) pour capturer la photo du colis sans quitter GL Client.
///
/// Contrairement à `ImagePicker(source: ImageSource.camera)` qui lance l'application
/// Appareil Photo OEM (gourmande en RAM et susceptible de faire tuer `MainActivity` par
/// Android), cet écran conserve toute la capture dans le processus léger de GL Client
/// (`ResolutionPreset.medium`, audio désactivé) — zéro redémarrage d'application.
class InAppCameraScreen extends StatefulWidget {
  const InAppCameraScreen({super.key});

  /// Ouvre la caméra intégrée et retourne le fichier [XFile] capturé, ou `null` si annulé.
  static Future<XFile?> capture(BuildContext context) {
    return Navigator.of(context).push<XFile?>(
      MaterialPageRoute(
        fullscreenDialog: true,
        builder: (_) => const InAppCameraScreen(),
      ),
    );
  }

  @override
  State<InAppCameraScreen> createState() => _InAppCameraScreenState();
}

class _InAppCameraScreenState extends State<InAppCameraScreen>
    with WidgetsBindingObserver {
  CameraController? _controller;
  List<CameraDescription> _cameras = const [];
  int _selectedCameraIndex = 0;

  bool _initializing = true;
  bool _capturing = false;
  String? _errorMessage;
  FlashMode _flashMode = FlashMode.auto;

  XFile? _capturedFile;
  Uint8List? _capturedBytes;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _initCamera();
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _controller?.dispose();
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    final controller = _controller;
    if (controller == null || !controller.value.isInitialized) return;

    if (state == AppLifecycleState.inactive) {
      controller.dispose();
      _controller = null;
    } else if (state == AppLifecycleState.resumed && _capturedFile == null) {
      _startControllerForIndex(_selectedCameraIndex);
    }
  }

  Future<void> _initCamera() async {
    setState(() {
      _initializing = true;
      _errorMessage = null;
    });

    try {
      final cameras = await availableCameras();
      if (!mounted) return;
      if (cameras.isEmpty) {
        setState(() {
          _initializing = false;
          _errorMessage = 'Aucun appareil photo détecté sur cet appareil.';
        });
        return;
      }

      final backIndex = cameras.indexWhere(
        (c) => c.lensDirection == CameraLensDirection.back,
      );
      _cameras = cameras;
      _selectedCameraIndex = backIndex >= 0 ? backIndex : 0;
      await _startControllerForIndex(_selectedCameraIndex);
    } on CameraException catch (e) {
      if (!mounted) return;
      final isDenied = e.code.toLowerCase().contains('access') ||
          e.code.toLowerCase().contains('permission');
      setState(() {
        _initializing = false;
        _errorMessage = isDenied
            ? 'Autorisation caméra refusée. Activez l\'accès à l\'appareil photo dans les paramètres du téléphone.'
            : 'Impossible de démarrer la caméra (${e.description ?? e.code}).';
      });
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _initializing = false;
        _errorMessage =
            'Impossible d\'accéder à la caméra pour le moment. Réessayez ou choisissez une image depuis la galerie.';
      });
    }
  }

  Future<void> _startControllerForIndex(int index) async {
    final previous = _controller;
    _controller = null;
    await previous?.dispose();

    final controller = CameraController(
      _cameras[index],
      ResolutionPreset.medium,
      enableAudio: false,
      imageFormatGroup: ImageFormatGroup.jpeg,
    );

    try {
      await controller.initialize();
      try {
        await controller.setFlashMode(_flashMode);
      } catch (_) {}

      if (!mounted) {
        await controller.dispose();
        return;
      }

      setState(() {
        _controller = controller;
        _initializing = false;
        _errorMessage = null;
      });
    } on CameraException catch (e) {
      await controller.dispose();
      if (!mounted) return;
      setState(() {
        _initializing = false;
        _errorMessage = e.code.toLowerCase().contains('access') ||
                e.code.toLowerCase().contains('permission')
            ? 'Autorisation caméra refusée. Activez l\'accès appareil photo dans les paramètres Android.'
            : 'Erreur d\'initialisation de la caméra (${e.description ?? e.code}).';
      });
    }
  }

  Future<void> _cycleFlashMode() async {
    final controller = _controller;
    if (controller == null || !controller.value.isInitialized) return;

    final nextMode = switch (_flashMode) {
      FlashMode.auto => FlashMode.always,
      FlashMode.always => FlashMode.off,
      _ => FlashMode.auto,
    };

    try {
      await controller.setFlashMode(nextMode);
      if (mounted) {
        setState(() => _flashMode = nextMode);
      }
    } catch (_) {}
  }

  Future<void> _takePhoto() async {
    final controller = _controller;
    if (_capturing || controller == null || !controller.value.isInitialized) {
      return;
    }

    setState(() => _capturing = true);
    try {
      final shot = await controller.takePicture();
      final bytes = await shot.readAsBytes();
      if (!mounted) return;
      setState(() {
        _capturedFile = shot;
        _capturedBytes = bytes;
        _capturing = false;
      });
    } catch (_) {
      if (!mounted) return;
      setState(() => _capturing = false);
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Impossible de capturer la photo. Veuillez réessayer.'),
        ),
      );
    }
  }

  void _retake() {
    setState(() {
      _capturedFile = null;
      _capturedBytes = null;
    });
  }

  IconData get _flashIcon => switch (_flashMode) {
        FlashMode.auto => Icons.flash_auto,
        FlashMode.always => Icons.flash_on,
        _ => Icons.flash_off,
      };

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: Colors.black,
      body: SafeArea(
        child: _capturedBytes != null && _capturedFile != null
            ? _buildPreviewConfirmation()
            : _buildLiveViewfinder(),
      ),
    );
  }

  Widget _buildPreviewConfirmation() {
    return Column(
      children: [
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
          child: Row(
            children: [
              IconButton(
                onPressed: _retake,
                icon: const Icon(Icons.arrow_back, color: Colors.white),
                tooltip: 'Reprendre',
              ),
              const Expanded(
                child: Text(
                  'Aperçu de la photo du colis',
                  style: TextStyle(
                    color: Colors.white,
                    fontSize: 16,
                    fontWeight: FontWeight.bold,
                  ),
                ),
              ),
            ],
          ),
        ),
        Expanded(
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: ClipRRect(
              borderRadius: BorderRadius.circular(18),
              child: Image.memory(
                _capturedBytes!,
                fit: BoxFit.contain,
              ),
            ),
          ),
        ),
        Padding(
          padding: const EdgeInsets.fromLTRB(20, 8, 20, 20),
          child: Row(
            children: [
              Expanded(
                child: OutlinedButton.icon(
                  style: OutlinedButton.styleFrom(
                    foregroundColor: Colors.white,
                    side: const BorderSide(color: Colors.white54),
                    padding: const EdgeInsets.symmetric(vertical: 14),
                    shape: RoundedRectangleBorder(
                      borderRadius: BorderRadius.circular(14),
                    ),
                  ),
                  onPressed: _retake,
                  icon: const Icon(Icons.refresh),
                  label: const Text('Reprendre'),
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                flex: 2,
                child: ElevatedButton.icon(
                  style: ElevatedButton.styleFrom(
                    backgroundColor: GoLivreurTheme.secondaire,
                    foregroundColor: Colors.white,
                    padding: const EdgeInsets.symmetric(vertical: 14),
                    shape: RoundedRectangleBorder(
                      borderRadius: BorderRadius.circular(14),
                    ),
                  ),
                  onPressed: () => Navigator.of(context).pop(_capturedFile),
                  icon: const Icon(Icons.check_circle_outline),
                  label: const Text(
                    'Utiliser cette photo',
                    style: TextStyle(fontWeight: FontWeight.bold),
                  ),
                ),
              ),
            ],
          ),
        ),
      ],
    );
  }

  Widget _buildLiveViewfinder() {
    final controller = _controller;
    final isReady =
        !_initializing && controller != null && controller.value.isInitialized;

    return Stack(
      fit: StackFit.expand,
      children: [
        if (isReady)
          Center(
            child: CameraPreview(controller),
          )
        else if (_initializing)
          const Center(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                CircularProgressIndicator(color: GoLivreurTheme.primaire),
                SizedBox(height: 14),
                Text(
                  'Ouverture de la caméra...',
                  style: TextStyle(color: Colors.white70, fontSize: 14),
                ),
              ],
            ),
          )
        else
          Center(
            child: Padding(
              padding: const EdgeInsets.all(24),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  const Icon(
                    Icons.no_photography_outlined,
                    color: GoLivreurTheme.primaire,
                    size: 52,
                  ),
                  const SizedBox(height: 14),
                  Text(
                    _errorMessage ?? 'Caméra indisponible.',
                    textAlign: TextAlign.center,
                    style: const TextStyle(color: Colors.white, fontSize: 14.5),
                  ),
                  const SizedBox(height: 20),
                  ElevatedButton.icon(
                    style: ElevatedButton.styleFrom(
                      backgroundColor: GoLivreurTheme.primaire,
                      foregroundColor: Colors.black87,
                    ),
                    onPressed: _initCamera,
                    icon: const Icon(Icons.refresh),
                    label: const Text('Réessayer'),
                  ),
                ],
              ),
            ),
          ),

        // Barre supérieure (Fermer + Titre + Flash)
        Positioned(
          top: 8,
          left: 12,
          right: 12,
          child: Row(
            children: [
              CircleAvatar(
                backgroundColor: Colors.black54,
                child: IconButton(
                  onPressed: () => Navigator.of(context).pop(null),
                  icon: const Icon(Icons.close, color: Colors.white),
                  tooltip: 'Fermer',
                ),
              ),
              const SizedBox(width: 12),
              const Expanded(
                child: Text(
                  'Photo du colis',
                  style: TextStyle(
                    color: Colors.white,
                    fontSize: 16,
                    fontWeight: FontWeight.bold,
                    shadows: [Shadow(blurRadius: 6, color: Colors.black87)],
                  ),
                ),
              ),
              if (isReady)
                CircleAvatar(
                  backgroundColor: Colors.black54,
                  child: IconButton(
                    onPressed: _cycleFlashMode,
                    icon: Icon(_flashIcon, color: Colors.white),
                    tooltip: 'Flash',
                  ),
                ),
            ],
          ),
        ),

        // Cadre indicatif + bouton déclencheur
        if (isReady)
          Positioned(
            left: 0,
            right: 0,
            bottom: 24,
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                Container(
                  padding: const EdgeInsets.symmetric(
                    horizontal: 14,
                    vertical: 6,
                  ),
                  decoration: BoxDecoration(
                    color: Colors.black54,
                    borderRadius: BorderRadius.circular(20),
                  ),
                  child: const Text(
                    'Placez bien le colis dans le cadre',
                    style: TextStyle(color: Colors.white, fontSize: 12.5),
                  ),
                ),
                const SizedBox(height: 18),
                GestureDetector(
                  onTap: _capturing ? null : _takePhoto,
                  child: Container(
                    width: 76,
                    height: 76,
                    decoration: BoxDecoration(
                      shape: BoxShape.circle,
                      border: Border.all(
                        color: GoLivreurTheme.primaire,
                        width: 4,
                      ),
                    ),
                    padding: const EdgeInsets.all(5),
                    child: Container(
                      decoration: BoxDecoration(
                        shape: BoxShape.circle,
                        color: _capturing ? Colors.white54 : Colors.white,
                      ),
                      child: _capturing
                          ? const Padding(
                              padding: EdgeInsets.all(18),
                              child: CircularProgressIndicator(
                                strokeWidth: 2.5,
                                color: GoLivreurTheme.primaire,
                              ),
                            )
                          : null,
                    ),
                  ),
                ),
              ],
            ),
          ),
      ],
    );
  }
}
