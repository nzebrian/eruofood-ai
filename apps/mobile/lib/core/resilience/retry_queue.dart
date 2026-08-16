import 'pending_operation.dart';

/// The outcome of trying to resolve one queued operation.
enum RetryOutcome {
  /// The server accepted it. Remove from the queue.
  succeeded,

  /// Nothing took effect; safe to send again after backoff.
  retryable,

  /// Settled one way or the other. Remove from the queue and report it.
  settled,

  /// The server has it and is still working. Keep it, do not resend.
  awaitingServer,
}

/// Somewhere to persist the queue across restarts.
///
/// Deliberately an interface: the queue's logic is what needs testing, and it
/// must be testable without a device, a keychain or a filesystem.
abstract class PendingOperationStore {
  Future<List<PendingOperation>> load();
  Future<void> save(List<PendingOperation> operations);
}

/// Operations waiting to reach the server, and the rules for retrying them.
///
/// ## The rule that prevents double charges
///
/// After a restart, a money-moving operation is **never replayed**. The app
/// cannot distinguish "the request never arrived" from "it arrived, succeeded,
/// and the response was lost" — and replaying the second case charges the
/// customer twice. So those are handed to reconciliation, which asks the server
/// what actually happened and answers with one of [RetryOutcome].
///
/// Non-money-moving operations may be replayed directly, because the
/// idempotency key already collapses a duplicate and the cost of being wrong is
/// a redundant write rather than a second charge.
///
/// ## Nothing here reports success
///
/// The queue has no state meaning "done and it worked". An operation leaves the
/// queue when the *server* said so. A client that marks its own work successful
/// is the failure this entire foundation exists to prevent.
class RetryQueue {
  RetryQueue(this._store);

  final PendingOperationStore _store;

  List<PendingOperation> _operations = const [];
  bool _loaded = false;

  List<PendingOperation> get operations => List.unmodifiable(_operations);

  Future<void> _ensureLoaded() async {
    if (_loaded) return;
    _operations = await _store.load();
    _loaded = true;
  }

  /// Enqueue before sending, so a crash mid-flight leaves a record.
  Future<void> enqueue(PendingOperation operation) async {
    await _ensureLoaded();

    // A repeat enqueue of the same key is the caller retrying, not a second
    // operation. Silently ignoring it keeps the queue a set rather than a log.
    if (_operations.any((o) => o.idempotencyKey == operation.idempotencyKey)) {
      return;
    }

    _operations = [..._operations, operation];
    await _store.save(_operations);
  }

  Future<void> remove(String idempotencyKey) async {
    await _ensureLoaded();
    _operations =
        _operations.where((o) => o.idempotencyKey != idempotencyKey).toList();
    await _store.save(_operations);
  }

  Future<void> recordAttempt(String idempotencyKey, DateTime at) async {
    await _ensureLoaded();
    _operations = _operations
        .map((o) => o.idempotencyKey == idempotencyKey ? o.withAttempt(at) : o)
        .toList();
    await _store.save(_operations);
  }

  /// Operations whose backoff has elapsed and which may be sent now.
  Future<List<PendingOperation>> readyAt(DateTime now) async {
    await _ensureLoaded();
    return _operations.where((o) => o.isReadyAt(now)).toList();
  }

  /// What to do with each queued operation after the app restarts.
  ///
  /// Returns the operations that must be reconciled with the server before
  /// anything is resent. Money-moving work is always in that set, however
  /// harmless the retry looks.
  Future<List<PendingOperation>> recoverAfterRestart() async {
    await _ensureLoaded();
    return _operations.where((o) => o.isMoneyMoving || o.attempts > 0).toList();
  }

  /// Operations safe to replay directly without asking the server first.
  Future<List<PendingOperation>> replayableAfterRestart() async {
    await _ensureLoaded();
    return _operations
        .where((o) => !o.isMoneyMoving && o.attempts == 0)
        .toList();
  }

  /// Apply a reconciliation answer.
  Future<void> applyOutcome(String idempotencyKey, RetryOutcome outcome) async {
    switch (outcome) {
      case RetryOutcome.succeeded:
      case RetryOutcome.settled:
        await remove(idempotencyKey);
      case RetryOutcome.retryable:
      case RetryOutcome.awaitingServer:
        // Stays queued. `awaitingServer` deliberately does not resend — the
        // server holds a claim and another attempt would be refused anyway.
        break;
    }
  }

  /// Map the server's reconciliation outcome onto a queue decision.
  static RetryOutcome outcomeFromReconciliation(
      Map<String, dynamic> operation) {
    final outcome = operation['outcome'] as String?;
    final safeToResend = operation['safe_to_resend'] as bool? ?? false;

    return switch (outcome) {
      'settled' => RetryOutcome.settled,
      'in_progress' => RetryOutcome.awaitingServer,
      'never_received' when safeToResend => RetryOutcome.retryable,
      // Unrecognised: wait rather than resend. An unknown answer must not be
      // read as permission to send money again.
      _ => RetryOutcome.awaitingServer,
    };
  }
}
