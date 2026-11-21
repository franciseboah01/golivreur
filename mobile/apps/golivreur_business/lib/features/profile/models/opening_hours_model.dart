/// Modèle structuré des horaires d'ouverture d'un commerce (fuseau Africa/Abidjan).
/// Compatible avec `App\Services\ShopAvailability` (Lot 9A Point 7).
class TimeInterval {
  final String start; // HH:mm
  final String end; // HH:mm

  const TimeInterval({required this.start, required this.end});

  bool get isOvernight => _toMinutes(end) < _toMinutes(start);

  bool get isValid {
    final s = _toMinutes(start);
    final e = _toMinutes(end);
    return s >= 0 && e >= 0 && s != e;
  }

  Map<String, String> toJson() => {'start': start, 'end': end};

  factory TimeInterval.fromJson(Map<String, dynamic> json) => TimeInterval(
        start: (json['start'] ?? '08:00').toString(),
        end: (json['end'] ?? '22:00').toString(),
      );

  static int _toMinutes(String hhmm) {
    final parts = hhmm.split(':');
    if (parts.length != 2) return -1;
    final h = int.tryParse(parts[0]);
    final m = int.tryParse(parts[1]);
    if (h == null || m == null || h < 0 || h > 23 || m < 0 || m > 59) {
      return -1;
    }
    return h * 60 + m;
  }

  String get displayLabel => isOvernight ? '$start → $end (+1j)' : '$start → $end';
}

class ShopOpeningHours {
  static const List<String> dayKeys = [
    'monday',
    'tuesday',
    'wednesday',
    'thursday',
    'friday',
    'saturday',
    'sunday',
  ];

  static const Map<String, String> dayLabels = {
    'monday': 'Lundi',
    'tuesday': 'Mardi',
    'wednesday': 'Mercredi',
    'thursday': 'Jeudi',
    'friday': 'Vendredi',
    'saturday': 'Samedi',
    'sunday': 'Dimanche',
  };

  final bool is247;
  final Map<String, List<TimeInterval>> days;

  const ShopOpeningHours({
    this.is247 = true,
    this.days = const {},
  });

  factory ShopOpeningHours.default247() => const ShopOpeningHours(is247: true);

  factory ShopOpeningHours.fromJson(dynamic raw) {
    if (raw is! Map) {
      return ShopOpeningHours.default247();
    }
    final map = Map<String, dynamic>.from(raw);
    if (map['is_24_7'] == true || map['always_open'] == true) {
      return const ShopOpeningHours(is247: true);
    }

    final rawDays = map['days'] is Map
        ? Map<String, dynamic>.from(map['days'] as Map)
        : const <String, dynamic>{};

    if (rawDays.isEmpty) {
      return ShopOpeningHours.default247();
    }

    final parsedDays = <String, List<TimeInterval>>{};
    for (final key in dayKeys) {
      final list = rawDays[key];
      if (list is List) {
        parsedDays[key] = list
            .whereType<Map>()
            .take(2)
            .map((e) => TimeInterval.fromJson(Map<String, dynamic>.from(e)))
            .where((i) => i.isValid)
            .toList();
      } else {
        parsedDays[key] = const [];
      }
    }

    return ShopOpeningHours(is247: false, days: parsedDays);
  }

  Map<String, dynamic> toJson() {
    if (is247) {
      return {
        'timezone': 'Africa/Abidjan',
        'is_24_7': true,
      };
    }
    return {
      'timezone': 'Africa/Abidjan',
      'is_24_7': false,
      'days': {
        for (final key in dayKeys)
          key: (days[key] ?? const []).take(2).map((i) => i.toJson()).toList(),
      },
    };
  }

  /// Retourne null si valide, sinon un message d'erreur lisible.
  String? validate() {
    if (is247) return null;
    var hasAtLeastOneOpenDay = false;
    for (final key in dayKeys) {
      final intervals = days[key] ?? const [];
      if (intervals.length > 2) {
        return '${dayLabels[key]} : maximum 2 plages horaires par jour.';
      }
      if (intervals.isNotEmpty) {
        hasAtLeastOneOpenDay = true;
      }
      for (final interval in intervals) {
        if (!interval.isValid) {
          return '${dayLabels[key]} : horaire invalide (${interval.start} → ${interval.end}).';
        }
      }
    }
    if (!hasAtLeastOneOpenDay) {
      return 'Sélectionnez au moins un jour d\'ouverture ou activez 24h/24 7j/7.';
    }
    return null;
  }

  String toReadableSummary() {
    if (is247) return 'Ouvert 24h/24, 7j/7 (Africa/Abidjan)';
    final lines = <String>[];
    for (final key in dayKeys) {
      final label = dayLabels[key]!.substring(0, 3);
      final intervals = days[key] ?? const [];
      if (intervals.isEmpty) {
        lines.add('$label : Fermé');
      } else {
        lines.add('$label : ${intervals.map((i) => i.displayLabel).join(', ')}');
      }
    }
    return lines.join(' • ');
  }

  ShopOpeningHours copyWith({
    bool? is247,
    Map<String, List<TimeInterval>>? days,
  }) {
    return ShopOpeningHours(
      is247: is247 ?? this.is247,
      days: days ?? this.days,
    );
  }
}
