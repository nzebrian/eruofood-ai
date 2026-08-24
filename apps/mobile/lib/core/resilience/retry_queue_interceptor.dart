import 'package:dio/dio.dart';

import 'pending_operation.dart';
import 'retry_eligibility.dart';
import 'retry_queue.dart';

/// Raised when a money-moving request cannot be recorded before it is sent.
///
/// The request is refused rather than sent unrecorded. See
/// [RetryQueueInterceptor] for why that is the safe direction.
class RetryQueueUnavailable implements Exception {
  const RetryQueueUnavailable(this.scope, this.cause);

  final String scope;
  final Object cause;

  @override
  String toString() =>
      'RetryQueueUnavailable($scope): the operation could not be recorded '
      'before sending, so it was not sent. Cause: $cause';
}

/// The seam that finally connects [RetryQueue] to the wire.
///
/// ## The order matters more than the mechanism
///
/// `PendingOperation` documents why the idempotency key is minted by the client
/// before sending: the case worth surviving is the one where the app never
/// hears back, and a server-minted key needs a response to arrive on. This
/// interceptor is where that sequence actually happens —
///
///   1. `onRequest`  — mint the key, **persist the operation**, then attach the
///                     `Idempotency-Key` header and let the request go.
///   2. `onResponse` — the server answered. Remove the entry; it is resolved.
///   3. `onError`    — classify. A refusal removes it; anything ambiguous
///                     records an attempt and leaves it queued.
///
/// Nothing here ever swallows an error or invents a success. The queue is a
/// resilience mechanism sitting beside the call, not in front of its result.
///
/// ## Fail closed on money, fail open on everything else
///
/// If the store cannot persist, a money-moving request is **refused**, because
/// sending one with no local record is exactly the situation reconciliation
/// exists to prevent: a charge the app cannot later ask about. Every other
/// declared endpoint proceeds without a record and says so through
/// [onDiagnostic] — degraded resilience, not a correctness failure. Today every
/// declared endpoint is money-moving, so in practice this always fails closed;
/// the branch exists so adding a harmless endpoint later does not silently
/// inherit the strict behaviour.
///
/// ## Why [QueuedInterceptor] and not [Interceptor]
///
/// [RetryQueue] reads the whole list, mutates it and writes it back. Two
/// concurrent requests through a plain [Interceptor] can interleave inside that
/// read-modify-write and the second save silently discards the first
/// operation — a money-moving request sent with no record, which is exactly the
/// state this class refuses to create deliberately. [QueuedInterceptor] runs
/// its callbacks one at a time, so the sequence is serialised by construction
/// rather than by a lock somebody has to remember.
class RetryQueueInterceptor extends QueuedInterceptor {
  RetryQueueInterceptor({
    required RetryQueue queue,
    required String Function() newIdempotencyKey,
    required DateTime Function() now,
    void Function(String message)? onDiagnostic,
  })  : _queue = queue,
        _newIdempotencyKey = newIdempotencyKey,
        _now = now,
        _onDiagnostic = onDiagnostic;

  /// The header the API's `UsesIdempotencyKey` trait reads.
  static const String headerName = 'Idempotency-Key';

  /// Where the key is stashed on `RequestOptions` so the response and error
  /// paths can find it again without re-deriving anything.
  static const String keyExtra = 'eruofood.retry_queue.key';

  /// Set by the processor when it is resending something already queued, so
  /// this interceptor reuses that key instead of minting a second one for the
  /// same operation.
  static const String replayKeyExtra = 'eruofood.retry_queue.replay_key';

  final RetryQueue _queue;
  final String Function() _newIdempotencyKey;
  final DateTime Function() _now;
  final void Function(String message)? _onDiagnostic;

  @override
  Future<void> onRequest(
    RequestOptions options,
    RequestInterceptorHandler handler,
  ) async {
    final endpoint = RetryEligibility.forRequest(options.method, options.path);
    if (endpoint == null) {
      handler.next(options);
      return;
    }

    final replayKey = options.extra[replayKeyExtra];
    if (replayKey is String && replayKey.isNotEmpty) {
      // A resend of something already on the queue. It is already recorded and
      // its attempt was counted by the processor before this call.
      options.headers[headerName] = replayKey;
      options.extra[keyExtra] = replayKey;
      handler.next(options);
      return;
    }

    final payload = RetryEligibility.sanitisePayload(options.data);
    if (payload == null) {
      // A body that cannot be reconstructed faithfully — FormData, a stream.
      // Sending it unqueued is honest; queueing an approximation is not.
      _onDiagnostic?.call(
        'retry_queue: ${endpoint.scope} was sent without a queue record — its '
        'request body is not a JSON object and could not be stored faithfully.',
      );
      handler.next(options);
      return;
    }

    final key = _newIdempotencyKey();
    final operation = PendingOperation(
      idempotencyKey: key,
      scope: endpoint.scope,
      endpoint: options.path,
      payload: payload,
      createdAt: _now(),
      isMoneyMoving: endpoint.isMoneyMoving,
    );

    try {
      await _queue.enqueue(operation);
    } on Object catch (error) {
      if (endpoint.isMoneyMoving) {
        _onDiagnostic?.call(
          'retry_queue: refused to send ${endpoint.scope} because it could not '
          'be recorded first ($error).',
        );
        handler.reject(
          DioException(
            requestOptions: options,
            type: DioExceptionType.unknown,
            error: RetryQueueUnavailable(endpoint.scope, error),
            message: 'This could not be sent safely. Please try again.',
          ),
        );
        return;
      }

      _onDiagnostic?.call(
        'retry_queue: ${endpoint.scope} was sent without a queue record '
        '($error).',
      );
      handler.next(options);
      return;
    }

    options.headers[headerName] = key;
    options.extra[keyExtra] = key;
    handler.next(options);
  }

  @override
  Future<void> onResponse(
    Response<dynamic> response,
    ResponseInterceptorHandler handler,
  ) async {
    final key = response.requestOptions.extra[keyExtra];
    if (key is String && key.isNotEmpty) {
      try {
        await _queue.remove(key);
      } on Object catch (error) {
        // The server accepted it. A storage failure now leaves a stale entry
        // that reconciliation will resolve as settled — it must not turn a
        // successful order into a failed one on the screen.
        _onDiagnostic?.call(
          'retry_queue: could not clear resolved operation $key ($error). It '
          'will be reconciled and removed on the next run.',
        );
      }
    }

    handler.next(response);
  }

  @override
  Future<void> onError(
    DioException err,
    ErrorInterceptorHandler handler,
  ) async {
    final key = err.requestOptions.extra[keyExtra];
    if (key is! String || key.isEmpty) {
      handler.next(err);
      return;
    }

    try {
      switch (RetryEligibility.classify(err)) {
        case RetryClassification.serverRefused:
          // The server answered and said no. Nothing is pending.
          await _queue.remove(key);
        case RetryClassification.transportFailed:
        case RetryClassification.serverIndeterminate:
          await _queue.recordAttempt(key, _now());
      }
    } on Object catch (error) {
      _onDiagnostic?.call(
        'retry_queue: could not update operation $key after a failure '
        '($error).',
      );
    }

    // Always. The caller's error is the caller's error; the queue does not get
    // to convert a failure into anything else.
    handler.next(err);
  }
}
