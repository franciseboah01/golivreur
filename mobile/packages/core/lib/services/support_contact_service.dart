import 'package:url_launcher/url_launcher.dart';

/// Utilitaire partagé pour lancer un appel téléphonique direct vers le service client.
class SupportContactService {
  static const String defaultSupportPhone = '+2250700000002';

  static Future<bool> callPhone(String? rawPhone) async {
    final cleaned = (rawPhone ?? defaultSupportPhone)
        .replaceAll(RegExp(r'[\s\.\-\(\)]+'), '')
        .trim();
    if (cleaned.isEmpty) return false;

    final telUri = Uri(scheme: 'tel', path: cleaned);
    try {
      if (await canLaunchUrl(telUri)) {
        return await launchUrl(telUri, mode: LaunchMode.externalApplication);
      }
      return await launchUrl(telUri, mode: LaunchMode.externalApplication);
    } catch (_) {
      return false;
    }
  }
}
