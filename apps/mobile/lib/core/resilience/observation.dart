import 'freshness.dart';

/// A value, when the server observed it, and how much that lets you trust it.
///
/// The client mirror of the server's `Observation` envelope. Binding the value
/// to its age means a widget cannot render the value without having been handed
/// the caveat — the type does the reminding, rather than a convention nobody
/// remembers at 5pm.
class Observation<T> {
  const Observation({
    required this.value,
    required this.observedAt,
    required this.freshness,
    this.ageSeconds,
    this.note,
  });

  /// Parse the server envelope.
  ///
  /// Anything missing or unrecognised degrades to [FreshnessState.staleUnknown]
  /// rather than defaulting to fresh. A client talking to an older server, or a
  /// response that lost a field, must not start claiming data is live.
  factory Observation.fromJson(
    Map<String, dynamic> json,
    T Function(dynamic) parseValue,
  ) {
    final raw = json['value'];
    final observedAtRaw = json['observed_at'];

    return Observation<T>(
      value: raw == null ? null : parseValue(raw),
      observedAt: observedAtRaw is String
          ? DateTime.tryParse(observedAtRaw)?.toUtc()
          : null,
      freshness: FreshnessState.fromWire(json['freshness'] as String?),
      ageSeconds: json['age_seconds'] as int?,
      note: json['note'] as String?,
    );
  }

  /// Something held locally whose age nobody has established.
  ///
  /// Cached data restored after a restart lands here. It is genuinely useful —
  /// a customer seeing their last known order beats an empty screen — but it is
  /// not current and must never be drawn as though it were.
  factory Observation.cached(T value, {String? note}) => Observation<T>(
        value: value,
        observedAt: null,
        freshness: FreshnessState.staleUnknown,
        note: note ?? 'Restored from local cache; age unknown.',
      );

  /// Nothing to show, and why.
  factory Observation.unavailable({
    FreshnessState freshness = FreshnessState.offline,
    String? note,
  }) =>
      Observation<T>(
          value: null, observedAt: null, freshness: freshness, note: note);

  final T? value;

  /// Always UTC. The device clock is never authoritative — see
  /// `server_time` on the reconciliation response.
  final DateTime? observedAt;

  final FreshnessState freshness;
  final int? ageSeconds;
  final String? note;

  bool get hasValue => value != null;

  /// Whether the UI may present this without an age or a caveat.
  bool get mayPresentAsLive => hasValue && freshness.mayPresentAsLive;

  /// Whether it is worth putting on screen at all, labelled.
  bool get isWorthShowing => hasValue && freshness.isUsableWithCaveat;
}
