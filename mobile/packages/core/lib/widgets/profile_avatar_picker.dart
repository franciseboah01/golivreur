import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';
import '../config/app_theme.dart';
import '../config/env_config.dart';

/// Composant réutilisable de photo / logo de profil partagé entre les 3 apps GoLivreur.
/// - Gère l'aperçu immédiat avant validation (`Uint8List?`)
/// - Supporte Galerie + Appareil photo (avec repli Web/Desktop transparent)
/// - Valide côté client le format (JPG/PNG/WEBP) et la taille maximale (2 Mo)
/// - Affiche un indicateur de chargement pendant l'upload
class ProfileAvatarPicker extends StatelessWidget {
  final String? currentImageUrl;
  final Uint8List? previewBytes;
  final String? fallbackEmoji;
  final IconData fallbackIcon;
  final Color accentColor;
  final String buttonLabel;
  final bool isUploading;
  final double size;
  final bool isSquare;
  final void Function(XFile file, Uint8List bytes) onImagePicked;

  const ProfileAvatarPicker({
    super.key,
    this.currentImageUrl,
    this.previewBytes,
    this.fallbackEmoji,
    this.fallbackIcon = Icons.person,
    this.accentColor = GoLivreurTheme.primaire,
    this.buttonLabel = 'Changer la photo',
    this.isUploading = false,
    this.size = 96,
    this.isSquare = false,
    required this.onImagePicked,
  });

  static const int maxImageBytes = 2 * 1024 * 1024; // 2 Mo
  static const List<String> allowedExtensions = ['jpg', 'jpeg', 'png', 'webp'];

  @override
  Widget build(BuildContext context) {
    final resolvedUrl = GoLivreurEnv.resolveMediaUrl(currentImageUrl);
    final radius =
        isSquare ? BorderRadius.circular(18) : BorderRadius.circular(size / 2);

    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        Stack(
          alignment: Alignment.center,
          children: [
            InkWell(
              onTap: isUploading ? null : () => _showPickerSheet(context),
              borderRadius: radius,
              child: Container(
                width: size,
                height: size,
                decoration: BoxDecoration(
                  color: accentColor.withValues(alpha: 0.12),
                  borderRadius: radius,
                  border: Border.all(
                    color: accentColor.withValues(alpha: 0.35),
                    width: 2,
                  ),
                ),
                clipBehavior: Clip.antiAlias,
                child: _buildPreviewContent(resolvedUrl),
              ),
            ),
            if (isUploading)
              Container(
                width: size,
                height: size,
                decoration: BoxDecoration(
                  color: Colors.black.withValues(alpha: 0.45),
                  borderRadius: radius,
                ),
                child: const Center(
                  child: CircularProgressIndicator(
                    color: Colors.white,
                    strokeWidth: 2.5,
                  ),
                ),
              ),
            if (!isUploading)
              Positioned(
                bottom: 0,
                right: 0,
                child: InkWell(
                  onTap: () => _showPickerSheet(context),
                  borderRadius: BorderRadius.circular(20),
                  child: Container(
                    padding: const EdgeInsets.all(6),
                    decoration: BoxDecoration(
                      color: accentColor,
                      shape: BoxShape.circle,
                      border: Border.all(color: Colors.white, width: 2),
                    ),
                    child: const Icon(
                      Icons.camera_alt,
                      size: 16,
                      color: Colors.white,
                    ),
                  ),
                ),
              ),
          ],
        ),
        const SizedBox(height: 8),
        TextButton.icon(
          onPressed: isUploading ? null : () => _showPickerSheet(context),
          icon:
              Icon(Icons.photo_library_outlined, size: 18, color: accentColor),
          label: Text(
            buttonLabel,
            style: TextStyle(
              color: accentColor,
              fontWeight: FontWeight.w600,
              fontSize: 13,
            ),
          ),
        ),
      ],
    );
  }

  Widget _buildPreviewContent(String? resolvedUrl) {
    if (previewBytes != null) {
      return Image.memory(
        previewBytes!,
        width: size,
        height: size,
        fit: BoxFit.cover,
      );
    }

    if (resolvedUrl != null && resolvedUrl.isNotEmpty) {
      return Image.network(
        resolvedUrl,
        width: size,
        height: size,
        fit: BoxFit.cover,
        errorBuilder: (_, __, ___) => _buildFallback(),
      );
    }

    return _buildFallback();
  }

  Widget _buildFallback() {
    if (fallbackEmoji != null && fallbackEmoji!.trim().isNotEmpty) {
      return Center(
        child: Text(
          fallbackEmoji!,
          style: TextStyle(fontSize: size * 0.42),
        ),
      );
    }

    return Icon(
      fallbackIcon,
      size: size * 0.46,
      color: accentColor,
    );
  }

  void _showPickerSheet(BuildContext context) {
    // Sur Web / Desktop, la galerie ouvre directement le sélecteur de fichiers natif
    if (kIsWeb ||
        (defaultTargetPlatform != TargetPlatform.android &&
            defaultTargetPlatform != TargetPlatform.iOS)) {
      _pickImage(context, ImageSource.gallery);
      return;
    }

    showModalBottomSheet(
      context: context,
      backgroundColor: Colors.white,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
      builder: (sheetCtx) => SafeArea(
        child: Padding(
          padding: const EdgeInsets.symmetric(vertical: 12),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              const Text(
                'Sélectionner une image',
                style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold),
              ),
              const SizedBox(height: 8),
              ListTile(
                leading: Icon(Icons.photo_library, color: accentColor),
                title: const Text('Choisir depuis la galerie'),
                onTap: () {
                  Navigator.pop(sheetCtx);
                  _pickImage(context, ImageSource.gallery);
                },
              ),
              ListTile(
                leading: Icon(Icons.camera_alt, color: accentColor),
                title: const Text('Prendre une photo avec la caméra'),
                onTap: () {
                  Navigator.pop(sheetCtx);
                  _pickImage(context, ImageSource.camera);
                },
              ),
            ],
          ),
        ),
      ),
    );
  }

  Future<void> _pickImage(BuildContext context, ImageSource source) async {
    try {
      final picker = ImagePicker();
      final picked = await picker.pickImage(
        source: source,
        maxWidth: 1024,
        maxHeight: 1024,
        imageQuality: 75,
        requestFullMetadata: false,
      );

      if (picked == null) return;

      final bytes = await picked.readAsBytes();
      final fileName = picked.name.toLowerCase();
      final ext = fileName.contains('.') ? fileName.split('.').last : '';

      if (ext.isNotEmpty && !allowedExtensions.contains(ext)) {
        if (context.mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(
              content: Text('Format non supporté. Utilisez JPG, PNG ou WEBP.'),
              backgroundColor: GoLivreurTheme.erreur,
            ),
          );
        }
        return;
      }

      if (bytes.lengthInBytes > maxImageBytes) {
        if (context.mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(
              content: Text(
                  'L\'image dépasse la taille maximale autorisée de 2 Mo.'),
              backgroundColor: GoLivreurTheme.erreur,
            ),
          );
        }
        return;
      }

      onImagePicked(picked, bytes);
    } catch (_) {
      if (context.mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text('Impossible d\'accéder à la sélection d\'image.'),
            backgroundColor: GoLivreurTheme.erreur,
          ),
        );
      }
    }
  }
}
