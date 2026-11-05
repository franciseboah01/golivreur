import 'package:flutter/material.dart';

/// Thème global GoLivreur.
/// Palette officielle validée par Francis EBOAH (Fondateur).
///
/// Couleurs :
///   Primaire  : Orange  #F4A623 — boutons CTA, éléments actifs
///   Secondaire: Vert    #1B5E20 — fiabilité, livraison réussie
///   Fond      : #FAFAFA — blanc cassé, plus doux que le blanc pur
///   Texte     : #212121 — gris très foncé, meilleur contraste
///   Erreur    : #D32F2F — rouge erreur
///   Succès    : #4CAF50 — vert succès
///
/// Police : Inter — lisible sur Tecno Spark et Infinix Hot entrée de gamme.
class GoLivreurTheme {
  // ─── Couleurs officielles ──────────────────────────────────────────────────

  static const Color primaire = Color(0xFFF4A623); // Orange
  static const Color secondaire = Color(0xFF1B5E20); // Vert foncé
  static const Color fond = Color(0xFFFAFAFA); // Blanc cassé
  static const Color textePrincipal = Color(0xFF212121); // Gris très foncé
  static const Color texteSecondaire = Color(0xFF757575); // Gris moyen
  static const Color erreur = Color(0xFFD32F2F); // Rouge
  static const Color succes = Color(0xFF4CAF50); // Vert succès
  static const Color avertissement = Color(0xFFFFC107); // Jaune
  static const Color surface = Color(0xFFFFFFFF); // Blanc pur (cartes)
  static const Color bordure = Color(0xFFE0E0E0); // Gris léger

  // ─── ThemeData complet ────────────────────────────────────────────────────

  static ThemeData get theme => ThemeData(
        useMaterial3: true,
        colorScheme: ColorScheme.fromSeed(
          seedColor: primaire,
          primary: primaire,
          secondary: secondaire,
          surface: surface,
          error: erreur,
          onPrimary: Colors.white,
          onSecondary: Colors.white,
          onSurface: textePrincipal,
          onError: Colors.white,
          brightness: Brightness.light,
        ),

        // Police Inter sur tous les textes
        fontFamily: 'Inter',

        scaffoldBackgroundColor: fond,

        // AppBar
        appBarTheme: const AppBarTheme(
          backgroundColor: surface,
          foregroundColor: textePrincipal,
          elevation: 0,
          centerTitle: false,
          titleTextStyle: TextStyle(
            fontFamily: 'Inter',
            fontSize: 18,
            fontWeight: FontWeight.w600,
            color: textePrincipal,
          ),
          iconTheme: IconThemeData(color: textePrincipal),
        ),

        // Boutons primaires — Orange, larges, faciles à taper avec le pouce
        elevatedButtonTheme: ElevatedButtonThemeData(
          style: ElevatedButton.styleFrom(
            backgroundColor: primaire,
            foregroundColor: Colors.white,
            minimumSize: const Size(double.infinity, 52),
            shape: RoundedRectangleBorder(
              borderRadius: BorderRadius.circular(10),
            ),
            textStyle: const TextStyle(
              fontFamily: 'Inter',
              fontSize: 16,
              fontWeight: FontWeight.w600,
            ),
            elevation: 0,
          ),
        ),

        // Boutons outline
        outlinedButtonTheme: OutlinedButtonThemeData(
          style: OutlinedButton.styleFrom(
            foregroundColor: primaire,
            minimumSize: const Size(double.infinity, 52),
            side: const BorderSide(color: primaire, width: 1.5),
            shape: RoundedRectangleBorder(
              borderRadius: BorderRadius.circular(10),
            ),
          ),
        ),

        // Boutons texte
        textButtonTheme: TextButtonThemeData(
          style: TextButton.styleFrom(
            foregroundColor: primaire,
            textStyle: const TextStyle(
              fontFamily: 'Inter',
              fontWeight: FontWeight.w500,
            ),
          ),
        ),

        // Champs de saisie
        inputDecorationTheme: InputDecorationTheme(
          filled: true,
          fillColor: surface,
          contentPadding:
              const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
          border: OutlineInputBorder(
            borderRadius: BorderRadius.circular(10),
            borderSide: const BorderSide(color: bordure),
          ),
          enabledBorder: OutlineInputBorder(
            borderRadius: BorderRadius.circular(10),
            borderSide: const BorderSide(color: bordure),
          ),
          focusedBorder: OutlineInputBorder(
            borderRadius: BorderRadius.circular(10),
            borderSide: const BorderSide(color: primaire, width: 2),
          ),
          errorBorder: OutlineInputBorder(
            borderRadius: BorderRadius.circular(10),
            borderSide: const BorderSide(color: erreur),
          ),
          labelStyle:
              const TextStyle(color: texteSecondaire, fontFamily: 'Inter'),
          hintStyle:
              const TextStyle(color: texteSecondaire, fontFamily: 'Inter'),
        ),

        // Cartes
        cardTheme: CardThemeData(
          color: surface,
          elevation: 1,
          shadowColor: Colors.black12,
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(12),
          ),
          margin: const EdgeInsets.symmetric(horizontal: 16, vertical: 6),
        ),

        // Bottom Navigation Bar
        bottomNavigationBarTheme: const BottomNavigationBarThemeData(
          backgroundColor: surface,
          selectedItemColor: primaire,
          unselectedItemColor: texteSecondaire,
          type: BottomNavigationBarType.fixed,
          elevation: 8,
        ),

        // Chips
        chipTheme: ChipThemeData(
          backgroundColor: fond,
          selectedColor: primaire.withValues(alpha: 0.15),
          labelStyle: const TextStyle(fontFamily: 'Inter', fontSize: 13),
          side: const BorderSide(color: bordure),
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(20),
          ),
        ),

        // SnackBar
        snackBarTheme: SnackBarThemeData(
          backgroundColor: textePrincipal,
          contentTextStyle: const TextStyle(
            fontFamily: 'Inter',
            color: Colors.white,
            fontSize: 14,
          ),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
          behavior: SnackBarBehavior.floating,
        ),

        // Typography
        textTheme: const TextTheme(
          headlineLarge: TextStyle(
            fontFamily: 'Inter',
            fontSize: 28,
            fontWeight: FontWeight.w700,
            color: textePrincipal,
          ),
          headlineMedium: TextStyle(
            fontFamily: 'Inter',
            fontSize: 22,
            fontWeight: FontWeight.w600,
            color: textePrincipal,
          ),
          titleLarge: TextStyle(
            fontFamily: 'Inter',
            fontSize: 18,
            fontWeight: FontWeight.w600,
            color: textePrincipal,
          ),
          titleMedium: TextStyle(
            fontFamily: 'Inter',
            fontSize: 16,
            fontWeight: FontWeight.w500,
            color: textePrincipal,
          ),
          bodyLarge: TextStyle(
            fontFamily: 'Inter',
            fontSize: 16,
            fontWeight: FontWeight.w400,
            color: textePrincipal,
          ),
          bodyMedium: TextStyle(
            fontFamily: 'Inter',
            fontSize: 14,
            fontWeight: FontWeight.w400,
            color: textePrincipal,
          ),
          bodySmall: TextStyle(
            fontFamily: 'Inter',
            fontSize: 12,
            fontWeight: FontWeight.w400,
            color: texteSecondaire,
          ),
          labelLarge: TextStyle(
            fontFamily: 'Inter',
            fontSize: 14,
            fontWeight: FontWeight.w600,
            color: textePrincipal,
          ),
        ),

        dividerColor: bordure,
        dividerTheme: const DividerThemeData(color: bordure, thickness: 1),
      );

  // ─── Couleurs sémantiques des statuts ─────────────────────────────────────

  static Color couleurStatutCommande(String statut) {
    return switch (statut) {
      'pending' => const Color(0xFFFFC107), // Jaune — en attente
      'accepted' => const Color(0xFF2196F3), // Bleu — accepté
      'ready' => const Color(0xFF9C27B0), // Violet — prêt
      'picked_up' => primaire, // Orange — en route
      'in_transit' => primaire, // Orange — en transit
      'delivered' => succes, // Vert — livré
      'refused' => erreur, // Rouge — refusé
      'cancelled' => texteSecondaire, // Gris — annulé
      'waiting' => const Color(0xFFFF5722), // Orange foncé — en attente livreur
      _ => texteSecondaire,
    };
  }

  static Color couleurStatutPaiement(String statut) {
    return switch (statut) {
      'paid' => succes,
      'pending' => avertissement,
      'pending_cash' => avertissement,
      'failed' => erreur,
      _ => texteSecondaire,
    };
  }

  // ─── Espacements standardisés ─────────────────────────────────────────────

  static const double espacementXS = 4.0;
  static const double espacementS = 8.0;
  static const double espacementM = 16.0;
  static const double espacementL = 24.0;
  static const double espacementXL = 32.0;

  // ─── Rayons de bordure standardisés ──────────────────────────────────────

  static const double rayonS = 6.0;
  static const double rayonM = 10.0;
  static const double rayonL = 12.0;
  static const double rayonXL = 20.0;
}
