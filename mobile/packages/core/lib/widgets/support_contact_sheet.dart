import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import '../config/app_theme.dart';
import '../services/support_contact_service.dart';

/// Feuille modale partagée entre GL Client, GL Business et GL Driver affichant
/// le numéro de service client officiel de la ville, avec appel direct et copie.
class SupportContactSheet extends StatelessWidget {
  final String? cityName;
  final String supportPhone;
  final String title;
  final Color accentColor;

  const SupportContactSheet({
    super.key,
    required this.supportPhone,
    this.cityName,
    this.title = 'Service client GoLivreur',
    this.accentColor = GoLivreurTheme.secondaire,
  });

  static Future<void> show(
    BuildContext context, {
    required String? supportPhone,
    String? cityName,
    String title = 'Service client GoLivreur',
    Color accentColor = GoLivreurTheme.secondaire,
  }) {
    final effectivePhone =
        (supportPhone != null && supportPhone.trim().isNotEmpty)
            ? supportPhone.trim()
            : SupportContactService.defaultSupportPhone;

    return showModalBottomSheet<void>(
      context: context,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(22)),
      ),
      builder: (_) => SupportContactSheet(
        supportPhone: effectivePhone,
        cityName: cityName,
        title: title,
        accentColor: accentColor,
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final hasCity = cityName != null && cityName!.trim().isNotEmpty;

    return SafeArea(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(20, 16, 20, 24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Center(
              child: Container(
                width: 40,
                height: 4,
                decoration: BoxDecoration(
                  color: GoLivreurTheme.bordure,
                  borderRadius: BorderRadius.circular(2),
                ),
              ),
            ),
            const SizedBox(height: 16),
            Row(
              children: [
                CircleAvatar(
                  radius: 22,
                  backgroundColor: accentColor.withValues(alpha: 0.14),
                  child: Icon(Icons.support_agent, color: accentColor, size: 26),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        hasCity ? '$title ($cityName)' : title,
                        style: const TextStyle(
                          fontSize: 17,
                          fontWeight: FontWeight.bold,
                          color: GoLivreurTheme.textePrincipal,
                        ),
                      ),
                      const SizedBox(height: 2),
                      Text(
                        hasCity
                            ? 'Numéro officiel de votre agence $cityName'
                            : 'Numéro officiel d\'assistance GoLivreur',
                        style: const TextStyle(
                          fontSize: 12.5,
                          color: GoLivreurTheme.texteSecondaire,
                        ),
                      ),
                    ],
                  ),
                ),
              ],
            ),
            const SizedBox(height: 18),
            Container(
              padding: const EdgeInsets.symmetric(vertical: 16, horizontal: 14),
              decoration: BoxDecoration(
                color: GoLivreurTheme.fond,
                borderRadius: BorderRadius.circular(14),
                border: Border.all(color: GoLivreurTheme.bordure),
              ),
              child: Row(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  Icon(Icons.phone, color: accentColor, size: 22),
                  const SizedBox(width: 10),
                  SelectableText(
                    supportPhone,
                    style: const TextStyle(
                      fontSize: 20,
                      fontWeight: FontWeight.bold,
                      letterSpacing: 0.6,
                      color: GoLivreurTheme.textePrincipal,
                    ),
                  ),
                ],
              ),
            ),
            const SizedBox(height: 18),
            FilledButton.icon(
              style: FilledButton.styleFrom(
                backgroundColor: accentColor,
                padding: const EdgeInsets.symmetric(vertical: 14),
                shape: RoundedRectangleBorder(
                  borderRadius: BorderRadius.circular(12),
                ),
              ),
              onPressed: () async {
                final ok = await SupportContactService.callPhone(supportPhone);
                if (!ok && context.mounted) {
                  ScaffoldMessenger.of(context).showSnackBar(
                    SnackBar(
                      content: Text('Composez le $supportPhone pour joindre l\'agence.'),
                    ),
                  );
                }
              },
              icon: const Icon(Icons.phone_in_talk),
              label: const Text(
                'Appeler maintenant',
                style: TextStyle(fontSize: 15, fontWeight: FontWeight.bold),
              ),
            ),
            const SizedBox(height: 10),
            OutlinedButton.icon(
              style: OutlinedButton.styleFrom(
                padding: const EdgeInsets.symmetric(vertical: 13),
                shape: RoundedRectangleBorder(
                  borderRadius: BorderRadius.circular(12),
                ),
              ),
              onPressed: () async {
                await Clipboard.setData(ClipboardData(text: supportPhone));
                if (context.mounted) {
                  Navigator.of(context).pop();
                  ScaffoldMessenger.of(context).showSnackBar(
                    SnackBar(
                      content: Text('Numéro $supportPhone copié !'),
                      backgroundColor: GoLivreurTheme.secondaire,
                    ),
                  );
                }
              },
              icon: const Icon(Icons.copy, size: 18),
              label: const Text('Copier le numéro'),
            ),
          ],
        ),
      ),
    );
  }
}
