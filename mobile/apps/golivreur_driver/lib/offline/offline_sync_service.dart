import 'dart:async';
import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:sqflite/sqflite.dart';

/// Service de synchronisation offline pour les livreurs.
///
/// Pourquoi ce service existe :
///   À Daloa, les coupures réseau sont fréquentes.
///   Un livreur ne doit pas perdre une course parce que son réseau a coupé
///   pendant 30 secondes. Ce service :
///   1. Stocke les positions GPS non envoyées dans SQLite local
///   2. Surveille la connexion en arrière-plan
///   3. Synchronise automatiquement quand le réseau revient
///
/// ⚠️ MAINTENANCE : Ne pas modifier l'ordre des opérations dans syncQueue().
///   Les positions GPS doivent être synchronisées dans l'ordre chronologique (ts ASC).
///   Une inversion de l'ordre fausserait le suivi GPS côté client.
class OfflineSyncService {
  // ── Base de données SQLite locale ──────────────────────────────────────────
  Database? _db;

  // Nom du fichier SQLite sur le téléphone du livreur
  static const String _nomFichier = 'golivreur_driver_offline.db';

  // Table de stockage des positions GPS en attente de sync
  static const String _tableGpsQueue = 'gps_queue';

  // ── Surveillance réseau ────────────────────────────────────────────────────
  // Abonnement aux changements de connectivité (WiFi, 4G, 2G, hors ligne)
  StreamSubscription? _abonnementConnectivite;

  // Indique si le livreur est actuellement en ligne
  bool _estEnLigne = true;

  // Callback appelé quand le statut de connexion change
  // Utilisé pour afficher/masquer la bannière "Mode hors-ligne" dans l'UI
  final void Function(bool estEnLigne) _onChangementConnectivite;

  // Référence au provider pour appeler l'API quand on resynchronise
  final Ref _ref;

  OfflineSyncService({
    required void Function(bool) onChangementConnectivite,
    required Ref ref,
  })  : _onChangementConnectivite = onChangementConnectivite,
        _ref = ref;

  // ── Initialisation ─────────────────────────────────────────────────────────

  /// Initialise la base de données SQLite et démarre la surveillance réseau.
  /// Appeler cette méthode au démarrage de l'app Driver.
  Future<void> initialiser() async {
    // 1. Ouvrir (ou créer) la base de données SQLite locale
    _db = await openDatabase(
      _nomFichier,
      version: 1,
      // onCreate est appelé uniquement à la première installation
      onCreate: (db, version) async {
        await db.execute('''
          CREATE TABLE $_tableGpsQueue (
            id      INTEGER PRIMARY KEY AUTOINCREMENT,
            lat     REAL    NOT NULL,      -- Latitude GPS
            lng     REAL    NOT NULL,      -- Longitude GPS
            ts      INTEGER NOT NULL,      -- Timestamp Unix (millisecondes)
            synced  INTEGER DEFAULT 0      -- 0 = en attente, 1 = envoyé avec succès
          )
        ''');
        // Index sur ts pour récupérer les positions dans l'ordre chronologique
        await db.execute(
          'CREATE INDEX idx_gps_queue_ts ON $_tableGpsQueue (ts ASC)',
        );
      },
    );

    // 2. Démarrer la surveillance de la connectivité réseau
    _demarrerSurveillanceReseau();
  }

  // ── Surveillance réseau ────────────────────────────────────────────────────

  void _demarrerSurveillanceReseau() {
    _abonnementConnectivite = Connectivity()
        .onConnectivityChanged
        .listen((ConnectivityResult result) {
      // ConnectivityResult.none = pas de réseau
      // ConnectivityResult.mobile = 2G/3G/4G
      // ConnectivityResult.wifi = WiFi
      final maintenant = result != ConnectivityResult.none;

      if (maintenant != _estEnLigne) {
        _estEnLigne = maintenant;

        // Notifier l'UI du changement de statut réseau
        _onChangementConnectivite(maintenant);

        if (maintenant) {
          // Réseau revenu → synchroniser immédiatement la queue GPS
          // Délai de 2 secondes pour laisser la connexion s'établir
          Future.delayed(const Duration(seconds: 2), _synchroniserQueue);
        }
      }
    });
  }

  // ── Stockage GPS offline ───────────────────────────────────────────────────

  /// Enregistre une position GPS en local si le réseau est indisponible.
  /// Appelé par le service GPS à la place de l'appel API quand hors-ligne.
  Future<void> sauvegarderPositionLocale({
    required double lat,
    required double lng,
  }) async {
    // S'assurer que la BDD est initialisée
    if (_db == null) await initialiser();

    await _db!.insert(_tableGpsQueue, {
      'lat': lat,
      'lng': lng,
      // ts en millisecondes pour la précision du tri chronologique
      'ts': DateTime.now().millisecondsSinceEpoch,
      'synced': 0,
    });
  }

  /// Retourne le nombre de positions GPS en attente de synchronisation.
  /// Utilisé pour afficher un badge dans l'UI ("X positions non synchronisées").
  Future<int> compterPositionsEnAttente() async {
    if (_db == null) return 0;
    final result = await _db!.rawQuery(
      'SELECT COUNT(*) as count FROM $_tableGpsQueue WHERE synced = 0',
    );
    return result.first['count'] as int? ?? 0;
  }

  // ── Synchronisation ────────────────────────────────────────────────────────

  /// Synchronise toutes les positions GPS en attente vers l'API.
  ///
  /// ⚠️ ORDRE IMPORTANT : Les positions sont envoyées dans l'ordre
  ///   chronologique (ts ASC) pour ne pas perturber le suivi côté client.
  ///   Ne jamais changer ORDER BY ts ASC.
  Future<void> _synchroniserQueue() async {
    if (_db == null || !_estEnLigne) return;

    // Récupérer toutes les positions non synchronisées, dans l'ordre chronologique
    final positions = await _db!.query(
      _tableGpsQueue,
      where: 'synced = 0',
      orderBy: 'ts ASC', // ⚠️ Ne pas modifier cet ordre
      limit: 50, // Max 50 positions par batch pour éviter de saturer l'API
    );

    if (positions.isEmpty) return;

    // Envoyer chaque position vers l'API
    for (final position in positions) {
      try {
        // Appel API : POST /api/v1/courier/location
        await _ref.read(locationServiceProvider).envoyerPosition(
              lat: position['lat'] as double,
              lng: position['lng'] as double,
            );

        // Marquer comme synchronisé seulement si l'appel API a réussi
        await _db!.update(
          _tableGpsQueue,
          {'synced': 1},
          where: 'id = ?',
          whereArgs: [position['id']],
        );
      } catch (e) {
        // Si un envoi échoue (réseau encore instable), arrêter la sync
        // Les positions restantes seront synchronisées au prochain cycle
        break;
      }
    }

    // Nettoyer les positions synchronisées de plus de 24h pour ne pas
    // encombrer le stockage local du téléphone du livreur
    final hier = DateTime.now()
        .subtract(const Duration(hours: 24))
        .millisecondsSinceEpoch;
    await _db!.delete(
      _tableGpsQueue,
      where: 'synced = 1 AND ts < ?',
      whereArgs: [hier],
    );
  }

  // ── Nettoyage ──────────────────────────────────────────────────────────────

  /// Libérer les ressources quand l'app est fermée.
  Future<void> dispose() async {
    await _abonnementConnectivite?.cancel();
    await _db?.close();
  }
}

/// Provider Riverpod du service offline.
final offlineSyncProvider = Provider<OfflineSyncService>((ref) {
  throw UnimplementedError('À initialiser dans main.dart');
});

/// Provider placeholder du service de localisation.
final locationServiceProvider = Provider((ref) => throw UnimplementedError());
