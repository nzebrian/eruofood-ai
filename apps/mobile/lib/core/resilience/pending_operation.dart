/// An operation the app sent, or tried to send, and has not yet resolved.
///
/// ## Why the key is generated before sending, not after
///
/// The whole point is surviving the case where the app never hears back. A key
/// minted by the server is useless for that — there is no response to carry it.
/// So the client generates it, stores it, and only then sends. If the process
/// dies between those two steps nothing was sent; if it dies after, the key is
/// on disk and reconciliation can ask what happened to it.
class PendingOperation {
  const PendingOperation({
    required this.idempotencyKey,
    required this.scope,
    required this.endpoint,
    required this.payload,
    required this.createdAt,
    this.attempts = 0,
    this.lastAttemptAt,
    this.isMoneyMoving = false,
  });

  factory PendingOperation.fromJson(Map<String, dynamic> json) =>
      PendingOperation(
        idempotencyKey: json['idempotency_key'] as String,
        scope: json['scope'] as String,
        endpoint: json['endpoint'] as String,
        payload: Map<String, dynamic>.from(json['payload'] as Map),
        createdAt: DateTime.parse(json['created_at'] as String).toUtc(),
        attempts: json['attempts'] as int? ?? 0,
        lastAttemptAt: json['last_attempt_at'] == null
            ? null
            : DateTime.parse(json['last_attempt_at'] as String).toUtc(),
        isMoneyMoving: json['is_money_moving'] as bool? ?? false,
      );

  final String idempotencyKey;
  final String scope;
  final String endpoint;
  final Map<String, dynamic> payload;
  final DateTime createdAt;
  final int attempts;
  final DateTime? lastAttemptAt;

  /// Whether this moves money or creates an irreversible commitment.
  ///
  /// Money-moving operations are never replayed blind after a restart: they are
  /// *reconciled* first, because the app cannot tell a request that failed from
  /// one that succeeded silently. See [RetryQueue.recoverAfterRestart].
  final bool isMoneyMoving;

  Map<String, dynamic> toJson() => {
        'idempotency_key': idempotencyKey,
        'scope': scope,
        'endpoint': endpoint,
        'payload': payload,
        'created_at': createdAt.toIso8601String(),
        'attempts': attempts,
        'last_attempt_at': lastAttemptAt?.toIso8601String(),
        'is_money_moving': isMoneyMoving,
      };

  PendingOperation withAttempt(DateTime at) => PendingOperation(
        idempotencyKey: idempotencyKey,
        scope: scope,
        endpoint: endpoint,
        payload: payload,
        createdAt: createdAt,
        attempts: attempts + 1,
        lastAttemptAt: at,
        isMoneyMoving: isMoneyMoving,
      );

  /// The ceiling, in seconds. Five minutes.
  ///
  /// A single named constant because the false-positive audit caught this
  /// having *two* caps: an inner `attempts.clamp(1, 8)` and an outer
  /// `seconds.clamp(2, 300)`. The inner one bounded the result to 256s, so the
  /// outer one could never fire — dead code, and the docblock's promise of five
  /// minutes was not what the code did. Worse, a test asserting the cap passed
  /// with the outer clamp deleted, because it was never the thing capping.
  static const int maxBackoffSeconds = 300;

  /// Exponential backoff with a ceiling: 2s, 4s, 8s … capped at five minutes.
  ///
  /// The cap matters more than the curve. Without one, a queue that has been
  /// retrying overnight waits hours before its next attempt and looks broken to
  /// the person holding the phone.
  Duration get backoff {
    if (attempts <= 0) return Duration.zero;

    // Bounded before the shift purely to keep it inside a 64-bit int; the
    // ceiling below is what actually caps the wait.
    final seconds = 1 << attempts.clamp(1, 30);

    return Duration(
      seconds: seconds > maxBackoffSeconds ? maxBackoffSeconds : seconds,
    );
  }

  bool isReadyAt(DateTime now) {
    final last = lastAttemptAt;
    if (last == null) return true;
    return !now.isBefore(last.add(backoff));
  }
}
