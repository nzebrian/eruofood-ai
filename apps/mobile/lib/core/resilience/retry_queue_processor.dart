import 'package:dio/dio.dart';

import '../network/api_client.dart';
import 'pending_operation.dart';
import 'reconciliation_gateway.dart';
import 'retry_eligibility.dart';
import 'retry_queue.dart';
import 'retry_queue_interceptor.dart';

/// What one pass over the queue did. Returned rather than logged so a caller
/// (and a test) can assert on it instead of reading console output.
class RetryQueueRun {
  const RetryQueueRun({
    this.skipped = false,
    this.reconciled = 0,
    this.removed = 0,
    this.resent = 0,
    this.stillQueued = 0,
    this.exhausted = const <String>[],
    this.reconciliationFailed = false,
  });

  /// Another pass was already running. Nothing was attempted.
  final bool skipped;

  final int reconciled;
  final int removed;
  final int resent;
  final int stillQueued;

  /// Keys that have used up [PendingOperation.maxAttempts]. They stay queued
  /// and are never sent automatically again — see the note on that constant.
  final List<String> exhausted;

  /// The server could not be asked. Nothing was resent, deliberately.
  final bool reconciliationFailed;
}

/// Drains the queue, under control.
///
/// ## What "under control" rules out
///
/// - **Concurrent passes.** One flag, checked and set synchronously before the
///   first `await`. Two passes would resend the same operation twice while each
///   believed it was the only one.
/// - **Polling.** Nothing here loops or schedules itself. [process] runs once
///   and returns; the app calls it at the moments it already has — cold start
///   and resume. The repository has no connectivity package, so there is no
///   event to subscribe to, and inventing a timer would be a retry storm with
///   extra steps.
/// - **Sending before asking.** Every operation that has been attempted, and
///   every money-moving operation whatever its attempt count, is reconciled
///   first. Only an explicit `never_received` + `safe_to_resend` from the
///   server makes one sendable again.
///
/// ## If the server cannot be reached
///
/// Nothing is resent. That is the whole point: without the server's answer the
/// client does not know whether a resend duplicates a charge, and "we could not
/// ask" is not evidence that nothing happened.
class RetryQueueProcessor {
  RetryQueueProcessor({
    required RetryQueue queue,
    required ReconciliationGateway reconciliation,
    required ApiClient client,
    required DateTime Function() now,
    void Function(String message)? onDiagnostic,
  })  : _queue = queue,
        _reconciliation = reconciliation,
        _client = client,
        _now = now,
        _onDiagnostic = onDiagnostic;

  final RetryQueue _queue;
  final ReconciliationGateway _reconciliation;
  final ApiClient _client;
  final DateTime Function() _now;
  final void Function(String message)? _onDiagnostic;

  bool _running = false;

  /// Whether a pass is in flight. Exposed for diagnostics and tests.
  bool get isRunning => _running;

  Future<RetryQueueRun> process() async {
    // Set before any await. Dart's single-threaded scheduling makes this a
    // sufficient guard, and only because there is no await between the read
    // and the write.
    if (_running) return const RetryQueueRun(skipped: true);
    _running = true;

    try {
      return await _run();
    } finally {
      _running = false;
    }
  }

  Future<RetryQueueRun> _run() async {
    final needsReconciliation = await _queue.recoverAfterRestart();

    // Never attempted and harmless: the request demonstrably never left, so
    // there is nothing for the server to have a record of.
    final sendable = <PendingOperation>[];
    sendable.addAll(await _queue.replayableAfterRestart());

    var reconciled = 0;
    var removed = 0;

    if (needsReconciliation.isNotEmpty) {
      final Map<String, RetryOutcome> outcomes;

      try {
        outcomes = await _reconciliation.reconcile(needsReconciliation);
      } on Object catch (error) {
        _onDiagnostic?.call(
          'retry_queue: could not reach reconciliation ($error). Nothing was '
          'resent.',
        );

        return RetryQueueRun(
          stillQueued: _queue.operations.length,
          reconciliationFailed: true,
          exhausted: _exhaustedKeys(),
        );
      }

      reconciled = outcomes.length;

      for (final operation in needsReconciliation) {
        final outcome = outcomes[operation.idempotencyKey];

        // Absent means the server did not answer for this key. Unresolved is
        // not settled, and it is certainly not permission to resend.
        if (outcome == null) continue;

        await _queue.applyOutcome(operation.idempotencyKey, outcome);

        switch (outcome) {
          case RetryOutcome.succeeded:
          case RetryOutcome.settled:
            removed++;
          case RetryOutcome.retryable:
            sendable.add(operation);
          case RetryOutcome.awaitingServer:
            break;
        }
      }
    }

    final resent = await _resend(sendable);

    return RetryQueueRun(
      reconciled: reconciled,
      removed: removed,
      resent: resent,
      stillQueued: _queue.operations.length,
      exhausted: _exhaustedKeys(),
    );
  }

  Future<int> _resend(List<PendingOperation> candidates) async {
    if (candidates.isEmpty) return 0;

    final now = _now();
    final ready = await _queue.readyAt(now);
    final readyKeys =
        ready.map((PendingOperation o) => o.idempotencyKey).toSet();

    var sent = 0;
    final seen = <String>{};

    for (final operation in candidates) {
      // Reconciliation and the never-attempted list can both yield the same
      // entry; sending it twice in one pass is the duplicate this guards.
      if (!seen.add(operation.idempotencyKey)) continue;
      if (!readyKeys.contains(operation.idempotencyKey)) continue;

      if (operation.isExhausted) {
        _onDiagnostic?.call(
          'retry_queue: ${operation.scope} ${operation.idempotencyKey} has used '
          'its ${PendingOperation.maxAttempts} attempts and will not be resent '
          'automatically. It remains queued for resolution.',
        );
        continue;
      }

      final endpoint = RetryEligibility.forScope(operation.scope);
      if (endpoint == null) {
        // A build that no longer declares this scope cannot rebuild the
        // request. Leaving it queued keeps it reconcilable.
        _onDiagnostic?.call(
          'retry_queue: no declaration for scope ${operation.scope}; left '
          'queued rather than guessed at.',
        );
        continue;
      }

      // Counted before sending. If the process dies mid-flight the attempt is
      // still on disk, so the next start reconciles rather than replays.
      await _queue.recordAttempt(operation.idempotencyKey, now);

      try {
        await _client.raw.request<dynamic>(
          operation.endpoint,
          data: operation.payload,
          options: Options(
            method: endpoint.method,
            // The original key, so the server collapses this onto the original
            // request rather than treating it as a new one. Authentication is
            // deliberately absent — the ApiClient's token interceptor supplies
            // the *current* token when this goes out.
            extra: <String, dynamic>{
              RetryQueueInterceptor.replayKeyExtra: operation.idempotencyKey,
            },
          ),
        );
        sent++;
      } on Object catch (error) {
        // The interceptor has already classified this and updated the queue.
        // Failing to resend one operation must not abandon the rest.
        _onDiagnostic?.call(
          'retry_queue: resend of ${operation.scope} failed ($error).',
        );
      }
    }

    return sent;
  }

  List<String> _exhaustedKeys() => _queue.operations
      .where((PendingOperation o) => o.isExhausted)
      .map((PendingOperation o) => o.idempotencyKey)
      .toList();
}
