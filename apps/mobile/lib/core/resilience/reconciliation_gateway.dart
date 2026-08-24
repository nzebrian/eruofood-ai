import '../network/api_client.dart';
import 'pending_operation.dart';
import 'retry_queue.dart';

/// Asks the server what happened to operations the app never got an answer to.
///
/// Wraps `POST /reconcile`, which is authenticated, throttled to 30/minute and
/// bounded to 50 operations per call — a returning client reconciles a batch
/// once rather than polling. The batch limit is mirrored here as
/// [maxOperationsPerCall] so an over-long queue is split rather than rejected.
///
/// The endpoint is read-only by design: it re-runs, cancels and repairs
/// nothing. Everything this class does is ask.
class ReconciliationGateway {
  ReconciliationGateway(this._client);

  /// `ReconciliationController::MAX_OPERATIONS` on the server.
  static const int maxOperationsPerCall = 50;

  final ApiClient _client;

  /// Reconcile a batch, keyed by idempotency key.
  ///
  /// Operations the server did not answer for are simply absent from the
  /// result. The caller must treat an absent key as unresolved — never as
  /// settled — which is why this returns a map rather than a parallel list.
  Future<Map<String, RetryOutcome>> reconcile(
    List<PendingOperation> operations,
  ) async {
    final outcomes = <String, RetryOutcome>{};

    for (var offset = 0;
        offset < operations.length;
        offset += maxOperationsPerCall) {
      final end = offset + maxOperationsPerCall;
      final batch = operations.sublist(
        offset,
        end > operations.length ? operations.length : end,
      );

      final res = await _client.post<dynamic>(
        '/reconcile',
        data: <String, dynamic>{
          'operations': batch
              .map((PendingOperation o) => <String, dynamic>{
                    'scope': o.scope,
                    'key': o.idempotencyKey,
                  })
              .toList(),
        },
      );

      final data = res.data;
      if (data is! Map) continue;

      final envelope = data['data'];
      if (envelope is! Map) continue;

      final answered = envelope['operations'];
      if (answered is! List) continue;

      for (final entry in answered) {
        if (entry is! Map) continue;

        final answer = Map<String, dynamic>.from(entry);
        final key = answer['idempotency_key'];
        if (key is! String) continue;

        outcomes[key] = RetryQueue.outcomeFromReconciliation(answer);
      }
    }

    return outcomes;
  }
}
